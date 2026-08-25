# Как Добавить Сайт

Документ описывает добавление новостного сайта в мониторинг и команды для работы с парсером сайтов.

Все команды запускаются из корня проекта.

## 1. Проверить Сайт Перед Добавлением

Перед добавлением проверьте, сможет ли приложение читать сайт через RSS или HTML:

```bash
docker compose run --rm marketing-php php artisan sites:probe "https://example.com/"
```

Команда покажет:

```text
Base URL
Recommended source
RSS URL
HTML listing URL
HTML article links
```

Если найден `Recommended source: rss`, сайт будет читаться через RSS.

Если найден `Recommended source: html`, сайт будет читаться через HTML-страницу со списком новостей.

## 2. Добавить Сайт

Обычное добавление:

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/"
```

Команда делает следующее:

1. Нормализует URL сайта.
2. Проверяет, есть ли RSS.
3. Если RSS нет, ищет ссылки на статьи в HTML.
4. Добавляет или обновляет сайт в таблице `monitored_sites`.
5. Сразу собирает старые статьи через backfill.
6. Не отправляет Telegram-уведомления при добавлении сайта.

## 3. Добавить Сайт С Названием

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/" --name="Example News"
```

Параметр `--name` задает человекочитаемое название сайта.

## 4. Ограничить Backfill При Добавлении

По умолчанию при добавлении сайта собирается до `500` старых статей.

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/" --backfill-limit=100
```

Параметр `--backfill-limit` задает количество старых статей, которые нужно сохранить сразу после добавления.

## 5. Добавить Сайт Без Backfill

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/" --no-backfill
```

Используйте `--no-backfill` осторожно: первые найденные статьи потом могут считаться новыми.

## 6. Принудительно Указать RSS

Если автоопределение не нашло RSS, но URL RSS-ленты известен:

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/" --source=rss --feed-url="https://example.com/feed/"
```

Параметры:

```text
--source=rss
--feed-url="https://example.com/feed/"
```

## 7. Принудительно Указать HTML

Если RSS нет, но есть страница со списком новостей:

```bash
docker compose run --rm marketing-php php artisan sites:add "https://example.com/" --source=html --listing-url="https://example.com/news"
```

Параметры:

```text
--source=html
--listing-url="https://example.com/news"
```

Если HTML-страница содержит ссылки на разделы, пагинацию или другие страницы, которые ошибочно определяются как статьи, задайте регулярное выражение для пути URL:

```bash
docker compose run --rm marketing-php php artisan sites:add "https://ria-m.tv/ua/" \
  --name="РИА Мелитополь" \
  --source=html \
  --listing-url="https://ria-m.tv/ua/news/" \
  --article-url-pattern='~^/ua/news/[0-9]+/[^/]+\.html$~u' \
  --no-backfill
```

Шаблон проверяется только против пути URL, например `/ua/news/412842/article.html`. Если шаблон не задан, используется стандартное автоматическое определение статей. Ссылки на другие домены и статические файлы отбрасываются независимо от шаблона.

Установить или заменить шаблон существующего сайта:

```bash
docker compose run --rm marketing-php php artisan sites:set-article-url-pattern 42 '~^/news/[0-9]+/[^/]+\.html$~u'
```

Вернуть стандартное автоматическое определение:

```bash
docker compose run --rm marketing-php php artisan sites:set-article-url-pattern 42 --clear
```

## 8. Собрать Старые Статьи

Backfill нужен, чтобы старые статьи попали в базу и не отправились в Telegram как новые.

Собрать старые статьи по всем сайтам:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --limit=500
```

Собрать старые статьи по одному сайту:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --site=1 --limit=500
```

Можно указать сайт по домену, названию или base URL:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --site="zabor.zp.ua" --limit=500
```

