<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} | {{ config('app.name', 'News Monitor') }}</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f4fbff;
                --card: #ffffff;
                --card-strong: #eaf6ff;
                --text: #0b2640;
                --muted: #5f7488;
                --line: #cfe7fb;
                --accent: #2998ff;
                --accent-dark: #075a9f;
                --accent-soft: #eaf6ff;
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                background:
                    radial-gradient(circle at 14% 12%, rgba(41, 152, 255, .20), transparent 34rem),
                    radial-gradient(circle at 88% 6%, rgba(41, 152, 255, .10), transparent 26rem),
                    linear-gradient(135deg, #ffffff 0%, var(--bg) 100%);
                color: var(--text);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            a {
                color: inherit;
            }

            .page {
                width: min(1180px, calc(100% - 32px));
                margin: 0 auto;
                padding: 32px 0;
            }

            .hero {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 24px;
                align-items: end;
                margin-bottom: 24px;
            }

            .eyebrow {
                margin: 0 0 8px;
                color: var(--accent-dark);
                font-size: 13px;
                font-weight: 800;
                letter-spacing: .1em;
                text-transform: uppercase;
            }

            h1 {
                margin: 0;
                font-size: clamp(34px, 5vw, 64px);
                line-height: .98;
                letter-spacing: -.05em;
            }

            .description {
                max-width: 620px;
                margin: 16px 0 0;
                color: var(--muted);
                font-size: 16px;
                line-height: 1.6;
            }

            .tabs, .months {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            .tabs {
                justify-content: flex-end;
            }

            .pill {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 40px;
                padding: 0 16px;
                border: 1px solid var(--line);
                border-radius: 999px;
                background: rgba(255, 255, 255, .78);
                color: var(--muted);
                font-size: 14px;
                font-weight: 700;
                text-decoration: none;
                transition: .16s ease;
            }

            .pill:hover, .pill.active {
                border-color: var(--accent);
                background: var(--accent);
                color: white;
            }

            .panel {
                overflow: hidden;
                border: 1px solid rgba(41, 152, 255, .18);
                border-radius: 28px;
                background: rgba(255, 255, 255, .88);
                box-shadow: 0 24px 80px rgba(7, 90, 159, .12);
                backdrop-filter: blur(18px);
            }

            .toolbar {
                display: grid;
                gap: 18px;
                padding: 20px;
                border-bottom: 1px solid var(--line);
                background: rgba(234, 246, 255, .74);
            }

            .filter-group {
                display: grid;
                gap: 10px;
            }

            .toolbar-title {
                margin: 0;
                font-size: 15px;
                font-weight: 800;
            }

            .day-filter {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                align-items: end;
            }

            .day-field {
                display: grid;
                gap: 5px;
            }

            .day-field span {
                color: var(--muted);
                font-size: 12px;
                font-weight: 800;
            }

            .day-field select {
                min-width: 112px;
                min-height: 40px;
                padding: 0 34px 0 12px;
                border: 1px solid var(--line);
                border-radius: 12px;
                background: white;
                color: var(--text);
                font: inherit;
                font-size: 14px;
                font-weight: 700;
            }

            .filter-button, .filter-reset {
                display: inline-flex;
                min-height: 40px;
                padding: 0 15px;
                align-items: center;
                justify-content: center;
                border-radius: 12px;
                font: inherit;
                font-size: 14px;
                font-weight: 800;
            }

            .filter-button {
                border: 0;
                background: var(--accent-dark);
                color: white;
                cursor: pointer;
            }

            .filter-reset {
                border: 1px solid var(--line);
                background: rgba(255, 255, 255, .78);
                color: var(--muted);
                text-decoration: none;
            }

            .table-wrap {
                overflow-x: auto;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                min-width: 620px;
            }

            th, td {
                padding: 16px 20px;
                border-bottom: 1px solid var(--line);
                text-align: left;
            }

            th {
                color: var(--muted);
                font-size: 12px;
                font-weight: 900;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            td {
                font-size: 15px;
            }

            tbody tr:last-child td {
                border-bottom: 0;
            }

            .source {
                display: grid;
                gap: 7px;
            }

            .source-head {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                align-items: center;
            }

            .source-name {
                font-weight: 800;
            }

            .status-badge {
                display: inline-flex;
                align-items: center;
                min-height: 24px;
                padding: 0 9px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 900;
            }

            .status-badge.active {
                background: #e7f8ef;
                color: #11733a;
            }

            .status-badge.error {
                background: #fff4db;
                color: #9b5b00;
            }

            .status-badge.paused {
                background: #e8efff;
                color: #315ca8;
            }

            .status-badge.disabled {
                background: #ffe8e8;
                color: #a62020;
            }

            .source-url {
                color: var(--muted);
                font-size: 13px;
                text-decoration: none;
                word-break: break-all;
            }

            .source-details {
                margin-top: 4px;
            }

            .details-toggle {
                display: inline-flex;
                width: max-content;
                cursor: pointer;
                color: var(--accent-dark);
                font-size: 13px;
                font-weight: 900;
                list-style: none;
            }

            .details-toggle::-webkit-details-marker {
                display: none;
            }

            .details-toggle::after {
                content: '↓';
                margin-left: 6px;
                transition: transform .16s ease;
            }

            .source-details[open] .details-toggle::after {
                transform: rotate(180deg);
            }

            .status-panel {
                display: grid;
                gap: 16px;
                margin-top: 12px;
                padding: 16px;
                border: 1px solid rgba(41, 152, 255, .16);
                border-radius: 20px;
                background: linear-gradient(135deg, rgba(234, 246, 255, .82), rgba(255, 255, 255, .95));
            }

            .status-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 10px;
            }

            .status-item {
                min-width: 0;
                padding: 12px;
                border: 1px solid rgba(207, 231, 251, .9);
                border-radius: 14px;
                background: rgba(255, 255, 255, .74);
            }

            .status-item span, .posts-head span, .post-date, .post-empty {
                color: var(--muted);
                font-size: 12px;
                font-weight: 800;
            }

            .status-item strong {
                display: block;
                margin-top: 5px;
                overflow-wrap: anywhere;
            }

            .status-error {
                padding: 13px 14px;
                border: 1px solid rgba(166, 32, 32, .18);
                border-radius: 16px;
                background: rgba(255, 232, 232, .65);
            }

            .status-error span {
                color: #a62020;
                font-size: 12px;
                font-weight: 900;
            }

            .status-error p {
                margin: 6px 0 0;
                color: #5d1e1e;
                font-size: 13px;
                line-height: 1.5;
                overflow-wrap: anywhere;
            }

            .posts-head {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                align-items: center;
                margin-bottom: 10px;
            }

            .posts-head strong {
                font-size: 14px;
            }

            .post-list {
                display: grid;
                gap: 8px;
            }

            .post-item {
                display: grid;
                gap: 4px;
                padding: 11px 12px;
                border: 1px solid rgba(207, 231, 251, .9);
                border-radius: 14px;
                background: rgba(255, 255, 255, .82);
                text-decoration: none;
            }

            .post-title {
                color: var(--text);
                font-size: 14px;
                font-weight: 800;
                line-height: 1.35;
            }

            .load-more {
                width: max-content;
                margin-top: 10px;
                padding: 10px 14px;
                border: 0;
                border-radius: 999px;
                background: var(--accent-dark);
                color: white;
                cursor: pointer;
                font: inherit;
                font-size: 13px;
                font-weight: 900;
            }

            .load-more:disabled {
                cursor: wait;
                opacity: .72;
            }

            .count {
                width: 180px;
                text-align: right;
                font-size: 24px;
                font-weight: 900;
                letter-spacing: -.03em;
            }

            th.count {
                font-size: 12px;
                letter-spacing: .08em;
            }

            .total {
                display: flex;
                justify-content: space-between;
                gap: 18px;
                align-items: center;
                padding: 24px 28px;
                background: linear-gradient(135deg, var(--accent-dark), var(--accent));
                color: white;
            }

            .total span {
                color: rgba(255, 255, 255, .68);
                font-size: 14px;
                font-weight: 700;
            }

            .total strong {
                font-size: clamp(36px, 8vw, 72px);
                line-height: .9;
                letter-spacing: -.06em;
            }

            .empty {
                padding: 48px 20px;
                color: var(--muted);
                text-align: center;
            }

            @media (max-width: 760px) {
                .page {
                    width: min(100% - 20px, 1180px);
                    padding: 18px 0;
                }

                .hero, .toolbar, .total {
                    grid-template-columns: 1fr;
                    align-items: stretch;
                }

                .hero {
                    display: block;
                }

                .tabs {
                    justify-content: flex-start;
                    margin-top: 20px;
                }

                .toolbar, .total {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .day-filter, .day-field, .day-field select, .filter-button, .filter-reset {
                    width: 100%;
                }

                .status-grid {
                    grid-template-columns: 1fr;
                }

                .pill {
                    flex: 1 1 auto;
                }
            }
        </style>
    </head>
    <body>
        <main class="page">
            <section class="hero">
                <div>
                    <p class="eyebrow">Статистика за період</p>
                    <h1>{{ $title }}</h1>
                    <p class="description">{{ $description }}</p>
                </div>

                <nav class="tabs" aria-label="Розділи моніторингу">
                    <a class="pill {{ $active === 'sites' ? 'active' : '' }}" href="{{ route('monitoring.sites', $periodQuery) }}">Сайти</a>
                    <a class="pill {{ $active === 'telegram' ? 'active' : '' }}" href="{{ route('monitoring.telegram', $periodQuery) }}">Telegram</a>
                </nav>
            </section>

            <section class="panel">
                <div class="toolbar">
                    <div class="filter-group">
                        <p class="toolbar-title">Вибір місяця: тільки останні 6 місяців</p>
                        <nav class="months" aria-label="Місяці">
                            @foreach ($months as $month)
                                <a
                                    class="pill {{ $selectedMonth === $month['key'] ? 'active' : '' }}"
                                    href="{{ route($active === 'sites' ? 'monitoring.sites' : 'monitoring.telegram', ['month' => $month['key']]) }}"
                                >{{ $month['label'] }}</a>
                            @endforeach
                        </nav>
                    </div>

                    <div class="filter-group">
                        <p class="toolbar-title">Додатковий фільтр за днями</p>
                        <form class="day-filter" method="GET" action="{{ route($active === 'sites' ? 'monitoring.sites' : 'monitoring.telegram') }}">
                            <input type="hidden" name="month" value="{{ $selectedMonth }}">
                            <label class="day-field">
                                <span>З дня</span>
                                <select name="from_day" required>
                                    <option value="" disabled @selected($selectedFromDay === null)>Оберіть день</option>
                                    @for ($day = 1; $day <= $daysInMonth; $day++)
                                        <option value="{{ $day }}" @selected($selectedFromDay === $day)>{{ $day }}</option>
                                    @endfor
                                </select>
                            </label>
                            <label class="day-field">
                                <span>По день</span>
                                <select name="to_day" required>
                                    <option value="" disabled @selected($selectedToDay === null)>Оберіть день</option>
                                    @for ($day = 1; $day <= $daysInMonth; $day++)
                                        <option value="{{ $day }}" @selected($selectedToDay === $day)>{{ $day }}</option>
                                    @endfor
                                </select>
                            </label>
                            <button class="filter-button" type="submit">Застосувати</button>
                            @if ($dayFilterActive)
                                <a class="filter-reset" href="{{ route($active === 'sites' ? 'monitoring.sites' : 'monitoring.telegram', ['month' => $selectedMonth]) }}">Весь місяць</a>
                            @endif
                        </form>
                    </div>
                </div>

                @if ($rows->isEmpty())
                    <div class="empty">Джерела поки не додані.</div>
                @else
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ $sourceLabel }}</th>
                                    <th class="count">Згадки</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    @php
                                        $postsUrl = $active === 'sites'
                                            ? route('monitoring.sites.posts', ['site' => $row['id']], false)
                                            : route('monitoring.telegram.posts', ['channel' => $row['id']], false);
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="source">
                                                <div class="source-head">
                                                    <span class="source-name">{{ $row['name'] }}</span>
                                                    <span class="status-badge {{ $row['status']['class'] }}">{{ $row['status']['label'] }}</span>
                                                </div>
                                                <a class="source-url" href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['url'] }}</a>
                                                <details
                                                    class="source-details"
                                                    data-posts-url="{{ $postsUrl }}"
                                                    data-month="{{ $selectedMonth }}"
                                                    data-from-day="{{ $selectedFromDay }}"
                                                    data-to-day="{{ $selectedToDay }}"
                                                >
                                                    <summary class="details-toggle">Статус і останні пости</summary>

                                                    <div class="status-panel">
                                                        <div class="status-grid">
                                                            <div class="status-item">
                                                                <span>Статус</span>
                                                                <strong>{{ $row['status']['label'] }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Остання перевірка</span>
                                                                <strong>{{ $row['last_checked_at'] ?? 'Немає даних' }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Наступна перевірка</span>
                                                                <strong>{{ $row['next_check_at'] ?? 'Не заплановано' }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Останній успіх</span>
                                                                <strong>{{ $row['last_success_at'] ?? 'Немає даних' }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Остання помилка</span>
                                                                <strong>{{ $row['last_error_at'] ?? 'Немає даних' }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Збоїв підряд</span>
                                                                <strong>{{ $row['consecutive_failures'] }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Тип помилки</span>
                                                                <strong>{{ $row['last_error_type'] ?? 'Немає' }}</strong>
                                                            </div>
                                                            <div class="status-item">
                                                                <span>Відключено</span>
                                                                <strong>{{ $row['disabled_at'] ?? 'Ні' }}</strong>
                                                            </div>
                                                        </div>

                                                        @if ($row['disabled_reason'] || $row['last_error'])
                                                            <div class="status-error">
                                                                @if ($row['disabled_reason'])
                                                                    <span>Причина відключення</span>
                                                                    <p>{{ $row['disabled_reason'] }}</p>
                                                                @endif

                                                                @if ($row['last_error'])
                                                                    <span>Помилка</span>
                                                                    <p>{{ $row['last_error'] }}</p>
                                                                @endif
                                                            </div>
                                                        @endif

                                                        <div class="posts-block">
                                                            <div class="posts-head">
                                                                <strong>Останні пости зі згадками за {{ $periodLabel }}</strong>
                                                                <span><span class="posts-loaded">{{ count($row['posts']) }}</span> з {{ $row['posts_count'] }}</span>
                                                            </div>

                                                            <div class="post-list" data-loaded="{{ count($row['posts']) }}" data-total="{{ $row['posts_count'] }}">
                                                                @forelse ($row['posts'] as $post)
                                                                    @if ($post['url'])
                                                                        <a class="post-item" href="{{ $post['url'] }}" target="_blank" rel="noopener noreferrer">
                                                                            <span class="post-title">{{ $post['title'] }}</span>
                                                                            <span class="post-date">{{ $post['date'] ?? 'Без дати' }}</span>
                                                                        </a>
                                                                    @else
                                                                        <div class="post-item">
                                                                            <span class="post-title">{{ $post['title'] }}</span>
                                                                            <span class="post-date">{{ $post['date'] ?? 'Без дати' }}</span>
                                                                        </div>
                                                                    @endif
                                                                @empty
                                                                    <div class="post-empty">За {{ $periodLabel }} постів немає.</div>
                                                                @endforelse
                                                            </div>

                                                            @if ($row['posts_has_more'])
                                                                <button class="load-more" type="button" data-offset="{{ $row['posts_next_offset'] }}">Підгрузити ще</button>
                                                            @endif
                                                        </div>
                                                    </div>
                                                </details>
                                            </div>
                                        </td>
                                        <td class="count">{{ number_format($row['mentions_count'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="total">
                    <span>Загальна кількість згадок за {{ $periodLabel }}</span>
                    <strong>{{ number_format($totalMentions, 0, ',', ' ') }}</strong>
                </div>
            </section>
        </main>

        <script>
            document.addEventListener('click', async (event) => {
                const button = event.target.closest('.load-more');

                if (! button) {
                    return;
                }

                const details = button.closest('.source-details');
                const list = details.querySelector('.post-list');
                const loadedCounter = details.querySelector('.posts-loaded');
                const url = new URL(details.dataset.postsUrl, window.location.origin);

                url.searchParams.set('month', details.dataset.month);

                if (details.dataset.fromDay && details.dataset.toDay) {
                    url.searchParams.set('from_day', details.dataset.fromDay);
                    url.searchParams.set('to_day', details.dataset.toDay);
                }

                url.searchParams.set('offset', button.dataset.offset || '0');

                const defaultLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Завантаження...';

                try {
                    const response = await fetch(url.toString(), {
                        headers: {
                            'Accept': 'application/json',
                        },
                    });

                    if (! response.ok) {
                        throw new Error('Request failed');
                    }

                    const payload = await response.json();
                    list.querySelector('.post-empty')?.remove();

                    payload.items.forEach((post) => {
                        list.appendChild(createPostElement(post));
                    });

                    list.dataset.loaded = String(Number(list.dataset.loaded || 0) + payload.items.length);
                    loadedCounter.textContent = list.dataset.loaded;
                    button.dataset.offset = payload.next_offset;

                    if (! payload.has_more) {
                        button.remove();
                        return;
                    }

                    button.disabled = false;
                    button.textContent = defaultLabel;
                } catch (error) {
                    button.textContent = 'Не вдалося завантажити';

                    setTimeout(() => {
                        button.disabled = false;
                        button.textContent = defaultLabel;
                    }, 1800);
                }
            });

            function createPostElement(post) {
                const element = post.url ? document.createElement('a') : document.createElement('div');
                const title = document.createElement('span');
                const date = document.createElement('span');

                element.className = 'post-item';

                if (post.url) {
                    element.href = post.url;
                    element.target = '_blank';
                    element.rel = 'noopener noreferrer';
                }

                title.className = 'post-title';
                title.textContent = post.title || 'Без назви';

                date.className = 'post-date';
                date.textContent = post.date || 'Без дати';

                element.append(title, date);

                return element;
            }
        </script>
    </body>
</html>
