# Как Добавить Сайт

Документ описывает добавление новостного сайта в мониторинг и команды для работы с парсером сайтов.

Все команды запускаются из корня проекта.

## 1. Проверить Сайт Перед Добавлением

Перед добавлением проверьте, сможет ли приложение читать сайт через RSS или HTML:

```bash
docker compose run --rm marketing-php php artisan sites:probe "https://www.soda.zp.ua/"
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
docker compose run --rm marketing-php php artisan sites:add "" --name="Example News"
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

## 8. Настроить Отбор URL И Текст Статьи

Парсер обрабатывает сайт в два отдельных этапа:

```text
listing_url → article_url_pattern → URL статьи → content_selector → проверка ключевых слов
```

| Поле | Формат | Когда применяется | Назначение |
|---|---|---|---|
| `article_url_pattern` | PCRE regex | На странице списка новостей, до сохранения статьи | Отбирает только правильные URL статей |
| `content_selector` | CSS selector | После открытия страницы статьи | Выделяет основной текст без меню, рекомендаций и других посторонних блоков |

`article_url_pattern` не выбирает текст статьи, а `content_selector` не фильтрует найденные ссылки.

### Настроить article_url_pattern

Если страница списка содержит ссылки на разделы, пагинацию или другие страницы, которые ошибочно определяются как статьи, задайте регулярное выражение для пути URL:

```bash
docker compose run --rm marketing-php php artisan sites:add "https://ria-m.tv/ua/" \
  --name="РИА Мелитополь" \
  --source=html \
  --listing-url="https://ria-m.tv/ua/news/" \
  --article-url-pattern='~^/ua/news/[0-9]+/[^/]+\.html$~u' \
  --no-backfill
```

Шаблон проверяется только против пути URL, например `/ua/news/412842/article.html`. Домен и query-параметры в проверке не участвуют.

Если шаблон не задан, используется стандартное автоматическое определение статей. Если шаблон задан, он заменяет стандартное определение. Ссылки на другие домены и статические файлы отбрасываются независимо от шаблона.

Используйте якоря `^` и `$`, чтобы выражение проверяло весь путь. В shell заключайте regex в одинарные кавычки, чтобы специальные символы не обрабатывались оболочкой.

Установить или заменить шаблон существующего сайта:

```bash
docker compose run --rm marketing-php php artisan sites:set-article-url-pattern 42 '~^/news/[0-9]+/[^/]+\.html$~u'
```

Вернуть стандартное автоматическое определение:

```bash
docker compose run --rm marketing-php php artisan sites:set-article-url-pattern 42 --clear
```

Невалидный или слишком длинный regex команда сохранить не позволит. Если значение было повреждено прямым изменением базы, проверка сайта завершится явной ошибкой конфигурации.

### Настроить content_selector

`content_selector` задаёт CSS-контейнер с основным текстом на странице статьи. Он помогает исключить связанные новости, комментарии, боковые панели и другие блоки, которые могут создавать ложные совпадения ключевых слов.

Например, для такой разметки:

```html
<div class="post">
    <div class="content">Основной текст статьи</div>
</div>
<section class="related">Другие новости</section>
```

нужно задать:

```bash
docker compose run --rm marketing-php php artisan sites:set-content-selector 42 '.post > .content'
```

Команда принимает ID, название, домен или base URL сайта:

```bash
docker compose run --rm marketing-php php artisan sites:set-content-selector "example.com" 'main .article-body'
```

Удалить настройку и вернуть стандартное извлечение:

```bash
docker compose run --rm marketing-php php artisan sites:set-content-selector 42 --clear
```

Подходящие примеры CSS-селекторов:

```css
article
.post-content
.post > .content
main .article-body
```

Парсер использует первый элемент, найденный по selector. Если `content_selector` не задан, некорректен или не найден, приложение пишет предупреждение в лог и последовательно пробует `<article>`, `<main>` и `<body>`.

Перед выбором selector откройте страницу обычной статьи в DevTools и найдите стабильный контейнер с основным текстом. Не используйте сгенерированные классы, которые меняются при каждой сборке сайта.

Поле работает как для HTML-, так и для RSS-источников: RSS отвечает за обнаружение URL, после чего страница статьи всё равно загружается для анализа текста.

### Настроить поля через seeder

Обе настройки можно хранить рядом с конфигурацией сайта в `MonitoredSitesSeeder`:

```php
[
    'name' => 'Example News',
    'url' => 'https://example.com/',
    'source_type' => 'html',
    'listing_url' => 'https://example.com/news/',
    'article_url_pattern' => '~^/news/[0-9]+/[^/]+\.html$~u',
    'content_selector' => 'main .article-body',
],
```

Для RSS-источника `article_url_pattern` не применяется и очищается при переключении источника на RSS. `content_selector` при этом сохраняет смысл и продолжает управлять извлечением текста статьи.

### Проверить настройки

Проверить обнаружение ссылок без сохранения статей и уведомлений:

```bash
docker compose run --rm marketing-php php artisan sites:audit --site=42 --limit=20
```

Выполнить полный анализ одного сайта без Telegram-уведомлений:

```bash
docker compose run --rm marketing-php php artisan parser:check --site=42 --limit=20 --no-notify
```

После проверки убедитесь, что в `articles.url` нет ссылок на категории и пагинацию, а в логах отсутствуют предупреждения `Configured article content selector did not match`.

## 9. Собрать Старые Статьи

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

## 10. Проверить Новые Статьи

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

## 11. Обновить Источники Сайтов

Если сайт был добавлен как HTML, но позже появилась RSS-лента, можно заново определить источник:

```bash
docker compose run --rm marketing-php php artisan sites:refresh-sources --site="example.com"
```

Проверить без сохранения изменений:

```bash
docker compose run --rm marketing-php php artisan sites:refresh-sources --dry-run
```

## 12. Автоматическая Проверка

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

## 13. Включить Отключённый Сайт

Включить сайт по ID:

```bash
docker compose run --rm marketing-php php artisan sites:enable 42
```

Или по точному URL:

```bash
docker compose run --rm marketing-php php artisan sites:enable "https://example.com/"
```

Команда сбрасывает ошибки и паузу, назначая проверку на ближайший запуск scheduler. Этой же командой можно досрочно возобновить уже включённый, но приостановленный сайт.

## 14. Удалить Сайт

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
docker compose run --rm marketing-php php artisan sites:set-content-selector 42 'main .article-body'
docker compose run --rm marketing-php php artisan sites:delete 42
docker compose run --rm marketing-php php artisan sites:enable 42
docker compose run --rm marketing-php php artisan sites:refresh-sources --site="example.com"
docker compose run --rm marketing-php php artisan parser:backfill --limit=500
docker compose run --rm marketing-php php artisan parser:check
docker compose run --rm marketing-php php artisan sources:dispatch-checks --sites-limit=15 --limit=20
```
