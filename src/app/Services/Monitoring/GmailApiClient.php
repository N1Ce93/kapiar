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

        $response = Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout(30)
            ->send($method, self::API_BASE_URL.$path, $options);

        if ($response->status() === 401 && $retryUnauthorized) {
            Cache::forget($this->tokenCacheKey());

            return $this->request($method, $path, $query, $json, false);
        }

        return $response;
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
                $detail === '' ? null : mb_substr($detail, 0, 1000, 'UTF-8'),
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
            throw new GmailApiException($response->status(), $operation);
        }

        return $response;
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
