<style>
    [data-gmail-open] { cursor: pointer; font-family: inherit; }
    .gmail-modal {
        width: min(640px, calc(100% - 32px));
        max-height: calc(100dvh - 48px);
        padding: 24px;
        border: 1px solid var(--line);
        border-radius: 24px;
        background: var(--card);
        color: var(--text);
        box-shadow: 0 24px 80px rgba(7, 90, 159, .24);
        overflow-y: auto;
    }
    .gmail-modal::backdrop { background: rgba(11, 38, 64, .45); }
    .gmail-modal [hidden] { display: none !important; }
    .gmail-modal-head, .gmail-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .gmail-modal h2 { margin: 0; font-size: 24px; }
    .gmail-modal button { cursor: pointer; font-family: inherit; }
    .gmail-modal button:disabled { cursor: wait; opacity: .55; }
    .gmail-close { border: 0; background: transparent; color: var(--muted); font-size: 28px; line-height: 1; }
    .gmail-state { margin: 20px 0 16px; font-weight: 800; }
    .gmail-state[data-state="active"] { color: #16713d; }
    .gmail-state[data-state="paused"], .gmail-state[data-state="disabled"] { color: #a53b26; }
    .gmail-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin: 0; }
    .gmail-fields dt { color: var(--muted); font-size: 12px; }
    .gmail-fields dd { margin: 4px 0 0; font-size: 14px; font-weight: 700; overflow-wrap: anywhere; }
    .gmail-note { color: var(--muted); font-size: 13px; line-height: 1.6; }
    .gmail-error { padding: 12px; border-radius: 12px; background: #fff2ed; color: #92341f; font-size: 14px; white-space: pre-wrap; overflow-wrap: anywhere; }
    .gmail-result { padding: 12px; border-radius: 12px; background: var(--accent-soft); font-size: 14px; line-height: 1.6; white-space: pre-line; }
    .gmail-actions { justify-content: flex-end; flex-wrap: wrap; margin-top: 20px; }
    @media (max-width: 480px) { .gmail-fields { grid-template-columns: 1fr; } }
</style>

<dialog
    class="gmail-modal"
    id="gmail-modal"
    aria-labelledby="gmail-modal-title"
    data-status-url="{{ route('monitoring.gmail.status', [], false) }}"
    data-resume-url="{{ route('monitoring.gmail.resume', [], false) }}"
>
    <div class="gmail-modal-head">
        <h2 id="gmail-modal-title">Парсер Gmail</h2>
        <button class="gmail-close" type="button" data-gmail-close aria-label="Закрити" autofocus>×</button>
    </div>

    <p class="gmail-state" data-gmail-state role="status" aria-live="polite">Завантаження стану…</p>
    <dl class="gmail-fields">
        <div><dt>Акаунт</dt><dd data-gmail-field="account">—</dd></div>
        <div><dt>Остання перевірка</dt><dd data-gmail-field="last_checked_at">—</dd></div>
        <div><dt>Призупинено</dt><dd data-gmail-field="paused_at">—</dd></div>
        <div><dt>Остання помилка</dt><dd data-gmail-field="last_error_at">—</dd></div>
        <div><dt>Листів очікують обробки</dt><dd data-gmail-field="pending">—</dd></div>
        <div><dt>Останній запуск із цього вікна</dt><dd data-gmail-field="recovery_requested_at">—</dd></div>
    </dl>
    <p class="gmail-note" data-gmail-note></p>
    <p class="gmail-error" data-gmail-parser-error hidden></p>
    <p class="gmail-result" data-gmail-result role="status" aria-live="polite" hidden></p>
    <p class="gmail-error" data-gmail-request-error role="alert" hidden></p>
    <p class="gmail-note">Час — за Києвом. Статус оновлюється кожні 3 секунди, доки вікно відкрите. Автоматичні перевірки потребують запущених scheduler і worker email.</p>
    <div class="gmail-actions">
        <button class="filter-reset" type="button" data-gmail-refresh>Оновити стан</button>
        <button class="filter-button" type="button" data-gmail-resume hidden disabled>Перевірити та відновити</button>
    </div>
</dialog>

<script>
    (() => {
        const dialog = document.getElementById('gmail-modal');
        const state = dialog.querySelector('[data-gmail-state]');
        const note = dialog.querySelector('[data-gmail-note]');
        const parserError = dialog.querySelector('[data-gmail-parser-error]');
        const requestError = dialog.querySelector('[data-gmail-request-error]');
        const result = dialog.querySelector('[data-gmail-result]');
        const resumeButton = dialog.querySelector('[data-gmail-resume]');
        const refreshButton = dialog.querySelector('[data-gmail-refresh]');
        const labels = {
            active: 'Моніторинг активний',
            paused: 'Моніторинг призупинено',
            disabled: 'Вимкнено в налаштуваннях',
            uninitialized: 'Моніторинг ще не ініціалізовано',
            queued: 'Перевірка очікує в черзі',
            running: 'Виконується перевірка Gmail',
        };
        let timer = null;
        let loading = false;

        function showError(message) {
            requestError.textContent = message;
            requestError.hidden = false;
            resumeButton.disabled = true;
        }

        function render(payload) {
            state.textContent = labels[payload.status] || 'Стан невідомий';
            state.dataset.state = payload.status;
            const values = { ...payload, recovery_requested_at: payload.recovery.requested_at };
            dialog.querySelectorAll('[data-gmail-field]').forEach((field) => {
                field.textContent = values[field.dataset.gmailField] ?? 'Немає даних';
            });

            const notes = {
                disabled: 'Установіть GMAIL_MONITORING_ENABLED=true у .env та оновіть кеш конфігурації. Це вікно не змінює налаштування.',
                queued: 'Запит збережено. Якщо перевірка не починається, перевірте, чи запущено worker email. Після 20 хвилин очікування можна повторити спробу.',
                running: 'Обробка може тривати кілька хвилин через обмеження Gmail. Повторний запуск заблоковано.',
                paused: 'Перевірка обробить нові листи й надішле збіги за ключовими словами в Telegram. Пауза зніметься лише після успішної обробки.',
                uninitialized: 'Перший запуск збереже початкову точку відліку. Старі листи не надсилатимуться.',
                active: 'Моніторинг увімкнено й не перебуває на паузі. Цей статус не є перевіркою доступності Google або worker.',
            };
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
            if (loading || ! dialog.open) return;
            loading = true;
            refreshButton.disabled = true;
            resumeButton.disabled = true;
            requestError.hidden = true;
            clearTimeout(timer);
            try {
                const response = await fetch(method === 'POST' ? dialog.dataset.resumeUrl : dialog.dataset.statusUrl, {
                    method,
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    signal: AbortSignal.timeout(15000),
                });
                if (response.redirected || [401, 403, 419].includes(response.status)) {
                    throw new Error('Сесію завершено. Оновіть сторінку та увійдіть знову.');
                }
                const payload = await response.json();
                if (! response.ok) {
                    throw new Error(payload.errors?.gmail?.[0] || 'Не вдалося отримати стан Gmail. Спробуйте ще раз.');
                }
                render(payload);
            } catch (error) {
                showError(error instanceof SyntaxError || error instanceof TypeError || error.name === 'TimeoutError'
                    ? 'Не вдалося отримати стан Gmail. Перевірте з’єднання та оновіть стан. Запитана перевірка могла вже початися.'
                    : error.message);
            } finally {
                loading = false;
                refreshButton.disabled = false;
                if (dialog.open) timer = setTimeout(() => load(), 3000);
            }
        }

        document.querySelector('[data-gmail-open]').addEventListener('click', () => {
            dialog.showModal();
            resumeButton.disabled = true;
            load();
        });
        dialog.querySelector('[data-gmail-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => clearTimeout(timer));
        dialog.addEventListener('click', (event) => {
            const bounds = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) dialog.close();
        });
        refreshButton.addEventListener('click', () => load());
        resumeButton.addEventListener('click', () => load('POST'));
    })();
</script>
