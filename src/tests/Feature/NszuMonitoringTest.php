<?php

namespace Tests\Feature;

use App\Jobs\CheckMonitoredSiteJob;
use App\Models\Article;
use App\Models\ArticleKeywordHit;
use App\Models\Keyword;
use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleMonitorService;
use App\Services\Monitoring\SourceHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NszuMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_articles_are_analyzed_notified_and_deduplicated_using_public_urls(): void
    {
        config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '-1000000000000']);
        $site = $this->site();
        Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://nszu.gov.ua/api/news?page=1' => Http::response($this->listing()),
            'https://nszu.gov.ua/api/news/story-1' => Http::response($this->detail()),
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response(['ok' => true]),
        ]);
        $monitor = app(ArticleMonitorService::class);

        $first = $monitor->ingestSite($site, 20, false, true, true);
        $second = $monitor->ingestSite($site, 20, false, true, true);

        $this->assertSame(1, $first['created']);
        $this->assertSame(1, $first['hits']);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(0, $second['sent']);
        $article = Article::query()->sole();
        $this->assertSame('https://nszu.gov.ua/news/story-1', $article->url);
        $this->assertNotNull($article->checked_at);
        $this->assertNotNull($article->content_hash);
        $this->assertNotNull($article->notified_at);
        $this->assertSame(1, ArticleKeywordHit::count());
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'https://nszu.gov.ua/news/story-1')
            && ! str_contains((string) $request['text'], '/api/news'));
    }

    public function test_article_api_failure_updates_source_health_and_can_be_retried(): void
    {
        $site = $this->site();
        Keyword::create(['phrase' => 'ЗОКБ', 'enabled' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://nszu.gov.ua/api/news?page=1' => Http::response($this->listing()),
            'https://nszu.gov.ua/api/news/story-1' => Http::sequence()->push('Blocked', 403)->push($this->detail()),
        ]);
        $job = new CheckMonitoredSiteJob($site->id, notify: false);
        $monitor = app(ArticleMonitorService::class);
        $health = app(SourceHealthService::class);

        $job->handle($monitor, $health);

        $site->refresh();
        $this->assertSame(1, $site->consecutive_failures);
        $this->assertSame('temporary', $site->last_error_type);
        $this->assertStringContainsString('NSZU API returned HTTP 403', $site->last_error);
        $this->assertNull($site->last_success_at);
        $this->assertNull(Article::query()->sole()->checked_at);
        $this->assertSame(0, ArticleKeywordHit::count());

        $job->handle($monitor, $health);

        $site->refresh();
        $this->assertSame(0, $site->consecutive_failures);
        $this->assertNull($site->last_error);
        $this->assertNotNull($site->last_success_at);
        $this->assertNotNull(Article::query()->sole()->checked_at);
        $this->assertSame(1, ArticleKeywordHit::count());
        Http::assertSentCount(4);
    }

    public function test_invalid_listing_does_not_record_a_successful_empty_check(): void
    {
        $site = $this->site();
        Http::fake(['*' => Http::response('<html>Cloudflare challenge</html>', 200)]);

        (new CheckMonitoredSiteJob($site->id, notify: false))->handle(
            app(ArticleMonitorService::class),
            app(SourceHealthService::class),
        );

        $this->assertSame(1, $site->fresh()->consecutive_failures);
        $this->assertNull($site->fresh()->last_success_at);
        $this->assertSame(0, Article::count());
    }

    private function site(): MonitoredSite
    {
        return MonitoredSite::create([
            'name' => 'НСЗУ',
            'base_url' => 'https://nszu.gov.ua/',
            'source_type' => 'html',
            'listing_url' => 'https://nszu.gov.ua/news',
            'enabled' => true,
        ]);
    }

    private function listing(): array
    {
        return ['data' => ['news' => [
            'current_page' => 1,
            'last_page' => 1,
            'data' => [['title' => 'Операція', 'url' => '/news/story-1', 'created_at' => '2026-10-01T07:25:23.000000Z']],
        ]]];
    }

    private function detail(): array
    {
        return ['data' => ['news' => [
            'title' => 'Операція',
            'content' => json_encode([['type' => 'element', 'tagName' => 'p', 'children' => [
                ['type' => 'text', 'content' => 'Лікарі ЗОКБ провели складну операцію.'],
            ]]]),
        ]]];
    }
}
