<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectChannelSiteIdentityHistory extends Model
{
    public const REASON_CANONICAL_CHANGED = 'canonical_changed';

    public const REASON_DISABLED = 'disabled';

    protected $fillable = [
        'project_channel_site_identity_id',
        'project_slug_snapshot',
        'canonical_url',
        'canonical_identity',
        'reason',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'project_channel_site_identity_id' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    public function siteIdentity(): BelongsTo
    {
        return $this->belongsTo(ProjectChannelSiteIdentity::class, 'project_channel_site_identity_id');
    }
}
