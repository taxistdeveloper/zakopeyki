# SECURITY_AUDIT — риски безопасности и приоритеты

> MASTERING-00 · 2026-11-09 · статический анализ кода, **без запросов к production, без чтения значений секретов**.
> Критичность: Critical / High / Medium / Low. Каждый пункт — [Ф] подтверждён чтением кода, если не указано иное.
> Исправления **не выполнялись** (правило этапа). Рекомендуется отдельная задача «MASTERING-01: security hardening» до начала мобильной разработки.

## 1. Сводка приоритетов

| # | Риск | Критичность | Блокирует мобайл? |
|---|---|---|---|
| S1 | Неаутентифицированный Halyk postlink: подделка оплат и зачисление в кошелёк | **Critical** | Да |
| S2 | `allow_simulated_payments = true` в отслеживаемом `config/app.php` | **Critical** (если так в production) | Да |
| S3 | Ссылка сброса пароля выводится на экран при `Host`, содержащем `localhost` | High (зависит от vhost) | Да |
| S4 | Документы KYC бизнес-заявок публичны в `public/uploads/business/` и в git | High | Нет |
| S5 | Удаление аккаунта невозможно для Google-пользователей; hard delete без проверок | High (App Store) | Да |
| S6 | Нет токен-авторизации для мобильных клиентов (только cookie+CSRF) | High (архитектурный) | Да |
| S7 | Гонка двойного расчёта escrow (`settle()` без блокировки строки заказа) | High (деньги) | Нет, но усиливается мобайлом |
| S8 | `courier_void`: покупатель единолично инициирует полный возврат | Medium | Нет |
| S9 | Webhook Cloudflare Stream без секрета принимает всё; формат подписи не соответствует CF | Medium | Нет |
| S10 | Webhook CDEK открыт в test_mode без токена | Medium | Нет |
| S11 | Сессии не инвалидируются при смене/сбросе пароля | Medium | Да (для токенов) |
| S12 | Подмена IP через `X-Forwarded-For`/`CF-Connecting-IP` обходит IP-лимиты; лимитер fail-open | Medium | Да |
| S13 | Обход лимита AI для гостей ротацией `guest_token` | Medium | Да |
| S14 | Файлы цифровых продуктов и AI-изображений в публичном `public/uploads` | Medium | Нет |
| S15 | Ошибки логики CSRF-исключений (micro-tasks по сессии; business при «любом» Bearer) | Medium | Нет |
| S16 | `debug=true` пишет тела писем (ссылки сброса) в `storage/mail.log` | Medium (если debug включат) | Нет |
| S17 | TOTP-коды можно повторно использовать в окне; нет HSTS/CSP; авто-связывание Google по email | Low | Нет |
| S18 | Отслеживаемые конфиги с литеральными учётными данными БД; посторонние файлы в web root | Low/Medium | Нет |

## 2. Подробности

### S1. Halyk postlink — Critical
- `POST /payments/halyk/postlink` — CSRF-exempt (`app/Core/Router.php:65`), без входа, разрешён даже в stub mode (`index.php:70-89`).
- `PaymentController::halykPostLink` (`app/Controllers/PaymentController.php:76-139`): `secret_hash` сверяется **только если он передан и intent найден** (`:109`); при отсутствии intent `pg_order_id` берётся из тела запроса (`:101-103`).
- Далее `applySimulatedGatewayResult`: для `WALLET-{uid}-…` вызывается `Wallet::deposit($userId, <amount из запроса>)` без проверки intent и без дедупликации (`:226-241`). Платежи заказов/доставки/услуг тоже можно перевести в «оплачено» при совпадении суммы.
- Строгая проверка `HalykEpayGateway::verifyCallback` (`app/Services/Payment/HalykEpayGateway.php:56-70`) **не вызывается**.
- **Рекомендация:** обязательный intent + обязательный `secret_hash` (hash_equals) + сверка суммы/валюты/terminal с intent + идемпотентность по `invoiceId`; для wallet — только через сохранённый intent; по возможности — server-to-server проверка статуса транзакции в Halyk API перед зачислением.