Проверить старые статьи на ключевые слова, но без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan parser:backfill --limit=500 --analyze
```

## 9. Проверить Новые Статьи

Ручной запуск проверки новых статей напрямую, без очереди:

```bash
docker compose run --rm marketing-php php artisan parser:check
```

Проверить один сайт:

```bash
docker compose run --rm marketing-php php artisan parser:check --site=1
```

Можно указать сайт по id, названию, домену или base URL:

```bash
docker compose run --rm marketing-php php artisan parser:check --site="example.com"
```

Ограничить количество свежих статей на сайт:

```bash
docker compose run --rm marketing-php php artisan parser:check --limit=20
```

Проверить только часть сайтов за один запуск:

```bash
docker compose run --rm marketing-php php artisan parser:check --sites-limit=15 --limit=20
```

Проверить без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan parser:check --no-notify
```

Статья считается новой, если ее `url` еще нет в таблице `articles`.

Поставить проверки сайтов в очередь `sources`:

```bash
docker compose run --rm marketing-php php artisan sources:dispatch-checks --sites-limit=15 --limit=20
```

Каждый сайт обрабатывается отдельной job. Повторная job для того же сайта не добавляется, пока предыдущая еще находится в очереди или выполняется.

## 10. Обновить Источники Сайтов

Если сайт был добавлен как HTML, но позже появилась RSS-лента, можно заново определить источник:

```bash
docker compose run --rm marketing-php php artisan sites:refresh-sources --site="example.com"
```

Проверить без сохранения изменений:

```bash
docker compose run --rm marketing-php php artisan sites:refresh-sources --dry-run
```

## 11. Автоматическая Проверка

В `src/routes/console.php` настроен поиск готовых к проверке сайтов каждые 10 минут:

```php
Schedule::command('sources:dispatch-checks --sites-limit=15 --limit=20')->everyTenMinutes()->withoutOverlapping();
```

Для обработки jobs нужен worker очереди `sources`:

```bash
docker compose run --rm marketing-php php artisan queue:work --queue=sources --timeout=300 --tries=1
```

В Docker Compose для этого есть отдельный сервис:

```bash
docker compose up -d marketing-sources-queue
```

Scheduler выбирает только сайты с пустым или наступившим `next_check_at`. После успешной проверки следующая назначается через 30 минут. После первой ошибки повтор выполняется через 30 минут, после второй — через 2 часа, после третьей сайт приостанавливается на 4 часа. После четвёртой и последующих временных ошибок проверка повторяется каждые 6 часов.

Временные ошибки (`429`, `5xx`, timeout, DNS) продлевают паузу на 6 часов и не отключают сайт. HTTP `404` или `410` считается постоянной ошибкой; сайт отключается, если она повторно подтверждена на четвёртой проверке спустя 4 часа. При паузе, восстановлении и окончательном отключении отправляются Telegram-уведомления.

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

## 12. Включить Отключённый Сайт

Включить сайт по ID:

```bash
docker compose run --rm marketing-php php artisan sites:enable 42
```

Или по точному URL:

```bash
docker compose run --rm marketing-php php artisan sites:enable "https://example.com/"
```

Команда сбрасывает ошибки и паузу, назначая проверку на ближайший запуск scheduler. Этой же командой можно досрочно возобновить уже включённый, но приостановленный сайт.

## 13. Удалить Сайт

Удалить сайт по ID вместе со всеми собранными статьями и совпадениями ключевых слов:

```bash
docker compose run --rm marketing-php php artisan sites:delete 42
```

Команда показывает количество статей и запрашивает подтверждение. Для запуска без подтверждения используйте `--force`. Общие ключевые слова не удаляются.

## Список Команд

```bash
docker compose run --rm marketing-php php artisan sites:probe "https://example.com/"
docker compose run --rm marketing-php php artisan sites:add "https://example.com/"
docker compose run --rm marketing-php php artisan sites:set-article-url-pattern 42 '~^/news/[0-9]+/[^/]+\.html$~u'
docker compose run --rm marketing-php php artisan sites:delete 42
docker compose run --rm marketing-php php artisan sites:enable 42
docker compose run --rm marketing-php php artisan sites:refresh-sources --site="example.com"
docker compose run --rm marketing-php php artisan parser:backfill --limit=500
docker compose run --rm marketing-php php artisan parser:check
docker compose run --rm marketing-php php artisan sources:dispatch-checks --sites-limit=15 --limit=20
```
