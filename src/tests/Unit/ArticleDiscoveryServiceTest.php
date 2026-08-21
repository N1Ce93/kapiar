<?php

namespace Tests\Unit;

use App\Models\MonitoredSite;
use App\Services\Monitoring\ArticleDiscoveryService;
use App\Services\Monitoring\SiteProbeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ArticleDiscoveryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Sleep::fake(false);

        parent::tearDown();
    }

    public function test_html_discovery_retries_temporary_http_errors(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push('', 504)
            ->push('', 503)
            ->push('<html><body></body></html>', 200);

        $this->assertSame([], $this->service()->discover($this->site(), 25));

        Http::assertSentCount(3);
        Sleep::assertSequence([
            Sleep::for(1)->second(),
            Sleep::for(3)->seconds(),
        ]);
    }

    public function test_html_discovery_retries_connection_errors(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->pushFailedConnection('Connection reset')
            ->push('<html><body></body></html>', 200);

        $this->assertSame([], $this->service()->discover($this->site(), 25));

        Http::assertSentCount(2);
        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public function test_html_discovery_does_not_retry_permanent_http_errors(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 404)]);

        try {
            $this->service()->discover($this->site(), 25);
            $this->fail('Expected HTML discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'HTML listing returned HTTP 404: https://example.com/news',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    private function service(): ArticleDiscoveryService
    {
        return new ArticleDiscoveryService(new SiteProbeService);
    }

    private function site(): MonitoredSite
    {
        return new MonitoredSite([
            'name' => 'Example',
            'base_url' => 'https://example.com/',
            'source_type' => 'html',
            'listing_url' => 'https://example.com/news',
            'enabled' => true,
        ]);
    }
}
