<?php

namespace App\Models;

use App\Enums\ClientProjectMemberRole;
use App\Enums\ClientProjectMemberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientProjectMember extends Model
{
    protected $fillable = ['client_project_id', 'admin_id', 'role', 'status', 'created_by_admin_id', 'updated_by_admin_id', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'role' => ClientProjectMemberRole::class,
            'status' => ClientProjectMemberStatus::class,
            'client_project_id' => 'integer',
            'admin_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
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
