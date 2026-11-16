# API_INVENTORY — каталог API и пригодность для мобильного клиента

> MASTERING-00 · 2026-11-09. Источник маршрутов: `config/routes.php` (396 маршрутов). Полный машинный список не дублируется — здесь сгруппирован по доменам с оценкой пригодности.
> Пригодность: 🟢 можно использовать почти как есть · 🟡 нужен JSON-адаптер/авторизация · 🔴 только HTML, нужен новый endpoint · ⛔ нельзя открывать мобильному клиенту.

## 1. Общие свойства существующего API

| Свойство | Факт | Источник |
|---|---|---|
| Версионирование | Только префикс `/api/v1/` у трёх групп (micro-tasks, try-on, business). Остальное — «страничные» маршруты без версий | `config/routes.php` |
| Документация | OpenAPI/Swagger для внутреннего API **нет**. `openapi_api_v2_integration.json` — спецификация CDEK | корень репо |
| Авторизация пользователя | Только PHP-сессия (cookie `PHPSESSID`, httponly, SameSite=Lax). Токенов для пользователей нет | `app/Core/Auth.php:28-34`, `BaseApiController.php:43-50` |
| CSRF | Обязателен для всех POST/DELETE (кроме исключений), токен из `_csrf` или `X-CSRF-TOKEN`; выдаётся **только** в HTML (`<meta name="csrf-token">`) | `Router.php:46-48`, `Csrf.php:28,42-55` |
| Исключения CSRF | 4 webhook/callback; все `/api/v1/micro-tasks/*`; `/api/v1/business/*` при наличии любого `Authorization: Bearer` | `Router.php:61-86` |
| Формат ответа | Нет единого стандарта: HTML+redirect+flash (большинство), `{ok,...}` (AJAX), `{success,data,error}` (`BaseApiController.php:21-25`), XML (FreedomPay) | — |
| Ошибки | 419 при CSRF (JSON при `X-Requested-With`/`Accept: json`); 404 — HTML-страница даже для `/api/*` (`Router.php:56-57`) | `Csrf.php:63-71` |
| Пагинация | Почти нигде: лента/каталог без LIMIT; chat poll `after=` + до 50 | `Product.php:121`, `ChatController.php:189` |
| URL медиа | Относительные (`ProductHelper::url`), абсолютные — только `absoluteUrl` | `ProductHelper.php:572-595` |
| Rate limiting | Auth (`AuthThrottle`), AI (20/мин, 200/ч), Try-On (6/мин, 30/ч); общего лимитера нет | `Services/Security/AuthThrottle.php:22-33`, `AI/.../RateLimiter.php:64-74` |
| CORS | Не настроен (для нативного приложения не нужен) | `.htaccess` |

**Вывод:** существующие маршруты спроектированы под браузер. Мобильному клиенту нужен отдельный версионированный JSON-слой (`/api/mobile/v1` или `/api/v2`) с bearer-токенами поверх **существующих моделей и сервисов**, а не над контроллерами-страницами.

## 2. Уже существующие JSON API

### 2.1 Virtual Try-On — `/api/v1/try-on` (`TryOnController`) — 🟡
| Метод | Путь | Auth | Ответ |
|---|---|---|---|
| GET | `/capabilities?product_id` | гость | envelope |
| GET/POST | `/jobs` | сессия (+CSRF на POST) | 202/200 (идемпотентность по `Idempotency-Key`) |
| GET | `/jobs/{id}`, `/jobs/{id}/result`, `/jobs/{id}/photo` | сессия | JSON / `image/jpeg` no-store |
| DELETE / POST | `/jobs/{id}`, `/jobs/{id}/delete` | сессия + CSRF | envelope |

Лучший образец контракта в проекте (envelope, коды ошибок `data.code`, идемпотентность). Не хватает bearer-авторизации (сам код помечает «PHASE 5», `TryOnController.php:14`).

### 2.2 Micro-tasks — `/api/v1/micro-tasks/*` — 🟡
`list, mine, create, offers/{offerId}/select, {id}/offer|complete|review|cancel|delete`. Сессия, CSRF-exempt, доступ ограничен (`requireGigsAccess`, `BaseApiController.php:52-57`).

### 2.3 Seller/Business API — `/api/v1/business/*` — 🟡 (не для покупательского приложения)
`status, purchase, import/xml, import/csv, import/jobs/{id}, catalog/items, api-keys (GET/POST/revoke), ai/infographic|seo|tryon, analytics/extended, manager/request`. Bearer `zk_…` (sha256, без scopes/expiry) принимают только catalogUpsert, imports, featureStatus, importJob (`BusinessApiController.php:287-322`). Это B2B-интеграция, не модель авторизации для мобильного приложения.

### 2.4 AI «Зак» — `/ai/*` (`AiAssistantController`) — 🟡
| Метод | Путь | Auth | Формат |
|---|---|---|---|
| POST | `/ai/chat` | гость (`guest_token`) / сессия; CSRF | JSON `{ok, reply, products[], suggestions[], actions[], response_type, conversation_id, …, tts{}}` |
| POST | `/ai/chat/stream` | то же | SSE `meta → delta* → done` / `error` (псевдо-стрим) |
| GET | `/ai/chat/messages` | то же | JSON |
| POST | `/ai/chat/clear`, `/ai/chat/feedback` | то же | JSON |
| POST | `/ai/voice` | гость/сессия | multipart `audio` / `audio_base64` / `transcript`; `transcribe_only`, `client_request_id` (409 на дубль) |
| POST | `/ai/tts` → GET `/ai/tts/audio/{token}` | гость/сессия; токен аудио привязан к user id или **session id** | JSON → `audio/mpeg` (Range) |
| POST | `/ai/image` | только вход | multipart `image` |
| POST | `/ai/action/confirm` | только вход | `{token}` → `{ok, action, product_id, url}` |

