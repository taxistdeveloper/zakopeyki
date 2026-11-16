# MOBILE_TARGET_ARCHITECTURE — предлагаемая архитектура

> MASTERING-00 · 2026-11-09. Это **предложение для утверждения**, не реализация. Окончательный UI не проектируется. Стек клиента — согласно MOBILE_STACK_COMPARISON (RN + Expo, резерв — Flutter); серверная часть не зависит от выбора клиента.

## 1. Общая схема

```
┌───────────── Mobile app (iOS/Android, RN+Expo, TS) ─────────────┐
│ UI (screens) → feature modules → domain hooks/state → API client │
│ (generated from OpenAPI) → transport (HTTPS, SSE, WS later)      │
│ secure storage · media/upload queue · push · deep links · i18n   │
└─────────────────────────────┬────────────────────────────────────┘
                              │ Bearer access token, JSON, /api/mobile/v1
┌─────────────────────────────▼────────────────────────────────────┐
│ PHP monolith (существующий)                                       │
│  NEW: app/Controllers/Api/Mobile/*  (тонкие JSON-контроллеры)     │
│  NEW: ApiAuth (токены), ApiResponse (envelope), ApiErrors, Rate   │
│  REUSE: Models/*, Services/* (Escrow, Return, Delivery, Cdek, AI, │
│         TryOn, Boost…, Wallet, Payment, AML, Contest…)            │
│  NEW: Listing/Checkout/Cart сервисы, вынесенные из контроллеров   │
│  NEW: DeviceToken + PushDispatcher (FCM HTTP v1 / APNs)           │
│  EXISTING: cron/воркеры; Halyk postlink (после исправления S1)    │
└──────────────────────────────────────────────────────────────────┘
Внешние: Halyk ePay (виджет), CDEK, Cloudflare Stream, Ollama/whisper/TTS, FCM/APNs, SFU (решение владельца)
```

Принцип: **бизнес-логика остаётся в существующих сервисах PHP**. Мобильный слой — новые тонкие контроллеры; логика, которая сейчас живёт внутри HTML-контроллеров (`ProfileController::store`, `CheckoutController::pay`, `OrderController::*`), выносится в сервисы, которые затем вызываются и web-, и mobile-контроллерами (без дублирования).

## 2. Структура мобильного клиента

```
app/                       маршруты (expo-router), группы: (guest) (buyer) (seller) (auth)
src/api/                   сгенерированный клиент + interceptors (auth refresh, retry, idempotency)
src/features/<domain>/     catalog, product, search, favorites, cart, checkout, orders, returns,
                           delivery, chat, notifications, listings, media, stories, live, auctions,
                           contests, wallet, paid-services, business, zak, tryon, profile, auth
src/shared/ui/             дизайн-система (токены цвета/типографики, компоненты, доступность)
src/shared/lib/            i18n, money/date formatting, analytics, logger, feature flags, storage
src/state/                 серверное состояние — TanStack Query; локальное — Zustand (минимально)
```

Правила слоёв: экран → хук фичи → API-клиент; экран не вызывает fetch напрямую; бизнес-правила (комиссии, статусы, сроки) **не вычисляются на клиенте** — только отображаются из ответа сервера.

## 3. Авторизация и сессии

- Новый `POST /api/mobile/v1/auth/login` (email+пароль) → при включённой 2FA возвращает `challenge_id`; `POST …/auth/2fa` → токены.
- **Access-токен**: короткий (10–15 мин), подписанный (JWT с ротацией ключей или opaque + lookup) — решение при реализации.
- **Refresh-токен**: opaque 32+ байт, хранится **хешем** в новой таблице `api_refresh_tokens` (user_id, device_id, family_id, expires_at, revoked_at, last_used_at, ua/platform), ротация при каждом обновлении, повторное использование → отзыв семейства.
- `POST …/auth/google` (ID-token из нативного SDK, проверка `aud`/`iss`/`exp`/`email_verified`), `POST …/auth/apple` (Sign in with Apple — обязателен для iOS при наличии Google-входа).
- Регистрация, сброс пароля, подтверждение email — JSON-версии на базе существующих `User`-методов и `AuthThrottle`; письма содержат universal/app links.
- Список активных устройств и «выйти везде»; отзыв всех токенов при смене/сбросе пароля и блокировке (`blocked_at`).
- CSRF для bearer-маршрутов не нужен; маршруты `/api/mobile/*` должны **не принимать** cookie-сессию (исключить смешение моделей — проблема S15).
- Хранение на устройстве — см. SECURITY_AUDIT §4.

