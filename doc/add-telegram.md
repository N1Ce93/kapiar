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
```

Для отправки уведомлений также нужны:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

После изменения `.env` перезапустите контейнеры приложения:

```bash
docker compose restart marketing-php marketing-queue marketing-scheduler
```

## 3. Авторизовать Telegram-Аккаунт

Перед авторизацией лучше остановить scheduler, чтобы он не использовал session-файл параллельно:

```bash
docker compose stop marketing-scheduler
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

Если включена 2FA, пароль можно передать параметром:

```bash
docker compose run --rm -it marketing-php php artisan telegram:login --password="your-password"
```

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

После успешной авторизации можно снова запустить scheduler:

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

Ручная проверка новых постов по всем каналам:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check
```

Проверить один канал:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check --channel=zoda_gov_ua
```

Ограничить количество последних сообщений на канал:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:check --limit=20
```

Новый пост определяется по `message_id`. Если пост уже есть в `telegram_messages`, повторное уведомление не отправляется.

## 9. Автоматическая Проверка

В `src/routes/console.php` настроена проверка каждые 5 минут:

```php
Schedule::command('telegram-channels:check')->everyFiveMinutes()->withoutOverlapping();
```

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
```
