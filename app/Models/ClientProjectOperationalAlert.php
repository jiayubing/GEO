<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClientProjectOperationalAlert extends Model
{
    protected $fillable = ['client_project_id', 'fingerprint', 'kind', 'severity', 'status', 'payload', 'first_seen_at', 'last_seen_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['client_project_id' => 'integer', 'payload' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