## 4. Контракты API

- Префикс `/api/mobile/v1`; ломающие изменения — только в новой версии; поддержка минимум N-1 версии клиента; заголовки `X-App-Version`, `X-Platform`, ответ `426 Upgrade Required` для принудительного обновления.
- Envelope (обобщение существующего `BaseApiController`): `{ "success": bool, "data": …, "error": { "code": "snake_case", "message": "<локализовано>", "fields": {…} }, "meta": { "next_cursor": … } }`.
- Коды ошибок машинные, тексты — через `t()` по `Accept-Language` (kk/ru/en).
- Пагинация — **курсорная** (`cursor`, `limit ≤ 50`); для ленты — по (`sort_at`,`id`).
- Деньги: `{ "amount": 12990, "currency": "KZT" }` (целые тенге, как в БД); даты — ISO 8601 с TZ.
- Медиа: абсолютные URL + варианты размеров (`thumb`, `medium`, `full`) + `width/height`.
- Идемпотентность: заголовок `Idempotency-Key` на все денежные и создающие POST (уже есть в Try-On — обобщить).
- Источник истины — **OpenAPI 3.1**, хранится в репозитории; клиент генерируется; CI проверяет совместимость (oasdiff).

## 5. Кэширование, офлайн, синхронизация

- Онлайн-первый маркетплейс; офлайн — только просмотр закэшированного (лента, карточки, избранное, заказы, чаты) с пометкой устаревания.
- TanStack Query с persist (MMKV/AsyncStorage, без PII); ETag/`If-None-Match` для справочников (категории, ПВЗ CDEK, конфиг флагов).
- Денежные операции **никогда не ставятся в офлайн-очередь**; черновики объявлений и неотправленные сообщения чата — локальная очередь с явным статусом.
- Корзина — серверная (новая таблица), с мержем гостевой корзины при входе.

## 6. Медиа: загрузка и выгрузка

- Сжатие на клиенте перед загрузкой (фото до ~2048 px, JPEG/WebP ~0.8; видео — пережатие до 720p/1080p H.264) — снижает трафик и укладывается в лимиты 5/50 MB.
- Загрузка отдельным шагом: `POST /media/uploads` → `upload_id` (позже — presigned URL в объектное хранилище), затем привязка к объявлению/Story. Фоновая загрузка (iOS background URLSession, Android WorkManager), возобновление, прогресс.
- Сервер: генерация превью/ресайз (сейчас отсутствует), постер для видео, проверка длительности (`VideoProbe` уже есть, не используется), в перспективе — объектное хранилище + CDN и транскодинг (Cloudflare Stream уже используется для курсов — кандидат для Stories/Shorts).
- Воспроизведение: HLS для длинного видео; MP4 progressive для коротких Stories допустим на старте.

## 7. Push, realtime, deep links

- **Push:** таблица `device_push_tokens` (user_id, device_id, platform, token, locale, app_version, enabled_categories); `PushDispatcher`, подключённый к единой точке `Notification::createFor` (все существующие уведомления, включая TargetPush/MassPush, автоматически получают канал push по согласию пользователя). FCM HTTP v1 для Android и iOS (или APNs напрямую). Отправка — через очередь/cron, не в запросе.
- **Realtime:** этап 1 — существующий polling (чат 3 с) с увеличенным интервалом в фоне + push «новое сообщение»; этап 2 — отдельный realtime-шлюз (WebSocket/SSE; варианты: Centrifugo/Soketi/Mercure — решение владельца), PHP публикует события через HTTP API шлюза.
- **Live Commerce:** текущий P2P mesh без TURN непригоден для мобильных сетей → SFU (LiveKit self-host/cloud или Cloudflare Realtime/Stream WHIP-WHEP). Токены комнаты выдаёт PHP; комментарии/лайки/магазин — через realtime-шлюз.
- **Deep links:** universal links (iOS, `apple-app-site-association`) и App Links (Android, `assetlinks.json`) для `/product/{id}`, `/users/{id}`, `/orders/{id}`, `/contests/{slug}`, `/reset-password/{token}`, `/verify-email/{token}`, `?ref=`; отложенные deep links для реферальной программы.

## 8. Платежи (Halyk, Safe Deal)

