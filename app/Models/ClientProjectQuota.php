<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientProjectQuota extends Model
{
    protected $fillable = [
        'client_project_id', 'ai_units_limit', 'storage_bytes_limit',
        'article_count_limit', 'concurrency_limit', 'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'client_project_id' => 'integer', 'ai_units_limit' => 'integer',
            'storage_bytes_limit' => 'integer', 'article_count_limit' => 'integer',
            'concurrency_limit' => 'integer', 'updated_by_admin_id' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
