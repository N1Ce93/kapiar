# Подключение Gmail

Приложение получает только новые события Gmail через History API. При первом запуске сохраняется текущий checkpoint, поэтому старые непрочитанные письма не отправляются в Telegram.

## Поведение

- Проверяются только письма с метками `INBOX` и `UNREAD`.
- Спам, архив, письма с метками `SENT`, `DRAFT` и `TRASH` пропускаются.
- Совпадение ищется в теме без учёта регистра; достаточно одного активного ключа.
- После совпадения темы загружается полное письмо. Для отзыва используется `text/plain`, а при его отсутствии — текст из `text/html`; файловые вложения игнорируются.
- Если тело содержит строки `Текст сообщения:` и `--`, в Telegram попадает текст между ними. Если пары маркеров нет, отправляется всё тело, а пустое тело отображается как `Отзыв отсутствует`.
- Telegram-уведомление содержит дату, тему, ссылку на Gmail и сворачиваемый блок с отзывом. Длинный отзыв сокращается примерно до 3000 символов.
- Если совпали несколько ключей, добавляются все связанные с ними ярлыки.
- После Telegram-уведомления Gmail одним запросом добавляет ярлыки и удаляет `UNREAD`.
- Успешно обработанные письма, их темы и тела в базе не сохраняются.

Если пользователь или Gmail-фильтр отметит письмо прочитанным до проверки, уведомление по нему отправлено не будет.

## Google Cloud

