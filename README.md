# News Monitor

Laravel-приложение мониторит новостные сайты, публичные Telegram-каналы и новые непрочитанные Gmail-письма. Найденные совпадения проверяются по ключевым словам и отправляются в Telegram.

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

После первой ошибки проверка повторяется через 30 минут, после второй — через 2 часа, после третьей источник приостанавливается на 4 часа. После четвёртой и последующих временных ошибок проверка повторяется каждые 6 часов; окончательное отключение возможно при подтверждении постоянной ошибки на четвёртой проверке.

Worker сайтов обрабатывает очередь `sources`:

```bash
docker compose up -d marketing-sources-queue
```

Telegram worker обрабатывает очередь `telegram`. Запускайте его только после успешного `telegram:status`:

```bash
docker compose up -d marketing-telegram-queue
```

Если Telegram session/MadelineProto нестабилен, оставьте `marketing-telegram-queue` выключенным. Мониторинг сайтов продолжит работать отдельно.

Gmail worker обрабатывает очередь `email`. Запускайте его только после OAuth-настройки и первой инициализации через `gmail:check`:

```bash
docker compose up -d marketing-email-queue
```

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

## Gmail

Gmail проверяется каждую минуту через History API. Обрабатываются только новые непрочитанные письма, включая спам; отправленные письма, черновики и корзина пропускаются. После успешного Telegram-уведомления письмо получает ярлыки совпавших ключей и отмечается прочитанным.

Заполните в `src/.env` OAuth-параметры с правом `gmail.modify`:

```env
GMAIL_MONITORING_ENABLED=false
GMAIL_CLIENT_ID=
GMAIL_CLIENT_SECRET=
GMAIL_REFRESH_TOKEN=
```

Проверьте OAuth, инициализируйте checkpoint без обработки старых писем и добавьте первый ключ с интерактивным выбором ярлыка:

```bash
docker compose run --rm marketing-php php artisan gmail:status
docker compose run --rm marketing-php php artisan gmail:check
docker compose run --rm -it marketing-php php artisan email-keywords:add "Оставить свой отзыв"
```

После этого установите `GMAIL_MONITORING_ENABLED=true`, перезапустите scheduler и запустите worker:

```bash
docker compose restart marketing-scheduler
docker compose up -d marketing-email-queue
```

Успешно обработанные письма в базе не сохраняются. Временная запись содержит только Gmail message ID, совпавшие ключи, целевые ярлыки и этап обработки; она удаляется после изменения письма в Gmail. Подробная настройка: [Как подключить Gmail](doc/add-gmail.md).

## Release / Deploy Runbook

Перед деплоем остановите новые dispatch-и и мягко перезапустите workers:

```bash
docker compose stop marketing-scheduler
docker compose exec marketing-php php artisan queue:restart
docker compose up -d --build marketing-php marketing-scheduler marketing-sources-queue marketing-email-queue
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
docker compose logs -f marketing-email-queue
docker compose run --rm marketing-php php artisan sites:probe "https://example.com/"
docker compose run --rm marketing-php php artisan sites:add "https://example.com/"
docker compose run --rm marketing-php php artisan parser:check --site="example.com" --limit=5 --no-notify
```

## Документация

- [Как добавить сайт](doc/add-site.md)
- [Как добавить Telegram-канал](doc/add-telegram.md)
- [Как подключить Gmail](doc/add-gmail.md)