### 2.5 Доставка — `/delivery/*` (`DeliveryController`) — 🟡
Около 40 JSON-ответов: справочники CDEK (`/delivery/cdek/points|cities|regions|postal-codes|coordinates`), отправитель/получатель, расчёт и выбор тарифа, регистрация, трекинг, проблемы, отказ/возврат, печатные формы. Проверки владения через `*ForUser`. `POST /delivery/{id}/pay` — redirect на оплату.

### 2.6 Live Commerce — `/streams/live/*` (`StreamController`) — 🟡/🔴
JSON: `start, heartbeat, end, signal, signal/poll, shop, comments, comment, like, giveaway/join|draw, feature, my-products`. Транспорт (P2P mesh + MySQL-сигнализация) для мобайла непригоден — см. MOBILE_TARGET_ARCHITECTURE §7.

### 2.7 Прочие JSON/XHR
- `/auctions/{id}/live` (JSON), `/auctions/{id}/bid|buy-now|accept` (POST).
- `/chat/{id}/poll` (JSON, `after=`), `/chat/{id}/send`.
- `/cart/*`, `/favorites/{id}/toggle`, `/users/{id}/follow` — JSON только при `X-Requested-With`.
- `/profile/listing-rules`, `/profile/lots/*-status` — JSON.
- `/digital/{id}/playback|heartbeat|complete|chat` — JSON.

## 3. HTML-only домены (нужны новые JSON-endpoint'ы) — 🔴

| Домен | Маршруты | Сервисы для переиспользования |
|---|---|---|
| Аутентификация | `/login`, `/login/2fa`, `/register`, `/forgot-password`, `/reset-password/{token}`, `/verify-email/{token}`, `/auth/google*`, `/logout` | `User`, `AuthThrottle`, `Totp`, `GoogleOAuth` (частично) |
| Главная, каталог, поиск, карточка, профиль продавца | `/`, `/catalog/{section}`, `/product/{id}`, `/users/{id}` | `Product::allActive/searchAdvanced/similar`, `ProductHelper`, `Review::statsForMany`, `Follow` |
| Создание/редактирование объявлений | `/profile/store`, `/profile/lots/{id}/update|delete`, `/profile/gigs/{id}/update` | логика внутри `ProfileController` — **нужно вынести в сервис** (`ListingService`) |
| Checkout | `/checkout/{id}`, `/checkout/{id}/pay`, `/checkout/cart*`, `/checkout/success/{id}` | `Order::createEscrow/createEscrowCart`, `EscrowService` |
| Заказы, возвраты, споры | `/orders`, `/orders/{id}`, `/orders/{id}/{ship,delivered,confirm,cancel,dispute,return-*,review}` | `EscrowService`, `ReturnService`, `Review` |
| Кошелёк | `/wallet`, `/wallet/deposit`, `/wallet/withdraw` | `Wallet`, `AMLService` |
| Профиль, 2FA, удаление | `/profile*`, `/profile/2fa/*`, `/profile/delete` | `User` |
| Уведомления | только рендер + `/notifications/clear` | `Notification` |
| Stories | `POST /stories`, `/stories/{id}/delete`; чтение — внутри главной | `Story::activeGrouped` |
| Платные услуги | `/profile/lots/{id}/{boost,highlight,top-fixed,top-fixed-xl,vip}`, `/ads*`, `/target-push*`, `/mass-push*` | соответствующие `*Service` |
| Business, бонусы, конкурсы, поддержка | `/business/*`, `/bonuses*`, `/contests*`, `/support*` | соответствующие сервисы |

## 4. ⛔ Не открывать мобильному клиенту

| Группа | Причина |
|---|---|
| `/admin/*` (~105 маршрутов) | Административные операции; мобильное приложение не является админкой (решение владельца) |
| `/orders/{id}/arbiter-*`, `approve-return`, `reject-dispute` | Арбитраж — только staff с permission `disputes`; в мобильный клиент v1 не выносить |
| `/payments/halyk/postlink`, `/payments/freedompay/result`, `/webhooks/*` | Server-to-server; **postlink сейчас не аутентифицирован** (Critical) |
| `/payments/halyk/sim/{token}` | Страница запуска платёжного виджета; в мобайле открывать только в системном браузере/WebView, без доступа клиента к параметрам расчёта |
| `/wallet/deposit` и `/wallet/withdraw` в текущем виде | Симуляция при `allow_simulated_payments=true`; реальное пополнение сломано |
| `/streams/live/giveaway/draw` | Должен вызываться только ведущим, выбор победителя — сервер |
| Любые параметры суммы/цены/комиссии от клиента | Расчёт строго на сервере (`DeliveryService.php:625-630` — правильный пример: суммы с фронта отбрасываются) |

## 5. Несогласованности контрактов

1. Три разных формата JSON-ответов (`{ok}`, `{success,data,error}`, сырые объекты) и HTML-404 для API.
2. Сообщения об ошибках на русском, захардкожены (~94 мест), без машинных кодов (кроме Try-On).
3. Даты — строки MySQL `Y-m-d H:i:s` в локальной TZ Asia/Almaty без смещения [П] (ISO 8601 не используется).
4. Деньги — целые тенге (`int`) с форматированием на сервере `number_format … ' ₸'`; нет поля валюты в большинстве ответов.
5. Медиа — относительные URL, без размеров и превью.
6. Идентификаторы — целочисленные автоинкременты (перебор по ID; владение проверяется, IDOR в ключевых местах не найдено — см. SECURITY_AUDIT §10).
