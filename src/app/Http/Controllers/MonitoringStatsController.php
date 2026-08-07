<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonitoringStatsController extends Controller
{
    private const POST_PAGE_SIZE = 3;

    private const MONTH_NAMES = [
        1 => 'Січень',
        2 => 'Лютий',
        3 => 'Березень',
        4 => 'Квітень',
        5 => 'Травень',
        6 => 'Червень',
        7 => 'Липень',
        8 => 'Серпень',
        9 => 'Вересень',
        10 => 'Жовтень',
        11 => 'Листопад',
        12 => 'Грудень',
    ];

    public function sites(Request $request): View
    {
        [$months, $selectedMonth, $start, $end] = $this->resolveMonth($request);
        $rows = $this->siteRows($start, $end);

        return view('monitoring.stats', [
            'active' => 'sites',
            'title' => 'Моніторинг сайтів',
            'description' => 'Унікальні статті зі згадками по кожному сайту за вибраний місяць.',
            'sourceLabel' => 'Сайт',
            'months' => $months,
            'selectedMonth' => $selectedMonth,
            'rows' => $rows,
            'totalMentions' => $rows->sum('mentions_count'),
        ]);
    }

    public function telegram(Request $request): View
    {
        [$months, $selectedMonth, $start, $end] = $this->resolveMonth($request);
        $rows = $this->telegramRows($start, $end);

        return view('monitoring.stats', [
            'active' => 'telegram',
            'title' => 'Моніторинг Telegram',
            'description' => 'Унікальні повідомлення зі згадками по кожному Telegram-каналу за вибраний місяць.',
            'sourceLabel' => 'Telegram-канал',
            'months' => $months,
            'selectedMonth' => $selectedMonth,
            'rows' => $rows,
            'totalMentions' => $rows->sum('mentions_count'),
        ]);
    }

    public function sitePosts(Request $request, MonitoredSite $site): JsonResponse
    {
        [, , $start, $end] = $this->resolveMonth($request);

        return response()->json($this->sitePostsPayload(
            $site,
            $start,
            $end,
            max(0, (int) $request->query('offset', 0)),
        ));
    }

    public function telegramPosts(Request $request, TelegramChannel $channel): JsonResponse
    {
        [, , $start, $end] = $this->resolveMonth($request);

        return response()->json($this->telegramPostsPayload(
            $channel,
            $start,
            $end,
            max(0, (int) $request->query('offset', 0)),
        ));
    }

    /**
     * @return array{0:list<array{key:string,label:string}>,1:string,2:Carbon,3:Carbon}
     */
    private function resolveMonth(Request $request): array
    {
        $months = [];
        $currentMonth = now()->startOfMonth();

        for ($i = 0; $i < 6; $i++) {
            $month = $currentMonth->copy()->subMonths($i);
            $months[] = [
                'key' => $month->format('Y-m'),
                'label' => self::MONTH_NAMES[(int) $month->format('n')].' '.$month->format('Y'),
            ];
        }

        $monthKeys = array_column($months, 'key');
        $selectedMonth = (string) $request->query('month', $months[0]['key']);

        if (! in_array($selectedMonth, $monthKeys, true)) {
            $selectedMonth = $months[0]['key'];
        }

        $start = Carbon::createFromFormat('Y-m-d', $selectedMonth.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$months, $selectedMonth, $start, $end];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function siteRows(Carbon $start, Carbon $end): Collection
    {
        return MonitoredSite::query()
            ->withCount([
                'articles as mentions_count' => function ($query) use ($start, $end): void {
                    $query
                        ->whereHas('hits')
                        ->whereBetween(DB::raw('COALESCE(published_at, discovered_at, created_at)'), [$start, $end]);
                },
            ])
            ->orderByDesc('mentions_count')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'base_url',
                'enabled',
                'consecutive_failures',
                'last_checked_at',
                'last_success_at',
                'last_error_at',
                'last_error',
                'disabled_at',
                'disabled_reason',
            ])
            ->map(function (MonitoredSite $site) use ($start, $end): array {
                $posts = $this->sitePostsPayload($site, $start, $end);

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'url' => $site->base_url,
                    'mentions_count' => (int) $site->mentions_count,
                    'status' => $this->sourceStatus($site),
                    'consecutive_failures' => (int) $site->consecutive_failures,
                    'last_checked_at' => $this->formatDate($site->last_checked_at),
                    'last_success_at' => $this->formatDate($site->last_success_at),
                    'last_error_at' => $this->formatDate($site->last_error_at),
                    'last_error' => $site->last_error,
                    'disabled_at' => $this->formatDate($site->disabled_at),
                    'disabled_reason' => $site->disabled_reason,
                    'posts' => $posts['items'],
                    'posts_count' => $posts['total'],
                    'posts_has_more' => $posts['has_more'],
                    'posts_next_offset' => $posts['next_offset'],
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function telegramRows(Carbon $start, Carbon $end): Collection
    {
        return TelegramChannel::query()
            ->withCount([
                'messages as mentions_count' => function ($query) use ($start, $end): void {
                    $query
                        ->whereHas('hits')
                        ->whereBetween(DB::raw('COALESCE(posted_at, created_at)'), [$start, $end]);
                },
            ])
            ->orderByDesc('mentions_count')
            ->orderBy('title')
            ->orderBy('username')
            ->get([
                'id',
                'title',
                'username',
                'url',
                'enabled',
                'consecutive_failures',
                'last_checked_at',
                'last_success_at',
                'last_error_at',
                'last_error',
                'disabled_at',
                'disabled_reason',
            ])
            ->map(function (TelegramChannel $channel) use ($start, $end): array {
                $posts = $this->telegramPostsPayload($channel, $start, $end);

                return [
                    'id' => $channel->id,
                    'name' => $channel->title ?: '@'.$channel->username,
                    'url' => $channel->url,
                    'mentions_count' => (int) $channel->mentions_count,
                    'status' => $this->sourceStatus($channel),
                    'consecutive_failures' => (int) $channel->consecutive_failures,
                    'last_checked_at' => $this->formatDate($channel->last_checked_at),
                    'last_success_at' => $this->formatDate($channel->last_success_at),
                    'last_error_at' => $this->formatDate($channel->last_error_at),
                    'last_error' => $channel->last_error,
                    'disabled_at' => $this->formatDate($channel->disabled_at),
                    'disabled_reason' => $channel->disabled_reason,
                    'posts' => $posts['items'],
                    'posts_count' => $posts['total'],
                    'posts_has_more' => $posts['has_more'],
                    'posts_next_offset' => $posts['next_offset'],
                ];
            });
    }

    /** @return array{items:list<array<string, string|null>>,total:int,next_offset:int,has_more:bool} */
    private function sitePostsPayload(MonitoredSite $site, Carbon $start, Carbon $end, int $offset = 0): array
    {
        $baseQuery = Article::query()
            ->where('monitored_site_id', $site->id)
            ->whereHas('hits')
            ->whereBetween(DB::raw('COALESCE(published_at, discovered_at, created_at)'), [$start, $end]);

        $total = (clone $baseQuery)->count();
        $items = $baseQuery
            ->orderByRaw('COALESCE(published_at, discovered_at, created_at) desc')
            ->offset($offset)
            ->limit(self::POST_PAGE_SIZE)
            ->get(['id', 'title', 'url', 'published_at', 'discovered_at', 'created_at'])
            ->map(fn (Article $article): array => [
                'title' => $this->shortTitle($article->title ?: $article->url, 'Без назви'),
                'date' => $this->formatDate($article->published_at ?: $article->discovered_at ?: $article->created_at),
                'url' => $article->url,
            ])
            ->values()
            ->all();

        $nextOffset = $offset + count($items);

        return [
            'items' => $items,
            'total' => $total,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < $total,
        ];
    }

    /** @return array{items:list<array<string, string|null>>,total:int,next_offset:int,has_more:bool} */
    private function telegramPostsPayload(TelegramChannel $channel, Carbon $start, Carbon $end, int $offset = 0): array
    {
        $baseQuery = TelegramMessage::query()
            ->where('telegram_channel_id', $channel->id)
            ->whereHas('hits')
            ->whereBetween(DB::raw('COALESCE(posted_at, created_at)'), [$start, $end]);

        $total = (clone $baseQuery)->count();
        $items = $baseQuery
            ->orderByRaw('COALESCE(posted_at, created_at) desc')
            ->offset($offset)
            ->limit(self::POST_PAGE_SIZE)
            ->get(['id', 'message_id', 'text', 'url', 'posted_at', 'created_at'])
            ->map(fn (TelegramMessage $message): array => [
                'title' => $this->telegramMessageTitle($message),
                'date' => $this->formatDate($message->posted_at ?: $message->created_at),
                'url' => $message->url,
            ])
            ->values()
            ->all();

        $nextOffset = $offset + count($items);

        return [
            'items' => $items,
            'total' => $total,
            'next_offset' => $nextOffset,
            'has_more' => $nextOffset < $total,
        ];
    }

    /** @return array{label:string,class:string} */
    private function sourceStatus(MonitoredSite|TelegramChannel $source): array
    {
        if (! $source->enabled) {
            return ['label' => 'Відключено', 'class' => 'disabled'];
        }

        if ($source->last_error_at && (! $source->last_success_at || $source->last_error_at->greaterThan($source->last_success_at))) {
            return ['label' => 'Помилка', 'class' => 'error'];
        }

        return ['label' => 'Активно', 'class' => 'active'];
    }

    private function telegramMessageTitle(TelegramMessage $message): string
    {
        $firstLine = trim(strtok((string) $message->text, "\n") ?: '');

        return $this->shortTitle($firstLine, 'Повідомлення #'.$message->message_id);
    }

    private function shortTitle(?string $title, string $fallback): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string) $title) ?: '');

        if ($title === '') {
            return $fallback;
        }

        return mb_strlen($title, 'UTF-8') > 110
            ? mb_substr($title, 0, 107, 'UTF-8').'...'
            : $title;
    }

    private function formatDate(?Carbon $date): ?string
    {
        return $date?->format('d.m.Y H:i');
    }
}
