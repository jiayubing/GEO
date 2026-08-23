<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientProjectUsageReservation extends Model
{
    public const RESERVED = 'reserved';
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const UNCERTAIN = 'uncertain';
    public const RELEASED = 'released';

    protected $fillable = [
        'client_project_id', 'reservation_key', 'kind', 'units', 'state',
        'operation', 'attempt', 'metadata',
    ];

    protected function casts(): array
    {
        return ['client_project_id' => 'integer', 'units' => 'integer', 'attempt' => 'integer', 'metadata' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
