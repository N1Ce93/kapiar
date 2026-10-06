<?php

namespace App\Services\Monitoring;

use Closure;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GmailApiClient
{
    private const API_BASE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private ?string $lastAccessToken = null;

    public function __construct(private readonly GmailQuotaLimiter $quotaLimiter = new GmailQuotaLimiter) {}

    /** @return array{emailAddress:string,historyId:string} */
    public function profile(): array
    {
        $data = $this->successful($this->request('GET', '/profile'), 'profile')->json();

        return [
            'emailAddress' => (string) ($data['emailAddress'] ?? ''),
            'historyId' => (string) ($data['historyId'] ?? ''),
        ];
    }

    /** @return array{message_ids:list<string>,history_id:string} */
    public function history(string $startHistoryId, ?Closure $heartbeat = null): array
    {
        $messageIds = [];
        $historyId = $startHistoryId;
        $pageToken = null;

        do {
            $heartbeat?->__invoke();
            $query = [
                'startHistoryId' => $startHistoryId,
                'historyTypes' => 'messageAdded',
                'maxResults' => 500,
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', '/history', query: $query);

            if ($response->status() === 404) {
                throw new GmailApiException(404, 'history');
            }

            $data = $this->successful($response, 'history')->json();
            $historyId = (string) ($data['historyId'] ?? $historyId);

            foreach ($data['history'] ?? [] as $history) {
                foreach ($history['messagesAdded'] ?? [] as $added) {
                    $messageId = (string) ($added['message']['id'] ?? '');

                    if ($messageId !== '') {
                        $messageIds[$messageId] = true;
                    }
                }
            }

            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null && $pageToken !== '');

        return ['message_ids' => array_keys($messageIds), 'history_id' => $historyId];
    }

    /** @return list<string> */
    public function unreadInboxMessagesSince(DateTimeInterface $since, ?Closure $heartbeat = null): array
    {
        $messageIds = [];
        $pageToken = null;

        do {
            $heartbeat?->__invoke();
            $query = [
                'q' => 'in:inbox is:unread after:'.$since->getTimestamp(),
                'includeSpamTrash' => 'false',
                'maxResults' => 500,
            ];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $data = $this->successful($this->request('GET', '/messages', query: $query), 'messages list')->json();

            foreach ($data['messages'] ?? [] as $message) {
                $messageId = (string) ($message['id'] ?? '');

                if ($messageId !== '') {
                    $messageIds[$messageId] = true;
                }
            }

            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
        } while ($pageToken !== null && $pageToken !== '');

        return array_keys($messageIds);
    }

    /** @return array<string,mixed>|null */
    public function message(string $messageId, string $format = 'metadata'): ?array
    {
        if (! in_array($format, ['metadata', 'full'], true)) {
            throw new RuntimeException('Unsupported Gmail message format: '.$format);
        }

        $response = $this->request('GET', '/messages/'.rawurlencode($messageId), query: ['format' => $format]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->successful($response, 'message get')->json();
    }

    public function attachmentData(string $messageId, string $attachmentId): string
    {
        $response = $this->request(
            'GET',
            '/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId),
        );

        return (string) $this->successful($response, 'message attachment get')->json('data', '');
    }

    /** @return list<array{id:string,name:string,type:string}> */
    public function labels(): array
    {
        $data = $this->successful($this->request('GET', '/labels'), 'labels list')->json();

        return array_values(array_filter(array_map(
            static fn (array $label): array => [
                'id' => (string) ($label['id'] ?? ''),
                'name' => (string) ($label['name'] ?? ''),
                'type' => (string) ($label['type'] ?? ''),
            ],
            $data['labels'] ?? [],
        ), static fn (array $label): bool => $label['id'] !== '' && $label['name'] !== ''));
    }

    public function createLabel(string $name): string
    {
        $data = $this->successful($this->request('POST', '/labels', json: [
            'name' => $name,
            'labelListVisibility' => 'labelShow',
            'messageListVisibility' => 'show',
        ]), 'label create')->json();
        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            throw new RuntimeException('Gmail API label create response did not contain an ID.');
        }

        return $id;
    }

    /** @param list<string> $labelIds */
    public function markProcessed(string $messageId, array $labelIds): void
    {
        $this->successful($this->request('POST', '/messages/'.rawurlencode($messageId).'/modify', json: [
            'addLabelIds' => array_values(array_unique($labelIds)),
            'removeLabelIds' => ['UNREAD'],
        ]), 'message modify');
    }

    private function request(string $method, string $path, array $query = [], array $json = [], bool $retryUnauthorized = true): Response
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($json !== []) {
            $options['json'] = $json;
        }

        $maxRetries = max(0, (int) config('services.gmail.max_retries', 3));
        $retries = 0;

        while (true) {
            $this->lastAccessToken = $this->accessToken();
            $this->quotaLimiter->acquire($this->quotaCost($method, $path));
            $response = Http::acceptJson()
                ->withToken($this->lastAccessToken)
                ->timeout(30)
                ->send($method, self::API_BASE_URL.$path, $options);

            if ($response->status() === 401 && $retryUnauthorized) {
                Cache::forget($this->tokenCacheKey());
                $retryUnauthorized = false;

                continue;
            }

            $delay = $this->retryDelay($response, $retries);

            if ($delay === null || $retries >= $maxRetries) {
                return $response;
            }

            // A shared cooldown also prevents another process from immediately spending the same quota.
            $this->quotaLimiter->cooldown($delay);
            $retries++;
        }
    }

    private function quotaCost(string $method, string $path): int
    {
        return match (true) {
            $path === '/profile' => 1,
            $path === '/history' => 2,
            $path === '/messages' => 5,
            $path === '/labels' && $method === 'GET' => 1,
            $path === '/labels', str_ends_with($path, '/modify') => 5,
            default => 20, // messages.get and messages.attachments.get, including metadata reads.
        };
    }

    private function retryDelay(Response $response, int $retries): ?int
    {
        $reasons = $response->status() === 403 ? $this->errorReasons($response) : [];
        $quotaError = $response->status() === 403
            && array_intersect($reasons, ['dailyLimitExceeded', 'domainPolicy', 'insufficientPermissions']) === []
            && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'RATE_LIMIT_EXCEEDED']) !== [];

        if (! $quotaError && ! in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            return null;
        }

        $delay = $quotaError || $response->status() === 429 ? 65 : min(60, 2 ** min($retries, 6));
        $retryAfter = trim((string) $response->header('Retry-After'));

        if ($retryAfter !== '') {
            $seconds = ctype_digit($retryAfter)
                ? (int) $retryAfter
                : max(0, (strtotime($retryAfter) ?: 0) - now()->getTimestamp());
            $delay = max($delay, $seconds);
        }

        // Do not retry earlier than Google requests or sleep beyond the worker's time budget.
        return $delay <= (int) config('services.gmail.max_retry_delay_seconds', 120) ? $delay : null;
    }

    /** @return list<string> */
    private function errorReasons(Response $response): array
    {
        $reasons = [];

        foreach ((array) $response->json('error.errors', []) as $error) {
            if (is_array($error) && is_string($error['reason'] ?? null)) {
                $reasons[] = $error['reason'];
            }
        }

        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && ($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.ErrorInfo'
                && is_string($detail['reason'] ?? null)) {
                $reasons[] = $detail['reason'];
            }
        }

        return array_values(array_unique($reasons));
    }

    private function accessToken(): string
    {
        $this->assertConfigured();
        $cacheKey = $this->tokenCacheKey();
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->acceptJson()->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('services.gmail.client_id'),
            'client_secret' => config('services.gmail.client_secret'),
            'refresh_token' => config('services.gmail.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $error = trim((string) $response->json('error', ''));
            $description = trim((string) $response->json('error_description', ''));
            $detail = trim($error.($description === '' ? '' : ': '.$description));

            throw new GmailApiException(
                $response->status(),
                'OAuth token refresh',
                $detail === '' ? null : $this->redactDetail($detail),
            );
        }

        $token = (string) $response->json('access_token', '');

        if ($token === '') {
            throw new RuntimeException('Gmail OAuth response did not contain an access token.');
        }

        $ttl = max(1, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($cacheKey, $token, $ttl);

        return $token;
    }

    private function successful(Response $response, string $operation): Response
    {
        if (! $response->successful()) {
            throw new GmailApiException($response->status(), $operation, $this->errorDetail($response));
        }

        return $response;
    }

    private function errorDetail(Response $response): ?string
    {
        $detail = [];

        foreach (['message', 'status'] as $field) {
            $value = $response->json('error.'.$field);

            if (is_string($value) && $value !== '') {
                $detail[$field] = $value;
            }
        }

        $reasons = $this->errorReasons($response);

        if ($reasons !== []) {
            $detail['reasons'] = $reasons;
        }

        if ($response->header('Retry-After') !== null) {
            $detail['retry_after'] = $response->header('Retry-After');
        }

        foreach ((array) $response->json('error.details', []) as $entry) {
            if (! is_array($entry) || ($entry['@type'] ?? '') !== 'type.googleapis.com/google.rpc.ErrorInfo') {
                continue;
            }

            foreach (['quota_metric', 'quota_limit', 'quota_limit_value', 'quota_unit'] as $field) {
                $value = $entry['metadata'][$field] ?? null;

                if (is_scalar($value)) {
                    $detail[$field] = $value;
                }
            }
        }

        return $detail === [] ? null : $this->redactDetail((string) json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function redactDetail(string $detail): string
    {
        foreach (array_filter([
            (string) config('services.gmail.client_secret'),
            (string) config('services.gmail.refresh_token'),
            $this->lastAccessToken,
        ]) as $secret) {
            $detail = str_replace($secret, '[redacted]', $detail);
        }

        return mb_substr($detail, 0, 2000, 'UTF-8');
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! config('services.gmail.'.$key)) {
                throw new RuntimeException('Gmail OAuth is not configured. Missing services.gmail.'.$key.'.');
            }
        }
    }

    private function tokenCacheKey(): string
    {
        return 'gmail:oauth:access-token:'.hash('sha256', (string) config('services.gmail.client_id'));
    }
}