### S2. Симуляция платежей
- `config/app.php:13` `allow_simulated_payments` = true; комментарий требует false в production. При true: мгновенное пополнение кошелька (`WalletController.php:55-56`), зачисление «карта/kaspi» из воздуха (`Order.php:383-385,458-462`), симулированный вывод (`WalletController.php:102`), приём тестовых ключей Halyk (`HalykEpay/Client.php:40-43`).
- [?] Значение в production неизвестно. **Рекомендация:** переключать через env, по умолчанию false; health-check, блокирующий старт при true в prod.

### S3. Утечка ссылки сброса пароля
- `AuthController.php:499-504`: если `HTTP_HOST` содержит `localhost`/`127.0.0.1`, URL сброса кладётся в сессию и показывается на экране.
- Если default vhost в production принимает произвольный `Host`, возможен захват любого аккаунта. [?] конфигурация Apache неизвестна. **Рекомендация:** привязать к явному флагу окружения, не к заголовку.

### S4. Публичные KYC-документы
- `BusinessUpgradeService.php:211-246` сохраняет в `public/uploads/business/` (проверка только расширения/размера); `.htaccess` с deny есть лишь в `disputes/`, `library/`, `ops-tasks/`. Файлы `public/uploads/business/*` отслеживаются git (3 шт.).
- **Рекомендация:** перенести в `storage/` с выдачей через авторизованный контроллер; удалить из истории git по отдельному решению владельца.

### S5. Удаление аккаунта
- `ProfileController.php:1170-1197` требует пароль; `User::verifyPassword` возвращает false при отсутствии пароля (`User.php:819-826`) → Google-only пользователи не могут удалить аккаунт.
- `User.php:873-940` — hard delete без проверки баланса кошелька, открытых escrow-сделок и споров; не удаляет KYC и доказательства споров.
- App Store Review Guideline 5.1.1(v) требует возможность удаления аккаунта внутри приложения; Google Play также требует путь удаления аккаунта и данных.
- **Рекомендация:** процесс «запрос на удаление» с повторной аутентификацией (пароль или OAuth/OTP), блокирующими проверками (открытые сделки, баланс), отложенным удалением/анонимизацией с сохранением финансовых записей в объёме, требуемом законом РК [?].

### S6. Авторизация мобильного клиента
- Нет JWT/bearer/refresh для пользователей (`BaseApiController.php:43-50`). Сегодня мобильный клиент мог бы работать только эмулируя браузер: cookie-jar + парсинг CSRF из HTML. Это хрупко и небезопасно (WebView-cookie, нет отзыва по устройству).
- **Рекомендация:** см. MOBILE_TARGET_ARCHITECTURE §3 (короткий access-токен + ротируемый refresh, хранимый хешем, привязка к устройству, список сессий, отзыв).

### S7. Гонка в расчёте escrow
- `EscrowService::settle` (`:438-589`) читает статус до транзакции и не делает `SELECT … FOR UPDATE` по заказу; `settle` вызывают cron, просмотр `GET /orders/{id}` (`OrderController.php:42`, **до** проверки доступа) и действия пользователей. Мобильные повторы запросов увеличивают вероятность двойного расчёта.
- **Рекомендация:** блокировка строки заказа + проверка статуса внутри транзакции; убрать побочные эффекты из GET.

### S8. `courier_void`
- `ReturnService.php:144-148,611-631`: покупатель по причине `courier_void` получает полный возврат по отправленному курьером заказу без арбитра. [?] Бизнес-намерение подтвердить у владельца.

### S9–S10. Прочие webhook'и
- Cloudflare: HMAC только при непустом `cf_stream_webhook_secret` (`DigitalController.php:346-349`); сравнение со всем заголовком, тогда как CF подписывает `time.body` в формате `time=…,sig1=…` (`CloudflareStreamClient.php:210-217`) — [П] с секретом реальные webhook'и будут отклоняться.
- CDEK: `CdekWebhookService.php:60-85` — без токена в test_mode открыт.
- FreedomPay: подпись `pg_sig` проверяется (`PaymentController.php:269`) — ок.

