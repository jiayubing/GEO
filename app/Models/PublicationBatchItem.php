<?php

namespace App\Models;

use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationBatchItem extends Model
{
    protected $attributes = ['status' => PublicationBatchItemStatus::PENDING, 'action' => 'publish'];

    protected $fillable = [
        'publication_batch_id', 'client_project_id', 'article_id', 'target_type', 'target_identity', 'action',
        'article_revision', 'article_content_hash', 'target_snapshot', 'result_snapshot', 'status', 'idempotency_key',
        'created_by_admin_id', 'updated_by_admin_id', 'approved_by_admin_id', 'article_distribution_id', 'manual_publication_id', 'approved_at', 'started_at',
        'finished_at', 'failure_code', 'observation',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $batch = $item->relationLoaded('batch') ? $item->batch : PublicationBatch::find($item->publication_batch_id);
            if ($batch !== null && (int) $batch->client_project_id !== (int) $item->client_project_id) {
                throw new \LogicException('Publication batch item project must match its batch project.');
            }

            if ($item->article_id !== null) {
                $article = $item->relationLoaded('article') ? $item->article : Article::find($item->article_id);
                if ($article !== null && $article->client_project_id !== null && (int) $article->client_project_id !== (int) $item->client_project_id) {
                    throw new \LogicException('Publication batch item article must belong to its project.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PublicationBatchItemStatus::class,
            'target_type' => PublicationTargetType::class,
            'publication_batch_id' => 'integer',
            'client_project_id' => 'integer',
            'article_id' => 'integer',
            'article_revision' => 'integer',
            'target_snapshot' => 'array',
            'result_snapshot' => 'array',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'approved_by_admin_id' => 'integer',
            'article_distribution_id' => 'integer',
            'manual_publication_id' => 'integer',
            'approved_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PublicationBatch::class, 'publication_batch_id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class)->withTrashed();
    }

    public function articleDistribution(): BelongsTo
    {
        return $this->belongsTo(ArticleDistribution::class);
    }

    public function manualPublication(): BelongsTo
    {
        return $this->belongsTo(ManualPublication::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function canTransitionTo(PublicationBatchItemStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }
}
