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
```

Очистите кеш конфигурации и проверьте подключение:

```bash
docker compose run --rm marketing-php php artisan config:clear
docker compose run --rm marketing-php php artisan gmail:status
```

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
