<?php

namespace App\Http\Controllers;

use App\Models\MonitoredSite;
use App\Models\TelegramChannel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonitoringStatsController extends Controller
{
    private const MONTH_NAMES = [
        1 => 'Январь',
        2 => 'Февраль',
        3 => 'Март',
        4 => 'Апрель',
        5 => 'Май',
        6 => 'Июнь',
        7 => 'Июль',
        8 => 'Август',
        9 => 'Сентябрь',
        10 => 'Октябрь',
        11 => 'Ноябрь',
        12 => 'Декабрь',
    ];

    public function sites(Request $request): View
    {
        [$months, $selectedMonth, $start, $end] = $this->resolveMonth($request);
        $rows = $this->siteRows($start, $end);

        return view('monitoring.stats', [
            'active' => 'sites',
            'title' => 'Мониторинг сайтов',
            'description' => 'Уникальные статьи с упоминаниями по каждому сайту за выбранный месяц.',
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
            'title' => 'Мониторинг Telegram',
            'description' => 'Уникальные сообщения с упоминаниями по каждому Telegram-каналу за выбранный месяц.',
            'sourceLabel' => 'Telegram-канал',
            'months' => $months,
            'selectedMonth' => $selectedMonth,
            'rows' => $rows,
            'totalMentions' => $rows->sum('mentions_count'),
        ]);
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

    /** @return Collection<int, array{name:string,url:string,mentions_count:int}> */
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
            ->get(['id', 'name', 'base_url'])
            ->map(fn (MonitoredSite $site): array => [
                'name' => $site->name,
                'url' => $site->base_url,
                'mentions_count' => (int) $site->mentions_count,
            ]);
    }

    /** @return Collection<int, array{name:string,url:string,mentions_count:int}> */
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
            ->get(['id', 'title', 'username', 'url'])
            ->map(fn (TelegramChannel $channel): array => [
                'name' => $channel->title ?: '@'.$channel->username,
                'url' => $channel->url,
                'mentions_count' => (int) $channel->mentions_count,
            ]);
    }
}
