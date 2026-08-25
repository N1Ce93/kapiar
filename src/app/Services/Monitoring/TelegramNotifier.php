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

    public function sendMessage(
        string $message,
        ?string $parseMode = null,
        bool $disableWebPagePreview = false,
        ?int $messageThreadId = null,
    ): bool {
        $token = config('services.telegram.bot_token');
        $chatId = config('services.telegram.chat_id');

        if (! $token || ! $chatId) {
            Log::info('Telegram is not configured; message was not sent.');

            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $parseMode === null ? mb_substr($message, 0, 4000, 'UTF-8') : $message,
            'disable_web_page_preview' => $disableWebPagePreview,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        $replyToChatId = config('services.telegram.reply_to_chat_id');
        $replyToMessageId = config('services.telegram.reply_to_message_id');

        if ($messageThreadId === null && $replyToChatId && $replyToMessageId && (string) $chatId === (string) $replyToChatId) {
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

        if ($response->json('ok') !== true) {
            Log::warning('Telegram send returned an invalid success response.', [
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

    public function sendGmailReview(
        string $subject,
        ?Carbon $receivedAt,
        string $senderName,
        string $senderEmail,
        string $review,
        ?string $gmailUrl,
    ): bool {
        $date = $receivedAt?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '-';
        $subject = trim($subject);
        $senderName = trim($senderName);
        $senderEmail = trim($senderEmail);

        if (mb_strlen($subject, 'UTF-8') > 500) {
            $subject = mb_substr($subject, 0, 497, 'UTF-8').'...';
        }

        $review = trim($review) !== '' ? trim($review) : 'Отзыв отсутствует';
        $truncated = "\n\n[текст сокращён]";
        $threadId = config('services.telegram.review_thread_id');

        if (mb_strlen($review, 'UTF-8') > 3000) {
            $review = mb_substr($review, 0, 3000 - mb_strlen($truncated, 'UTF-8'), 'UTF-8').$truncated;
        }

        $link = $gmailUrl === null
            ? ''
            : sprintf("\n<b>Письмо:</b> <a href=\"%s\">Открыть в Gmail</a>", $this->escapeHtml($gmailUrl));
        $sender = ($senderName === '' ? '' : sprintf("\n<b>Отправитель:</b> %s", $this->escapeHtml($senderName)))
            .($senderEmail === '' ? '' : sprintf("\n<b>Email:</b> %s", $this->escapeHtml($senderEmail)));

        return $this->sendMessage(sprintf(
            "<b>Новый отзыв</b>\n\n<b>Дата:</b> %s\n<b>Тема:</b> %s%s%s\n\n<b>Отзыв:</b>\n<blockquote expandable>%s</blockquote>",
            $this->escapeHtml($date),
            $this->escapeHtml($subject),
            $sender,
            $link,
            $this->escapeHtml($review),
        ), 'HTML', true, is_numeric($threadId) ? (int) $threadId : null);
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

    private function redact(string $text): string
    {
        $token = (string) config('services.telegram.bot_token');
        $apiHash = (string) config('services.telegram.api_hash');

        foreach (array_filter([$token, $apiHash]) as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return preg_replace('~bot\d+:[A-Za-z0-9_-]+~', 'bot[redacted]', $text) ?? $text;
    }

    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
    }
}