1. Клиент вызывает `POST /checkout` (товар/корзина, `deal_mode`, способ доставки) → сервер создаёт заказ `awaiting_payment` и платёжный intent, возвращает `payment_url` (одноразовый токен).
2. Клиент открывает URL в SFSafariViewController / Chrome Custom Tabs (предпочтительно) или WebView; виджет Halyk работает на странице сервера.
3. Подтверждение оплаты — **только** серверный postlink (после исправления S1); клиент после возврата по deep link опрашивает `GET /orders/{id}` / `GET /payments/{id}`.
4. Комиссия 10%, сбор 3%, сроки — рассчитывает сервер и возвращает в preview-ответе (`/checkout/preview`).
5. **Цифровые товары и внутриплатформенные услуги** (Boost/VIP/Ads/Business, курсы) могут требовать Apple IAP / Google Play Billing — см. RISKS Q3. Архитектура должна позволять скрывать/переключать способ оплаты для них по платформе через feature flags.

## 9. Ошибки, аналитика, наблюдаемость

- Клиент: Sentry (или Firebase Crashlytics) с маскированием PII/токенов; release health; source maps/dSYM загружаются CI.
- Сервер: `X-Request-Id` во всех ответах (в AI уже есть `request_id`), структурированный лог, Sentry для PHP (сейчас мониторинга нет).
- Аналитика: продуктовые события по согласию пользователя (ATT на iOS при трекинге); минимальный набор: просмотр карточки, добавление в корзину, начало/успех оплаты, публикация объявления.

## 10. Локализация KK/RU/EN

- Клиент: ICU MessageFormat (i18next/FormatJS), ключи строк клиента отдельно от `lang/*.php`; язык по системной настройке с ручным переключателем; передача `Accept-Language` на сервер.
- Сервер: добавить `en` в `config/app.php:locales` и `lang/en.php`; per-key fallback kk→ru→en (сейчас отсутствует, `Lang.php:65-73`); перенести захардкоженные русские сообщения API в `t()`.
- Форматирование: `Intl.NumberFormat('kk-KZ'|'ru-KZ'|'en-KZ', {currency:'KZT'})`, даты в Asia/Almaty; поддержка Dynamic Type / font scale; RTL не требуется.

## 11. Feature flags и поэтапное включение

- Существующие флаги в `Setting` (`contests_enabled`, флаги платных услуг, `finance_journal_admin_enabled` …) публиковать в `GET /api/mobile/v1/config` (только безопасные к раскрытию) вместе с `min_supported_version`.
- Отдельные мобильные флаги по платформе и версии (например, `mobile.paid_services.ios=false` до решения по IAP).

## 12. Тестирование и CI/CD

- Сервер: включить PHPUnit (сейчас не установлен) + существующие раннеры `tools/run_*_tests.php` в CI; контрактные тесты OpenAPI; интеграционные тесты escrow/return/postlink на тестовой БД.
- Клиент: Jest + React Native Testing Library (unit/компоненты), Maestro или Detox (E2E по критическим путям), MSW-моки по OpenAPI.
- CI: GitHub Actions (или аналог) → lint/typecheck/tests → EAS Build (preview для внутреннего тестирования, production) → EAS Submit в TestFlight / Play Internal testing; OTA-каналы `preview`/`production`.
- Устройства: матрица минимум — iPhone SE (малый экран), актуальный iPhone, бюджетный Android 2–3 ГБ ОЗУ, средний Android, планшет (только проверка отсутствия поломок); эмуляторы для регресса, реальные устройства для медиа/live/push/платежей.

## 13. Переиспользование backend (сводка)

| Переиспользуется как есть | Переиспользуется с выносом/доработкой | Новое |
|---|---|---|
| `EscrowService`, `ReturnService`, `Delivery/*`, `Cdek/*`, `Payment::completeFromGateway`, `Wallet` (блокировки), `AMLService`, AI Orchestrator и Tools, TryOn сервисы, `Review`, `Follow`, `Notification`, `AuctionService`, Contest-сервисы, `PaidServiceCatalog` и сервисы услуг | логика создания объявлений (из `ProfileController`), checkout (из `CheckoutController`), корзина (сессия → БД), поиск (`searchAdvanced` + FULLTEXT), Stories-чтение, Lang (en + fallback), загрузка медиа (ресайз/превью), удаление аккаунта | токены и устройства, push, realtime-шлюз, SFU для live, OpenAPI, envelope/ошибки, курсорная пагинация, конфиг-endpoint, мониторинг |

Детализация — BACKEND_GAP_ANALYSIS.md.
