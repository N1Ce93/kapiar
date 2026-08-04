<?php

namespace App\Services\Monitoring;

use App\Models\Article;
use App\Models\TelegramMessage;
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

        try {
            $response = Http::asForm()->timeout(15)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => mb_substr($message, 0, 4000, 'UTF-8'),
                'disable_web_page_preview' => false,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Telegram send failed.', ['error' => $exception->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Telegram send returned non-success response.', ['status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        return true;
    }

    /** @param list<string> $keywords */
    public function sendTelegramChannelMention(TelegramMessage $message, array $keywords, ?string $context = null): bool
    {
        $channel = $message->channel;
        $text = trim(sprintf(
            "Найдено упоминание в Telegram\n\nКанал: @%s\nКлючевые слова: %s\nДата поста: %s\nСсылка: %s%s",
            $channel?->username ?? 'unknown',
            implode(', ', $keywords),
            $message->posted_at?->format('Y-m-d H:i') ?? '-',
            $message->url ?: '-',
            $context ? "\n\nФрагмент:\n".$context : '',
        ));

        return $this->sendMessage($text);
    }
}
