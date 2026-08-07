<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Доступ | {{ config('app.name', 'News Monitor') }}</title>
        <style>
            :root {
                color-scheme: light;
                --blue: #2998ff;
                --blue-dark: #075a9f;
                --blue-soft: #eaf6ff;
                --text: #0b2640;
                --muted: #5f7488;
                --line: #cfe7fb;
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
                width: min(100% - 32px, 460px);
                padding: 34px;
                border: 1px solid rgba(41, 152, 255, .2);
                border-radius: 30px;
                background: rgba(255, 255, 255, .92);
                box-shadow: 0 24px 80px rgba(7, 90, 159, .14);
                backdrop-filter: blur(18px);
            }

            h1 {
                margin: 0;
                font-size: clamp(30px, 8vw, 44px);
                line-height: 1;
                letter-spacing: -.05em;
            }

            p {
                margin: 14px 0 0;
                color: var(--muted);
                font-size: 16px;
                line-height: 1.6;
            }

            form {
                display: grid;
                gap: 14px;
                margin-top: 28px;
            }

            label {
                color: var(--blue-dark);
                font-size: 13px;
                font-weight: 900;
                letter-spacing: .08em;
                text-transform: uppercase;
            }

            input {
                width: 100%;
                min-height: 52px;
                padding: 0 16px;
                border: 1px solid var(--line);
                border-radius: 16px;
                color: var(--text);
                font: inherit;
                outline: none;
                transition: .16s ease;
            }

            input:focus {
                border-color: var(--blue);
                box-shadow: 0 0 0 4px rgba(41, 152, 255, .14);
            }

            button {
                min-height: 52px;
                border: 0;
                border-radius: 999px;
                background: var(--blue);
                color: white;
                cursor: pointer;
                font: inherit;
                font-weight: 900;
                transition: .16s ease;
            }

            button:hover {
                background: var(--blue-dark);
                transform: translateY(-1px);
            }

            .error {
                margin: 0;
                color: #b42318;
                font-size: 14px;
            }
        </style>
    </head>
    <body>
        <main class="card">
            <h1>Доступ до моніторингу</h1>
            <p>Введіть пароль, щоб відкрити внутрішню сторінку моніторингу.</p>

            <form method="post" action="{{ route('access.store') }}">
                @csrf

                <label for="password">Пароль</label>
                <input id="password" name="password" type="password" autocomplete="current-password" autofocus required>

                @error('password')
                    <p class="error">{{ $message }}</p>
                @enderror

                <button type="submit">Відкрити сайт</button>
            </form>
        </main>
    </body>
</html>
