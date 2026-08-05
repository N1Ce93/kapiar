# Как Добавить Telegram-Канал

Документ описывает настройку Telegram Client API, добавление Telegram-канала в мониторинг и команды для работы с Telegram-постами.

Все команды запускаются из корня проекта.

## 1. Как Это Работает

Публичные Telegram-каналы читаются через Telegram Client API обычным Telegram-аккаунтом.

Бот из `TELEGRAM_BOT_TOKEN` используется только для отправки уведомлений.

## 2. Настроить Telegram API

Получите `api_id` и `api_hash`:

1. Откройте `https://my.telegram.org`.
2. Войдите через Telegram-аккаунт, который будет читать каналы.
3. Откройте `API development tools`.
4. Создайте приложение.
5. Скопируйте `api_id` и `api_hash`.

Добавьте в `src/.env`:

```env
TELEGRAM_API_ID=
TELEGRAM_API_HASH=
TELEGRAM_SESSION=storage/app/telegram/client.session
TELEGRAM_MONITORING_ENABLED=false
```

Для отправки уведомлений также нужны:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_REPLY_TO_MESSAGE_ID=8240
```

После изменения `.env` перезапустите scheduler и Telegram worker, если он включен:

```bash
docker compose restart marketing-scheduler
docker compose restart marketing-telegram-queue
```

## 3. Авторизовать Telegram-Аккаунт

Перед авторизацией остановите Telegram worker, чтобы он не использовал session-файл параллельно:

```bash
docker compose stop marketing-telegram-queue
```

Запустите интерактивную авторизацию:

```bash
docker compose run --rm -it marketing-php php artisan telegram:login
```

Команда попросит телефон и код из Telegram. Если включена 2FA, дополнительно попросит пароль.

Можно передать телефон сразу:

```bash
docker compose run --rm -it marketing-php php artisan telegram:login --phone="+380XXXXXXXXX"
```

Если код уже получен, его можно передать параметром:

```bash
docker compose run --rm -it marketing-php php artisan telegram:login --phone="+380XXXXXXXXX" --code="12345"
```

Если включена 2FA, вводите пароль только через интерактивный secret prompt. Не передавайте пароль через CLI-аргументы, чтобы он не попал в shell history или process list.

Проверить статус логина:

```bash
docker compose run --rm marketing-php php artisan telegram:status
```

Успешный статус выглядит так:

```text
Authorization state: LOGGED_IN
Telegram account is authorized.
```

Session сохраняется в:

```text
src/storage/app/telegram/client.session
```

После успешной авторизации установите `TELEGRAM_MONITORING_ENABLED=true` и снова запустите scheduler:

```bash
docker compose up -d marketing-scheduler
```

## 4. Добавить Telegram-Канал

Добавить канал по URL:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:add "https://t.me/zoda_gov_ua"
```

Добавить канал по username:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:add "@zoda_gov_ua"
```

Добавить канал с названием:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:add "https://t.me/zoda_gov_ua" --title="ЗОВА"
```

Команда добавляет или обновляет канал в таблице `telegram_channels` и сразу собирает старые посты без уведомлений.

## 5. Ограничить Backfill При Добавлении

По умолчанию при добавлении канала собирается до `500` старых сообщений.

```bash
docker compose run --rm marketing-php php artisan telegram-channels:add "https://t.me/zoda_gov_ua" --backfill-limit=200
```

## 6. Добавить Канал Без Backfill

```bash
docker compose run --rm marketing-php php artisan telegram-channels:add "https://t.me/zoda_gov_ua" --no-backfill
```

Используйте `--no-backfill` осторожно: первые найденные посты потом могут считаться новыми.

## 7. Собрать Старые Посты

Собрать старые посты по всем каналам без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:backfill --limit=500
```

Собрать старые посты по одному каналу:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:backfill --channel=zoda_gov_ua --limit=500
```

Канал можно указать по id, username или URL:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:backfill --channel="https://t.me/zoda_gov_ua" --limit=500
```

Проверить старые посты на ключевые слова, но без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:backfill --limit=500 --analyze
```

## 8. Проверить Новые Посты

Ручная проверка новых постов по всем каналам напрямую, без очереди:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check
```

Проверить один канал:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check --channel=zoda_gov_ua
```

Задать количество сообщений в одном запросе к Telegram API:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check --limit=20
```

При обычной проверке команда догружает историю страницами до предыдущего `last_message_id`, чтобы не пропустить посты между запусками.

Новый пост определяется по `message_id`. Если пост уже есть в `telegram_messages`, повторное уведомление не отправляется.

Поставить проверки Telegram-каналов в очередь `telegram`:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:dispatch-checks --channels-limit=20 --limit=5
```

Команда ничего не поставит в очередь, если `TELEGRAM_MONITORING_ENABLED=false`. Это безопасный режим для периода, когда Telegram worker остановлен или session требует ремонта.

Каждый канал обрабатывается отдельной job. Повторная job для того же канала не добавляется, пока предыдущая еще находится в очереди или выполняется.

## 9. Автоматическая Проверка

В `src/routes/console.php` настроена постановка задач в очередь каждые 10 минут:

```php
Schedule::command('telegram-channels:dispatch-checks --channels-limit=20 --limit=5')->everyTenMinutes()->withoutOverlapping();
```

Для Telegram используйте отдельный worker и на первом этапе только один процесс, потому что Telegram Client API использует общий session-файл:

```bash
docker compose run --rm marketing-php php artisan queue:work --queue=telegram --timeout=180 --tries=1
```

В Docker Compose для этого есть отдельный сервис:

```bash
docker compose up -d marketing-telegram-queue
```

Не запускайте worker, пока `telegram:status` не показывает успешную авторизацию.

Ошибки сохраняются в `telegram_channels`: `consecutive_failures`, `last_error_at`, `last_error`. После 4 ошибок подряд канал автоматически отключается и отправляется Telegram-уведомление.

Запустить scheduler:

```bash
docker compose up -d marketing-scheduler
```

Проверить scheduler:

```bash
docker compose ps marketing-scheduler
```

Посмотреть логи scheduler:

```bash
docker compose logs -f marketing-scheduler
```

## Список Команд

```bash
docker compose run --rm -it marketing-php php artisan telegram:login
docker compose run --rm marketing-php php artisan telegram:status
docker compose run --rm marketing-php php artisan telegram-channels:add "https://t.me/zoda_gov_ua"
docker compose run --rm marketing-php php artisan telegram-channels:backfill --limit=500
docker compose run --rm marketing-php php artisan telegram-channels:check
docker compose run --rm marketing-php php artisan telegram-channels:dispatch-checks --channels-limit=20 --limit=5
```
