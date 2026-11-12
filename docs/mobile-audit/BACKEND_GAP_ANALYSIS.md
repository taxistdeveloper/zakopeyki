# BACKEND_GAP_ANALYSIS — необходимые backend-изменения для мобильного приложения

> MASTERING-00 · 2026-11-09. Приоритет: **P0** — блокер (безопасность/деньги, до любого мобильного релиза) · **P1** — нужно для MVP · **P2** — для функционального паритета · **P3** — улучшения.
> Размер: S (≤3 дн.), M (1–2 нед.), L (2–4 нед.), XL (>4 нед.) — грубая оценка одного разработчика, знакомого с кодом; неопределённость ±50%.

## P0 — безопасность и корректность денег

| ID | Изменение | Затрагивает | Размер |
|---|---|---|---|
| B0.1 | Аутентификация Halyk postlink: обязательный intent + `secret_hash`, сверка суммы/терминала, идемпотентность по invoice, wallet только по intent (S1) | `PaymentController::halykPostLink/applySimulatedGatewayResult`, `HalykEpayGateway::verifyCallback`, `IntentStore` | M |
| B0.2 | `allow_simulated_payments`, `debug`, `stub_mode` — из env, безопасные значения по умолчанию, проверка при старте (S2) | `config/app.php`, `index.php` | S |
| B0.3 | Исправить реальный путь пополнения кошелька (`createPaymentIntent` не существует) | `WalletController.php:81`, `HalykEpay/Client::initPayment` | S |
| B0.4 | Ссылка сброса пароля — только по явному флагу окружения (S3) | `AuthController.php:499-504` | S |
| B0.5 | Блокировка строки заказа в `settle()` и проверка статуса в транзакции; убрать `processDeadlines` из GET `/orders/{id}` (S7) | `EscrowService.php:438-589`, `OrderController.php:42` | M |
| B0.6 | KYC/цифровые файлы/AI-изображения — из `public/uploads` в `storage/` с авторизованной выдачей (S4, S14) | `BusinessUpgradeService`, `DigitalController`, `AiAssistantController::image` | M |
| B0.7 | Webhook'и Cloudflare/CDEK: обязательный секрет вне test, корректный формат подписи CF (S9–S10) | `DigitalController.php:346-349`, `CloudflareStreamClient.php:210-217`, `CdekWebhookService.php:60-85` | S |
| B0.8 | Доверенные прокси для IP; fail-closed (или degraded) для `AuthThrottle` (S12) | `ActivityLogger.php:150-167`, `AuthThrottle.php:48-54` | S |

## P1 — фундамент мобильного API (MVP)

| ID | Изменение | Детали | Размер |
|---|---|---|---|
| B1.1 | Мобильная авторизация | `api_refresh_tokens`, access-токены, `/auth/{login,2fa,refresh,logout,logout-all,register,forgot,reset,verify-resend}`, middleware bearer; отзыв при смене пароля/блокировке | L |
| B1.2 | Нативный Google + Sign in with Apple | проверка ID-token (JWKS), связывание аккаунтов с подтверждением | M |
| B1.3 | Инфраструктура API | `/api/mobile/v1` в `Router` (+ метод PATCH при необходимости), envelope, машинные коды ошибок, JSON-404/405/500, `X-Request-Id`, версия клиента/`426`, общий rate limiter, `Idempotency-Key` middleware | M |
| B1.4 | OpenAPI 3.1 спецификация + проверка в CI | `docs/api/mobile-v1.yaml` | M (растёт с API) |
| B1.5 | Конфиг-endpoint | `GET /config`: флаги, min version, категории, лимиты загрузки, комиссии/сборы для отображения | S |
| B1.6 | Каталог и поиск | курсорная пагинация, фильтры (тип, категория, цена, город, состояние), сортировки, FULLTEXT (`ft_search`) или внешний поиск позже; вынести `runDueLifts` из read-path (`Product.php:191`); индексы `(status,type,sort_at,id)`, по категории | L |
| B1.7 | Категории как данные | API дерева из `ProductHelper::PRODUCT_CATEGORY_TREE` (минимум) или таблица категорий (решение) | S–M |
| B1.8 | Карточка товара, профиль продавца, отзывы, избранное (список), подписки | JSON-представления на базе существующих моделей; абсолютные URL медиа | M |
| B1.9 | Медиа-пайплайн | upload-endpoint, ресайз/превью (GD уже есть), размеры в ответе; лимиты; позже объектное хранилище | M |
| B1.10 | Создание/редактирование объявлений | вынести логику `ProfileController::store/updateLot/deleteLot/storeGig` в `ListingService`; web и mobile вызывают его | L |
| B1.11 | Корзина в БД | таблица `cart_items`, мерж гостевой корзины; web переводится на неё же | M |
| B1.12 | Checkout + preview | вынести из `CheckoutController` в `CheckoutService`; `POST /checkout/preview` (расчёт 3%/доставки), `POST /checkout` → `payment_url`; возврат в приложение по deep link | L |
| B1.13 | Заказы, действия по заказу, возвраты | JSON-обёртки над `EscrowService`/`ReturnService` для ролей buyer/seller (без арбитража) | M |
| B1.14 | Чат | список диалогов, сообщения с курсором, отправка, отметки прочтения; вложения-фото (новое) | M |
| B1.15 | Уведомления + push | `GET /notifications` (курсор, unread), `device_push_tokens`, `PushDispatcher` (FCM HTTP v1), категории согласий, отправка из очереди | L |
| B1.16 | Удаление аккаунта | путь для OAuth-пользователей (повторный вход/OTP), проверки сделок и баланса, анонимизация, `DELETE /me` (S5) | M |
| B1.17 | Локализация API | `en` locale, per-key fallback, `Accept-Language`, перевод ~94 захардкоженных JSON-сообщений и ~159 непереведённых kk-строк | M |
| B1.18 | Доставка для мобайла | использовать существующие JSON-endpoint'ы `DeliveryController` через bearer (минимальная адаптация), `pay` → `payment_url` | S–M |
| B1.19 | Universal/App links | `apple-app-site-association`, `assetlinks.json`, маршруты-обработчики | S |
| B1.20 | Наблюдаемость | Sentry (PHP), структурированные логи, алерты на ошибки платежей/воркеров | S–M |

