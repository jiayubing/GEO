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
use App\Services\GeoFlow\PublicationBatchRecoveryService;
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
        private readonly PublicationBatchRecoveryService $recovery,
    ) {}

    public function index(Request $request)
    {
        $project = $this->project($request);
        $admin = $this->admin($request);
        $this->access->requireRead($admin, $project);

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
            'canManageContentAdministration' => $this->access->canManageContentAdministration($admin, $project),
        ]);
    }

    public function approvalIndex(Request $request)
    {
        $this->superAdmin($request);

        $status = $request->string('status')->toString();
        $statuses = array_map(static fn (PublicationBatchStatus $value): string => $value->value, PublicationBatchStatus::cases());
        if ($status === 'all') {
            $status = '';
        } elseif (! in_array($status, $statuses, true)) {
            $status = PublicationBatchStatus::SUBMITTED->value;
        }

        $batches = PublicationBatch::query()
            ->whereNotNull('submitted_by_admin_id')
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with([
                'clientProject:id,client_id,name,slug',
                'clientProject.client:id,name,slug',
                'submitter:id,username,display_name',
            ])
            ->withCount('items')
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.publication-batches.approval-index', [
            'batches' => $batches,
            'status' => $status,
            'statuses' => PublicationBatchStatus::cases(),
            'pageTitle' => '平台发布批次审批',
            'activeMenu' => 'publication_approvals',
        ]);
    }

    public function approvalShow(Request $request, int $batchId)
    {
        $this->superAdmin($request);
        $batch = PublicationBatch::query()
            ->with([
                'items',
                'clientProject:id,client_id,name,slug',
                'clientProject.client:id,name,slug',
                'submitter:id,username,display_name',
            ])
            ->whereKey($batchId)
            ->firstOrFail();

        return view('admin.publication-batches.show', [
            'batch' => $batch,
            'canDecide' => true,
            'canExecuteLocal' => true,
            'approvedLocalItemCount' => $this->approvedLocalItemCount($batch),
            'isApprovalCenter' => true,
            'batchActionRoute' => 'admin.publication-batch-approvals',
            'batchIndexRoute' => 'admin.publication-batch-approvals.index',
            'pageTitle' => '平台发布批次审批 #'.$batch->getKey(),
            'activeMenu' => 'publication_approvals',
            'canManageContentAdministration' => true,
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

    public function executeLocalBatch(Request $request, int $batchId): RedirectResponse
    {
        $admin = $this->admin($request);
        abort_unless($admin->isSuperAdmin(), 403);
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        $project = $batch->clientProject()->firstOrFail();
        $this->access->requireWrite($admin, $project, true);

        try {
            $this->recovery->executeApprovedLocalItems($batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return redirect()->route('admin.publication-batch-approvals.show', ['batchId' => $batch->getKey()])->with('message', 'publication_batch_local_batch_executed');
    }

    public function approve(Request $request, int $batchId): RedirectResponse
    {
        $this->superAdmin($request);
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
        $this->superAdmin($request);
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
        $this->superAdmin($request);
        $batch = PublicationBatch::query()->whereKey($batchId)->firstOrFail();
        try {
            $this->service->rejectBatch($this->admin($request), $batch);
        } catch (DomainException $exception) {
            return back()->withErrors($exception->getMessage());
        }

        return back()->with('message', 'publication_batch_rejected');
    }

    public function show(Request $request, int $batchId)
    {
        $batch = PublicationBatch::query()->with('items')->whereKey($batchId)->firstOrFail();
        $project = $batch->clientProject()->firstOrFail();
        $admin = $this->admin($request);
        $this->access->requireRead($admin, $project);
        $context = $this->project($request);
        abort_unless((int) $context->getKey() === (int) $project->getKey(), 404);

        if ($request->expectsJson()) {
            return response()->json($batch);
        }

        return view('admin.publication-batches.show', [
            'batch' => $batch,
            'canDecide' => false,
            'canExecuteLocal' => false,
            'approvedLocalItemCount' => $this->approvedLocalItemCount($batch),
            'isApprovalCenter' => false,
            'batchActionRoute' => 'admin.publication-batches',
            'batchIndexRoute' => 'admin.publication-batches.index',
            'pageTitle' => '发布批次 #'.$batch->getKey(),
            'activeMenu' => 'articles',
            'canManageContentAdministration' => $this->access->canManageContentAdministration($admin, $project),
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

    private function superAdmin(Request $request): Admin
    {
        $admin = $this->admin($request);
        abort_unless($admin->isSuperAdmin(), 403);

        return $admin;
    }

    private function approvedLocalItemCount(PublicationBatch $batch): int
    {
        return $batch->items
            ->filter(static fn (PublicationBatchItem $item): bool => $item->target_type?->value === PublicationGateContract::TARGET_LOCAL
                && $item->status === PublicationBatchItemStatus::APPROVED)
            ->count();
    }
}
