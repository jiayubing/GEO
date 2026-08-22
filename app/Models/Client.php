<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'slug', 'status', 'is_legacy', 'created_by_admin_id', 'updated_by_admin_id'];

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'is_legacy' => 'boolean',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
        ];
    }

    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class);
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
