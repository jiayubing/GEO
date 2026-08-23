<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Enums\ClientProjectStatus;
use App\Models\AiModel;
use App\Models\ClientProject;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class UrlImportProcessingService
{
    private const AI_ANALYSIS_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly ProjectResourceResolver $projectResources,
    ) {}

    /**
     * @return array{url:string,host:string}
     */
    public function normalizeInputUrl(string $input): array
    {
        $candidate = trim($input);
        if ($candidate === '') {
            throw new \InvalidArgumentException(__('admin.url_import.error.url_required'));
        }

        if (! preg_match('#^https?://#i', $candidate)) {
            $candidate = 'https://'.$candidate;
        }

        try {
            $target = $this->safeHttp->resolveTarget($candidate);
        } catch (OutboundRequestBlockedException) {
            throw new \InvalidArgumentException(__('admin.url_import.error.invalid_url'));
        }

        return [
            'url' => $target->url,
            'host' => $target->host,
        ];
    }

    public function assertAnalysisModelReady(): AiModel
    {
        $lastException = null;
        foreach ($this->resolveAnalysisModels() as $model) {
            try {
                $this->prepareAiRuntime($model);

                return $model;
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        if ($lastException) {
            throw new \RuntimeException($lastException->getMessage(), 0, $lastException);
        }

        throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function assertAnalysisModelsReady(): Collection
    {
        $models = $this->resolveAnalysisModels();
        if ($models->isEmpty()) {
            throw new \RuntimeException(__('admin.url_import.error.ai_model_required'));
        }

        $ready = collect();
        $errors = [];
        foreach ($models as $model) {
            try {
                $this->prepareAiRuntime($model);
                $ready->push($model);
            } catch (Throwable $exception) {
                $errors[] = $this->formatModelFailure($model, $exception);
            }
        }

        if ($ready->isEmpty()) {
            throw new \RuntimeException(__('admin.url_import.error.ai_all_models_failed', [
                'messages' => implode('；', $errors),
            ]));
        }

        return $ready;
    }

    public function hasReadyAnalysisModel(): bool
    {
        try {
            $this->assertAnalysisModelReady();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function process(UrlImportJob $job): UrlImportJob
    {
        return $this->processById((int) $job->getKey());
    }

    public function processById(int $jobId): UrlImportJob
    {
        $job = $this->claimProcessingJob($jobId);
        if (in_array((string) $job->status, ['completed', 'imported'], true)) {
            return $job;
        }

        try {
            $this->updateStep($job, 'fetch', 10, [
                'status' => 'running',
                'started_at' => now(),
                'error_message' => '',
            ]);
            $this->log($job, 'info', __('admin.url_import.log.fetch_start', ['url' => $job->normalized_url]));

            $job = $this->refreshRunningJob((int) $job->getKey());
            $fetched = $this->fetchPage((string) $job->normalized_url);
            $this->log($job, 'info', __('admin.url_import.log.fetch_done', ['length' => strlen($fetched['html'])]));

            $this->updateStep($job, 'page_json', 25);
            $this->log($job, 'info', __('admin.url_import.log.page_json_start'));
            $job = $this->refreshRunningJob((int) $job->getKey());
            $parsed = $this->parseHtml($fetched['html'], (string) $job->normalized_url);
            $this->log($job, 'info', __('admin.url_import.log.extract_done', [
                'chars' => mb_strlen($parsed['text'], 'UTF-8'),
            ]));
            $this->log($job, 'info', __('admin.url_import.log.page_json_done', [
                'chars' => mb_strlen((string) data_get($parsed, 'raw_json.text', ''), 'UTF-8'),
            ]));

            $analysis = $this->buildAnalysis($parsed, $this->refreshRunningJob((int) $job->getKey()));

            $result = [
                'source' => [
                    'url' => (string) $job->url,
                    'normalized_url' => (string) $job->normalized_url,
                    'domain' => (string) $job->source_domain,
                    'fetched_at' => now()->toIso8601String(),
                    'status' => $fetched['status'],
                ],
                'page' => $parsed,
                'analysis' => $analysis,
                'import' => [
                    'status' => 'preview',
                    'summary' => null,
                ],
            ];

            $this->updateStep($job, 'preview', 96);
            $this->log($job, 'info', __('admin.url_import.log.preview_start'));

            $this->updateStep($job, 'preview', 100, [
                'page_title' => $parsed['title'],
                'status' => 'completed',
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'finished_at' => now(),
            ]);
            $this->log($job, 'info', __('admin.url_import.log.preview_ready'));

            return $job->refresh();
        } catch (Throwable $exception) {
            $code = $this->safeErrorCode($exception, 'url_import_processing_failed');
            $failedJob = $this->markProcessingFailed((int) $job->getKey(), $code);
            if ($failedJob === null) {
                throw new \DomainException('url_import_job_not_found', 0, $exception);
            }
            $this->log($failedJob, 'error', __('admin.url_import.log.failed', ['message' => $code]));

            return $failedJob;
        }
    }

    public function recoverStaleProcessingById(int $jobId): UrlImportJob
    {
        $job = DB::transaction(function () use ($jobId): UrlImportJob {
            $locked = UrlImportJob::query()->lockForUpdate()->find($jobId);
            if ($locked === null) {
                throw new \DomainException('url_import_job_not_found');
            }

            $this->assertActiveOwner($locked);
            $staleAt = now()->subSeconds((int) config('geoflow.url_import_processing_stale_seconds', 900));
            if ((string) $locked->status !== 'running'
                || $locked->started_at === null
                || $locked->started_at->greaterThan($staleAt)) {
                throw new \DomainException('url_import_job_not_recoverable');
            }

            $locked->forceFill([
                'status' => 'queued',
                'current_step' => 'queued',
                'progress_percent' => 0,
                'error_message' => 'url_import_processing_interrupted',
                'started_at' => null,
                'finished_at' => null,
            ])->save();

            return $locked->refresh();
        }, 3);
        $this->log($job, 'warning', 'url_import_processing_recovered', 'queued');

        return $job;
    }

    /**
     * @return array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int}
     */
    public function commit(UrlImportJob $job): array
    {
        return $this->commitById((int) $job->getKey());
    }

    /**
     * @return array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int}
     */
    public function commitById(int $jobId): array
    {
        try {
            $summary = DB::transaction(fn (): ?array => $this->commitLockedJob($jobId), 3);
            if ($summary === null) {
                throw new \DomainException('url_import_commit_uncertain');
            }
        } catch (Throwable $exception) {
            $code = $this->safeErrorCode($exception, 'url_import_commit_failed');
            if ($code === 'url_import_commit_uncertain') {
                $this->markCommitUncertain($jobId);
            } else {
                $this->markCommitFailed($jobId, $code);
            }

            throw new \DomainException($code, 0, $exception);
        }

        $committedJob = UrlImportJob::query()->find($jobId);
        if ($committedJob !== null) {
            $this->log($committedJob, 'info', __('admin.url_import.log.import_done'));
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeResult(UrlImportJob $job): array
    {
        $decoded = json_decode((string) $job->result_json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{html:string,status:int}
     */
    private function fetchPage(string $url): array
    {
        $request = $this->http->timeout(20)
            ->connectTimeout(8)
            ->withHeaders([
                'User-Agent' => 'GEOFlow URL Importer/1.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ]);
        $response = $this->safeHttp->get(
            $request,
            $url,
            (int) config('geoflow.outbound_import_max_bytes', 5 * 1024 * 1024),
            3,
        );

        if (! $response->successful()) {
            throw new \RuntimeException(__('admin.url_import.error.fetch_failed', ['status' => $response->status()]));
        }

        $html = (string) $response->body();
        if (trim($html) === '') {
            throw new \RuntimeException(__('admin.url_import.error.empty_page'));
        }

        return [
            'html' => $html,
            'status' => $response->status(),
        ];
    }

    /**
     * @return array{title:string,description:string,text:string,summary:string,raw_json:array<string,mixed>}
     */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//script|//style|//noscript|//nav|//footer|//header|//form|//aside') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $title = $this->firstMetaContent($xpath, ['og:title', 'twitter:title']);
        if ($title === '') {
            $titleNode = $xpath->query('//title')->item(0);
            $title = $titleNode ? trim((string) $titleNode->textContent) : '';
        }
        if ($title === '') {
            $h1 = $xpath->query('//h1')->item(0);
            $title = $h1 ? trim((string) $h1->textContent) : ((string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'URL素材'));
        }

        $description = $this->firstMetaContent($xpath, ['description', 'og:description', 'twitter:description']);
        $body = $xpath->query('//article')->item(0) ?: $xpath->query('//main')->item(0) ?: $xpath->query('//body')->item(0);
        $text = $body ? $this->normalizeText((string) $body->textContent) : '';
        $summary = $description !== '' ? $description : Str::limit($text, 220, '...');

        return [
            'title' => $this->normalizeText($title),
            'description' => $this->normalizeText($description),
            'text' => Str::limit($text, 20000, ''),
            'summary' => $this->normalizeText($summary),
            'raw_json' => [
                'title' => $this->normalizeText($title),
                'description' => $this->normalizeText($description),
                'text' => Str::limit($text, 20000, ''),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{summary:string,library_name:string,keywords:list<string>,titles:list<string>,knowledge_markdown:string,analysis_source:string,model:mixed}
     */
    private function buildAnalysis(array $parsed, UrlImportJob $job): array
    {
        $title = (string) ($parsed['title'] ?? '');
        $text = (string) ($parsed['text'] ?? '');
        $summary = (string) ($parsed['summary'] ?? '');
        $libraryName = $this->safeName($title !== '' ? $title : (string) $job->source_domain);
        $pageJson = $this->buildPageJson($parsed, $job);

        $models = $this->assertAnalysisModelsReady();
        $errors = [];

        foreach ($models as $model) {
            for ($attempt = 1; $attempt <= self::AI_ANALYSIS_MAX_ATTEMPTS; $attempt++) {
                try {
                    $this->log($job, 'info', __('admin.url_import.log.ai_model_attempt', [
                        'model' => $this->modelDisplayName($model),
                        'current' => $attempt,
                        'max' => self::AI_ANALYSIS_MAX_ATTEMPTS,
                    ]), 'knowledge');
                    $runtime = $this->prepareAiRuntime($model);

                    $this->updateStep($job, 'knowledge', 45);
                    $this->log($job, 'info', __('admin.url_import.log.knowledge_start'));
                    $this->log($job, 'info', __('admin.url_import.log.clean_start'));
                    $cleaned = $this->normalizeCleanedPage($this->requestAiJson(
                        $runtime,
                        $this->buildCleanSystemPrompt(),
                        $this->buildCleanUserPrompt($pageJson)
                    ), $parsed);
                    $this->log($job, 'info', __('admin.url_import.log.clean_done', [
                        'chars' => mb_strlen((string) $cleaned['text'], 'UTF-8'),
                    ]));

                    $knowledgePayload = $this->requestAiJson(
                        $runtime,
                        $this->buildKnowledgeSystemPrompt(),
                        $this->buildKnowledgeUserPrompt($pageJson, $cleaned, [])
                    );
                    $aiSummary = $this->normalizeText($this->aiResponseTextToString($knowledgePayload['summary'] ?? $cleaned['summary'] ?? $summary));
                    $aiLibraryName = $this->safeName($this->aiResponseTextToString($knowledgePayload['library_name'] ?? $cleaned['title'] ?? $libraryName));
                    $aiKnowledge = trim($this->aiResponseTextToString($knowledgePayload['knowledge_markdown'] ?? ''));
                    if ($aiKnowledge === '') {
                        throw new \RuntimeException(__('admin.url_import.error.ai_knowledge_missing'));
                    }
                    $this->log($job, 'info', __('admin.url_import.log.knowledge_done', [
                        'chars' => mb_strlen($aiKnowledge, 'UTF-8'),
                    ]));

                    $this->updateStep($job, 'keywords', 62);
                    $this->log($job, 'info', __('admin.url_import.log.keywords_start'));
                    $keywordPayload = $this->requestAiJson(
                        $runtime,
                        $this->buildKeywordsSystemPrompt(),
                        $this->buildKeywordsUserPrompt($pageJson, $cleaned, $aiKnowledge),
                        'keywords'
                    );
                    $keywordValues = $keywordPayload['keywords'] ?? (array_is_list($keywordPayload) ? $keywordPayload : []);
                    $aiKeywords = array_slice($this->cleanKeywordList($this->stringList($keywordValues)), 0, 10);
                    if ($aiKeywords === []) {
                        throw new \RuntimeException(__('admin.url_import.error.ai_keywords_missing'));
                    }
                    $this->log($job, 'info', __('admin.url_import.log.keywords_done', ['count' => count($aiKeywords)]));

                    $this->updateStep($job, 'titles', 80);
                    $this->log($job, 'info', __('admin.url_import.log.titles_start'));
                    $titlePayload = $this->requestAiJson(
                        $runtime,
                        $this->buildTitlesSystemPrompt(),
                        $this->buildTitlesUserPrompt($pageJson, $cleaned, $aiKnowledge, $aiKeywords),
                        'titles'
                    );
                    $titleValues = $titlePayload['titles'] ?? (array_is_list($titlePayload) ? $titlePayload : []);
                    $aiTitles = array_slice($this->stringList($titleValues), 0, 50);
                    if ($aiTitles === []) {
                        throw new \RuntimeException(__('admin.url_import.error.ai_titles_missing'));
                    }
                    $this->log($job, 'info', __('admin.url_import.log.titles_done', ['count' => count($aiTitles)]));

                    $this->log($job, 'info', __('admin.url_import.log.ai_analyze_done', ['model' => $this->modelDisplayName($model)]));

                    return [
                        'summary' => $aiSummary !== '' ? $aiSummary : Str::limit($text, 220, '...'),
                        'library_name' => $aiLibraryName !== '' ? $aiLibraryName : $libraryName,
                        'keywords' => $aiKeywords,
                        'titles' => $aiTitles,
                        'knowledge_markdown' => $aiKnowledge,
                        'analysis_source' => 'ai',
                        'model' => [
                            'id' => (int) $model->id,
                            'name' => (string) $model->name,
                        ],
                        'page_json' => $pageJson,
                        'cleaned' => $cleaned,
                    ];
                } catch (Throwable $exception) {
                    $message = 'url_import_ai_model_failed';
                    if ($attempt < self::AI_ANALYSIS_MAX_ATTEMPTS) {
                        $this->log($job, 'warning', __('admin.url_import.log.ai_model_retry', [
                            'model' => $this->modelDisplayName($model),
                            'current' => $attempt,
                            'max' => self::AI_ANALYSIS_MAX_ATTEMPTS,
                            'message' => $message,
                        ]), (string) ($job->current_step ?: 'knowledge'));

                        continue;
                    }

                    $errors[] = $this->formatModelFailure($model, $exception);
                    $this->log($job, 'warning', __('admin.url_import.log.ai_model_failed', [
                        'model' => $this->modelDisplayName($model),
                        'message' => $message,
                    ]), (string) ($job->current_step ?: 'knowledge'));
                }
            }
        }

        throw new \RuntimeException(__('admin.url_import.error.ai_parse_failed', [
            'message' => __('admin.url_import.error.ai_all_models_failed', [
                'messages' => implode('；', $errors),
            ]),
        ]));
    }

    /**
     * @return Collection<int, AiModel>
     */
    private function resolveAnalysisModels(): Collection
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{provider:string,model_id:string,model:AiModel}
     */
    private function prepareAiRuntime(AiModel $model): array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        if ($providerUrl === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_url_missing'));
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException(__('admin.url_import.error.ai_key_missing'));
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($model->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('url_import_analysis', $driver, $providerUrl, $apiKey);

        return [
            'provider' => $providerName,
            'model_id' => (string) ($model->model_id ?? ''),
            'model' => $model,
        ];
    }

    /**
     * @param  array{provider:string,model_id:string,model:AiModel}  $runtime
     * @return array<string, mixed>
     */
    private function requestAiJson(array $runtime, string $systemPrompt, string $userPrompt, ?string $listFallbackKey = null): array
    {
        $agent = new MarkdownContentWriterAgent($systemPrompt);
        /** @var AiModel $model */
        $model = $runtime['model'];
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            throw new \RuntimeException('AI model has reached its daily usage limit.');
        }

        try {
            $response = $agent->prompt(
                $userPrompt,
                [],
                $runtime['provider'],
                $runtime['model_id']
            );
            $content = $this->aiResponseTextToString($response->text ?? '');
            if ($content === '') {
                throw new \RuntimeException(__('admin.url_import.error.ai_empty_content'));
            }

            $decoded = $this->decodeAiJson($content);
            if ($decoded === []) {
                $fallbackList = $listFallbackKey ? $this->parseAiList($content) : [];
                if ($fallbackList !== []) {
                    $decoded = [$listFallbackKey => $fallbackList];
                }
            }

            if ($decoded === []) {
                throw new \RuntimeException(__('admin.url_import.error.ai_invalid_json', [
                    'preview' => $this->previewAiContent($content),
                ]));
            }
        } catch (Throwable $exception) {
            $this->usageQuota->releaseModel($reservation);

            throw new \RuntimeException($this->normalizeAiErrorMessage($exception, $model), 0, $exception);
        }

        $this->usageQuota->recordModelSuccess($reservation);

        return $decoded;
    }

    private function aiResponseTextToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                foreach (['text', 'content', 'message'] as $key) {
                    if (array_key_exists($key, $value)) {
                        $nested = $this->aiResponseTextToString($value[$key]);
                        if ($nested !== '') {
                            return $nested;
                        }
                    }
                }

                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

                return is_string($json) ? trim($json) : '';
            }

            $parts = [];
            foreach ($value as $item) {
                $part = $this->aiResponseTextToString($item);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }

            return trim(implode("\n", $parts));
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return trim((string) $value);
            }

            return $this->aiResponseTextToString(get_object_vars($value));
        }

        return '';
    }

    private function modelDisplayName(AiModel $model): string
    {
        $name = trim((string) ($model->name ?? ''));
        $modelId = trim((string) ($model->model_id ?? ''));

        return trim($name.($modelId !== '' ? ' / '.$modelId : '')) ?: '#'.(int) $model->id;
    }

    private function formatModelFailure(AiModel $model, Throwable $exception): string
    {
        return $this->modelDisplayName($model).'：url_import_ai_model_failed';
    }

    private function normalizeAiErrorMessage(Throwable $exception, ?AiModel $model = null): string
    {
        $providerUrl = '';
        if ($model) {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        }

        return OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl);
    }

    private function buildCleanSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的网页正文清洗器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：clean_title, clean_summary, clean_text, core_business, entities, facts, noise_removed。
目标：从页面 JSON 中去掉导航、菜单、广告、版权、按钮、登录、推荐流、重复模板文案，只保留页面主体内容和可被知识库引用的事实，并识别页面背后的真实核心业务。
core_business 必须描述页面主体对应的行业、产品/服务、目标客户、商业场景、价值主张和可验证边界。
不能虚构页面没有的信息。
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function buildPageJson(array $parsed, UrlImportJob $job): array
    {
        $options = json_decode((string) $job->options_json, true);
        $options = is_array($options) ? $options : [];

        return [
            'source_url' => (string) $job->normalized_url,
            'source_domain' => (string) $job->source_domain,
            'project_name' => (string) ($options['project_name'] ?? ''),
            'source_label' => (string) ($options['source_label'] ?? ''),
            'content_language' => (string) ($options['content_language'] ?? ''),
            'operator_notes' => (string) ($options['notes'] ?? ''),
            'title' => (string) ($parsed['title'] ?? ''),
            'description' => (string) ($parsed['description'] ?? ''),
            'summary' => (string) ($parsed['summary'] ?? ''),
            'text' => Str::limit((string) ($parsed['text'] ?? ''), 12000, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $pageJson
     */
    private function buildCleanUserPrompt(array $pageJson): string
    {
        return "请清洗以下页面 JSON，输出 clean_title、clean_summary、clean_text、entities、facts、noise_removed。\n\n页面 JSON：\n"
            .json_encode($pageJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\n输出要求：\n"
            ."1. clean_text 只保留主体正文，不要保留“查看详情、返回首页、登录、注册、更多、相关阅读”等模板噪声。\n"
            ."2. clean_summary 120-240 字，概括真实主体内容。\n"
            ."3. core_business 输出对象，包含 industry、products_services、target_audience、commercial_scenarios、value_proposition、evidence_limits。\n"
            ."4. facts 输出页面明确出现或可直接归纳的事实短句，优先服务/产品/能力/客户/场景/数据。\n"
            .'5. entities 输出品牌、产品、服务、行业、目标用户、地名、人名等实体。';
    }

    private function buildKeywordsSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的核心业务关键词提炼器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：keywords。
keywords 最多 10 个，必须是短关键词或短语。中文关键词优先 2-5 个字，英文关键词优先 1-3 个单词。
只允许输出基于知识库反推出来的核心业务词根、产品/服务词、行业词、需求场景词、问题词、解决方案词。
关键词必须具备商业价值或内容选题价值，能支撑后续生成 GEO 文章。
禁止输出：AI、GEO、URL、来源、页面描述、引擎、官网、首页、公司名、人名、导航词、按钮词、广告口号、整段摘要、长句和重复词。
不能虚构页面没有的信息。
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     */
    private function buildKeywordsUserPrompt(array $pageJson, array $cleaned, string $knowledgeMarkdown): string
    {
        return "请只基于已清洗知识库，提取 5-10 个最核心的业务词根或业务关键词。不要从原网页机械摘词，要先判断业务本质，再输出能带来商业检索价值的短关键词。\n\n"
            ."GEOFlow 内置规则：\n".$this->builtInGeoCollectionPrompt()."\n\n"
            ."后台关键词提示词：\n".$this->latestPromptContent('keyword')."\n\n"
            ."页面来源与清洗结果：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'source_domain' => $pageJson['source_domain'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'entities' => $cleaned['entities'] ?? [],
                'facts' => $cleaned['facts'] ?? [],
                'knowledge_markdown' => Str::limit($knowledgeMarkdown, 9000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function buildTitlesSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的 GEO 标题库构建器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：titles。
titles 最多 50 个，必须基于页面真实信息、知识库和 10 个核心业务词生成，适合后续生成真实可信的 GEO 内容。
标题角度要多样：是什么、为什么、怎么做、选型、对比、指南、清单、常见问题、场景拆解、趋势判断、2026 趋势或商业价值。
每个标题都必须围绕某个核心业务词或业务场景展开，面向 AI 搜索/GEO 的问答、推荐、比较、选型、采购、实施和风险判断。
不要机械复读网页标题，不要虚构“第一、最好、领先”等无来源支撑的绝对化表述。
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     * @param  list<string>  $keywords
     */
    private function buildTitlesUserPrompt(array $pageJson, array $cleaned, string $knowledgeMarkdown, array $keywords): string
    {
        return "请为 GEOFlow 标题库生成 50 个可用于内容任务的标题。标题要围绕核心业务词展开，必须服务于用户在 AI 搜索中的真实问题、比较、选型、采购、实施或运营决策。\n\n"
            ."后台正文提示词参考：\n".$this->latestPromptContent('content')."\n\n"
            ."输入：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'keywords' => array_slice($keywords, 0, 10),
                'facts' => $cleaned['facts'] ?? [],
                'knowledge_markdown' => Str::limit($knowledgeMarkdown, 7000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private function buildKnowledgeSystemPrompt(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的知识库构建器。只输出 JSON，不要输出 Markdown 代码块。
字段固定为：summary, library_name, knowledge_markdown。
knowledge_markdown 必须围绕“核心业务”构建，是真实可追溯、结构化、原子化的知识库内容，保留来源 URL，只沉淀页面明确出现或可由页面内容直接归纳的信息。
必须优先抽取：核心业务、产品/服务、目标用户、业务场景、能力/优势、可验证事实、使用边界、适合支撑的 GEO 内容方向。
不能虚构事实、案例、客户、排名、数据、背书。信息不足时明确标注“页面未明确说明”。
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $pageJson
     * @param  array<string, mixed>  $cleaned
     * @param  list<string>  $keywords
     */
    private function buildKnowledgeUserPrompt(array $pageJson, array $cleaned, array $keywords): string
    {
        return "请基于页面 JSON 和清洗正文生成可直接入库的 GEOFlow 知识库 Markdown。先识别核心业务，再把页面信息拆成结构化、原子化事实，最后归纳 GEO 内容可用方向和使用边界。\n\n"
            ."后台描述提示词参考：\n".$this->latestPromptContent('description')."\n\n"
            ."输入：\n".json_encode([
                'source_url' => $pageJson['source_url'] ?? '',
                'source_domain' => $pageJson['source_domain'] ?? '',
                'title' => $cleaned['title'] ?? $pageJson['title'] ?? '',
                'summary' => $cleaned['summary'] ?? '',
                'core_business' => $cleaned['core_business'] ?? [],
                'keywords' => array_slice($keywords, 0, 40),
                'entities' => $cleaned['entities'] ?? [],
                'facts' => $cleaned['facts'] ?? [],
                'clean_text' => Str::limit((string) ($cleaned['text'] ?? ''), 10000, ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            ."\n\n建议结构：来源、核心业务摘要、原子化事实、产品/服务与能力、目标用户与场景、可引用事实、GEO 内容建议、使用边界。";
    }

    private function builtInGeoCollectionPrompt(): string
    {
        return <<<'PROMPT'
你正在为 GEOFlow 构建可复用素材库。请把网页内容拆成三类资产：

关键词库：
- 输出短词或短语，不要输出完整句子。
- 优先：产品/服务词、行业词、目标客户词、需求场景词、痛点词、解决方案词、AI 搜索/GEO/SEO/内容运营相关词。
- 避免：纯品牌词、公司名、人名、泛词、空话、标点堆叠、整句广告语、无法独立检索的长句。
- 中文关键词尽量控制在 2-5 个字，英文关键词尽量控制在 1-3 个单词。

标题库：
- 标题要能驱动后续生成文章，围绕“是什么、为什么、怎么做、对比、选型、指南、清单、案例拆解、常见问题、趋势判断”等角度展开。
- 不要全部套用同一个模板；不要虚构“最好、第一、领先”等没有来源支撑的绝对化表述。

知识库：
- 先沉淀事实，再生成观点。
- 保留来源 URL、页面标题、页面摘要、明确出现的品牌/产品/服务/能力/场景。
- 对不确定信息要标注边界，不能伪造客户案例、数据、第三方评价或排名。
PROMPT;
    }

    private function latestPromptContent(string $type): string
    {
        return (string) (Prompt::query()
            ->where('type', $type)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->value('content') ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAiJson(string $content): array
    {
        foreach ($this->jsonCandidates($content) as $candidate) {
            $decoded = json_decode($candidate, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded !== []) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function jsonCandidates(string $content): array
    {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content);
        $content = trim(preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content) ?? $content);

        $candidates = [$content];

        if (preg_match_all('/```(?:json)?\s*(.*?)```/is', $content, $matches)) {
            foreach ($matches[1] ?? [] as $match) {
                $candidates[] = trim((string) $match);
            }
        }

        foreach ([['{', '}'], ['[', ']']] as [$open, $close]) {
            $balanced = $this->extractBalancedJson($content, $open, $close);
            if ($balanced !== '') {
                $candidates[] = $balanced;
            }

            $start = strpos($content, $open);
            $end = strrpos($content, $close);
            if ($start !== false && $end !== false && $end > $start) {
                $candidates[] = substr($content, $start, $end - $start + 1);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $candidates))));
    }

    private function extractBalancedJson(string $content, string $open, string $close): string
    {
        $start = strpos($content, $open);
        if ($start === false) {
            return '';
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($index = $start; $index < $length; $index++) {
            $char = $content[$index];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }

            if ($char === $open) {
                $depth++;

                continue;
            }

            if ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $index - $start + 1);
                }
            }
        }

        return '';
    }

    private function previewAiContent(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

        return Str::limit(trim($content), 240, '...');
    }

    /**
     * Some models obey the semantic request but ignore the strict JSON wrapper and
     * return comma-separated or numbered lists. Preserve those valid answers for
     * list-only steps such as keywords and titles, while still requiring JSON for
     * knowledge-base extraction.
     *
     * @return list<string>
     */
    private function parseAiList(string $content): array
    {
        $content = trim(strip_tags($content));
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content) ?? $content;
        $content = preg_replace('/```(?:json|text)?\s*|\s*```/i', '', $content) ?? $content;
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $content) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^\s*(?:[-*•]|\d+[\.\)、)]|[（(]?\d+[）)])\s*/u', '', $line) ?? $line;
            foreach (preg_split('/[,\x{FF0C};；、]/u', $line) ?: [] as $part) {
                $part = trim((string) $part);
                $part = preg_replace('/^[\"“”‘’]+|[\"“”‘’]+$/u', '', $part) ?? $part;
                if ($part !== '') {
                    $items[] = $part;
                }
            }
        }

        return Collection::make($items)
            ->map(fn (string $item): string => $this->normalizeText($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->take(80)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text): array
    {
        preg_match_all('/[\p{Han}A-Za-z0-9][\p{Han}A-Za-z0-9\-\+\.]{1,24}/u', $text, $matches);
        $stopWords = ['http', 'https', 'www', 'com', 'the', 'and', 'for', 'with', 'this', 'that', 'from', '一个', '我们', '可以', '这个', '以及', '进行', '页面', '内容', '如果', '通过', '不是', '还有', '查看详情', '详情', '更多', '重磅', '首页', '登录', '注册', '返回', '点击', '阅读', '分享'];
        $counts = [];
        foreach ($matches[0] ?? [] as $word) {
            $word = trim($word);
            if (mb_strlen($word, 'UTF-8') < 2 || in_array(mb_strtolower($word, 'UTF-8'), $stopWords, true)) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);

        return array_slice(array_keys($counts), 0, 100);
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function cleanKeywordList(array $keywords): array
    {
        $stopWords = [
            'ai', 'geo', 'url', '来源', '引擎', '官网', '页面', '页面描述', '来源域名', '公司',
            '查看详情', '详情', '重磅', '更多', '查看更多', '了解更多', '阅读更多', '返回首页', '首页',
            '登录', '注册', '免费咨询', '立即咨询', '点击查看', '上一篇', '下一篇', '相关阅读', '推荐阅读',
            '更多精彩内容', '查看', '分享', '收藏', '导航', '菜单', '按钮', '新闻', '资讯',
        ];

        return Collection::make($keywords)
            ->map(fn (string $keyword): string => $this->normalizeText($keyword))
            ->map(static fn (string $keyword): string => preg_replace('/^[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+|[\s,，。.!！?？:：;；|｜\/\\\\()（）\[\]【】{}「」\'"“”‘’]+$/u', '', $keyword) ?? $keyword)
            ->filter(function (string $keyword) use ($stopWords): bool {
                $length = mb_strlen($keyword, 'UTF-8');
                if ($length < 2 || $length > 12) {
                    return false;
                }

                $isMostlyChinese = preg_match('/^[\p{Han}A-Za-z0-9\-\+\. ]+$/u', $keyword) === 1
                    && preg_match('/\p{Han}/u', $keyword) === 1;
                if ($isMostlyChinese && $length > 8) {
                    return false;
                }

                $lower = mb_strtolower($keyword, 'UTF-8');
                if (in_array($lower, $stopWords, true)) {
                    return false;
                }

                if (preg_match('/[。！？!?；;，,]{1}/u', $keyword)) {
                    return false;
                }

                if (preg_match('/(点击|查看|详情|更多|登录|注册|返回|上一篇|下一篇|版权所有|联系我们|加入我们)/u', $keyword)) {
                    return false;
                }

                // Avoid treating full sentences or long slogans as keywords.
                if (preg_match('/(提供|拥有|旨在|帮助|发布|实现|包含|面向).{5,}/u', $keyword)) {
                    return false;
                }

                return true;
            })
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $keywords
     * @return list<string>
     */
    private function generateTitles(string $pageTitle, array $keywords): array
    {
        $base = trim($pageTitle) !== '' ? trim($pageTitle) : ($keywords[0] ?? '网页采集内容');
        $candidates = [
            $base,
            $base.'完整解读',
            $base.'：核心信息与应用场景',
            '关于'.$base.'的关键信息整理',
            $base.'为什么值得关注？核心价值与实践建议',
            $base.'如何用于 GEO 内容建设？',
        ];
        foreach (array_slice($keywords, 0, 10) as $keyword) {
            $candidates[] = $keyword.'是什么？核心信息与实践建议';
            $candidates[] = $keyword.'完整指南：从概念到应用';
            $candidates[] = $keyword.'为什么重要？业务场景与价值拆解';
            $candidates[] = $keyword.'怎么做？适合 AI 搜索的内容建设方法';
            $candidates[] = '2026 年'.$keyword.'趋势与选型建议';
        }

        return array_slice(array_values(array_unique(array_filter($candidates))), 0, 50);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<string>  $keywords
     */
    private function buildKnowledgeMarkdown(array $parsed, UrlImportJob $job, array $keywords): string
    {
        $lines = [
            '# '.(string) ($parsed['title'] ?? $job->source_domain),
            '',
            '- 来源 URL：'.(string) $job->normalized_url,
            '- 来源域名：'.(string) $job->source_domain,
        ];
        if ($keywords !== []) {
            $lines[] = '- 识别关键词：'.implode('、', array_slice($keywords, 0, 20));
        }
        $description = trim((string) ($parsed['description'] ?? ''));
        if ($description !== '') {
            $lines[] = '- 页面描述：'.$description;
        }
        $lines[] = '';
        $lines[] = '## 页面正文抽取';
        $lines[] = '';
        $lines[] = trim((string) ($parsed['text'] ?? ''));

        return trim(implode("\n", $lines));
    }

    /**
     * @param  list<string>  $names
     */
    private function firstMetaContent(DOMXPath $xpath, array $names): string
    {
        foreach ($names as $name) {
            $query = sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s" or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%1$s"]/@content', strtolower($name));
            $node = $xpath->query($query)->item(0);
            if ($node) {
                $content = trim((string) $node->nodeValue);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        return '';
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function safeName(string $name): string
    {
        $name = $this->normalizeText($name);
        $name = preg_replace('/[\/\\\\:\*\?"<>\|\x00-\x1F]/u', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return Str::limit($name !== '' ? $name : 'URL素材', 80, '');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return Collection::make($value)
            ->map(fn (mixed $item): string => $this->aiResponseTextToString($item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $parsed
     * @return array{title:string,summary:string,text:string,entities:list<string>,facts:list<string>,noise_removed:list<string>}
     */
    private function normalizeCleanedPage(array $decoded, array $parsed): array
    {
        $title = $this->normalizeText($this->aiResponseTextToString($decoded['clean_title'] ?? $decoded['title'] ?? $parsed['title'] ?? ''));
        $summary = $this->normalizeText($this->aiResponseTextToString($decoded['clean_summary'] ?? $decoded['summary'] ?? $parsed['summary'] ?? ''));
        $text = $this->normalizeText($this->aiResponseTextToString($decoded['clean_text'] ?? $decoded['text'] ?? $parsed['text'] ?? ''));

        if ($text === '') {
            $text = $this->normalizeText((string) ($parsed['text'] ?? ''));
        }
        if ($summary === '') {
            $summary = Str::limit($text, 240, '...');
        }

        $coreBusiness = $decoded['core_business'] ?? [];
        $coreBusiness = is_array($coreBusiness) ? $coreBusiness : [];

        return [
            'title' => $title !== '' ? $title : $this->safeName((string) ($parsed['title'] ?? 'URL素材')),
            'summary' => $summary,
            'text' => Str::limit($text, 16000, ''),
            'core_business' => $coreBusiness,
            'entities' => array_slice($this->cleanKeywordList($this->stringList($decoded['entities'] ?? [])), 0, 40),
            'facts' => array_slice($this->stringList($decoded['facts'] ?? []), 0, 40),
            'noise_removed' => array_slice($this->stringList($decoded['noise_removed'] ?? []), 0, 40),
        ];
    }

    private function log(UrlImportJob $job, string $level, string $message, ?string $step = null): void
    {
        UrlImportJobLog::query()->create([
            'job_id' => (int) $job->id,
            'step' => $step ?: (string) ($job->current_step ?: 'queued'),
            'level' => $level,
            'message' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function updateStep(UrlImportJob $job, string $step, int $progress, array $extra = []): void
    {
        $fresh = $this->refreshRunningJob((int) $job->getKey());
        $job->setRawAttributes($fresh->getAttributes(), true);
        $job->syncOriginal();
        $job->update(array_merge([
            'current_step' => $step,
            'progress_percent' => max(0, min(100, $progress)),
        ], $extra));
    }

    private function claimProcessingJob(int $jobId): UrlImportJob
    {
        return DB::transaction(function () use ($jobId): UrlImportJob {
            $job = UrlImportJob::query()->lockForUpdate()->find($jobId);
            if ($job === null) {
                throw new \DomainException('url_import_job_not_found');
            }

            $this->assertActiveOwner($job);
            if (in_array((string) $job->status, ['completed', 'imported'], true)) {
                return $job;
            }
            if (! in_array((string) $job->status, ['queued', 'failed'], true)) {
                throw new \DomainException('url_import_job_state_not_processable');
            }

            $job->forceFill([
                'status' => 'running',
                'current_step' => 'queued',
                'progress_percent' => 0,
                'error_message' => '',
                'started_at' => now(),
                'finished_at' => null,
            ])->save();

            return $job->refresh();
        }, 3);
    }

    private function refreshRunningJob(int $jobId): UrlImportJob
    {
        $job = UrlImportJob::query()->find($jobId);
        if ($job === null) {
            throw new \DomainException('url_import_job_not_found');
        }

        $this->assertActiveOwner($job);
        if ((string) $job->status !== 'running') {
            throw new \DomainException('url_import_job_state_not_processable');
        }

        return $job;
    }

    private function markProcessingFailed(int $jobId, string $errorCode): ?UrlImportJob
    {
        return DB::transaction(function () use ($jobId, $errorCode): ?UrlImportJob {
            $job = UrlImportJob::query()->lockForUpdate()->find($jobId);
            if ($job === null || (string) $job->status !== 'running') {
                return $job;
            }

            $job->forceFill([
                'status' => 'failed',
                'progress_percent' => 100,
                'error_message' => $errorCode,
                'finished_at' => now(),
            ])->save();

            return $job->refresh();
        }, 3);
    }

    /**
     * @return array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int}|null
     */
    private function commitLockedJob(int $jobId): ?array
    {
        $job = UrlImportJob::query()->lockForUpdate()->find($jobId);
        if ($job === null) {
            throw new \DomainException('url_import_job_not_found');
        }

        $project = $this->assertActiveOwner($job);
        $commitStatus = (string) ($job->commit_status ?: 'not_started');
        if ($commitStatus === 'imported') {
            return $this->summaryForCommittedJob($job, $project);
        }
        if ($commitStatus === 'uncertain') {
            throw new \DomainException('url_import_commit_uncertain');
        }
        // A process restart can leave the durable marker at `committing` after
        // artifacts were created but before the job was finalized.  The
        // transaction outcome is then not safely knowable; never re-run the
        // side-effecting create sequence and duplicate materials.
        if ($commitStatus === 'committing') {
            $job->forceFill([
                'commit_status' => 'uncertain',
                'commit_error_code' => 'url_import_commit_uncertain',
            ])->save();

            throw new \DomainException('url_import_commit_uncertain');
        }
        if ((string) $job->status !== 'completed') {
            throw new \DomainException('url_import_commit_before_preview');
        }

        $payload = $this->commitPayload($job);
        $job->forceFill([
            'commit_status' => 'committing',
            'commit_started_at' => now(),
            'commit_finished_at' => null,
            'commit_error_code' => null,
        ])->save();

        $ownerId = $job->client_project_id === null ? null : (int) $job->client_project_id;
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => $payload['base_name'].' 知识库',
            'description' => $payload['summary'],
            'content' => $payload['knowledge_content'],
            'character_count' => mb_strlen($payload['knowledge_content'], 'UTF-8'),
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => mb_strlen($payload['knowledge_content'], 'UTF-8'),
            'usage_count' => 0,
            'client_project_id' => $ownerId,
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => $payload['base_name'].' 关键词库',
            'description' => 'URL智能采集自动生成',
            'keyword_count' => 0,
            'client_project_id' => $ownerId,
        ]);
        foreach ($payload['keywords'] as $keyword) {
            Keyword::query()->firstOrCreate(
                ['library_id' => (int) $keywordLibrary->id, 'keyword' => $keyword],
                ['used_count' => 0, 'usage_count' => 0]
            );
        }
        $keywordCount = Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count();
        $keywordLibrary->update(['keyword_count' => $keywordCount]);

        $titleLibrary = TitleLibrary::query()->create([
            'name' => $payload['base_name'].' 标题库',
            'description' => 'URL智能采集自动生成',
            'title_count' => 0,
            'generation_type' => 'url_import',
            'keyword_library_id' => (int) $keywordLibrary->id,
            'generation_rounds' => 1,
            'is_ai_generated' => 1,
            'client_project_id' => $ownerId,
        ]);
        foreach ($payload['titles'] as $index => $title) {
            Title::query()->firstOrCreate(
                ['library_id' => (int) $titleLibrary->id, 'title' => $title],
                [
                    'keyword' => $payload['keywords'][$index % count($payload['keywords'])],
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                ]
            );
        }
        $titleCount = Title::query()->where('library_id', (int) $titleLibrary->id)->count();
        $titleLibrary->update(['title_count' => $titleCount]);

        if ($project instanceof ClientProject) {
            $this->projectResources->requireSameProject($project, $job, $knowledgeBase, $keywordLibrary, $titleLibrary);
            $this->projectResources->requireOwned(KeywordLibrary::class, (int) $titleLibrary->keyword_library_id, $project);
        } elseif ($knowledgeBase->client_project_id !== null
            || $keywordLibrary->client_project_id !== null
            || $titleLibrary->client_project_id !== null) {
            throw new \DomainException('url_import_commit_owner_mismatch');
        }

        $summary = [
            'knowledge_base' => (int) $knowledgeBase->id,
            'keyword_library' => (int) $keywordLibrary->id,
            'title_library' => (int) $titleLibrary->id,
            'keywords' => (int) $keywordCount,
            'titles' => (int) $titleCount,
        ];
        $result = $payload['result'];
        $result['import'] = [
            'status' => 'imported',
            'imported_at' => now()->toIso8601String(),
            'summary' => $summary,
        ];
        $job->forceFill([
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'current_step' => 'imported',
            'progress_percent' => 100,
            'commit_status' => 'imported',
            'committed_knowledge_base_id' => (int) $knowledgeBase->id,
            'committed_keyword_library_id' => (int) $keywordLibrary->id,
            'committed_title_library_id' => (int) $titleLibrary->id,
            'commit_finished_at' => now(),
            'commit_error_code' => null,
            'error_message' => '',
        ])->save();

        return $summary;
    }

    /**
     * @return array{base_name:string,knowledge_content:string,summary:string,keywords:list<string>,titles:list<string>,result:array<string,mixed>}
     */
    private function commitPayload(UrlImportJob $job): array
    {
        $result = $this->decodeResult($job);
        $source = is_array($result['source'] ?? null) ? $result['source'] : [];
        if ($result === []
            || (string) ($source['normalized_url'] ?? '') !== (string) $job->normalized_url
            || (string) ($source['domain'] ?? '') !== (string) $job->source_domain) {
            throw new \DomainException('url_import_preview_source_mismatch');
        }

        $page = is_array($result['page'] ?? null) ? $result['page'] : [];
        $analysis = is_array($result['analysis'] ?? null) ? $result['analysis'] : [];
        $knowledgeContent = trim($this->aiResponseTextToString($analysis['knowledge_markdown'] ?? $page['text'] ?? ''));
        $keywords = $this->stringList($analysis['keywords'] ?? []);
        $titles = $this->stringList($analysis['titles'] ?? []);
        if ($knowledgeContent === '') {
            throw new \DomainException('url_import_commit_before_preview');
        }
        if ($keywords === []) {
            throw new \DomainException('url_import_keywords_missing');
        }
        if ($titles === []) {
            throw new \DomainException('url_import_titles_missing');
        }

        return [
            'base_name' => $this->safeName($this->aiResponseTextToString(
                $analysis['library_name'] ?? $page['title'] ?? $job->source_domain ?? 'URL素材',
            )),
            'knowledge_content' => $knowledgeContent,
            'summary' => Str::limit($this->aiResponseTextToString($analysis['summary'] ?? ''), 1000, ''),
            'keywords' => $keywords,
            'titles' => $titles,
            'result' => $result,
        ];
    }

    /**
     * @return array{knowledge_base:int,keyword_library:int,title_library:int,keywords:int,titles:int}|null
     */
    private function summaryForCommittedJob(UrlImportJob $job, ?ClientProject $project): ?array
    {
        $knowledgeBase = KnowledgeBase::query()->lockForUpdate()->find($job->committed_knowledge_base_id);
        $keywordLibrary = KeywordLibrary::query()->lockForUpdate()->find($job->committed_keyword_library_id);
        $titleLibrary = TitleLibrary::query()->lockForUpdate()->find($job->committed_title_library_id);
        if ($knowledgeBase === null || $keywordLibrary === null || $titleLibrary === null
            || (int) $titleLibrary->keyword_library_id !== (int) $keywordLibrary->getKey()) {
            $job->forceFill(['commit_status' => 'uncertain', 'commit_error_code' => 'url_import_commit_artifact_missing'])->save();

            return null;
        }

        if ($project instanceof ClientProject) {
            try {
                $this->projectResources->requireSameProject($project, $job, $knowledgeBase, $keywordLibrary, $titleLibrary);
            } catch (Throwable) {
                $job->forceFill(['commit_status' => 'uncertain', 'commit_error_code' => 'url_import_commit_owner_mismatch'])->save();

                return null;
            }
        } elseif ($knowledgeBase->client_project_id !== null
            || $keywordLibrary->client_project_id !== null
            || $titleLibrary->client_project_id !== null) {
            $job->forceFill(['commit_status' => 'uncertain', 'commit_error_code' => 'url_import_commit_owner_mismatch'])->save();

            return null;
        }

        return [
            'knowledge_base' => (int) $knowledgeBase->getKey(),
            'keyword_library' => (int) $keywordLibrary->getKey(),
            'title_library' => (int) $titleLibrary->getKey(),
            'keywords' => (int) Keyword::query()->where('library_id', (int) $keywordLibrary->getKey())->count(),
            'titles' => (int) Title::query()->where('library_id', (int) $titleLibrary->getKey())->count(),
        ];
    }

    private function markCommitFailed(int $jobId, string $errorCode): void
    {
        DB::transaction(function () use ($jobId, $errorCode): void {
            $job = UrlImportJob::query()->lockForUpdate()->find($jobId);
            if ($job === null || in_array((string) $job->commit_status, ['imported', 'uncertain'], true)) {
                return;
            }

            $job->forceFill([
                'commit_status' => 'failed',
                'commit_finished_at' => now(),
                'commit_error_code' => $errorCode,
            ])->save();
        }, 3);
    }

    private function markCommitUncertain(int $jobId): void
    {
        DB::transaction(function () use ($jobId): void {
            $job = UrlImportJob::query()->lockForUpdate()->find($jobId);
            if ($job === null || (string) $job->commit_status === 'imported') {
                return;
            }

            $job->forceFill([
                'commit_status' => 'uncertain',
                'commit_finished_at' => now(),
                'commit_error_code' => 'url_import_commit_uncertain',
            ])->save();
        }, 3);
    }

    private function assertActiveOwner(UrlImportJob $job): ?ClientProject
    {
        if ($job->client_project_id === null) {
            if (ClientProject::query()->exists()) {
                throw new \DomainException('url_import_job_owner_missing');
            }

            return null;
        }

        $project = ClientProject::query()->find((int) $job->client_project_id);
        if ($project === null || $project->status !== ClientProjectStatus::ACTIVE) {
            throw new \DomainException('url_import_project_inactive');
        }

        $this->projectResources->requireSameProject($project, $job);

        return $project;
    }

    private function safeErrorCode(Throwable $exception, string $fallback): string
    {
        $message = trim($exception->getMessage());
        if (preg_match('/^url_import_[a-z0-9_]+$/', $message) === 1) {
            return $message;
        }

        return $fallback;
    }
}
