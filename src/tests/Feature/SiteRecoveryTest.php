<?php

namespace Tests\Feature;

use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Services\Monitoring\ArticleDiscoveryService;
use App\Services\Monitoring\SourceHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-27 12:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('recoverableStates')]
    public function test_successful_parser_check_enables_site_and_resets_health(bool $enabled, string $sourceType): void
    {
        $site = $this->site(['enabled' => $enabled, 'source_type' => $sourceType]);
        // A stale claim must not prevent recovery indefinitely.
        $site->update([
            'check_pending_at' => now()->subHours(SourceHealthService::CHECK_CLAIM_TIMEOUT_HOURS + 1),
            'check_claim_token' => 'stale-claim',
        ]);
        $this->fakeWorkingSource();

        $this->withSession(['site_access_granted' => true])
            ->postJson('/sites/'.$site->id.'/recover')
            ->assertOk()
            ->assertSessionHas('site_recovery_success');

        $site->refresh();
        $this->assertTrue($site->enabled);
        $this->assertSame(0, $site->consecutive_failures);
        $this->assertTrue($site->last_checked_at->equalTo(now()));
        $this->assertTrue($site->last_success_at->equalTo(now()));
        $this->assertTrue($site->next_check_at->equalTo(now()));

        foreach (['paused_at', 'disabled_at', 'disabled_reason', 'last_error_at', 'last_error', 'last_error_type', 'check_pending_at', 'check_claim_token'] as $field) {
            $this->assertNull($site->$field, $field);
        }

        Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/news/first');
        $this->assertDatabaseCount('articles', 0);
        $this->get('/sites')->assertSee('Перевірка успішна.')->assertDontSee('data-recover-url=', false);
    }

    public static function recoverableStates(): array
    {
        return [
            'disabled HTML source' => [false, 'html'],
            'paused HTML source' => [true, 'html'],
            'disabled RSS source' => [false, 'rss'],
        ];
    }

    #[DataProvider('failedSources')]
    public function test_failed_check_shows_reason_and_preserves_site_state(bool $enabled, string $failure, string $reason): void
    {
        $site = $this->site(['enabled' => $enabled]);
        $before = $site->fresh()->getAttributes();

        Http::fake([
            'https://example.com/news' => match ($failure) {
                'http' => Http::response('Forbidden', 403),
                'empty' => Http::response('<html><body>No publications</body></html>'),
                default => Http::response('<a href="/news/first">First article</a>'),
            },
            'https://example.com/news/first' => Http::response('<html><body></body></html>'),
        ]);

        $this->withSession(['site_access_granted' => true])
            ->postJson('/sites/'.$site->id.'/recover')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site')
            ->assertJsonPath('errors.site.0', fn (string $message): bool => str_contains($message, $reason));

        $site->refresh();
        foreach ($before as $field => $value) {
            if ($field !== 'updated_at') {
                $this->assertSame($value, $site->getAttributes()[$field], $field);
            }
        }
    }

    public static function failedSources(): array
    {
        return [
            'disabled, HTTP error' => [false, 'http', 'HTTP 403'],
            'paused, HTTP error' => [true, 'http', 'HTTP 403'],
            'no publications' => [false, 'empty', 'Не знайдено публікацій'],
            'unreadable article' => [true, 'article', 'Не вдалося прочитати текст статті'],
        ];
    }

    public function test_active_site_and_in_progress_checks_are_rejected_without_http_requests(): void
    {
        $site = $this->site(['enabled' => true, 'paused_at' => null]);

        $this->withSession(['site_access_granted' => true])
            ->postJson('/sites/'.$site->id.'/recover')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site');

        $site->update(['paused_at' => now(), 'check_pending_at' => now(), 'check_claim_token' => 'worker-claim']);

        $this->postJson('/sites/'.$site->id.'/recover')
            ->assertUnprocessable()
            ->assertJsonPath('errors.site.0', 'Сайт уже перевіряється. Спробуйте пізніше.');

        $this->assertSame('worker-claim', $site->fresh()->check_claim_token);
        Http::assertNothingSent();
    }

    public function test_recovery_does_not_overwrite_a_replaced_claim(): void
    {
        $site = $this->site(['enabled' => true]);
        $discovery = Mockery::mock(ArticleDiscoveryService::class);
        $discovery->shouldReceive('discover')->once()->andReturnUsing(function (MonitoredSite $checkingSite): array {
            $this->assertNotNull($checkingSite->check_claim_token);
            $this->assertNull(app(SourceHealthService::class)->reserveCheck($checkingSite, bypassSchedule: true));
            $checkingSite->newQuery()->whereKey($checkingSite->id)->update(['check_claim_token' => 'replacement-claim']);

            return [['url' => 'https://example.com/news/first']];
        });
        $this->app->instance(ArticleDiscoveryService::class, $discovery);
        $this->fakeWorkingSource();

        $this->withSession(['site_access_granted' => true])
            ->postJson('/sites/'.$site->id.'/recover')
            ->assertUnprocessable()
            ->assertJsonPath('errors.site.0', 'Стан перевірки змінився. Оновіть сторінку та спробуйте ще раз.');

        $this->assertNotNull($site->fresh()->paused_at);
        $this->assertSame('replacement-claim', $site->fresh()->check_claim_token);
    }

    public function test_recovery_requires_site_access_and_an_existing_site(): void
    {
        $site = $this->site();
        $this->postJson('/sites/'.$site->id.'/recover')->assertRedirect('/access');
        $this->withSession(['site_access_granted' => true])->postJson('/sites/9999/recover')->assertNotFound();
        Http::assertNothingSent();
        $this->assertFalse($site->fresh()->enabled);
    }

    public function test_button_only_appears_for_disabled_or_paused_sites(): void
    {
        $disabled = $this->site();
        $paused = $this->site(['base_url' => 'https://paused.example.com', 'enabled' => true]);
        $active = $this->site(['base_url' => 'https://active.example.com', 'enabled' => true, 'paused_at' => null, 'last_error_at' => null]);
        $error = $this->site(['base_url' => 'https://error.example.com', 'enabled' => true, 'paused_at' => null]);

        $this->withSession(['site_access_granted' => true])->get('/sites')
            ->assertOk()
            ->assertSee('data-recover-url="/sites/'.$disabled->id.'/recover"', false)
            ->assertSee('data-recover-url="/sites/'.$paused->id.'/recover"', false)
            ->assertDontSee('data-recover-url="/sites/'.$active->id.'/recover"', false)
            ->assertDontSee('data-recover-url="/sites/'.$error->id.'/recover"', false);

        TelegramChannel::create(['username' => 'disabled_channel', 'url' => 'https://t.me/disabled_channel', 'enabled' => false]);
        $this->get('/telegram')->assertOk()->assertDontSee('data-recover-url=', false);
    }

    private function site(array $attributes = []): MonitoredSite
    {
        return MonitoredSite::create(array_merge([
            'name' => 'Example News',
            'base_url' => 'https://example.com',
            'listing_url' => 'https://example.com/news',
            'feed_url' => 'https://example.com/feed',
            'content_selector' => '.article-body',
            'article_url_pattern' => '~^/news/first$~',
            'source_type' => 'html',
            'enabled' => false,
            'consecutive_failures' => 4,
            'paused_at' => now()->subHours(4),
            'disabled_at' => now()->subHour(),
            'disabled_reason' => 'Previous failure',
            'last_checked_at' => now()->subHour(),
            'next_check_at' => now()->addHours(4),
            'last_error_at' => now()->subHour(),
            'last_error' => 'Previous HTTP 404',
            'last_error_type' => 'permanent',
        ], $attributes));
    }

    private function fakeWorkingSource(): void
    {
        Http::fake([
            'https://example.com/news' => Http::response('<a href="/news/first">First article</a>'),
            'https://example.com/feed' => Http::response('<rss version="2.0"><channel><item><link>https://example.com/news/first</link><title>First article</title></item></channel></rss>'),
            'https://example.com/news/first' => Http::response('<html><body><div class="article-body">Readable article text</div></body></html>'),
        ]);
    }
}
