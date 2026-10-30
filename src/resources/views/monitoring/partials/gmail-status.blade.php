@php
    $labels = [
        'active' => 'Моніторинг активний',
        'paused' => 'Моніторинг призупинено',
        'disabled' => 'Вимкнено в налаштуваннях',
        'uninitialized' => 'Моніторинг ще не ініціалізовано',
        'queued' => 'Перевірка очікує в черзі',
        'running' => 'Виконується перевірка Gmail',
    ];
    $notes = [
        'disabled' => 'Установіть GMAIL_MONITORING_ENABLED=true у .env та оновіть кеш конфігурації. Ця сторінка не змінює налаштування.',
        'queued' => 'Запит збережено. Якщо перевірка не починається, перевірте, чи запущено worker email. Після 20 хвилин очікування можна повторити спробу.',
        'running' => 'Обробка може тривати кілька хвилин через обмеження Gmail. Повторний запуск заблоковано.',
        'paused' => 'Перевірка обробить нові листи й надішле збіги за ключовими словами в Telegram. Пауза зніметься лише після успішної обробки.',
        'uninitialized' => 'Перший запуск збереже початкову точку відліку. Старі листи не надсилатимуться.',
        'active' => 'Моніторинг увімкнено й не перебуває на паузі. Цей статус не є перевіркою доступності Google або worker.',
    ];
    $fields = [
        'account' => 'Акаунт',
        'last_checked_at' => 'Остання перевірка',
        'paused_at' => 'Призупинено',
        'last_error_at' => 'Остання помилка',
        'pending' => 'Листів очікують обробки',
        'recovery_requested_at' => 'Останній запит на відновлення',
        'recovery_started_at' => 'Початок відновлення',
        'recovery_finished_at' => 'Завершення відновлення',
    ];
    $values = [
        ...$gmail,
        'recovery_requested_at' => $gmail['recovery']['requested_at'],
        'recovery_started_at' => $gmail['recovery']['started_at'],
        'recovery_finished_at' => $gmail['recovery']['finished_at'],
    ];
    $recovery = $gmail['recovery'];
    $stats = $recovery['stats'];
    $resultText = match ($recovery['status']) {
        'succeeded' => ($stats['initialized'] ?? false)
            ? 'Перевірка успішна. Моніторинг ініціалізовано; старі листи не оброблялися.'
            : sprintf('Перевірка успішна. Знайдено: %d. Збігів: %d. Надіслано в Telegram: %d. Завершено: %d. Очікують: %d.',
                $stats['found'] ?? 0, $stats['matched'] ?? 0, $stats['sent'] ?? 0, $stats['completed'] ?? 0, $stats['pending'] ?? 0),
        'failed' => 'Остання спроба завершилася помилкою: '.($recovery['error'] ?: 'Причина невідома.'),
        'queued' => 'Запит на перевірку прийнято.',
        'running' => 'Перевірка триває…',
        default => '',
    };
    if ($recovery['status'] === 'succeeded') {
        $resultText .= "\nЗавершено: ".$recovery['finished_at'].'.';
    }
@endphp

<section
    class="gmail-panel"
    id="gmail-status"
    aria-labelledby="gmail-status-title"
    data-status-url="{{ route('monitoring.gmail.status', [], false) }}"
    data-resume-url="{{ route('monitoring.gmail.resume', [], false) }}"
>
    <h2 id="gmail-status-title">Стан парсера Gmail</h2>

    <p class="gmail-state" data-gmail-state data-state="{{ $gmail['status'] }}" role="status" aria-live="polite">{{ $labels[$gmail['status']] ?? 'Стан невідомий' }}</p>
    <dl class="gmail-fields">
        @foreach ($fields as $field => $label)
            <div><dt>{{ $label }}</dt><dd data-gmail-field="{{ $field }}">{{ $values[$field] ?? 'Немає даних' }}</dd></div>
        @endforeach
    </dl>
    <p class="gmail-note" data-gmail-note>{{ $notes[$gmail['status']] ?? '' }}</p>
    <p class="gmail-error" data-gmail-parser-error @if (! $gmail['last_error']) hidden @endif>{{ $gmail['last_error'] ? 'Остання помилка парсера: '.$gmail['last_error'] : '' }}</p>
    <p class="gmail-result" data-gmail-result role="status" aria-live="polite" @if (! $recovery['status']) hidden @endif>{{ $resultText }}</p>
    <p class="gmail-error" data-gmail-request-error role="alert" hidden></p>
    <p class="gmail-updated" data-gmail-updated>Стан завантажено: {{ now()->timezone('Europe/Kyiv')->format('d.m.Y H:i:s') }}</p>
    <p class="gmail-note">Час — за Києвом. Статус оновлюється кожні 3 секунди, доки сторінка видима. Перегляд стану не звертається до Google. Автоматичні перевірки потребують запущених scheduler і worker email.</p>
    <noscript><p class="gmail-error">Для автоматичного оновлення та відновлення увімкніть JavaScript. Щоб отримати актуальний стан, перезавантажте сторінку.</p></noscript>
    <div class="gmail-actions">
        <button type="button" data-gmail-refresh disabled>Оновити стан</button>
        <button type="button" data-gmail-resume @if (! $gmail['can_resume']) hidden @endif disabled>Перевірити та відновити</button>
    </div>