## P2 — функциональный паритет

| ID | Изменение | Размер |
|---|---|---|
| B2.1 | Stories: read-API (группы, просмотры), загрузка через медиа-пайплайн, превью/постер, ограничение длительности; **единый формат Stories/Shorts** (модель, ленты) — требует продуктового решения | L |
| B2.2 | Live Commerce: SFU (LiveKit/Cloudflare), выдача токенов комнат, realtime-канал для комментариев/лайков/магазина; giveaway — сервер | XL |
| B2.3 | Realtime-шлюз (WebSocket/SSE) для чата, аукционов, live | L |
| B2.4 | Аукционы: JSON-API ставок, серверное время, события в realtime | M |
| B2.5 | Платные услуги и Business в мобайле — после решения по IAP (Q3): JSON-обёртки над сервисами Boost/Highlight/TopFixed/VIP/Ads/TargetPush/MassPush/Business; при IAP — серверная проверка чеков App Store Server API / Google Play Developer API | L–XL |
| B2.6 | Кошелёк: история, пополнение, реальный вывод (интеграция выплат — отсутствует) | L |
| B2.7 | Zak для мобайла: bearer-авторизация `/ai/*`, привязка `guest_token` к устройству и лимит по устройству/IP, TTS-токены без привязки к session id, ffmpeg для m4a/AAC (или требование WAV), настоящий стриминг (`OllamaProvider::chatStream` уже есть) | M |
| B2.8 | Try-On: bearer-авторизация (запланированная «PHASE 5») | S |
| B2.9 | Конкурсы, бонусы, рефералы (deferred deep links), micro-tasks, цифровые курсы (Cloudflare signed playback) | L |
| B2.10 | Поддержка (тикеты), жалобы | S |

## P3 — улучшения качества

- Миграционный раннер с учётом версий вместо runtime `ensureTable/SHOW COLUMNS` (~52 модели).
- Объектное хранилище + CDN для всех медиа; транскодинг видео.
- Поисковый движок (Meilisearch/OpenSearch) при росте каталога.
- Резервное копирование БД и медиа с проверкой восстановления.
- CI/CD для backend, staging-окружение, автоматический деплой с миграциями и рестартом воркеров (сейчас `deploy.sh` = `git pull`).
- Вынос констант комиссии/сбора (`EscrowService.php:24,27`) в версионируемую конфигурацию с историей (если бизнесу нужны изменения ставок).

## Критическая цепочка зависимостей

```
B0.* ──► B1.3 ──► B1.1/B1.2 ──► B1.6–B1.10 ──► B1.11–B1.13 (+B1.18) ──► B1.15 ──► MVP
              └─► B1.4 (OpenAPI, параллельно и постоянно)
B1.15 ──► B2.3 ──► B2.2 / B2.4
Q3 (IAP) ──► B2.5
```
