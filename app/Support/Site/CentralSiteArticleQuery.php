<?php

namespace App\Support\Site;

use App\Enums\ClientProjectStatus;
use App\Enums\PublicationBatchItemStatus;
use App\Enums\PublicationGate;
use App\Enums\PublicationTargetType;
use App\Models\Article;
use App\Models\ClientProject;
use App\Models\PublicationBatchItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The one SQL eligibility projection for the platform's central public site.
 *
 * This deliberately only reads persisted publication facts. Controllers and
 * view composers must not recreate these predicates in PHP collections.
 */
final class CentralSiteArticleQuery
{
    /**
     * @param  EloquentBuilder<Article>  $query
     * @return EloquentBuilder<Article>
     */
    public static function apply(EloquentBuilder $query): EloquentBuilder
    {
        $articles = (new Article)->getTable();
        $projects = (new ClientProject)->getTable();
        $items = (new PublicationBatchItem)->getTable();

        return $query
            ->where($articles.'.status', 'published')
            ->whereNull($articles.'.deleted_at')
            ->whereIn($articles.'.review_status', ['approved', 'auto_approved'])
            ->where($articles.'.central_site_allowed', true)
            ->whereExists(function (QueryBuilder $projectQuery) use ($articles, $projects, $items): void {
                $projectQuery
                    ->selectRaw('1')
                    ->from($projects)
                    ->whereColumn($projects.'.id', $articles.'.client_project_id')
                    ->where($projects.'.status', ClientProjectStatus::ACTIVE->value)
                    ->where(function (QueryBuilder $gateQuery) use ($articles, $projects, $items): void {
                        $gateQuery
                            ->where(function (QueryBuilder $legacyQuery) use ($projects): void {
                                $legacyQuery
                                    ->where($projects.'.is_legacy', true)
                                    ->where($projects.'.publication_gate', PublicationGate::LEGACY_AUTO->value);
                            })
                            ->orWhere(function (QueryBuilder $approvedQuery) use ($articles, $projects, $items): void {
                                $approvedQuery
                                    ->where($projects.'.publication_gate', PublicationGate::PLATFORM_APPROVAL->value)
                                    ->whereExists(function (QueryBuilder $itemQuery) use ($articles, $items): void {
                                        $itemQuery
                                            ->selectRaw('1')
                                            ->from($items)
                                            ->whereColumn($items.'.article_id', $articles.'.id')
                                            ->whereColumn($items.'.client_project_id', $articles.'.client_project_id')
                                            ->where($items.'.target_type', PublicationTargetType::LOCAL->value)
                                            ->where($items.'.action', 'publish')
                                            ->where($items.'.status', PublicationBatchItemStatus::LOCAL_PUBLISHED->value);
                                    });
                            });
                    });
            });
    }
}
