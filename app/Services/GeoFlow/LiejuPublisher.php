<?php

namespace App\Services\GeoFlow;

use App\Exceptions\LiejuRemoteResultUncertainException;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SafeOutboundRequest;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class LiejuPublisher implements DistributionPublisherInterface
{
    private const MAX_IMAGE_BYTES = 1048576;
    private const MAX_IMAGES = 4;

    public function __construct(private readonly SafeOutboundHttpClient $safeHttp, private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function health(DistributionChannel $channel): array
    {
        $config = $channel->resolvedLiejuConfig();
        $login = $this->get($channel, '/member/upage.php');
        if ($login->status() < 200 || $login->status() >= 300) {
            throw new RuntimeException('列举网登录探测失败：HTTP '.$login->status());
        }
        $loginHtml = $this->decode($login->body(), $login->header('Content-Type'));
        if (preg_match('/(你还没有登录|请先登录|未登录)/u', strip_tags($loginHtml)) === 1) {
            throw new RuntimeException('列举网登录探测未通过，请配置登录 Cookie。');
        }
        $city = $this->get($channel, '/city.php?post='.rawurlencode((string) $config['lieju_post_id']));
        if ($city->status() < 200 || $city->status() >= 300) {
            throw new RuntimeException('列举网城市目录探测失败：HTTP '.$city->status());
        }
        $cities = $this->cities($city->body(), $city->header('Content-Type'));
        $match = $this->findCity($cities, (string) $config['lieju_city']);
        if ($match === null) {
            throw new RuntimeException('列举网城市目录中未找到配置城市。');
        }
        $form = $this->get($channel, $this->postUrl($channel, $match['city_id'], (string) $config['lieju_post_id']));
        if ($form->status() < 200 || $form->status() >= 300) {
            throw new RuntimeException('列举网投稿表单探测失败：HTTP '.$form->status());
        }

        return [
            'ok' => true,
            'channel_type' => 'lieju',
            'login_url' => $this->url($channel, '/member/upage.php'),
            'city' => $match['name'],
            'city_id' => $match['city_id'],
            'form_url' => $this->postUrl($channel, $match['city_id'], (string) $config['lieju_post_id']),
            'encoding' => $this->encoding($city->body(), $city->header('Content-Type')),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $config = $channel->resolvedLiejuConfig();
        $cityResponse = $this->get($channel, '/city.php?post='.rawurlencode((string) $config['lieju_post_id']));
        if ($cityResponse->status() < 200 || $cityResponse->status() >= 300) {
            throw new RuntimeException('列举网城市目录失败：HTTP '.$cityResponse->status());
        }
        $match = $this->findCity($this->cities($cityResponse->body(), $cityResponse->header('Content-Type')), (string) $config['lieju_city']);
        if ($match === null) {
            throw new RuntimeException('列举网城市目录中未找到配置城市。');
        }
        $formUrl = $this->postUrl($channel, $match['city_id'], (string) $config['lieju_post_id']);
        $formResponse = $this->get($channel, $formUrl);
        if ($formResponse->status() < 200 || $formResponse->status() >= 300) {
            throw new RuntimeException('列举网投稿表单失败：HTTP '.$formResponse->status());
        }
        if (preg_match('/(你还没有登录|请先登录|未登录)/u', $this->decode($formResponse->body(), $formResponse->header('Content-Type'))) === 1) {
            throw new RuntimeException('列举网投稿需要已登录会话，请配置登录 Cookie。');
        }
        $form = $this->formFields($formResponse->body(), $formResponse->header('Content-Type'));
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $fields = $form['fields'];
        $fields['postdb[title]'] = (string) ($article['title'] ?? '');
        $fields['postdb[content]'] = $this->plainText((string) ($article['content'] ?? $article['content_html'] ?? ''));
        $fields['postdb[zone_id]'] = (string) ($config['lieju_zone_id'] !== '' ? $config['lieju_zone_id'] : ($fields['postdb[zone_id]'] ?? $match['city_id']));
        $fields['postdb[mobphone]'] = (string) ($config['lieju_mobphone'] ?: ($fields['postdb[mobphone]'] ?? ''));
        $fields['postdb[linkman]'] = (string) ($config['lieju_linkman'] ?: ($fields['postdb[linkman]'] ?? ''));
        $fields['fid'] = (string) ($fields['fid'] ?? $config['lieju_post_id']);

        $request = $this->request($channel)->asMultipart();
        $images = is_array(($payload['assets'] ?? [])['images'] ?? null) ? $payload['assets']['images'] : [];
        $attached = 0;
        foreach (array_slice($images, 0, self::MAX_IMAGES) as $image) {
            if (! is_array($image) || ! is_string($image['content_base64'] ?? null) || $image['content_base64'] === '') {
                continue;
            }
            $bytes = base64_decode($image['content_base64'], true);
            if (! is_string($bytes)) {
                throw new RuntimeException('列举网图片内容无效。');
            }
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                throw new RuntimeException('列举网单张图片不能超过 1 MB。');
            }
            $attached++;
            $slot = $attached;
            $request = $request->attach('local_file'.$slot, $bytes, (string) ($image['filename'] ?? ('image'.$slot.'.img')), ['Content-Type' => (string) ($image['mime_type'] ?? 'application/octet-stream')]);
            $fields['photodb['.$slot.']'] = (string) ($image['filename'] ?? '');
            $fields['piddb['.$slot.']'] = (string) ($image['source_url'] ?? '');
            $fields['ftype['.$slot.']'] = (string) ($image['mime_type'] ?? '');
        }
        try {
            $response = $request->send('POST', $formUrl.'?action=postnew', $fields);
        } catch (OutboundRequestFailedException $exception) {
            throw new LiejuRemoteResultUncertainException('列举网发布请求已发出，但响应无法确认。', 0, $exception);
        }
        if ($response->status() >= 500 || $response->status() === 0) {
            throw new LiejuRemoteResultUncertainException('列举网发布请求已发出，但响应无法确认。');
        }
        $location = trim((string) $response->header('Location', ''));
        if ($response->status() >= 300 && $response->status() < 400 && $location !== '') {
            $redirectResult = $this->remoteResultFromUrl($location, $formUrl);
            if ($redirectResult !== null) {
                return $redirectResult + ['remote_meta' => ['lieju' => ['city_id' => $match['city_id'], 'image_count' => $attached]]];
            }
        }
        $parsed = $this->parsePublishResult($response->body(), $response->header('Content-Type'), $formUrl);
        if ($response->failed()) {
            throw new RuntimeException('列举网发布失败：HTTP '.$response->status());
        }
        if ($parsed === null) {
            throw new LiejuRemoteResultUncertainException;
        }

        return $parsed + ['remote_meta' => ['lieju' => ['city_id' => $match['city_id'], 'image_count' => $attached]]];
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        throw new RuntimeException('列举网渠道不支持安全更新远端投稿，请创建新的投稿记录。');
    }

    public function delete(ArticleDistribution $distribution): array
    {
        throw new RuntimeException('列举网渠道不支持安全删除远端投稿。');
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        return ['ok' => true, 'skipped' => true, 'reason' => 'lieju_no_site_settings_api'];
    }

    private function request(DistributionChannel $channel): SafeOutboundRequest
    {
        $headers = ['Accept' => 'text/html,application/xhtml+xml', 'User-Agent' => 'GEOFlow/2.0 Lieju Publisher'];
        $channel->loadMissing('activeSecret');
        if ($channel->activeSecret) {
            $cookie = trim($this->apiKeyCrypto->decrypt((string) $channel->activeSecret->secret_ciphertext));
            if ($cookie !== '') $headers['Cookie'] = $cookie;
        }
        return new SafeOutboundRequest($this->safeHttp, Http::timeout(30)->connectTimeout(5)->withHeaders($headers), 8 * 1024 * 1024);
    }

    private function get(DistributionChannel $channel, string $path): Response
    {
        return $this->request($channel)->get($this->url($channel, $path));
    }

    private function url(DistributionChannel $channel, string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        return rtrim((string) $channel->endpoint_url, '/').'/'.ltrim($path, '/');
    }

    private function postUrl(DistributionChannel $channel, string $cityId, string $postId): string
    {
        $base = (string) $channel->resolvedLiejuConfig()['lieju_post_base_url'];
        if ($base === '') {
            $base = preg_replace('#^https?://www\\.#i', 'https://post.', rtrim((string) $channel->endpoint_url, '/')) ?: rtrim((string) $channel->endpoint_url, '/');
        }
        return rtrim($base, '/').'/'.rawurlencode($cityId).'/'.rawurlencode($postId);
    }

    /** @return list<array{name:string,city_id:string,url:string}> */
    private function cities(string $body, string $contentType): array
    {
        $html = $this->decode($body, $contentType);
        preg_match_all('#<a\\b[^>]*href=["\\x27]([^"\\x27]+)["\\x27][^>]*>(.*?)</a>#isu', $html, $matches, PREG_SET_ORDER);
        $result = [];
        foreach ($matches as $match) {
            $href = html_entity_decode(trim((string) $match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~/(\\d+)/(?:\\d+)(?:[/?#]|$)~', $href, $id) !== 1) {
                continue;
            }
            $name = trim(preg_replace('/\\s+/u', ' ', strip_tags((string) $match[2])) ?: '');
            if ($name !== '') {
                $result[] = ['name' => $name, 'city_id' => $id[1], 'url' => $href];
            }
        }
        return $result;
    }

    private function findCity(array $cities, string $needle): ?array
    {
        $needle = trim($needle);
        foreach ($cities as $city) {
            if ($city['name'] === $needle || str_contains($city['name'], $needle) || str_contains($needle, $city['name'])) {
                return $city;
            }
        }
        return null;
    }

    /** @return array{fields:array<string,string>} */
    private function formFields(string $body, string $contentType): array
    {
        $html = $this->decode($body, $contentType);
        preg_match_all('#<input\\b[^>]*>#isu', $html, $inputs);
        $fields = [];
        foreach ($inputs[0] ?? [] as $input) {
            if (preg_match('#\\bname=["\\x27]([^"\\x27]+)["\\x27]#isu', $input, $name) !== 1) {
                continue;
            }
            if (preg_match('#\\bvalue=["\\x27]([^"\\x27]*)["\\x27]#isu', $input, $value) === 1) {
                $fields[html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] = html_entity_decode($value[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return ['fields' => $fields];
    }

    /** @return array{remote_id:string,remote_url:string}|null */
    private function parsePublishResult(string $body, string $contentType, string $fallbackBase): ?array
    {
        $html = $this->decode($body, $contentType);
        $success = preg_match('/(发布成功|投稿成功|提交成功|恭喜|成功发布)/u', strip_tags($html)) === 1;
        if (! $success) {
            return null;
        }
        preg_match_all('#https?://[^"\\x27<>\\s]+#i', $html, $urls);
        foreach ($urls[0] ?? [] as $url) {
            $result = $this->remoteResultFromUrl($url, $fallbackBase);
            if ($result !== null) {
                return $result;
            }
        }
        if (preg_match('~(?:[?&](?:id|aid|itemid|article_id)=)(\\d{3,})(?:[&#"\\x27\\s]|$)~i', $html, $id) === 1) {
            return ['remote_id' => $id[1], 'remote_url' => ''];
        }
        return null;
    }

    private function isAllowedRemoteUrl(string $url, string $fallbackBase): bool
    {
        $urlHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string) parse_url($fallbackBase, PHP_URL_HOST));
        if ($urlHost === '' || $baseHost === '') return false;
        $normalizedBaseHost = preg_replace('/^www\\./i', '', $baseHost) ?: $baseHost;
        return $urlHost === $baseHost || $urlHost === $normalizedBaseHost || str_ends_with($urlHost, '.'.$normalizedBaseHost) || str_ends_with($baseHost, '.'.$urlHost);
    }

    /** @return array{remote_id:string,remote_url:string}|null */
    private function remoteResultFromUrl(string $url, string $fallbackBase): ?array
    {
        if (! $this->isAllowedRemoteUrl($url, $fallbackBase)) {
            return null;
        }

        $urlPath = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $basePath = rtrim((string) parse_url($fallbackBase, PHP_URL_PATH), '/');
        $remoteId = $this->remoteId($url);
        if ($urlPath === '' || $urlPath === $basePath || $remoteId === '') {
            return null;
        }

        return ['remote_id' => $remoteId, 'remote_url' => $url];
    }

    private function remoteId(string $url): string
    {
        if (preg_match('~[?&](?:id|aid|itemid|article_id)=(\\d+)~i', $url, $m) === 1) {
            return (string) $m[1];
        }
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn (string $segment): bool => ctype_digit($segment)));
        return $segments === [] ? '' : (string) end($segments);
    }

    private function plainText(string $content): string
    {
        $html = ArticleHtmlPresenter::markdownToHtml($content);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\\r\\n?|\\n{3,}/", "\n\n", $text) ?: $text;
        return trim($text);
    }

    private function decode(string $body, string $contentType): string
    {
        $encoding = $this->encoding($body, $contentType);
        return $encoding === 'UTF-8' ? $body : mb_convert_encoding($body, 'UTF-8', $encoding);
    }

    private function encoding(string $body, string $contentType): string
    {
        if (preg_match('/charset=\\s*["\\x27]?([^;"\\x27\\s]+)/i', $contentType, $m) === 1) {
            $candidate = strtoupper(trim($m[1]));
            if (in_array($candidate, ['UTF-8', 'GBK', 'GB2312', 'CP936'], true)) return $candidate === 'CP936' ? 'GBK' : $candidate;
        }
        if (preg_match('/<meta[^>]+charset=["\\x27]?([^"\\x27 >]+)/i', $body, $m) === 1) {
            $candidate = strtoupper(trim($m[1]));
            if (in_array($candidate, ['UTF-8', 'GBK', 'GB2312', 'CP936'], true)) return $candidate === 'CP936' ? 'GBK' : $candidate;
        }
        return mb_detect_encoding($body, ['UTF-8', 'GBK', 'GB2312'], true) ?: 'UTF-8';
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) throw new RuntimeException('分发记录缺少列举网渠道。');
        return $distribution->channel;
    }
}