### S11–S13. Сессии и лимиты
- Remember-me 30 дней, серверный `session.gc_maxlifetime` не настраивается (`Auth.php:16,38-40,54-68`); сброс пароля не убивает другие сессии.
- IP берётся из `CF-Connecting-IP`/`X-Forwarded-For` раньше `REMOTE_ADDR` (`ActivityLogger.php:150-167`); `AuthThrottle` fail-open при недоступности хранилища (`:48-54`).
- AI-лимит ключуется по `guest_token`, который клиент может генерировать заново (`RateLimiter.php:64-74`).

### S14–S16
- `DigitalController.php:580-603` (публично `public/uploads/digital/{id}/`), `/ai/image` → `public/uploads/ai/`.
- CSRF: `Router.php:61-86` — см. API_INVENTORY §1.
- `Mail.php:54-60,372-387` пишет письма в лог при debug или ошибке отправки.

### S17–S18
- `Totp.php:46-61` без учёта использованных кодов; заголовки — только nosniff, X-Frame-Options, Referrer-Policy (`.htaccess:44-47`).
- `config/database.php` в git с литеральными (вероятно, локальными) учётными данными; посторонние файлы в корне (`qa.zip`, `*.txt`, `test_dmitry.mp3`, `tmp_home.html`, `_vas*_extract`).

## 3. Что сделано хорошо (переиспользовать)

- bcrypt (`PASSWORD_DEFAULT`), `session_regenerate_id` при входе, httponly/SameSite cookie.
- Токены сброса/верификации — 32 байта, хранятся sha256, ограниченный TTL (`User.php:493-575`).
- Google OAuth со `state` и `hash_equals`; TOTP 2FA с recovery-кодами.
- Загрузка файлов: allowlist расширений + `finfo` + `getimagesize`/magic bytes, серверные имена файлов (`UploadHelper.php:47-103`).
- Идемпотентность и `FOR UPDATE` в `Payment::completeFromGateway` (`Payment.php:142-205`), блокировки кошелька (`Wallet.php:425-437`).
- Проверки владения в заказах, доставке, чате, доказательствах (`OrderController.php:51-59,261-300`, `Chat.php:252`) — IDOR в проверенных endpoint'ах не найден.
- Суммы доставки — только с сервера (`DeliveryService.php:625-630`).
- Данные карт не проходят через Zakopeyki: используется виджет `halyk.pay()` (`Views/payments/halyk-sim.php:103-110`).
- AI: подтверждение действий токеном с TTL, проверка владения в инструментах, базовый фильтр prompt-injection (`PromptInjectionGuard.php`, слабый — регулярки).

## 4. Мобильная модель хранения учётных данных (требования)

1. **Никаких секретов backend в приложении**: ключи Halyk, CDEK, Azure, Genlook, Ollama, Cloudflare остаются только на сервере. Мобильный клиент знает лишь базовый URL API и публичные идентификаторы (Firebase config, Google/Apple client ID — не секреты).
2. Refresh-токен — в iOS Keychain (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`) / Android Keystore-backed EncryptedSharedPreferences/DataStore; access-токен — только в памяти.
3. Не хранить пароль, коды 2FA, полные персональные данные (ИИН/БИН) локально; кэш — без PII или в зашифрованном хранилище.
4. Привязка refresh-токена к `device_id`, ротация при каждом использовании, обнаружение повторного использования → отзыв всей цепочки.
5. Опционально: биометрия как локальный «замок» поверх refresh-токена; App Attest / Play Integrity для чувствительных операций (решение владельца).
6. Certificate pinning — не обязательно на старте (риск «окирпичивания» при смене сертификата); TLS 1.2+ обязательно.
7. Логи и crash-репорты — с маскированием токенов и PII.
