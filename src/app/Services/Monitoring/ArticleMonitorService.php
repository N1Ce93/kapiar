<?php

namespace App\Services\Monitoring;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use Illuminate\Support\Carbon;

class ArticleMonitorService
{
    private const MIN_EXCERPT_FALLBACK_LENGTH = 100;

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
                if ($item['excerpt'] && mb_strlen($item['excerpt'], 'UTF-8') > mb_strlen((string) $article->excerpt, 'UTF-8')) {
                    $article->forceFill(['excerpt' => $item['excerpt']])->save();
                }

                $stats['skipped']++;

                if ($analyze && ($article->checked_at === null || $article->content_hash === null)) {
                    $processStats = $this->processArticle(
                        $article,
                        notify: ! $backfill && ! $article->is_backfilled && $notify,
                        contentSelector: $site->content_selector,
                    );
                    $stats['analyzed']++;
                    $stats['hits'] += $processStats['hits'];
                    $stats['sent'] += $processStats['sent'];
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
        $excerpt = trim((string) $article->excerpt);

        if ($extracted === null) {
            if (mb_strlen($excerpt, 'UTF-8') < self::MIN_EXCERPT_FALLBACK_LENGTH) {
                return ['hits' => 0, 'sent' => 0];
            }

            $extracted = [
                'title' => null,
                'text' => $excerpt,
                'hash' => hash('sha256', $excerpt),
            ];
        } elseif (mb_strlen($excerpt, 'UTF-8') > mb_strlen($extracted['text'], 'UTF-8')) {
            $extracted['text'] = $excerpt;
            $extracted['hash'] = hash('sha256', $excerpt);
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
