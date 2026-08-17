<?php

namespace App\Services\Monitoring;

use App\Models\Article;
use App\Models\TelegramMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramNotifier
{
    /** @param list<string> $keywords */
    public function sendArticleMention(Article $article, array $keywords): bool
    {
        $sent = $this->sendMessage($this->articleMentionMessage(
            $article->site?->name ?? 'unknown',
            $keywords,
            $article->title ?: 'Без заголовка',
            $article->url,
        ));

        if (! $sent) {
            Log::info('Telegram article mention was not sent.', ['article_id' => $article->id]);
        }

        return $sent;
    }

    /** @param list<string> $keywords */
    public function articleMentionMessage(string $site, array $keywords, string $title, string $url): string
    {
        return trim(sprintf(
            "Найдено упоминание\n\nСайт: %s\nКлючевые слова: %s\nЗаголовок: %s\nСсылка: %s",
            $site,
            implode(', ', $keywords),
            $title,
            $url,
        ));
    }

    public function sendMessage(string $message): bool
    {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            Log::info('Telegram is not configured; message was not sent.');

            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($message, 0, 4000, 'UTF-8'),
            'disable_web_page_preview' => false,
        ];

        $replyToChatId = config('services.telegram.reply_to_chat_id');
        $replyToMessageId = config('services.telegram.reply_to_message_id');

        if ($replyToChatId && $replyToMessageId && (string) $chatId === (string) $replyToChatId) {
            $payload['reply_to_message_id'] = (int) $replyToMessageId;
        }

        try {
            $response = Http::asForm()->timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', $payload);
        } catch (Throwable $exception) {
            Log::warning('Telegram send failed.', ['error' => $this->redact((string) $exception->getMessage())]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Telegram send returned non-success response.', [
                'status' => $response->status(),
                'body' => $this->redact($response->body()),
            ]);

            return false;
        }

        return true;
    }

    /** @param list<string> $keywords */
    public function sendTelegramChannelMention(TelegramMessage $message, array $keywords, ?string $context = null): bool
    {
        $channel = $message->channel;
        $postedAt = $message->posted_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '-';
        $text = trim(sprintf(
            "Найдено упоминание в Telegram\n\nКанал: @%s\nКлючевые слова: %s\nДата поста: %s\nСсылка: %s%s",
            $channel?->username ?? 'unknown',
            implode(', ', $keywords),
            $postedAt,
            $message->url ?: '-',
            $context ? "\n\nФрагмент:\n".$context : '',
        ));

        return $this->sendMessage($text);
    }

    public function sendSourceDisabled(string $type, int $id, string $name, string $reason): bool
    {
        return $this->sendMessage(trim(sprintf(
            "Источник автоматически отключён\n\nТип: %s\nID: %d\nИсточник: %s\nПричина: %s",
            $type,
            $id,
            $name,
            mb_substr($this->redact($reason), 0, 1000, 'UTF-8'),
        )));
    }

    public function sendSourcePaused(string $type, int $id, string $name, Carbon $nextCheckAt, string $reason): bool
    {
        return $this->sendMessage(trim(sprintf(
            "Проверка источника приостановлена\n\nТип: %s\nID: %d\nИсточник: %s\nСледующая проверка: %s\nПричина: %s",
            $type,
            $id,
            $name,
            $nextCheckAt->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            mb_substr($this->redact($reason), 0, 1000, 'UTF-8'),
        )));
    }

    public function sendSourceRecovered(string $type, int $id, string $name): bool
    {
        return $this->sendMessage(trim(sprintf(
            "Проверка источника восстановлена\n\nТип: %s\nID: %d\nИсточник: %s",
            $type,
            $id,
            $name,
        )));
    }

    private function redact(string $text): string
    {
        $token = (string) config('services.telegram.bot_token');
        $apiHash = (string) config('services.telegram.api_hash');

        foreach (array_filter([$token, $apiHash]) as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot[redacted]', $text) ?? $text;
    }
}
