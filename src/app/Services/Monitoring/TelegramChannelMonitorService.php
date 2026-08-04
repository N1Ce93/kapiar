<?php

namespace App\Services\Monitoring;

use App\Models\Keyword;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageKeywordHit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class TelegramChannelMonitorService
{
    public function __construct(
        private readonly TelegramClientService $clientService,
        private readonly KeywordMatcher $keywordMatcher,
        private readonly TelegramNotifier $telegramNotifier,
    ) {
    }

    /** @return array{title:?string,telegram_peer:string} */
    public function resolveChannel(string $peer): array
    {
        $client = $this->authenticatedClient();
        $info = $client->getInfo($peer);
        $constructor = is_array($info) ? ($info['Chat'] ?? $info['User'] ?? $info) : [];

        return [
            'title' => is_array($constructor) ? ($constructor['title'] ?? $constructor['first_name'] ?? null) : null,
            'telegram_peer' => $peer,
        ];
    }

    /** @return array{found:int,created:int,skipped:int,analyzed:int,hits:int,sent:int} */
    public function ingestChannel(TelegramChannel $channel, int $limit, bool $backfill, bool $analyze, bool $notify): array
    {
        $messages = $this->fetchMessages($channel, $limit, $backfill ? null : $channel->last_message_id);
        $stats = ['found' => count($messages), 'created' => 0, 'skipped' => 0, 'analyzed' => 0, 'hits' => 0, 'sent' => 0];
        $maxMessageId = $channel->last_message_id ?? 0;

        foreach ($messages as $item) {
            $maxMessageId = max($maxMessageId, $item['message_id']);

            $message = TelegramMessage::firstOrCreate(
                ['telegram_channel_id' => $channel->id, 'message_id' => $item['message_id']],
                [
                    'text' => $item['text'],
                    'url' => $item['url'],
                    'posted_at' => $item['posted_at'],
                    'is_backfilled' => $backfill,
                ],
            );

            if (! $message->wasRecentlyCreated) {
                $stats['skipped']++;

                if ($backfill && $analyze && $message->checked_at === null) {
                    $processStats = $this->processMessage($message, notify: false);
                    $stats['analyzed']++;
                    $stats['hits'] += $processStats['hits'];
                }

                continue;
            }

            $stats['created']++;

            if ($analyze) {
                $processStats = $this->processMessage($message, $notify);
                $stats['analyzed']++;
                $stats['hits'] += $processStats['hits'];
                $stats['sent'] += $processStats['sent'];
            }
        }

        $channel->forceFill(($backfill ? ['last_backfilled_at' => now()] : ['last_checked_at' => now()]) + [
            'last_message_id' => $maxMessageId ?: null,
        ])->save();

        return $stats;
    }

    /** @return array{hits:int,sent:int} */
    public function processMessage(TelegramMessage $message, bool $notify): array
    {
        $text = trim((string) $message->text);

        if ($text === '') {
            $message->forceFill(['checked_at' => now()])->save();

            return ['hits' => 0, 'sent' => 0];
        }

        $matches = $this->keywordMatcher->match(Keyword::where('enabled', true)->get(), $text);

        foreach ($matches as $match) {
            TelegramMessageKeywordHit::updateOrCreate(
                ['telegram_message_id' => $message->id, 'keyword_id' => $match['keyword']->id],
                ['matched_text' => $match['matched_text'], 'context' => $match['context']],
            );
        }

        $message->forceFill(['checked_at' => now()])->save();
        $sent = 0;

        if ($notify && $matches !== []) {
            $message->loadMissing('channel');
            $keywords = array_values(array_unique(array_map(fn (array $match): string => $match['keyword']->phrase, $matches)));
            $context = $matches[0]['context'] ?? null;

            if ($this->telegramNotifier->sendTelegramChannelMention($message, $keywords, $context)) {
                $message->forceFill(['notified_at' => Carbon::now()])->save();
                $sent = 1;
            }
        }

        return ['hits' => count($matches), 'sent' => $sent];
    }

    /** @return list<array{message_id:int,text:string,url:string,posted_at:?CarbonImmutable}> */
    private function fetchMessages(TelegramChannel $channel, int $limit, ?int $afterMessageId): array
    {
        $client = $this->authenticatedClient();
        $peer = $channel->telegram_peer ?: '@'.$channel->username;
        $response = $client->messages->getHistory(peer: $peer, limit: $limit);
        $messages = [];

        foreach (($response['messages'] ?? []) as $message) {
            if (($message['_'] ?? '') !== 'message') {
                continue;
            }

            $id = (int) ($message['id'] ?? 0);
            $text = trim((string) ($message['message'] ?? ''));

            if ($id <= 0 || $text === '') {
                continue;
            }

            if ($afterMessageId !== null && $id <= $afterMessageId) {
                continue;
            }

            $messages[] = [
                'message_id' => $id,
                'text' => $text,
                'url' => 'https://t.me/'.$channel->username.'/'.$id,
                'posted_at' => isset($message['date']) ? CarbonImmutable::createFromTimestamp((int) $message['date']) : null,
            ];
        }

        usort($messages, fn (array $a, array $b): int => $a['message_id'] <=> $b['message_id']);

        return $messages;
    }

    private function authenticatedClient()
    {
        try {
            return $this->clientService->client();
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }
}
