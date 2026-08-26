<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ArticleMonitorFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_the_complete_article_monitoring_flow(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1000000000000',
        ]);
        $site = $this->site();
        $keyword = Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        Http::fake(fn (Request $request) => match ($request->url()) {
            'https://example.com/feed/' => Http::response($this->feed(), 200),
            'https://example.com/news/operation' => Http::response($this->article(), 200),
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true], 200),
            default => Http::response('', 404),
        });

        $stats = app(ArticleMonitorService::class)->ingestSite(
            $site,
            limit: 20,
            backfill: false,
            analyze: true,
            notify: true,
        );

        $this->assertSame([
            'found' => 1,
            'created' => 1,
            'skipped' => 0,
            'analyzed' => 1,
            'hits' => 1,
            'sent' => 1,
        ], $stats);
        $article = Article::query()->sole();
        $this->assertNotNull($article->content_hash);
        $this->assertNotNull($article->checked_at);
        $this->assertNotNull($article->notified_at);
        $this->assertTrue(ArticleKeywordHit::query()
            ->where('article_id', $article->id)
            ->where('keyword_id', $keyword->id)
            ->exists());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'ЗОКБ'));
    }

    public function test_it_retries_an_existing_article_after_extraction_failed(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1000000000000',
        ]);
        $site = $this->site();
        Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        $articleRequests = 0;
        Http::fake(function (Request $request) use (&$articleRequests) {
            if ($request->url() === 'https://example.com/feed/') {
                return Http::response($this->feed(), 200);
            }

            if ($request->url() === 'https://example.com/news/operation') {
                $articleRequests++;

                return $articleRequests === 1
                    ? Http::response('', 503)
                    : Http::response($this->article(), 200);
            }

            if (str_contains($request->url(), 'api.telegram.org')) {
                return Http::response(['ok' => true], 200);
            }

            return Http::response('', 404);
        });

        $first = app(ArticleMonitorService::class)->ingestSite($site, 20, false, true, true);
        $failedArticle = Article::query()->sole();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $first['hits']);
        $this->assertNull($failedArticle->checked_at);
        $this->assertNull($failedArticle->content_hash);

        $second = app(ArticleMonitorService::class)->ingestSite($site, 20, false, true, true);
        $failedArticle->refresh();

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, $second['analyzed']);
        $this->assertSame(1, $second['hits']);
        $this->assertSame(1, $second['sent']);
        $this->assertNotNull($failedArticle->checked_at);
        $this->assertNotNull($failedArticle->content_hash);
    }

    public function test_it_never_notifies_when_retrying_a_backfilled_article(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.chat_id' => '-1000000000000',
        ]);
        $site = $this->site();
        Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        $article = Article::create([
            'monitored_site_id' => $site->id,
            'url' => 'https://example.com/news/operation',
            'title' => 'Операція',
            'is_backfilled' => true,
        ]);
        Http::fake(fn (Request $request) => match ($request->url()) {
            'https://example.com/feed/' => Http::response($this->feed(), 200),
            'https://example.com/news/operation' => Http::response($this->article(), 200),
            default => Http::response('', 404),
        });

        $stats = app(ArticleMonitorService::class)->ingestSite($site, 20, false, true, true);

        $this->assertSame(1, $stats['hits']);
        $this->assertSame(0, $stats['sent']);
        $this->assertNull($article->fresh()->notified_at);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_it_uses_full_rss_content_when_article_extraction_fails(): void
    {
        $site = $this->site();
        Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        Http::fake(fn (Request $request) => match ($request->url()) {
            'https://example.com/feed/' => Http::response($this->feedWithEncodedContent(), 200),
            'https://example.com/news/operation' => Http::response('', 503),
            default => Http::response('', 404),
        });

        $stats = app(ArticleMonitorService::class)->ingestSite($site, 20, false, true, false);
        $article = Article::query()->sole();

        $this->assertSame(1, $stats['hits']);
        $this->assertNotNull($article->content_hash);
        $this->assertNotNull($article->checked_at);
        $this->assertDatabaseHas('article_keyword_hits', ['article_id' => $article->id]);
    }

    private function site(): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => 'Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'rss',
            'feed_url' => 'https://example.com/feed/',
            'enabled' => true,
        ]);
    }

    private function feed(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><item>
                <title>Операція</title>
                <link>https://example.com/news/operation</link>
                <description>Опис</description>
                <pubDate>Tue, 25 Aug 2026 12:00:00 +0300</pubDate>
            </item></channel></rss>
            XML;
    }

    private function article(): string
    {
        return '<html><head><title>Операція</title></head><body><article>Лікарі ЗОКБ провели складну операцію.</article></body></html>';
    }

    private function feedWithEncodedContent(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><item>
                <title>Операція</title>
                <link>https://example.com/news/operation</link>
                <description>Опис</description>
                <content:encoded><![CDATA[
                    <p>Лікарі ЗОКБ провели складну операцію.</p>
                    <p>Цей розширений опис містить усі важливі подробиці матеріалу та достатньо тексту для надійного аналізу ключових слів.</p>
                ]]></content:encoded>
            </item></channel></rss>
            XML;
    }
}
