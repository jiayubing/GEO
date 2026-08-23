<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 文章归档：总览与按年月列表（PostgreSQL 总览用 SQL 聚合；年月区间查询兼容 SQLite）。
 */
class ArchiveController extends Controller
{
    public function index(): View
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $articlesTable = (new Article)->getTable();
        $period = 'COALESCE('.$articlesTable.'.published_at, '.$articlesTable.'.created_at)';
        if (DB::getDriverName() === 'pgsql') {
            $year = 'EXTRACT(YEAR FROM '.$period.')::int';
            $month = "LPAD(EXTRACT(MONTH FROM {$period})::text, 2, '0')";
        } else {
            $year = "strftime('%Y', {$period})";
            $month = "strftime('%m', {$period})";
        }

        $rows = Article::query()
            ->centralSitePublic()
            ->toBase()
            ->selectRaw($year.' AS y, '.$month.' AS m, COUNT(*) AS cnt')
            ->groupByRaw($year.', '.$month)
            ->orderByDesc('y')
            ->orderByDesc('m')
            ->get();
        $archives = $rows->map(static fn (object $row): array => [
            'year' => (string) $row->y,
            'month' => (string) $row->m,
            'count' => (int) $row->cnt,
        ])->all();

        $pageTitle = __('site.archive_title').' - '.$siteTitle;

        return SiteThemeViewResolver::first('archive-index', [
            'activeNav' => 'archive',
            'archives' => $archives,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $siteDescription,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.archive'),
        ]);
    }

    public function month(string $year, string $month): View
    {
        if (! preg_match('/^\d{4}$/', $year) || ! preg_match('/^\d{2}$/', $month)) {
            throw new NotFoundHttpException;
        }

        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $start = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfDay();
        $end = (clone $start)->copy()->endOfMonth()->endOfDay();

        $articles = Article::query()
            ->with(['category', 'author'])
            ->centralSitePublic()
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('published_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end): void {
                        $q2->whereNull('published_at')->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $summaries = [];
        foreach ($articles as $row) {
            if ($row instanceof Article) {
                $summaries[$row->id] = ArticleHtmlPresenter::cardSummary($row, 120);
            }
        }

        $periodLabel = app()->getLocale() === 'en'
            ? $start->translatedFormat('F Y')
            : $year.'年'.$month.'月';

        $pageTitle = __('site.archive_month_title', ['period' => $periodLabel]).' - '.$siteTitle;

        return SiteThemeViewResolver::first('archive-month', [
            'activeNav' => 'archive',
            'year' => $year,
            'month' => $month,
            'periodLabel' => $periodLabel,
            'articles' => $articles,
            'cardSummaries' => $summaries,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageTitle,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.archive.month', ['year' => $year, 'month' => $month]),
        ]);
    }
}
