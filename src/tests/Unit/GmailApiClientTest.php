<?php

namespace Tests\Unit;

use App\Services\Monitoring\GmailApiClient;
use App\Services\Monitoring\GmailApiException;
use App\Services\Monitoring\GmailQuotaLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GmailApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gmail.client_id' => 'gmail-client-id',
            'services.gmail.client_secret' => 'gmail-client-secret',
            'services.gmail.refresh_token' => 'gmail-refresh-token',
            'services.gmail.quota_units_per_minute' => 4000,
            'services.gmail.max_retries' => 3,
            'services.gmail.max_retry_delay_seconds' => 120,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        Sleep::fake(syncWithCarbon: true);
        Cache::flush();
        Cache::put($this->tokenCacheKey(), 'gmail-access-token', 3600);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_backlog_stays_within_the_shared_minute_budget_even_after_token_rotation(): void
    {
        $times = [];
        Http::fake(function () use (&$times) {
            $times[] = $this->nowInMicroseconds();

            return Http::response(['id' => 'message']);
        });

        for ($i = 0; $i < 301; $i++) {
            if ($i === 150) {
                config(['services.gmail.refresh_token' => 'replacement-refresh-token']);
                Cache::put($this->tokenCacheKey(), 'replacement-access-token', 3600);
            }

            // New client/limiter instances must still share the same budget.
            (new GmailApiClient)->message('message-'.$i);
        }

        $this->assertGreaterThanOrEqual(90_000_000, $times[300] - $times[0]);

        foreach ($times as $start) {
            $requestsInWindow = count(array_filter($times, fn (int $time): bool => $time >= $start && $time < $start + 60_000_000));
            $this->assertLessThanOrEqual(4000, $requestsInWindow * 20);
        }

        Http::assertSentCount(301);
    }

    public function test_the_budget_accounts_for_history_metadata_and_attachment_costs(): void
    {
        $times = [];
        Http::fake(function () use (&$times) {
            $times[] = $this->nowInMicroseconds();

            return Http::response(['id' => 'message', 'emailAddress' => 'monitor@gmail.com', 'historyId' => '101', 'data' => 'attachment']);
        });

        $client = app(GmailApiClient::class);
        $client->profile();
        $client->history('100');
        $client->message('message');
        $client->attachmentData('message', 'attachment');
        $client->message('next-message');

        $this->assertSame([0, 15_000, 45_000, 345_000, 645_000], array_map(fn (int $time): int => $time - $times[0], $times));
    }

    #[DataProvider('quotaReasons')]
    public function test_quota_errors_wait_for_the_window_and_retry_without_refreshing_the_token(string $reason, bool $errorInfoOnly): void
    {
        $times = [];
        Http::fake(function (Request $request) use (&$times, $reason, $errorInfoOnly) {
            $this->assertSame('Bearer gmail-access-token', $request->header('Authorization')[0]);
            $times[] = $this->nowInMicroseconds();

            return count($times) === 1
                ? Http::response($this->quotaError($reason, $errorInfoOnly), 403)
                : Http::response(['id' => 'message']);
        });

        $this->assertSame('message', app(GmailApiClient::class)->message('message')['id']);
        $this->assertSame(65_000_000, $times[1] - $times[0]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));
    }

    public static function quotaReasons(): array
    {
        return [
            'project quota' => ['rateLimitExceeded', false],
            'user quota' => ['userRateLimitExceeded', false],
            'ErrorInfo without legacy errors' => ['RATE_LIMIT_EXCEEDED', true],
        ];
    }

    #[DataProvider('permanentReasons')]
    public function test_permanent_forbidden_errors_are_not_retried(string $reason): void
    {
        $error = [
            'error' => ['message' => 'Access denied.', 'errors' => [['reason' => $reason]]],
        ];

        if ($reason === 'dailyLimitExceeded') {
            $error['error']['details'] = [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'RATE_LIMIT_EXCEEDED',
                'metadata' => ['quota_limit' => 'totalQueryCostPerDay'],
            ]];
        }

        Http::fake(['gmail.googleapis.com/*' => Http::response($error, 403)]);

        try {
            app(GmailApiClient::class)->message('message');
            $this->fail('Expected a permanent Gmail error.');
        } catch (GmailApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertStringContainsString($reason, $exception->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public static function permanentReasons(): array
    {
        return [
            ['domainPolicy'],
            ['insufficientPermissions'],
            ['dailyLimitExceeded'],
        ];
    }

    public function test_exhausted_retries_include_quota_details_and_redact_credentials(): void
    {
        config(['services.gmail.max_retries' => 2]);
        $error = $this->quotaError();
        $error['error']['message'] .= ' gmail-client-secret gmail-refresh-token gmail-access-token';
        Http::fake(['gmail.googleapis.com/*' => Http::response($error, 403)]);

        try {
            app(GmailApiClient::class)->message('message');
            $this->fail('Expected retries to be exhausted.');
        } catch (GmailApiException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertStringContainsString('rateLimitExceeded', $exception->getMessage());
            $this->assertStringContainsString('totalQueryCostPerMinutePerUser', $exception->getMessage());
            $this->assertStringContainsString('6000', $exception->getMessage());
            $this->assertStringContainsString('PERMISSION_DENIED', $exception->getMessage());

            foreach (['gmail-client-secret', 'gmail-refresh-token', 'gmail-access-token'] as $secret) {
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(65)->seconds(), Sleep::for(65)->seconds()]);
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_retry_after_is_honored_for_too_many_requests(string $retryAfter): void
    {
        $start = $this->nowInMicroseconds();
        Http::fake(['gmail.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Too many requests.']], 429, ['Retry-After' => $retryAfter])
            ->push(['id' => 'message'])]);

        $this->assertSame('message', app(GmailApiClient::class)->message('message')['id']);
        $this->assertSame(90_000_000, $this->nowInMicroseconds() - $start);
        Http::assertSentCount(2);
    }

    public static function retryAfterHeaders(): array
    {
        return [
            'seconds' => ['90'],
            'HTTP date' => ['Tue, 06 Oct 2026 12:01:30 GMT'],
        ];
    }

    public function test_a_retry_after_beyond_the_wait_budget_is_not_retried_early(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Try later.'],
        ], 429, ['Retry-After' => '3600'])]);

        try {
            app(GmailApiClient::class)->message('message');
            $this->fail('Expected an excessive retry delay to stop the request.');
        } catch (GmailApiException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertStringContainsString('3600', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_backend_errors_use_exponential_backoff(): void
    {
        Http::fake(['gmail.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Backend error.']], 500)
            ->push(['error' => ['message' => 'Unavailable.']], 503)
            ->push(['id' => 'message'])]);

        $this->assertSame('message', app(GmailApiClient::class)->message('message')['id']);
        Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(2)->seconds()]);
        Http::assertSentCount(3);
    }

    public function test_a_shared_cooldown_cannot_be_shortened_by_another_limiter(): void
    {
        $start = $this->nowInMicroseconds();
        (new GmailQuotaLimiter)->cooldown(90);
        (new GmailQuotaLimiter)->cooldown(5);
        Http::fake(['gmail.googleapis.com/*' => Http::response(['id' => 'message'])]);

        (new GmailApiClient)->message('message');

        $this->assertSame(90_000_000, $this->nowInMicroseconds() - $start);
    }

    public function test_unauthorized_refreshes_the_access_token_only_once(): void
    {
        Http::fake([
            'gmail.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Expired access token.']], 401)
                ->push(['error' => ['message' => 'Invalid credentials.']], 401),
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'new-access-token', 'expires_in' => 3600]),
        ]);

        try {
            app(GmailApiClient::class)->message('message');
            $this->fail('Expected a second unauthorized response to stop the request.');
        } catch (GmailApiException $exception) {
            $this->assertSame(401, $exception->status);
        }

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer new-access-token']);
        $this->assertSame('new-access-token', Cache::get($this->tokenCacheKey()));
    }

    private function quotaError(string $reason = 'rateLimitExceeded', bool $errorInfoOnly = false): array
    {
        return ['error' => [
            'message' => 'Quota exceeded for Units per minute per user.',
            'status' => 'PERMISSION_DENIED',
            'errors' => $errorInfoOnly ? [] : [['reason' => $reason]],
            'details' => [[
                '@type' => 'type.googleapis.com/google.rpc.ErrorInfo',
                'reason' => 'RATE_LIMIT_EXCEEDED',
                'metadata' => [
                    'quota_metric' => 'gmail.googleapis.com/total_query_cost',
                    'quota_limit' => 'totalQueryCostPerMinutePerUser',
                    'quota_limit_value' => '6000',
                    'quota_unit' => '1/min/{project}/{user}',
                ],
            ]],
        ]];
    }

    private function nowInMicroseconds(): int
    {
        return (int) now()->format('Uu');
    }

    private function tokenCacheKey(): string
    {
        return 'gmail:oauth:access-token:'.hash('sha256', 'gmail-client-id');
    }
}
