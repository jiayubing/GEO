<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'client_project_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'client_project_id' => 'integer',
        ];
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function articlesIncludingTrashed(): HasMany
    {
        return $this->hasMany(Article::class, 'category_id')->withTrashed();
    }
}
