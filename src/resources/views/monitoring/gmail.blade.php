<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Моніторинг Gmail | {{ config('app.name', 'News Monitor') }}</title>
        <style>
            :root {
                color-scheme: light;
                --bg: #f4fbff;
                --card: #ffffff;
                --text: #0b2640;
                --muted: #5f7488;
                --line: #cfe7fb;
                --accent: #2998ff;
                --accent-dark: #075a9f;
                --accent-soft: #eaf6ff;
            }
            * { box-sizing: border-box; }
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
            [hidden] { display: none !important; }
            .page { width: min(1180px, calc(100% - 32px)); margin: 0 auto; padding: 32px 0; }
            .hero { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 24px; align-items: end; margin-bottom: 24px; }
            .eyebrow { margin: 0 0 8px; color: var(--accent-dark); font-size: 13px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
            h1 { margin: 0; font-size: clamp(34px, 5vw, 64px); line-height: .98; letter-spacing: -.05em; }
            .description { max-width: 620px; margin: 16px 0 0; color: var(--muted); font-size: 16px; line-height: 1.6; }
            .tabs { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
            .pill { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border: 1px solid var(--line); border-radius: 999px; background: rgba(255, 255, 255, .78); color: var(--muted); font-size: 14px; font-weight: 700; text-decoration: none; }
            .pill:hover, .pill.active { border-color: var(--accent); background: var(--accent); color: white; }
            .gmail-panel { padding: 28px; border: 1px solid rgba(41, 152, 255, .18); border-radius: 28px; background: rgba(255, 255, 255, .88); box-shadow: 0 24px 80px rgba(7, 90, 159, .12); }
            .gmail-panel h2 { margin: 0; font-size: 24px; }
            .gmail-state { margin: 16px 0 24px; font-weight: 800; }
            .gmail-state[data-state="active"] { color: #16713d; }
            .gmail-state[data-state="paused"], .gmail-state[data-state="disabled"], .gmail-state[data-state="unknown"] { color: #a53b26; }
            .gmail-fields { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; margin: 0; }
            .gmail-fields dt { color: var(--muted); font-size: 13px; }
            .gmail-fields dd { margin: 6px 0 0; font-size: 15px; font-weight: 700; overflow-wrap: anywhere; }
            .gmail-note, .gmail-updated { color: var(--muted); font-size: 14px; line-height: 1.6; }
            .gmail-note { margin-top: 24px; }
            .gmail-error { padding: 16px; border-radius: 12px; background: #fff2ed; color: #92341f; font-size: 14px; white-space: pre-wrap; overflow-wrap: anywhere; }
            .gmail-result { padding: 16px; border-radius: 12px; background: var(--accent-soft); font-size: 14px; line-height: 1.6; white-space: pre-line; }
            .gmail-actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
            .gmail-actions button { min-height: 44px; padding: 0 16px; border: 1px solid var(--line); border-radius: 12px; background: white; color: var(--text); font: inherit; font-size: 14px; font-weight: 800; cursor: pointer; }
            .gmail-actions [data-gmail-resume] { border-color: var(--accent); background: var(--accent); color: white; }
            .gmail-actions button:disabled { cursor: wait; opacity: .55; }
            @media (max-width: 760px) {
                .hero { grid-template-columns: 1fr; }
                .tabs { justify-content: flex-start; }
                .gmail-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            }
            @media (max-width: 480px) {
                .page { padding: 20px 0; }
                .gmail-panel { padding: 20px; }
                .gmail-fields { grid-template-columns: 1fr; gap: 16px; }
                .gmail-actions button { width: 100%; }
            }
        </style>
    </head>
    <body>
        <main class="page">
            <section class="hero">
                <div>
                    <p class="eyebrow">Стан парсера</p>
                    <h1>Моніторинг Gmail</h1>
                    <p class="description">Актуальний стан парсера Google, остання перевірка та безпечне відновлення після помилки.</p>
                </div>
                <nav class="tabs" aria-label="Розділи моніторингу">
                    <a class="pill" href="{{ route('monitoring.sites') }}">Сайти</a>
                    <a class="pill" href="{{ route('monitoring.telegram') }}">Telegram</a>
                    <a class="pill active" href="{{ route('monitoring.gmail') }}" aria-current="page">Gmail</a>
                </nav>
            </section>

            @include('monitoring.partials.gmail-status')
        </main>
    </body>
</html>