1. Создайте проект в [Google Cloud Console](https://console.cloud.google.com/).
2. Включите Gmail API.
3. Настройте OAuth consent screen для внешнего приложения и добавьте нужный Gmail-адрес как тестового пользователя.
4. Создайте OAuth client типа Web application.
5. Добавьте redirect URI `https://developers.google.com/oauthplayground`.
6. Откройте [OAuth 2.0 Playground](https://developers.google.com/oauthplayground/).
7. В настройках Playground включите `Use your own OAuth credentials` и укажите client ID и client secret.
8. Авторизуйте scope `https://www.googleapis.com/auth/gmail.modify`.
9. Обменяйте authorization code на токены и сохраните refresh token.

OAuth-приложение в режиме Testing обычно выдаёт refresh token с ограниченным сроком жизни. Для постоянного мониторинга переведите приложение в Production и пройдите требования Google для используемого scope.

## Конфигурация

Добавьте в `src/.env`:

```env
GMAIL_MONITORING_ENABLED=false
GMAIL_CLIENT_ID=your-client-id
GMAIL_CLIENT_SECRET=your-client-secret
GMAIL_REFRESH_TOKEN=your-refresh-token
GMAIL_QUOTA_UNITS_PER_MINUTE=4000
GMAIL_MAX_RETRIES=3
GMAIL_MAX_RETRY_DELAY_SECONDS=120
TELEGRAM_REVIEW_THREAD_ID=12345
```

`TELEGRAM_REVIEW_THREAD_ID` — ID темы в супергруппе из `TELEGRAM_CHAT_ID`. Он передаётся в Telegram как `message_thread_id` только для отзывов. Если оставить значение пустым, отзывы будут отправляться по общим правилам Telegram-уведомлений.

Очистите кеш конфигурации и проверьте подключение:

```bash
docker compose run --rm marketing-php php artisan config:clear
docker compose run --rm marketing-php php artisan gmail:status
```

Проверка запускается в `08:00`, `10:00`, `12:00`, `14:00` и `16:00` по Киеву. Gmail-клиент ограничивает расход квоты и повторяет временно отклонённые API-запросы. Если повторы исчерпаны или ошибка постоянная, мониторинг ставится на паузу, а причина один раз отправляется в Telegram. После исправления возобновите мониторинг и сразу поставьте проверку в очередь:

```bash
docker compose exec marketing-php php artisan gmail:resume
```

## Квота Gmail И HTTP 403

Для проектов с новыми [квотами Gmail API](https://developers.google.com/workspace/gmail/api/reference/quota) лимит составляет 6000 единиц в минуту на пользователя в проекте. Один `messages.get` стоит 20 единиц, даже для `format=metadata`. Получение истории стоит 2 единицы, списка писем — 5, профиля — 1. Квота не связана со сроком жизни OAuth-токена.

- `GMAIL_QUOTA_UNITS_PER_MINUTE=4000` задаёт бюджет приложения с запасом относительно лимита Google. Запросы распределяются равномерно по времени с учётом стоимости операции. Используется общий cache-lock и состояние для Client ID: перезапуск воркера или замена токена не сбрасывают бюджет. В production используйте общий cache store с поддержкой атомарных блокировок, например Redis.
- `GMAIL_MAX_RETRIES=3` разрешает до трёх дополнительных попыток для HTTP 403 с причиной `rateLimitExceeded` / `userRateLimitExceeded`, HTTP 429 и HTTP 500/502/503/504. При минутном лимите и HTTP 429 клиент ждёт не менее 65 секунд; для 5xx задержка увеличивается: 1, 2, 4 секунды.
- `Retry-After` учитывается как число секунд или HTTP-дата. `GMAIL_MAX_RETRY_DELAY_SECONDS=120` ограничивает одну задержку. Если Google требует ждать дольше, запрос завершается ошибкой вместо преждевременного повтора.
- Ошибки `domainPolicy`, `insufficientPermissions`, `dailyLimitExceeded` и OAuth `invalid_grant` не повторяются. HTTP 401 по-прежнему вызывает одно обновление access token.
- При окончательной ошибке сохраняются сообщение Google, причины, статус и параметры квоты. Client secret, refresh token и текущий access token маскируются.

При HTTP 403 с причиной `rateLimitExceeded` менять refresh token не требуется. После исправления скорости запросов нужно снять сохранённую паузу командой `gmail:resume`. Контрольная точка истории при неудачной обработке не продвигается: накопившиеся письма будут прочитаны при следующей успешной проверке.

### Развёртывание Исправления Квоты

Загрузите обновлённые файлы приложения. Новые переменные окружения необязательны: значения выше используются по умолчанию. Из корня проекта выполните:

```bash
docker compose exec marketing-php php artisan config:clear
docker compose restart marketing-email-queue marketing-scheduler
docker compose exec marketing-php php artisan gmail:resume
docker compose logs --since=10m -f marketing-email-queue
```

Обработка накопившейся истории может занять несколько минут из-за ограничения скорости. После завершения проверьте состояние:

```bash
docker compose exec marketing-php php artisan gmail:status
```

Для этого исправления миграции БД и замена токена не нужны.

## Первый запуск

Примените миграции и сохраните начальный Gmail checkpoint:

```bash
docker compose run --rm marketing-php php artisan migrate --force
docker compose run --rm marketing-php php artisan gmail:check
```

Первый `gmail:check` только сохраняет текущий `historyId`. Существующие письма не обрабатываются.

Добавьте ключ. Команда интерактивно запросит название Gmail-ярлыка и создаст сам ярлык при первом совпадении:

```bash
docker compose run --rm -it marketing-php php artisan email-keywords:add "Оставить свой отзыв"
```

Пример ответа:

```text
Название Gmail-ярлыка: Відгуки
```

Другие команды:

```bash
docker compose run --rm marketing-php php artisan email-keywords:list
docker compose run --rm marketing-php php artisan email-keywords:disable "Оставить свой отзыв"
docker compose run --rm marketing-php php artisan email-keywords:enable "Оставить свой отзыв"
```

Включите мониторинг и запустите сервисы:

```env
GMAIL_MONITORING_ENABLED=true
```

```bash
docker compose restart marketing-scheduler
docker compose up -d marketing-email-queue
```

## Защита От Повторов

Scheduler раз в два часа запускает dispatch, а `CheckGmailJob` является уникальной задачей. Redis-lock дополнительно не позволяет одновременно запустить проверку из cron, другого worker или команды `gmail:check`.

Если Telegram не принял уведомление, письмо остаётся непрочитанным, а временная запись повторяется. Если Telegram уже принял уведомление, но Gmail не обновил письмо, следующий запуск повторяет только назначение ярлыков и снятие `UNREAD`.

Между внешними API нет общей транзакции. Если процесс аварийно завершится точно после принятия сообщения Telegram, но до сохранения этого статуса, возможен редкий повтор уведомления; выбран режим гарантированной доставки вместо риска потерять сообщение.

Проверка вручную использует ту же блокировку:

```bash
docker compose run --rm marketing-php php artisan gmail:check
```
