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
                display: flex;
                justify-content: space-between;
                gap: 18px;
                align-items: center;
                padding: 20px;
                border-bottom: 1px solid var(--line);
                background: rgba(234, 246, 255, .74);
            }

            .toolbar-title {
                margin: 0;
                font-size: 15px;
                font-weight: 800;
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
                gap: 4px;
            }

            .source-name {
                font-weight: 800;
            }

            .source-url {
                color: var(--muted);
                font-size: 13px;
                text-decoration: none;
                word-break: break-all;
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
                    <p class="eyebrow">Статистика за місяць</p>
                    <h1>{{ $title }}</h1>
                </div>

                <nav class="tabs" aria-label="Розділи моніторингу">
                    <a class="pill {{ $active === 'sites' ? 'active' : '' }}" href="{{ route('monitoring.sites', ['month' => $selectedMonth]) }}">Сайти</a>
                    <a class="pill {{ $active === 'telegram' ? 'active' : '' }}" href="{{ route('monitoring.telegram', ['month' => $selectedMonth]) }}">Telegram</a>
                </nav>
            </section>

            <section class="panel">
                <div class="toolbar">
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
                                    <tr>
                                        <td>
                                            <div class="source">
                                                <span class="source-name">{{ $row['name'] }}</span>
                                                <a class="source-url" href="{{ $row['url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['url'] }}</a>
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
                    <span>Загальна кількість згадок за вибраний місяць</span>
                    <strong>{{ number_format($totalMentions, 0, ',', ' ') }}</strong>
                </div>
            </section>
        </main>
    </body>
</html>
