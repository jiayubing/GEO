<?php

namespace App\Models;

use App\Enums\ClientProjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientProject extends Model
{
    protected $fillable = ['client_id', 'name', 'slug', 'status', 'is_legacy', 'created_by_admin_id', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return [
            'status' => ClientProjectStatus::class,
            'is_legacy' => 'boolean',
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
}
