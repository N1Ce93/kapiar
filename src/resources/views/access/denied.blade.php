<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Немає доступу | {{ config('app.name', 'News Monitor') }}</title>
        <style>
            :root {
                color-scheme: light;
                --blue: #2998ff;
                --blue-dark: #075a9f;
                --text: #0b2640;
                --muted: #5f7488;
            }

            * {
                box-sizing: border-box;
            }

            body {
                display: grid;
                min-height: 100vh;
                margin: 0;
                place-items: center;
                background:
                    radial-gradient(circle at 18% 15%, rgba(41, 152, 255, .18), transparent 30rem),
                    linear-gradient(135deg, #ffffff 0%, #eef8ff 100%);
                color: var(--text);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            .card {
                width: min(100% - 32px, 500px);
                padding: 34px;
                border: 1px solid rgba(41, 152, 255, .2);
                border-radius: 30px;
                background: rgba(255, 255, 255, .92);
                box-shadow: 0 24px 80px rgba(7, 90, 159, .14);
                text-align: center;
            }

            .code {
                display: inline-flex;
                width: 64px;
                height: 64px;
                align-items: center;
                justify-content: center;
                margin-bottom: 22px;
                border-radius: 22px;
                background: #eaf6ff;
                color: var(--blue);
                font-size: 22px;
                font-weight: 900;
            }

            h1 {
                margin: 0;
                font-size: clamp(30px, 8vw, 44px);
                line-height: 1;
                letter-spacing: -.05em;
            }

            p {
                margin: 14px auto 0;
                max-width: 360px;
                color: var(--muted);
                font-size: 16px;
                line-height: 1.6;
            }

            a {
                display: inline-flex;
                min-height: 48px;
                align-items: center;
                justify-content: center;
                margin-top: 26px;
                padding: 0 22px;
                border-radius: 999px;
                background: var(--blue);
                color: white;
                font-weight: 900;
                text-decoration: none;
            }

            a:hover {
                background: var(--blue-dark);
            }
        </style>
    </head>
    <body>
        <main class="card">
            <div class="code">403</div>
            <h1>Вибачте, у вас немає доступу</h1>
            <p>Пароль вказано неправильно. Перевірте пароль і спробуйте відкрити сайт ще раз.</p>
            <a href="{{ route('access.show') }}">Спробувати ще раз</a>
        </main>
    </body>
</html>
