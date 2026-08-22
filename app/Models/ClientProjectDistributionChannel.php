<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectDistributionChannel extends Model
{
    protected $fillable = ['client_project_id', 'distribution_channel_id', 'status', 'created_by_admin_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'client_project_id' => 'integer',
            'distribution_channel_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
