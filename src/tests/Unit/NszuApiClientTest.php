<?php

namespace Tests\Unit;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleDiscoveryService;
use App\Services\Monitoring\ArticleTextExtractor;
use App\Services\Monitoring\NszuApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NszuApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_existing_html_source_uses_api_pagination_and_canonical_article_urls(): void
    {
        Http::fake([
            'https://nszu.gov.ua/api/news?page=1' => Http::response($this->page(1, 3, range(1, 6))),
            'https://nszu.gov.ua/api/news?page=2' => Http::response($this->page(2, 3, range(6, 11))),
        ]);
        $site = new MonitoredSite([
            'base_url' => 'https://www.nszu.gov.ua/',
            'listing_url' => 'https://www.nszu.gov.ua/news',
            'source_type' => 'html',
            'article_url_pattern' => '~^/news/(?!page-[1-9][0-9]*$)[a-z0-9]+(?:-[a-z0-9]+)*$~',
        ]);

        $items = app(ArticleDiscoveryService::class)->discover($site, 8);

        $this->assertCount(8, $items);
        $this->assertSame('https://nszu.gov.ua/news/story-1', $items[0]['url']);
        $this->assertSame('https://nszu.gov.ua/news/story-8', $items[7]['url']);
        $this->assertSame('Новина 1', $items[0]['title']);
        $this->assertNull($items[0]['excerpt']);
        $this->assertSame('2026-10-01T07:25:23+00:00', $items[0]['published_at']->toIso8601String());
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-localization', 'uk')
            && $request->hasHeader('X-Requested-With', 'XMLHttpRequest')
            && $request->hasHeader('Referer', 'https://nszu.gov.ua/news')
            && $request->hasHeader('Accept', 'application/json, text/plain, */*'));
    }

    public function test_it_can_discover_more_than_twenty_small_api_pages_for_backfill(): void
    {
        Http::fake(function (Request $request) {
            $page = (int) $request['page'];

            return Http::response($this->page($page, 30, range(($page - 1) * 6 + 1, $page * 6)));
        });

        $this->assertCount(125, app(NszuApiClient::class)->discover(125));
        Http::assertSentCount(21);
    }

    public function test_it_stops_at_the_last_page_even_when_limit_is_larger(): void
    {
        Http::fake(['*' => Http::response($this->page(1, 1, [1, 2]))]);

        $this->assertCount(2, app(NszuApiClient::class)->discover(50));
        Http::assertSentCount(1);
    }

    public function test_a_valid_empty_listing_is_not_an_error(): void
    {
        Http::fake(['*' => Http::response($this->page(1, 1, []))]);

        $this->assertSame([], app(NszuApiClient::class)->discover(20));
    }

    public function test_listing_failure_on_a_later_page_is_not_reported_as_partial_success(): void
    {
        Http::fakeSequence()->push($this->page(1, 2, [1]))->push('Blocked', 403);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NSZU API returned HTTP 403');

        try {
            app(NszuApiClient::class)->discover(20);
        } finally {
            Http::assertSentCount(2);
            Sleep::assertNeverSlept();
        }
    }

    public function test_repeated_pages_fail_instead_of_looping_forever(): void
    {
        Http::fakeSequence()->push($this->page(1, 100, [1]))->push($this->page(2, 100, [1]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pagination made no progress');

        app(NszuApiClient::class)->discover(20);
    }

    #[DataProvider('invalidListings')]
    public function test_invalid_listing_payloads_fail_loudly(mixed $payload): void
    {
        Http::fake(['*' => Http::response($payload)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NSZU API');

        app(NszuApiClient::class)->discover(20);
    }

    public static function invalidListings(): array
    {
        return [
            'html challenge with status 200' => ['<html>Cloudflare challenge</html>'],
            'missing news' => [['data' => []]],
            'wrong page' => [['data' => ['news' => ['current_page' => 2, 'last_page' => 2, 'data' => []]]]],
            'missing pagination' => [['data' => ['news' => ['data' => []]]]],
            'invalid item' => [['data' => ['news' => ['current_page' => 1, 'last_page' => 1, 'data' => [[]]]]]],
        ];
    }

    public function test_it_rejects_article_urls_from_other_hosts(): void
    {
        $payload = $this->page(1, 1, [1]);
        $payload['data']['news']['data'][0]['url'] = 'https://example.com/news/story-1';
        Http::fake(['*' => Http::response($payload)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid article URL');

        app(NszuApiClient::class)->discover(20);
    }

    public function test_it_retries_transient_http_and_connection_errors(): void
    {
        Http::fakeSequence()
            ->pushFailedConnection('Connection reset')
            ->push('', 503)
            ->push($this->page(1, 1, [1]));

        $this->assertCount(1, app(NszuApiClient::class)->discover(20));
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(3)->seconds()]);
    }

    public function test_article_extractor_uses_full_api_text_and_preserves_inline_words(): void
    {
        $nodes = [
            ['type' => 'element', 'tagName' => 'p', 'children' => [
                ['type' => 'text', 'content' => 'Лікарі ЗО'],
                ['type' => 'element', 'tagName' => 'strong', 'children' => [['type' => 'text', 'content' => 'КБ']]],
                ['type' => 'text', 'content' => ' провели&nbsp;операцію.'],
            ]],
            ['type' => 'element', 'tagName' => 'p', 'children' => [
                ['type' => 'element', 'tagName' => 'a', 'attributes' => [['key' => 'href', 'value' => 'https://example.com']], 'children' => [['type' => 'text', 'content' => 'Деталі &amp; результати.']]],
                ['type' => 'element', 'tagName' => 'br'],
                ['type' => 'text', 'content' => 'Дякуємо!'],
            ]],
            ['type' => 'element', 'tagName' => 'script', 'children' => [['type' => 'text', 'content' => 'noise']]],
        ];
        Http::fake(['https://nszu.gov.ua/api/news/story-1' => Http::response([
            'data' => ['news' => ['title' => 'Операція', 'content' => json_encode($nodes)], 'recommendations' => [['title' => 'Noise']]],
        ])]);

        $article = app(ArticleTextExtractor::class)->extract('https://www.nszu.gov.ua/news/story-1/?utm_source=test');

        $this->assertSame('Лікарі ЗОКБ провели операцію. Деталі & результати. Дякуємо!', $article['text']);
        $this->assertSame('Операція', $article['title']);
        $this->assertSame(hash('sha256', $article['text']), $article['hash']);
        Http::assertSentCount(1);
    }

    #[DataProvider('invalidContents')]
    public function test_invalid_article_content_is_not_swallowed_by_the_extractor(mixed $content): void
    {
        Http::fake(['*' => Http::response(['data' => ['news' => ['title' => 'Новина', 'content' => $content]]])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NSZU API');

        app(ArticleTextExtractor::class)->extract('https://nszu.gov.ua/news/story-1');
    }

    public static function invalidContents(): array
    {
        return [
            'missing content' => [null],
            'invalid JSON' => ['not-json'],
            'not a tree' => ['null'],
            'empty tree' => ['[]'],
            'invalid node' => ['[null]'],
            'invalid children' => ['[{"type":"element","tagName":"p","children":"text"}]'],
            'unsupported node' => ['[{"type":"unknown","content":"Text"}]'],
        ];
    }

    public function test_api_routing_is_limited_to_nszu_news(): void
    {
        $client = app(NszuApiClient::class);

        foreach (['https://backend.nszu.gov.ua/news', 'https://nszu.gov.ua.example.com/news', 'https://example.com/news', 'https://nszu.gov.ua/contacts'] as $url) {
            $this->assertFalse($client->supportsListingUrl($url));
            $this->assertFalse($client->supportsArticleUrl($url));
        }

        $this->assertFalse($client->supportsArticleUrl('https://nszu.gov.ua/news/page-2'));
        Http::fake(['https://example.com/news/story-1' => Http::response('<article>Звичайна новина.</article>')]);
        $this->assertSame('Звичайна новина.', app(ArticleTextExtractor::class)->extract('https://example.com/news/story-1')['text']);
    }

    private function page(int $page, int $lastPage, array $ids): array
    {
        return ['data' => ['news' => [
            'current_page' => $page,
            'last_page' => $lastPage,
            // API-provided pagination URLs must not be followed directly.
            'next_page_url' => 'http://backend.nszu.gov.ua/news?page='.($page + 1),
            'data' => array_map(fn (int $id): array => [
                'url' => $id === 1 ? 'https://www.nszu.gov.ua/news/story-1/' : '/news/story-'.$id,
                'title' => 'Новина '.$id,
                'created_at' => '2026-10-01T07:25:23.000000Z',
            ], $ids),
        ]]];
    }
}
