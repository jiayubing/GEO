<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleRiskGateException;
use App\Exceptions\PublicationGateException;
use App\Models\Article;
use App\Models\ClientProject;
use App\Support\GeoFlow\PublicationGateContract;
use Illuminate\Support\Facades\DB;

class ArticleWorkflowTransitionService
{
    public function __construct(private readonly ArticleRiskGate $articleRiskGate) {}

    /**
     * @param  array{status: string, review_status: string, published_at: mixed}  $workflowState
     * @param  array{status: string, review_status: string, published_at: mixed}|null  $rejectedWorkflowState
     * @param  (callable(Article): void)|null  $lockedGuard
     */
    public function transition(
        Article $article,
        array $workflowState,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
        ?array $rejectedWorkflowState = null,
        ?callable $lockedGuard = null,
        string $publicationTarget = PublicationGateContract::TARGET_LOCAL,
        bool $platformApproved = false,
    ): Article {
        $result = DB::transaction(function () use (
            $article,
            $workflowState,
            $trigger,
            $adminId,
            $overrideReason,
            $allowExistingOverride,
            $rejectedWorkflowState,
            $lockedGuard,
            $publicationTarget,
            $platformApproved,
        ): Article|ArticleRiskGateException {
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedGuard !== null) {
                $lockedGuard($lockedArticle);
            }

            if ($workflowState['status'] === 'published' && (string) $lockedArticle->status !== 'published') {
                $project = $lockedArticle->client_project_id !== null
                    ? ClientProject::query()->find($lockedArticle->client_project_id)
                    : null;

                // Articles without a project are the pre-project legacy surface.
                if ($project instanceof ClientProject) {
                    $gate = PublicationGateContract::evaluate(
                        $project,
                        (string) ($lockedArticle->status ?? 'draft'),
                        (string) ($workflowState['review_status'] ?? 'pending'),
                        $publicationTarget,
                        $platformApproved,
                    );
                    if (! $gate['allowed']) {
                        throw new PublicationGateException($gate['code'], $publicationTarget, $gate['gate']);
                    }
                }
            }

            try {
                $this->articleRiskGate->check(
                    $lockedArticle,
                    $trigger,
                    $adminId,
                    $overrideReason,
                    $allowExistingOverride,
                );
            } catch (ArticleRiskGateException $exception) {
                if ($rejectedWorkflowState !== null) {
                    $lockedArticle->update([
                        'status' => $rejectedWorkflowState['status'],
                        'review_status' => $rejectedWorkflowState['review_status'],
                        'published_at' => $rejectedWorkflowState['published_at'],
                    ]);
                }

                return $exception;
            }

            $lockedArticle->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);

            return $lockedArticle->refresh();
        });

        if ($result instanceof ArticleRiskGateException) {
            throw $result;
        }

        return $result;
    }
}