</section>

<script>
    (() => {
        const panel = document.getElementById('gmail-status');
        const state = panel.querySelector('[data-gmail-state]');
        const note = panel.querySelector('[data-gmail-note]');
        const parserError = panel.querySelector('[data-gmail-parser-error]');
        const requestError = panel.querySelector('[data-gmail-request-error]');
        const result = panel.querySelector('[data-gmail-result]');
        const updated = panel.querySelector('[data-gmail-updated]');
        const resumeButton = panel.querySelector('[data-gmail-resume]');
        const refreshButton = panel.querySelector('[data-gmail-refresh]');
        const labels = @js($labels);
        const notes = @js($notes);
        let timer = null;
        let loading = false;
        let stopped = false;
        let controller = null;

        function showError(message) {
            requestError.textContent = message;
            requestError.hidden = false;
            resumeButton.disabled = true;
            state.textContent = 'Не вдалося оновити стан — дані могли застаріти';
            state.dataset.state = 'unknown';
        }

        function render(payload) {
            state.textContent = labels[payload.status] || 'Стан невідомий';
            state.dataset.state = payload.status;
            const values = {
                ...payload,
                recovery_requested_at: payload.recovery.requested_at,
                recovery_started_at: payload.recovery.started_at,
                recovery_finished_at: payload.recovery.finished_at,
            };
            panel.querySelectorAll('[data-gmail-field]').forEach((field) => {
                field.textContent = values[field.dataset.gmailField] ?? 'Немає даних';
            });

            note.textContent = notes[payload.status] || '';
            parserError.hidden = ! payload.last_error;
            parserError.textContent = payload.last_error ? `Остання помилка парсера: ${payload.last_error}` : '';
            resumeButton.hidden = ! payload.can_resume;
            resumeButton.disabled = ! payload.can_resume;
            resumeButton.textContent = payload.status === 'uninitialized' ? 'Перевірити та ініціалізувати' : 'Перевірити та відновити';

            const recovery = payload.recovery;
            result.hidden = ! recovery.status;
            if (recovery.status === 'succeeded') {
                const stats = recovery.stats;
                result.textContent = stats?.initialized
                    ? 'Перевірка успішна. Моніторинг ініціалізовано; старі листи не оброблялися.'
                    : `Перевірка успішна. Знайдено: ${stats?.found ?? 0}. Збігів: ${stats?.matched ?? 0}. Надіслано в Telegram: ${stats?.sent ?? 0}. Завершено: ${stats?.completed ?? 0}. Очікують: ${stats?.pending ?? 0}.`;
                result.textContent += `\nЗавершено: ${recovery.finished_at}.`;
            } else if (recovery.status === 'failed') {
                result.textContent = `Остання спроба завершилася помилкою: ${recovery.error || 'Причина невідома.'}`;
            } else {
                result.textContent = recovery.status === 'queued' ? 'Запит на перевірку прийнято.' : 'Перевірка триває…';
            }
        }

        async function load(method = 'GET') {
            if (loading || stopped || document.hidden) return;
            loading = true;
            refreshButton.disabled = true;
            resumeButton.disabled = true;
            requestError.hidden = true;
            clearTimeout(timer);
            controller = new AbortController();
            try {
                const response = await fetch(method === 'POST' ? panel.dataset.resumeUrl : panel.dataset.statusUrl, {
                    method,
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]),
                });
                if (response.redirected || [401, 403, 419].includes(response.status)) {
                    throw new Error('Сесію завершено. Оновіть сторінку та увійдіть знову.');
                }
                const payload = await response.json();
                if (! response.ok) {
                    throw new Error(payload.errors?.gmail?.[0] || 'Не вдалося отримати стан Gmail. Спробуйте ще раз.');
                }
                render(payload);
                updated.textContent = `Стан оновлено: ${new Date().toLocaleString('uk-UA', { timeZone: 'Europe/Kyiv' })}`;
            } catch (error) {
                if (stopped) return;
                showError(error instanceof SyntaxError || error instanceof TypeError || error.name === 'TimeoutError'
                    ? 'Не вдалося отримати стан Gmail. Перевірте з’єднання та оновіть стан. Запитана перевірка могла вже початися.'
                    : error.message);
            } finally {
                loading = false;
                refreshButton.disabled = false;
                if (! stopped && ! document.hidden) timer = setTimeout(() => load(), 3000);
            }
        }

        document.addEventListener('visibilitychange', () => {
            clearTimeout(timer);
            if (! document.hidden) load();
        });
        window.addEventListener('pagehide', () => {
            stopped = true;
            clearTimeout(timer);
            controller?.abort();
        });
        window.addEventListener('pageshow', () => {
            stopped = false;
            load();
        });
        refreshButton.addEventListener('click', () => load());
        resumeButton.addEventListener('click', () => load('POST'));
        render(@js($gmail));
        load();
    })();
</script>
