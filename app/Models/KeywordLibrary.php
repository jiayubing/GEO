<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordLibrary extends Model
{
    protected $table = 'keyword_libraries';

    protected $fillable = [
        'name',
        'description',
        'keyword_count',
        'client_project_id',
    ];

    protected function casts(): array
    {
        return [
            'keyword_count' => 'integer',
            'client_project_id' => 'integer',
        ];
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'library_id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'keyword_library_id');
    }
}
