<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectChannelSiteIdentity extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'client_project_id',
        'distribution_channel_id',
        'project_slug_snapshot',
        'canonical_url',
        'canonical_identity',
        'status',
        'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'client_project_id' => 'integer',
            'distribution_channel_id' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class);
    }

    public function distributionChannel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(ProjectChannelSiteIdentityHistory::class);
    }
}
