<?php

namespace App\Services\Monitoring;

use App\Models\EmailSubjectKeyword;
use App\Models\GmailMonitorState;
use App\Models\GmailProcessingMessage;
use Closure;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class GmailMonitorService
{
    private const SKIPPED_LABELS = ['SPAM', 'SENT', 'DRAFT', 'TRASH'];

    public function __construct(
        private readonly GmailApiClient $gmail,
        private readonly EmailSubjectMatcher $matcher,
        private readonly GmailReviewExtractor $reviewExtractor,
        private readonly TelegramNotifier $notifier,
    ) {}

    /** @return array{initialized:bool,found:int,matched:int,sent:int,completed:int,pending:int,recovered:bool} */
    public function check(?Closure $heartbeat = null): array
    {
        $startedAt = now();
        $profile = $this->gmail->profile();
        $heartbeat?->__invoke();

        if ($profile['emailAddress'] === '' || $profile['historyId'] === '') {
            throw new RuntimeException('Gmail profile response is missing the email address or history ID.');
        }

        $state = GmailMonitorState::query()->first();

        if ($state && strcasecmp($state->email_address, $profile['emailAddress']) !== 0) {
            throw new RuntimeException(sprintf(
                'Gmail OAuth account changed from %s to %s. Reset Gmail monitoring state explicitly before switching accounts.',
                $state->email_address,
                $profile['emailAddress'],
            ));
        }

        if (! $state) {
            GmailMonitorState::create([
                'email_address' => $profile['emailAddress'],
                'history_id' => $profile['historyId'],
                'initialized_at' => $startedAt,
                'last_checked_at' => $startedAt,
            ]);

            return ['initialized' => true, 'found' => 0, 'matched' => 0, 'sent' => 0, 'completed' => 0, 'pending' => 0, 'recovered' => false];
        }

        $recovered = false;

        try {
            $changes = $this->gmail->history($state->history_id, $heartbeat);
            $messageIds = $changes['message_ids'];
            $nextHistoryId = $changes['history_id'];
        } catch (GmailApiException $exception) {
            if ($exception->status !== 404) {
                throw $exception;
            }

            $recovered = true;
            $fallbackSince = $state->last_checked_at->copy()->subMinutes(5);

            if ($fallbackSince->lt($state->initialized_at)) {
                $fallbackSince = $state->initialized_at;
            }

            $messageIds = $this->gmail->unreadInboxMessagesSince($fallbackSince, $heartbeat);
            $nextHistoryId = $profile['historyId'];
        }

        $keywords = EmailSubjectKeyword::query()->where('enabled', true)->orderBy('id')->get();
        $matched = 0;

        foreach ($messageIds as $messageId) {
            $heartbeat?->__invoke();

            if (GmailProcessingMessage::query()
                ->where('gmail_monitor_state_id', $state->id)
                ->where('gmail_message_id', $messageId)
                ->exists()) {
                continue;
            }

            $message = $this->gmail->message($messageId);

            if ($message === null || ! $this->shouldProcess($message)) {
                continue;
            }

            $subject = $this->header($message, 'Subject');
            $matches = $this->matcher->match($keywords, $subject);

            if ($matches === []) {
                continue;
            }

            GmailProcessingMessage::firstOrCreate(
                ['gmail_monitor_state_id' => $state->id, 'gmail_message_id' => $messageId],
                [
                    'matched_keywords' => array_values(array_unique(array_map(
                        static fn (EmailSubjectKeyword $keyword): string => $keyword->phrase,
                        $matches,
                    ))),
                    'target_labels' => array_values(array_unique(array_map(
                        static fn (EmailSubjectKeyword $keyword): string => $keyword->label_name,
                        $matches,
                    ))),
                ],
            );
            $matched++;
        }

        $state->forceFill([
            'history_id' => $nextHistoryId,
            'last_checked_at' => $startedAt,
        ])->save();

        $delivery = $this->deliverPending($state, $heartbeat);

        return [
            'initialized' => false,
            'found' => count($messageIds),
            'matched' => $matched,
            'sent' => $delivery['sent'],
            'completed' => $delivery['completed'],
            'pending' => $state->processingMessages()->count(),
            'recovered' => $recovered,
        ];
    }

    /** @return array{sent:int,completed:int} */
    private function deliverPending(GmailMonitorState $state, ?Closure $heartbeat): array
    {
        $pending = $state->processingMessages()->orderBy('id')->get();

        if ($pending->isEmpty()) {
            return ['sent' => 0, 'completed' => 0];
        }

        $availableLabels = $this->availableLabels($heartbeat);
        $sent = 0;
        $completed = 0;

        foreach ($pending as $processing) {
            $heartbeat?->__invoke();

            try {
                $message = $this->gmail->message(
                    $processing->gmail_message_id,
                    $processing->telegram_sent_at === null ? 'full' : 'metadata',
                );

                if ($message === null) {
                    $processing->delete();
                    $completed++;

                    continue;
                }

                if ($processing->telegram_sent_at === null) {
                    if (! $this->shouldProcess($message)) {
                        $processing->delete();
                        $completed++;

                        continue;
                    }

                    $extracted = $this->reviewExtractor->extract($processing->gmail_message_id, $message);
                    $labelIds = $this->ensureLabels($processing->target_labels, $availableLabels, $heartbeat);

                    if (! $this->notifier->sendGmailReview(
                        subject: $this->header($message, 'Subject') ?: 'Без темы',
                        receivedAt: $this->receivedAt($message),
                        senderName: $extracted['senderName'],
                        senderEmail: $extracted['senderEmail'],
                        review: $extracted['review'],
                        gmailUrl: $this->gmailUrl((string) ($message['threadId'] ?? '')),
                    )) {
                        throw new RuntimeException('Telegram notification was not accepted.');
                    }

                    $processing->forceFill([
                        'telegram_sent_at' => now(),
                        'attempts' => $processing->attempts + 1,
                        'last_error' => null,
                    ])->save();
                    $sent++;
                } else {
                    $labelIds = $this->ensureLabels($processing->target_labels, $availableLabels, $heartbeat);
                }

                $this->gmail->markProcessed(
                    $processing->gmail_message_id,
                    $labelIds,
                );
                $processing->delete();
                $completed++;
            } catch (InvalidGmailLabelException $exception) {
                $processing->forceFill([
                    'attempts' => $processing->attempts + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000, 'UTF-8'),
                ])->save();

                throw $exception;
            } catch (Throwable $exception) {
                $processing->forceFill([
                    'attempts' => $processing->attempts + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 4000, 'UTF-8'),
                ])->save();

                throw $exception;
            }
        }

        return ['sent' => $sent, 'completed' => $completed];
    }

    /** @return array<string,array{id:string,name:string,type:string}> */
    private function availableLabels(?Closure $heartbeat): array
    {
        $existing = [];
        $heartbeat?->__invoke();

        foreach ($this->gmail->labels() as $label) {
            $existing[self::normalizeLabelName($label['name'])] = $label;
        }

        return $existing;
    }

    /**
     * @param  list<string>  $names
     * @param  array<string,array{id:string,name:string,type:string}>  $available
     * @return list<string>
     */
    private function ensureLabels(array $names, array &$available, ?Closure $heartbeat): array
    {
        $resolved = [];

        foreach (array_values(array_unique($names)) as $name) {
            $normalized = self::normalizeLabelName($name);

            if (isset($available[$normalized]) && $available[$normalized]['type'] !== 'user') {
                throw new InvalidGmailLabelException('Gmail label must be a user label: '.$name);
            }

            if (isset($available[$normalized])) {
                $resolved[] = $available[$normalized]['id'];
            } else {
                $heartbeat?->__invoke();

                try {
                    $id = $this->gmail->createLabel($name);
                } catch (GmailApiException $exception) {
                    if ($exception->status === 400) {
                        throw new InvalidGmailLabelException('Gmail rejected the user label: '.$name, previous: $exception);
                    }

                    throw $exception;
                }

                $available[$normalized] = ['id' => $id, 'name' => $name, 'type' => 'user'];
                $resolved[] = $id;
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $message */
    private function shouldProcess(array $message): bool
    {
        $labels = array_map('strval', $message['labelIds'] ?? []);

        return in_array('INBOX', $labels, true)
            && in_array('UNREAD', $labels, true)
            && array_intersect(self::SKIPPED_LABELS, $labels) === [];
    }

    /** @param array<string,mixed> $message */
    private function header(array $message, string $name): string
    {
        foreach ($message['payload']['headers'] ?? [] as $header) {
            if (strcasecmp((string) ($header['name'] ?? ''), $name) !== 0) {
                continue;
            }

            $value = trim((string) ($header['value'] ?? ''));
            $decoded = str_contains($value, '=?')
                ? iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8')
                : $value;

            return trim(preg_replace('~\s+~u', ' ', $decoded === false ? $value : $decoded) ?? $value);
        }

        return '';
    }

    /** @param array<string,mixed> $message */
    private function receivedAt(array $message): ?Carbon
    {
        $milliseconds = (int) ($message['internalDate'] ?? 0);

        return $milliseconds > 0 ? Carbon::createFromTimestampUTC((int) floor($milliseconds / 1000)) : null;
    }

    private static function normalizeLabelName(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    private function gmailUrl(string $threadId): ?string
    {
        return $threadId === ''
            ? null
            : 'https://mail.google.com/mail/u/0/?tab=rm&ogbl#all/'.rawurlencode($threadId);
    }
}
