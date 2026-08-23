<?php

namespace App\Models;

use App\Enums\ClientProjectStatus;
use App\Enums\PublicationGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClientProject extends Model
{
    protected $attributes = [
        'status' => 'active',
        'publication_gate' => 'platform_approval',
    ];

    protected $fillable = ['client_id', 'name', 'slug', 'status', 'is_legacy', 'publication_gate', 'created_by_admin_id', 'updated_by_admin_id'];

    protected static function booted(): void
    {
        static::creating(function (self $project): void {
            if ($project->status === null) {
                $project->status = ClientProjectStatus::ACTIVE;
            }
            if ($project->publication_gate === null) {
                $project->publication_gate = PublicationGate::PLATFORM_APPROVAL;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => ClientProjectStatus::class,
            'is_legacy' => 'boolean',
            'publication_gate' => PublicationGate::class,
            'client_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClientProjectMember::class);
    }

    public function channelMemberships(): HasMany
    {
        return $this->hasMany(ClientProjectDistributionChannel::class, 'client_project_id');
    }

    public function distributionChannels(): BelongsToMany
    {
        return $this->belongsToMany(DistributionChannel::class, 'client_project_distribution_channels')
            ->withPivot(['status', 'created_by_admin_id', 'revoked_at'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public function publicationBatches(): HasMany
    {
        return $this->hasMany(PublicationBatch::class);
    }

    public function publicationBatchItems(): HasMany
    {
        return $this->hasMany(PublicationBatchItem::class);
    }

    public function channelSiteIdentities(): HasMany
    {
        return $this->hasMany(ProjectChannelSiteIdentity::class);
    }

    public function quota(): HasOne
    {
        return $this->hasOne(ClientProjectQuota::class, 'client_project_id');
    }

    public function usageReservations(): HasMany
    {
        return $this->hasMany(ClientProjectUsageReservation::class, 'client_project_id');
    }

    public function operationalAlerts(): HasMany
    {
        return $this->hasMany(ClientProjectOperationalAlert::class, 'client_project_id');
    }
}
