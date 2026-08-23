<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EnterpriseKnowledgeProject extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'draft_content',
        'structured_json',
        'validation_json',
        'published_knowledge_base_id',
        'ai_model_id',
        'error_message',
        'created_by_admin_id',
        'client_project_id',
    ];

    protected function casts(): array
    {
        return [
            'published_knowledge_base_id' => 'integer',
            'ai_model_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'client_project_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $project): void {
            if ($project->client_project_id === null || $project->published_knowledge_base_id === null) {
                return;
            }

            $knowledgeBase = KnowledgeBase::query()
                ->select(['id', 'client_project_id'])
                ->find($project->published_knowledge_base_id);

            if ($knowledgeBase !== null
                && (int) $knowledgeBase->client_project_id !== (int) $project->client_project_id) {
                throw new LogicException('enterprise_knowledge_published_knowledge_base_project_mismatch');
            }
        });
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeSource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function clientProject(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeRevision::class)->latest();
    }

    public function publishedKnowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'published_knowledge_base_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function validationItems(): array
    {
        $decoded = json_decode((string) ($this->validation_json ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function structuredData(): array
    {
        $decoded = json_decode((string) ($this->structured_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function draftGenerationProgress(): array
    {
        $progress = $this->structuredData()['draft_generation'] ?? [];

        return is_array($progress) ? $progress : [];
    }
}
