<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Services\GeoFlow\ProjectAccessService;
use App\Services\GeoFlow\PublicationBatchLocalItemExecutor;
use App\Services\GeoFlow\PublicationBatchService;
use App\Support\GeoFlow\PublicationGateContract;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class PublicationBatchController extends Controller
{
    public function __construct(
        private readonly PublicationBatchService $service,
        private readonly ProjectAccessService $access,
        private readonly PublicationBatchLocalItemExecutor $localExecutor,
    ) {}

    public function index(Request $request)
    {
        $project = $this->project($request);
        $this->access->requireRead($this->admin($request), $project);

        $status = $request->string('status')->toString();
        $statuses = array_map(static fn (PublicationBatchStatus $value): string => $value->value, PublicationBatchStatus::cases());
        if (! in_array($status, $statuses, true)) {
            $status = '';
        }

        $batches = PublicationBatch::query()
            ->where('client_project_id', $project->getKey())
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with(['creator:id,username,display_name'])
            ->withCount('items')
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.publication-batches.index', [
            'batches' => $batches,
            'project' => $project,
            'status' => $status,
            'statuses' => PublicationBatchStatus::cases(),
            'pageTitle' => '发布批次',
            'activeMenu' => 'articles',
        ]);
    }

    public function create(Request $request)
    {
        $project = $this->project($request);
        $this->access->requireRead($this->admin($request), $project);

        $articles = Article::query()
            ->where('client_project_id', $project->getKey())
            ->where('review_status', 'approved')
            ->whereIn('status', ['draft', 'private'])
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'status', 'review_status']);

        return view('admin.publication-batches.create', [
            'articles' => $articles,
            'project' => $project,
            'idempotencyKey' => old('idempotency_key', (string) Str::uuid()),
            'pageTitle' => '创建发布批次',
            'activeMenu' => 'articles',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $project = $this->project($request);
        try {
            $selections = $request->input('selections');
            if ($selections === null && is_array($request->input('article_ids'))) {
                $selections = collect($request->input('article_ids'))
                    ->map(fn ($articleId): array => [
                        'article_id' => (int) $articleId,
                        'targets' => [['target_type' => 'local']],
                    ])->all();
            }
            $batch = $this->service->createDraft($admin, $project, (array) $selections, $request->input('idempotency_key'));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }

        return redirect()->route('admin.publication-batches.show', ['batchId' => $batch->getKey()]);
    }

    public function update(Request $request, int $batchId): RedirectResponse
    {
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->updateDraft($this->admin($request), $batch, (array) $request->input('selections', []));
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_updated');
    }

    public function submit(Request $request, int $batchId): RedirectResponse
    {
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->submit($this->admin($request), $batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_submitted');
    }

    public function executeLocal(Request $request, int $batchId, int $itemId): RedirectResponse
    {
        $admin = $this->admin($request);
        $project = $this->project($request);
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        abort_unless((int) $batch->client_project_id === (int) $project->getKey(), 404);
        $this->access->requireWrite($admin, $project, true);
        $item = PublicationBatchItem::query()->where('publication_batch_id', $batch->getKey())->whereKey($itemId)->firstOrFail();
        abort_unless($item->status === PublicationBatchItemStatus::APPROVED && $item->target_type?->value === PublicationGateContract::TARGET_LOCAL, 422, '仅批准的本地目标可执行');
        $this->localExecutor->execute($item);

        return redirect()->route('admin.publication-batches.show', ['batchId' => $batch->getKey()])->with('message', 'publication_batch_local_executed');
    }

    public function approve(Request $request, int $batchId): RedirectResponse
    {
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->approve($this->admin($request), $batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_approved');
    }

    public function returnBatch(Request $request, int $batchId): RedirectResponse
    {
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->returnBatch($this->admin($request), $batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_returned');
    }

    public function reject(Request $request, int $batchId): RedirectResponse
    {
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->rejectBatch($this->admin($request), $batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_rejected');
    }

    public function approveItem(Request $request, int $batchId, int $itemId): RedirectResponse
    {
        return $this->decideItem($request, $batchId, $itemId, 'approve');
    }

    public function rejectItem(Request $request, int $batchId, int $itemId): RedirectResponse
    {
        return $this->decideItem($request, $batchId, $itemId, 'reject');
    }

    private function decideItem(Request $request, int $batchId, int $itemId, string $decision): RedirectResponse
    {
        $item = PublicationBatchItem::query()->where('publication_batch_id', $batchId)->whereKey($itemId)->firstOrFail();
        try {
            $this->service->decideItem($this->admin($request), $item, $decision);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_item_'.$decision.'d');
    }

    public function show(Request $request, int $batchId)
    {
        $batch = PublicationBatch::query()->with('items')->whereKey($batchId)->firstOrFail();
        $project = $batch->clientProject()->firstOrFail();
        $this->access->requireRead($this->admin($request), $project);
        $context = $this->project($request);
        abort_unless((int) $context->getKey() === (int) $project->getKey(), 404);

        if ($request->expectsJson()) {
            return response()->json($batch);
        }

        return view('admin.publication-batches.show', [
            'batch' => $batch,
            'pageTitle' => '发布批次 #'.$batch->getKey(),
            'activeMenu' => 'articles',
        ]);
    }

    private function project(Request $request): ClientProject
    {
        $project = $request->attributes->get('project_context');
        abort_unless($project instanceof ClientProject, 409, '项目上下文缺失');

        return $project;
    }

    private function admin(Request $request): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return $admin;
    }
}
