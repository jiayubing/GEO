<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class UrlImportJob extends Model
{
    protected $table = 'url_import_jobs';

    protected $fillable = [
        'url',
        'normalized_url',
        'source_domain',
        'page_title',
        'status',
        'current_step',
        'progress_percent',
        'options_json',
        'result_json',
        'error_message',
        'created_by',
        'started_at',
        'finished_at',
        'client_project_id',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'client_project_id' => 'integer',
            'committed_knowledge_base_id' => 'integer',
            'committed_keyword_library_id' => 'integer',
            'committed_title_library_id' => 'integer',
            'commit_started_at' => 'datetime',
            'commit_finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $job): void {
            if ($job->isDirty('client_project_id')) {
                throw new LogicException('url_import_job_project_owner_immutable');
            }
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportJobLog::class, 'job_id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
