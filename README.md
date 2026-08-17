# News Monitor

Laravel-приложение мониторит новостные сайты и публичные Telegram-каналы. Найденные статьи и посты сохраняются в базу, проверяются по ключевым словам и при совпадениях отправляются Telegram-уведомления.

## Требования

- Docker
- Docker Compose

## Первый Запуск

Создать корневой `.env` для Docker Compose:

```bash
cp .env.example .env
```

Задайте сильный пароль до первого запуска MySQL:

```env
MYSQL_DATABASE=marketing
MYSQL_ROOT_PASSWORD=change_this_password
```

Создать Laravel `.env`:

```bash
cp src/.env.example src/.env
```

В `src/.env` пароль БД должен совпадать с `MYSQL_ROOT_PASSWORD`:

```env
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=marketing
DB_USERNAME=root
DB_PASSWORD=change_this_password
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

Установить зависимости:

```bash
docker compose run --rm marketing-php composer install --no-cache --no-dev --optimize-autoloader
```

Сгенерировать ключ приложения:

```bash
docker compose run --rm marketing-php php artisan key:generate
```

Поднять базовые сервисы:

```bash
docker compose up -d marketing-db marketing-cache
```

Применить миграции:

```bash
docker compose run --rm marketing-php php artisan migrate --force
```

Добавить стартовые сайты, Telegram-каналы и ключевые слова:

```bash
docker compose run --rm marketing-php php artisan db:seed --force
```

По умолчанию seeder не делает live-probe внешних сайтов. Если нужно переопределить источники по live-detection, задайте `MONITORED_SITES_PROBE_ON_SEED=true` вручную и используйте это только осознанно.

Собрать старые статьи без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --limit=500
```

## Запуск Мониторинга

Scheduler каждые 10 минут ставит в очередь только источники, у которых наступил `next_check_at`. Исправные сайты проверяются раз в 30 минут, Telegram-каналы — раз в 10 минут:

```bash
docker compose up -d marketing-scheduler
```

После первой ошибки проверка повторяется через 15 минут, после второй — через час, после третьей источник приостанавливается на 24 часа. Временные сетевые и серверные ошибки не отключают источник; окончательное отключение возможно только после суточного подтверждения постоянной ошибки.

Worker сайтов обрабатывает очередь `sources`:

```bash
docker compose up -d marketing-sources-queue
```

Telegram worker обрабатывает очередь `telegram`. Запускайте его только после успешного `telegram:status`:

```bash
docker compose up -d marketing-telegram-queue
```

Если Telegram session/MadelineProto нестабилен, оставьте `marketing-telegram-queue` выключенным. Мониторинг сайтов продолжит работать отдельно.

## Telegram

Для уведомлений и чтения каналов заполните в `src/.env`:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
TELEGRAM_REPLY_TO_CHAT_ID=-1002354975882
TELEGRAM_REPLY_TO_MESSAGE_ID=8240
TELEGRAM_API_ID=
TELEGRAM_API_HASH=
TELEGRAM_SESSION=storage/app/telegram/client.session
TELEGRAM_MONITORING_ENABLED=false
```

Перед интерактивным login остановите Telegram worker:

```bash
docker compose stop marketing-telegram-queue
docker compose run --rm -it marketing-php php artisan telegram:login
docker compose run --rm marketing-php php artisan telegram:status
```

После успешного статуса установите `TELEGRAM_MONITORING_ENABLED=true`, перезапустите scheduler и запустите worker:

```bash
docker compose restart marketing-scheduler
docker compose up -d marketing-telegram-queue
```

## Release / Deploy Runbook

Перед деплоем остановите новые dispatch-и и мягко перезапустите workers:

```bash
docker compose stop marketing-scheduler
docker compose exec marketing-php php artisan queue:restart
docker compose up -d --build marketing-php marketing-scheduler marketing-sources-queue
```

Telegram worker включайте отдельно, только если `telegram:status` успешен:

```bash
docker compose run --rm marketing-php php artisan telegram:status
docker compose up -d --build marketing-telegram-queue
```

Если Telegram session/MadelineProto нестабилен, оставьте `TELEGRAM_MONITORING_ENABLED=false`, очистите очередь `telegram` от stale jobs и держите `marketing-telegram-queue` выключенным до восстановления `telegram:status`.

Проверить расписание:

```bash
docker compose run --rm marketing-php php artisan schedule:list
```

Проверить очереди:

```bash
docker compose run --rm marketing-php php artisan queue:monitor redis:sources --max=200
docker compose run --rm marketing-php php artisan queue:monitor redis:telegram --max=200
```

Если scheduler был аварийно остановлен, очистите stale mutexes:

```bash
docker compose run --rm marketing-php php artisan schedule:clear-cache
```

## Полезные Команды

```bash
docker compose ps
docker compose logs -f marketing-scheduler
docker compose logs -f marketing-sources-queue
docker compose logs -f marketing-telegram-queue
docker compose run --rm marketing-php php artisan sites:probe "https://example.com/"
docker compose run --rm marketing-php php artisan sites:add "https://example.com/"
docker compose run --rm marketing-php php artisan parser:check --site="example.com" --limit=5 --no-notify
```

## Документация

- [Как добавить сайт](doc/add-site.md)
- [Как добавить Telegram-канал](doc/add-telegram.md)
