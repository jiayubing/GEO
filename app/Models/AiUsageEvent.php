<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiUsageEvent extends Model
{
    protected $fillable = [
        'client_project_id', 'scope', 'model', 'operation', 'attempt', 'units',
        'outcome', 'fallback', 'reservation_key', 'metadata',
    ];

    protected function casts(): array
    {
        return ['client_project_id' => 'integer', 'attempt' => 'integer', 'units' => 'integer', 'fallback' => 'boolean', 'metadata' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}
