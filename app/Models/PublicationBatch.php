<?php

namespace App\Models;

use App\Enums\PublicationBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationBatch extends Model
{
    protected $attributes = ['status' => PublicationBatchStatus::DRAFT];

    protected $fillable = [
        'client_project_id', 'task_id', 'status', 'idempotency_key', 'created_by_admin_id', 'updated_by_admin_id',
        'submitted_by_admin_id', 'approved_by_admin_id', 'status_changed_at', 'submitted_at', 'approved_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PublicationBatchStatus::class,
            'client_project_id' => 'integer',
            'task_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'submitted_by_admin_id' => 'integer',
            'approved_by_admin_id' => 'integer',
            'status_changed_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PublicationBatchItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'submitted_by_admin_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by_admin_id');
    }

    public function canTransitionTo(PublicationBatchStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }
}
