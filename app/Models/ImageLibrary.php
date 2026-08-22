<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageLibrary extends Model
{
    protected $table = 'image_libraries';

    protected $fillable = [
        'name',
        'description',
        'image_count',
        'used_task_count',
        'client_project_id',
    ];

    protected function casts(): array
    {
        return [
            'image_count' => 'integer',
            'used_task_count' => 'integer',
            'client_project_id' => 'integer',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(Image::class, 'library_id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'image_library_id');
    }
}
