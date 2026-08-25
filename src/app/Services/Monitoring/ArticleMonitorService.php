<?php

namespace App\Services\Monitoring;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use Illuminate\Support\Carbon;

class ArticleMonitorService
{
    public function __construct(
        private readonly ArticleDiscoveryService $discoveryService,
        private readonly ArticleTextExtractor $textExtractor,
        private readonly KeywordMatcher $keywordMatcher,
        private readonly TelegramNotifier $telegramNotifier,
    ) {}

    /** @return array{found:int,created:int,skipped:int,analyzed:int,hits:int,sent:int} */
    public function ingestSite(MonitoredSite $site, int $limit, bool $backfill, bool $analyze, bool $notify): array
    {
        $items = $this->discoveryService->discover($site, $limit);
        $stats = ['found' => count($items), 'created' => 0, 'skipped' => 0, 'analyzed' => 0, 'hits' => 0, 'sent' => 0];

        foreach ($items as $item) {
            $article = Article::firstOrCreate(
                ['url' => $item['url']],
                [
                    'monitored_site_id' => $site->id,
                    'title' => $item['title'],
                    'excerpt' => $item['excerpt'],
                    'published_at' => $item['published_at'],
                    'discovered_at' => now(),
                    'is_backfilled' => $backfill,
                ],
            );

            if (! $article->wasRecentlyCreated) {
                $stats['skipped']++;

                if ($backfill && $analyze && $article->checked_at === null) {
                    $processStats = $this->processArticle($article, notify: false, contentSelector: $site->content_selector);
                    $stats['analyzed']++;
                    $stats['hits'] += $processStats['hits'];
                }

                continue;
            }

            $stats['created']++;

            if ($analyze) {
                $processStats = $this->processArticle($article, $notify, $site->content_selector);
                $stats['analyzed']++;
                $stats['hits'] += $processStats['hits'];
                $stats['sent'] += $processStats['sent'];
            }
        }

        $site->forceFill($backfill ? ['last_backfilled_at' => now()] : ['last_checked_at' => now()])->save();

        return $stats;
    }

    /** @return array{hits:int,sent:int} */
    public function processArticle(Article $article, bool $notify, ?string $contentSelector = null): array
    {
        $extracted = $this->textExtractor->extract($article->url, $contentSelector);

        if ($extracted === null) {
            $article->forceFill(['checked_at' => now()])->save();

            return ['hits' => 0, 'sent' => 0];
        }

        $article->forceFill([
            'title' => $article->title ?: $extracted['title'],
            'content_hash' => $extracted['hash'],
            'checked_at' => now(),
        ])->save();

        $text = trim(($article->title ?? '').' '.$extracted['text']);
        $matches = $this->keywordMatcher->match(Keyword::where('enabled', true)->get(), $text);

        foreach ($matches as $match) {
            ArticleKeywordHit::updateOrCreate(
                ['article_id' => $article->id, 'keyword_id' => $match['keyword']->id],
                ['matched_text' => $match['matched_text'], 'context' => $match['context']],
            );
        }

        $sent = 0;

        if ($notify && $matches !== []) {
            $article->loadMissing('site');
            $keywords = array_values(array_unique(array_map(fn (array $match): string => $match['keyword']->phrase, $matches)));

            if ($this->telegramNotifier->sendArticleMention($article, $keywords)) {
                $article->forceFill(['notified_at' => Carbon::now()])->save();
                $sent = 1;
            }
        }

        return ['hits' => count($matches), 'sent' => $sent];
    }
}
