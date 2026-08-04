# News Monitor

Laravel-приложение мониторит новостные сайты и публичные Telegram-каналы. Найденные статьи и посты сохраняются в базу, проверяются по ключевым словам и при совпадениях отправляются Telegram-уведомления.

## Требования

- Docker
- Docker Compose

## Первый Запуск

Создать `src/.env`, если файла еще нет:

```bash
cp src/.env.example src/.env
```

Установить PHP-зависимости, если они еще не установлены:

```bash
docker compose run --rm marketing-php composer install --no-cache
```

Сгенерировать ключ приложения:

```bash
docker compose run --rm marketing-php php artisan key:generate
```

Поднять базовые сервисы:

```bash
docker compose up -d marketing-db marketing-cache
```

Сервис `marketing-php` используется для разовых Artisan-команд через `docker compose run --rm marketing-php ...`. Без веб-сервера держать его постоянно запущенным не нужно.

Сервис `marketing-queue` нужен только если в проекте появятся фоновые jobs. Сейчас парсеры и Telegram-уведомления работают напрямую из scheduler.

Применить миграции:

```bash
docker compose run --rm marketing-php php artisan migrate --force
```

Добавить стартовые сайты, Telegram-каналы и ключевые слова:

```bash
docker compose run --rm marketing-php php artisan db:seed --force
```

Собрать старые статьи, чтобы они не считались новыми:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --limit=500
```

Собрать старые Telegram-посты, если Telegram-аккаунт уже авторизован:

```bash
docker compose run --rm marketing-php php artisan telegram-channels:backfill --limit=500
```

Запустить scheduler для автоматической проверки каждые 5 минут:

```bash
docker compose up -d marketing-scheduler
```

## Настройка Telegram-Уведомлений

Для отправки уведомлений добавьте в `src/.env`:

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

После изменения `.env` перезапустите scheduler:

```bash
docker compose restart marketing-scheduler
```

Если Telegram не настроен, данные все равно сохраняются в базу, но уведомления не отправляются.

## Полезные Команды

Проверить статус контейнеров:

```bash
docker compose ps
```

Посмотреть логи PHP-сервиса, если он запущен:

```bash
docker compose logs -f marketing-php
```

Посмотреть логи очереди, если она запущена:

```bash
docker compose logs -f marketing-queue
```

Посмотреть логи scheduler:

```bash
docker compose logs -f marketing-scheduler
```

Остановить контейнеры:

```bash
docker compose down
```

## Документация

- [Как добавить сайт](doc/add-site.md)
- [Как добавить Telegram-канал](doc/add-telegram.md)
