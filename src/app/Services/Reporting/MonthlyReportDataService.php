<?php

namespace App\Services\Reporting;

use App\Models\Article;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MonthlyReportDataService
{
    /**
     * @return array{
     *     sites:list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}>,
     *     telegram:list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}>
     * }
     */
    public function forPeriod(Carbon $start, Carbon $end): array
    {
        return [
            'sites' => $this->siteGroups($start, $end),
            'telegram' => $this->telegramGroups($start, $end),
        ];
    }

    /** @return list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}> */
    private function siteGroups(Carbon $start, Carbon $end): array
    {
        $dateSql = 'COALESCE(articles.published_at, articles.discovered_at, articles.created_at)';

        return MonitoredSite::query()
            ->whereHas('articles', function ($query) use ($dateSql, $start, $end): void {
                $query
                    ->whereHas('hits')
                    ->whereRaw("{$dateSql} >= ? AND {$dateSql} < ?", [$start, $end]);
            })
            ->with(['articles' => function ($query) use ($dateSql, $start, $end): void {
                $query
                    ->whereHas('hits')
                    ->whereRaw("{$dateSql} >= ? AND {$dateSql} < ?", [$start, $end])
                    ->with('hits.keyword')
                    ->orderByRaw("{$dateSql} DESC");
            }])
            ->get()
            ->map(fn (MonitoredSite $site): array => [
                'name' => $site->name,
                'items' => $site->articles
                    ->map(fn (Article $article): array => [
                        'date' => $article->published_at ?: $article->discovered_at ?: $article->created_at,
                        'name' => $this->normalizeText($article->title ?: $article->url),
                        'url' => $article->url,
                        'keywords' => $this->keywordPhrases($article->hits),
                    ])
                    ->values()
                    ->all(),
            ])
            ->sort($this->groupSorter(...))
            ->values()
            ->all();
    }

    /** @return list<array{name:string,items:list<array{date:Carbon,name:string,url:string,keywords:string}>}> */
    private function telegramGroups(Carbon $start, Carbon $end): array
    {
        $dateSql = 'COALESCE(telegram_messages.posted_at, telegram_messages.created_at)';

        return TelegramChannel::query()
            ->whereHas('messages', function ($query) use ($dateSql, $start, $end): void {
                $query
                    ->whereHas('hits')
                    ->whereRaw("{$dateSql} >= ? AND {$dateSql} < ?", [$start, $end]);
            })
            ->with(['messages' => function ($query) use ($dateSql, $start, $end): void {
                $query
                    ->whereHas('hits')
                    ->whereRaw("{$dateSql} >= ? AND {$dateSql} < ?", [$start, $end])
                    ->with('hits.keyword')
                    ->orderByRaw("{$dateSql} DESC");
            }])
            ->get()
            ->map(fn (TelegramChannel $channel): array => [
                'name' => $channel->title ?: '@'.$channel->username,
                'items' => $channel->messages
                    ->map(fn (TelegramMessage $message): array => [
                        'date' => $message->posted_at ?: $message->created_at,
                        'name' => $this->telegramMessageTitle($message),
                        'url' => $message->url ?: '',
                        'keywords' => $this->keywordPhrases($message->hits),
                    ])
                    ->values()
                    ->all(),
            ])
            ->sort($this->groupSorter(...))
            ->values()
            ->all();
    }

    private function telegramMessageTitle(TelegramMessage $message): string
    {
        $title = $this->normalizeText(trim(strtok((string) $message->text, "\n") ?: ''));

        if ($title === '') {
            return 'Сообщение #'.$message->message_id;
        }

        return mb_strlen($title, 'UTF-8') > 110
            ? mb_substr($title, 0, 107, 'UTF-8').'...'
            : $title;
    }

    /** @param Collection<int, mixed> $hits */
    private function keywordPhrases(Collection $hits): string
    {
        return $hits
            ->map(fn ($hit): string => $this->normalizeText((string) ($hit->keyword?->phrase ?? '')))
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->implode(', ');
    }

    private function normalizeText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
    }

    /**
     * @param  array{name:string,items:list<mixed>}  $left
     * @param  array{name:string,items:list<mixed>}  $right
     */
    private function groupSorter(array $left, array $right): int
    {
        return count($right['items']) <=> count($left['items'])
            ?: strnatcasecmp($left['name'], $right['name']);
    }
}
