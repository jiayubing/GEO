<?php

namespace App\Services\GeoFlow;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationBatchStatus;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\PublicationBatch;
use App\Models\PublicationBatchItem;
use App\Support\AdminActivityLogger;
use App\Support\GeoFlow\PublicationGateContract;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Owns operator publication-batch drafting and submission. No execution side effects. */
final class PublicationBatchService
{
    public function __construct(
        private readonly ProjectAccessService $access,
        private readonly PublicationBatchTargetResolver $targets,
    ) {}

    /** @param array<int,array{article_id:int,targets:array<int,array<string,mixed>|string>,action?:string}> $selections */
    public function createDraft(Admin $admin, ClientProject $project, array $selections, ?string $idempotencyKey = null): PublicationBatch
    {
        $this->access->requireWrite($admin, $project, true);
        $project = $project->fresh();
        if (! $project instanceof ClientProject) {
            throw new DomainException('publication_project_inactive');
        }

        return DB::transaction(function () use ($admin, $project, $selections, $idempotencyKey): PublicationBatch {
            $key = $idempotencyKey !== null && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;
            if ($key !== null) {
                $existing = PublicationBatch::query()->where('client_project_id', $project->getKey())
                    ->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->created_by_admin_id !== (int) $admin->getKey()) {
                        throw new DomainException('publication_batch_idempotency_conflict');
                    }

                    return $existing->load('items');
                }
            }

            $batch = PublicationBatch::query()->create([
                'client_project_id' => $project->getKey(),
                'status' => PublicationBatchStatus::DRAFT,
                'idempotency_key' => $key,
                'created_by_admin_id' => $admin->getKey(),
                'updated_by_admin_id' => $admin->getKey(),
                'status_changed_at' => now(),
            ]);
            $this->replaceItems($batch, $admin, $project, $selections);
            AdminActivityLogger::log($admin, 'publication_batch.created', [
                'target_type' => 'publication_batch', 'target_id' => $batch->getKey(),
                'details' => ['project_id' => $project->getKey(), 'item_count' => $batch->items()->count()],
            ]);

            return $batch->load('items');
        });
    }

    /** @param array<int,array{article_id:int,targets:array<int,array<string,mixed>|string>,action?:string}> $selections */
    public function updateDraft(Admin $admin, PublicationBatch $batch, array $selections): PublicationBatch
    {
        $project = $batch->clientProject()->firstOrFail();
        $this->access->requireWrite($admin, $project, true);

        return DB::transaction(function () use ($admin, $batch, $project, $selections): PublicationBatch {
            $locked = PublicationBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [PublicationBatchStatus::DRAFT, PublicationBatchStatus::RETURNED], true)) {
                throw new DomainException('publication_batch_not_editable');
            }
            $locked->items()->delete();
            $locked->forceFill(['updated_by_admin_id' => $admin->getKey()])->save();
            $this->replaceItems($locked, $admin, $project, $selections);

            return $locked->load('items');
        });
    }

    public function submit(Admin $admin, PublicationBatch $batch): PublicationBatch
    {
        $project = $batch->clientProject()->firstOrFail();
        $this->access->requireWrite($admin, $project, true);

        return DB::transaction(function () use ($admin, $batch, $project): PublicationBatch {
            $locked = PublicationBatch::query()->with('items')->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === PublicationBatchStatus::SUBMITTED) {
                return $locked;
            }
            if (! in_array($locked->status, [PublicationBatchStatus::DRAFT, PublicationBatchStatus::RETURNED], true)) {
                throw new DomainException('publication_batch_not_submittable');
            }
            if ($locked->items->isEmpty()) {
                throw new DomainException('publication_batch_empty');
            }

            foreach ($locked->items as $item) {
                $article = $item->article()->first();
                if (! $article instanceof Article || (int) $article->client_project_id !== (int) $project->getKey()) {
                    throw new DomainException('publication_article_project_mismatch');
                }
                $this->assertArticleGate($project, $article, (string) $item->target_type->value);
                $this->targets->assertFresh($item);
            }

            $now = now();
            $locked->forceFill([
                'status' => PublicationBatchStatus::SUBMITTED,
                'submitted_by_admin_id' => $admin->getKey(),
                'updated_by_admin_id' => $admin->getKey(),
                'submitted_at' => $now,
                'status_changed_at' => $now,
            ])->save();
            AdminActivityLogger::log($admin, 'publication_batch.submitted', [
                'target_type' => 'publication_batch', 'target_id' => $locked->getKey(),
                'details' => ['project_id' => $project->getKey(), 'item_count' => $locked->items->count()],
            ]);

            return $locked->fresh(['items']);
        });
    }

    public function approve(Admin $admin, PublicationBatch $batch): PublicationBatch
    {
        return $this->decideBatch($admin, $batch, PublicationBatchStatus::APPROVED, 'publication_batch.approved');
    }

    public function returnBatch(Admin $admin, PublicationBatch $batch): PublicationBatch
    {
        return $this->decideBatch($admin, $batch, PublicationBatchStatus::RETURNED, 'publication_batch.returned');
    }

    public function rejectBatch(Admin $admin, PublicationBatch $batch): PublicationBatch
    {
        return $this->decideBatch($admin, $batch, PublicationBatchStatus::REJECTED, 'publication_batch.rejected');
    }

    public function decideItem(Admin $admin, PublicationBatchItem $item, string $decision): PublicationBatchItem
    {
        $batch = $item->batch()->firstOrFail();
        $project = $batch->clientProject()->firstOrFail();
        $this->requireApprover($admin, $project, $batch);

        return DB::transaction(function () use ($admin, $item, $batch, $project, $decision): PublicationBatchItem {
            $lockedBatch = PublicationBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            $locked = PublicationBatchItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            if ($decision === 'approve') {
                if ($locked->status === PublicationBatchItemStatus::APPROVED) {
                    return $locked;
                }
                if ($lockedBatch->status !== PublicationBatchStatus::SUBMITTED) {
                    throw new DomainException('publication_item_not_decidable');
                }
                $this->assertApprovableItem($project, $locked);
                $status = PublicationBatchItemStatus::APPROVED;
            } elseif ($decision === 'reject') {
                if ($locked->status === PublicationBatchItemStatus::REJECTED) {
                    return $locked;
                }
                if ($lockedBatch->status !== PublicationBatchStatus::SUBMITTED) {
                    throw new DomainException('publication_item_not_decidable');
                }
                $status = PublicationBatchItemStatus::REJECTED;
            } else {
                throw new DomainException('publication_item_decision_invalid');
            }
            $locked->forceFill(['status' => $status, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => now(), 'updated_by_admin_id' => $admin->getKey()])->save();
            $this->aggregateItemDecisions($lockedBatch, $admin);

            return $locked->fresh();
        });
    }

    private function aggregateItemDecisions(PublicationBatch $batch, Admin $admin): void
    {
        $statuses = $batch->items()->pluck('status');
        if ($statuses->contains(PublicationBatchItemStatus::PENDING->value)) {
            return;
        }
        $approved = $statuses->contains(PublicationBatchItemStatus::APPROVED->value);
        $status = $approved && $statuses->unique()->count() > 1
            ? PublicationBatchStatus::PARTIAL
            : ($approved ? PublicationBatchStatus::APPROVED : PublicationBatchStatus::REJECTED);
        $batch->forceFill([
            'status' => $status,
            'approved_by_admin_id' => $admin->getKey(),
            'approved_at' => now(),
            'updated_by_admin_id' => $admin->getKey(),
            'status_changed_at' => now(),
        ])->save();
    }

    private function decideBatch(Admin $admin, PublicationBatch $batch, PublicationBatchStatus $status, string $event): PublicationBatch
    {
        $project = $batch->clientProject()->firstOrFail();
        $this->requireApprover($admin, $project, $batch);

        return DB::transaction(function () use ($admin, $batch, $project, $status, $event): PublicationBatch {
            $locked = PublicationBatch::query()->with('items')->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === $status) {
                return $locked;
            }
            if ($locked->status !== PublicationBatchStatus::SUBMITTED) {
                throw new DomainException('publication_batch_not_decidable');
            }
            if ($status === PublicationBatchStatus::APPROVED) {
                foreach ($locked->items as $item) {
                    $this->assertApprovableItem($project, $item);
                }
            }
            $now = now();
            $locked->items()->where('status', PublicationBatchItemStatus::PENDING->value)->update(['status' => $status === PublicationBatchStatus::APPROVED ? PublicationBatchItemStatus::APPROVED->value : PublicationBatchItemStatus::REJECTED->value, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => $now]);
            $locked->forceFill(['status' => $status, 'approved_by_admin_id' => $admin->getKey(), 'approved_at' => $now, 'status_changed_at' => $now])->save();
            AdminActivityLogger::log($admin, $event, ['target_type' => 'publication_batch', 'target_id' => $locked->getKey(), 'details' => ['project_id' => $project->getKey()]]);

            return $locked->fresh(['items']);
        });
    }

    private function requireApprover(Admin $admin, ClientProject $project, PublicationBatch $batch): void
    {
        $this->access->requireRead($admin, $project);
        if (! $admin->isSuperAdmin() && strtolower((string) $admin->role) !== 'platform_approver') {
            throw new DomainException('publication_approver_required');
        }
        if (! $admin->isSuperAdmin() && (int) $batch->created_by_admin_id === (int) $admin->getKey()) {
            throw new DomainException('publication_self_approval_forbidden');
        }
    }

    private function assertApprovableItem(ClientProject $project, PublicationBatchItem $item): void
    {
        $article = $item->article()->first();
        if (! $article instanceof Article) {
            throw new DomainException('publication_article_missing');
        }
        $this->assertArticleGate($project, $article, (string) $item->target_type->value, true);
        $this->targets->assertFresh($item);
    }

    /** @param array<int,array{article_id:int,targets:array<int,array<string,mixed>|string>,action?:string}> $selections */
    private function replaceItems(PublicationBatch $batch, Admin $admin, ClientProject $project, array $selections): void
    {
        // Empty drafts are allowed so operators can save a batch before choosing
        // articles; submission remains explicitly rejected until an item exists.
        $articleIds = collect($selections)->pluck('article_id')->map(fn ($id): int => (int) $id)->values();
        if ($articleIds->contains(fn (int $id): bool => $id <= 0) || $articleIds->unique()->count() !== $articleIds->count()) {
            throw new DomainException('publication_article_selection_invalid');
        }
        $articles = Article::query()->whereIn('id', $articleIds)->get()->keyBy('id');
        foreach ($selections as $selection) {
            $article = $articles->get((int) ($selection['article_id'] ?? 0));
            if (! $article || (int) $article->client_project_id !== (int) $project->getKey()) {
                throw new DomainException('publication_article_project_mismatch');
            }
            $targets = $selection['targets'] ?? [];
            if ($targets === []) {
                throw new DomainException('publication_targets_required');
            }
            foreach ($targets as $target) {
                $frozen = $this->targets->freeze($project, $article, $target, (string) ($selection['action'] ?? 'publish'));
                PublicationBatchItem::query()->create($frozen + [
                    'publication_batch_id' => $batch->getKey(), 'created_by_admin_id' => $admin->getKey(),
                    'updated_by_admin_id' => $admin->getKey(),
                ]);
            }
        }
    }

    private function assertArticleGate(ClientProject $project, Article $article, string $target, bool $platformApproved = false): void
    {
        $gate = PublicationGateContract::evaluate($project, (string) $article->status, (string) $article->review_status, $target, $platformApproved);
        if (! $gate['allowed'] && $gate['code'] !== 'platform_approval_required') {
            throw new DomainException('publication_gate_'.$gate['code']);
        }
    }
}
