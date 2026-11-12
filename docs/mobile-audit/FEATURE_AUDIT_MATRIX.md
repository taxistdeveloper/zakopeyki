# FEATURE_AUDIT_MATRIX — фактический статус функций

> MASTERING-00 · 2026-11-09. Статусы: ✅ реализовано · 🟡 частично · ❌ отсутствует · ❔ не удалось проверить.
> «Ответ» — формат ответа существующего backend: **HTML** (страница/redirect + flash в сессии), **JSON**, **JSON/XHR** (JSON только при заголовке `X-Requested-With`).
> «Мобайл» — что нужно для мобильного клиента: **R** — переиспользовать сервис как есть через новый JSON-слой, **R+** — переиспользовать с доработкой, **N** — нужна новая серверная реализация.

## 1. Аккаунт и профиль

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Регистрация email+пароль | ✅ | `AuthController::register` (`:171-282`) | HTML | R+ | Логинит сразу; верификация email не обязательна для входа |
| Вход email+пароль | ✅ | `AuthController::login` (`:39-93`), bcrypt (`User.php:394`) | HTML | R+ | Нужна выдача токенов вместо cookie |
| 2FA TOTP + recovery-коды | ✅ | `AuthController::twoFactor*`, `Helpers/Totp.php` | HTML | R+ | Нет защиты от повторного использования кода |
| Восстановление пароля | ✅ | `AuthController::forgot/resetPassword`, токены sha256, TTL 1 ч | HTML | R+ | Не инвалидирует другие сессии |
| Подтверждение email | ✅ | `AuthController::verifyEmail` (`:621-644`) | HTML | R | Ссылка в письме → нужен deep link |
| Google OAuth | 🟡 | `AuthController::google*`, `Helpers/GoogleOAuth.php` | redirect | N | Только web-redirect; нет приёма ID-token из нативного SDK |
| Sign in with Apple | ❌ | — | — | N | **Обязательно для iOS**, если есть вход через Google (App Review 4.8) |
| Вход по телефону/SMS | ❌ | — | — | — | Телефон — только поле профиля |
| Профиль, аватар, био, личные данные | ✅ | `ProfileController::{index,avatar,updatePersonal,updateBio,updatePassword}` | HTML | R+ | |
| Удаление аккаунта | 🟡 | `ProfileController.php:1170-1197`, `User.php:873-940` | HTML | R+ | Невозможно для Google-only пользователей (нет пароля); не учитывает баланс/открытые сделки; hard delete |
| Роли | ✅ | `Auth.php:8-14,262-275`: `admin`, `manager`(+permissions), `user`; business = `account_type` | — | R | Отдельной роли «продавец» нет — любой пользователь продаёт |

## 2. Каталог и поиск

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Главная лента | ✅ | `HomeController::index`, `Product::allActive` | HTML | N | **Без LIMIT/пагинации** (`Product.php:121-197`) |
| Разделы каталога | ✅ | `CatalogController::show` (new/used/free/exchange/services/gigs) | HTML | R+ | |
| Категории | 🟡 | `ProductHelper::PRODUCT_CATEGORY_TREE` (`:127`), текст `"Родитель / Дочерняя"` в `products.category` | — | N | Нет таблицы категорий и API дерева; категории только у new/used |
| Карточка товара | ✅ | `ProductController::show` | HTML | R+ | Похожие товары, рейтинг продавца, аукцион, доставка |
| Поиск | 🟡 | `?q=` на `/` → `LIKE '%q%'` по 4 колонкам (`Product.php:138-145`) | HTML | N | FULLTEXT `ft_search` есть в `schema.sql:56`, но не используется; `searchAdvanced` (`:229`) только для AI |
| Фильтры | 🟡 | только по категории (`CatalogController.php:45-63`) | HTML | N | Нет цены/города/состояния |
| Сортировка | ❌ | порядок задан платным продвижением | — | N | |
| Пагинация | ❌ | — | — | N | Критично для мобильных сетей |
| Избранное | ✅ | `FavoriteController::toggle`, `/profile?tab=favorites` | JSON/XHR | R+ | Нет отдельного списка-API |
| Просмотры товара | ✅ | `Product::recordView` (`:814`) | — | R+ | Учитываются при рендере HTML |
| Жалоба на объявление | ✅ | `ProductController::report` → SupportTicket | HTML | R+ | |

## 3. Объявления (продавец)

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Создание | ✅ | `POST /profile/store` → `ProfileController::store` (`:474`) | HTML | R+ | Цепочка проверок: правила, AML/KYC, лимиты, квоты business-пакета, платный сбор для услуг |
| Редактирование/удаление | ✅ | `updateLot` (`:791`), `deleteLot` (`:929`), `updateGig` (`:725`) | HTML | R+ | |
| Фото | 🟡 | ≤3 (≤10 с business-пакетом, `BusinessPackageService.php:23-24`), ≤5 MB, jpg/png/webp/gif, GD-водяной знак | — | R+ | **Нет ресайза и превью** — мобильный клиент получит оригиналы |
| Видео в объявлении | ❌ | — | — | N | |
| Характеристики, количество | ✅ | `products.characteristics`, `StockService` | — | R | |
| Верификация продавца (AML по ИИН/БИН) | ✅ | `ProfileController::verifyListing`, `AMLService::screenUser` | HTML | R+ | |
| Типы: new/used/service/gig/course/free/exchange | ✅ | `ProductHelper::TYPES` (`:44`), enum `products.type` | — | R | «Даром» = `free`, бартер = `exchange` + `exchange_for` |

## 4. Покупка, Safe Deal, деньги

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Корзина | ✅ | `app/Services/Cart.php` — **только `$_SESSION['cart']`** | JSON/XHR | N | Нужна корзина в БД для синхронизации устройств |
| Оформление 1 товара | ✅ | `CheckoutController::pay` (`:170-256`) → `Order::createEscrow` | HTML | R+ | |
| Оформление корзины | ✅ | `CheckoutController::cartPay` → `createEscrowCart` (`Order.php:622`) | HTML | R+ | Всегда escrow; по заказу на товар |
| Обычная покупка (`direct`) | ✅ | `deal_mode=direct` (`CheckoutController.php:177-185`), только для подходящих типов (`ProductHelper::supportsDirectBuy`) | HTML | R+ | **Без комиссии 10% и без сбора** (`Wallet.php:228-246`) — [?] соответствует ли это бизнес-правилам |
| Safe Deal (escrow) по выбору покупателя | ✅ | `deal_mode=escrow` по умолчанию; `EscrowService` | HTML | R+ | Выбор только при покупке одного товара |
| Арбитражный сбор покупателя 3% | ✅ | `EscrowService::ARBITRATION_FEE_PERCENT = 3` (`:24`) | — | R | Константа в коде, не настройка |
| Комиссия продавца 10% | ✅ | `EscrowService::SELLER_COMMISSION_PERCENT = 10` (`:27`), `settle()` | — | R | Константа в коде |
| Блокировка средств до подтверждения | ✅ | `escrow_hold`, `EscrowService::settle` (`:438-589`) | — | R | Средства — во внутреннем кошельке, не в eScrow банка [?] |
| Окно спора 24 ч после получения | 🟡 | `INSPECT_HOURS = 24` (`:21`), `markDelivered` (`:149-169`) | — | R+ | Отсчёт с момента, когда **покупатель** отметил получение; трекинг CDEK не переводит в `delivered`; нет автозакрытия из `shipped` |
| Halyk ePay | 🟡 | `app/Services/HalykEpay/Client.php`, виджет `halyk.pay()` (`Views/payments/halyk-sim.php`) | redirect | R+ | Карта не проходит через Zakopeyki [Ф]; **callback не проверяется** (см. SECURITY_AUDIT) |
| Кошелёк: пополнение | 🟡 | `WalletController::deposit` | HTML | R+ | Реальный путь вызывает несуществующий `createPaymentIntent` (`:81`) — сломан; работает только симуляция |
| Кошелёк: вывод | 🟡 | `WalletController::withdraw` + AML | HTML | N | Реальной выплаты нет (симуляция) |
| Возвраты/споры/арбитраж | ✅ | `ReturnService` (сроки `:18-22`), `OrderController` return-*/arbiter-* | HTML | R+ | Роли проверяются; гонка в `settle()` без `FOR UPDATE` |
| Отмены | ✅ | `EscrowService` (`:189`) | HTML | R+ | |
| Возврат на карту через шлюз | ❌ | все возвраты — на внутренний кошелёк | — | — | |
| Отзывы и рейтинги | ✅ | `Review::createForOrder`, `statsFor` | HTML | R+ | |

## 5. Доставка

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Абстракция провайдера | ✅ | `Delivery/LogisticsProviderInterface`, `DeliveryService::providerFor` (`:76-82`), таблица `logistics_providers` | — | R | Конфигурируемо по `logistics_code`; реализованы CDEK и Stub |
| CDEK: ПВЗ, города, расчёт, регистрация, трекинг, печатные формы, отказ/возврат | ✅ | `DeliveryController` (~40 JSON-ответов), `app/Services/Cdek/*` | JSON (кроме `pay`) | R+ | Самый «API-готовый» модуль |
| Оплата доставки | ✅ | `DeliveryPaymentService` (сумма только с сервера) | redirect | R+ | |
| Webhook статусов | ✅ | `/webhooks/delivery/status`, токен `x-cdek-webhook-token` | JSON | — | Открыт в test_mode без токена |

## 6. Коммуникации

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Чат покупатель–продавец | ✅ | `ChatController` (`poll` JSON, `send`) | HTML + JSON | R+ | Polling 3 с; **нет вложений** |
| Ответ на Story в чат | ✅ | `chat_messages.story_id` | — | R | |
| Уведомления in-app | ✅ | `Notification::createFor/forUser/unreadCount` | HTML | R+ | Нет API списка/непрочитанных |
| Push (FCM/APNs/Web Push) | ❌ | не найдено | — | N | |
| Realtime (WebSocket) | ❌ | не найдено; SSE только в AI | — | N | |
| Email | 🟡 | только auth и конкурсы (`Mail.php`, `ContestNotifier`) | — | R | |
| Подписки на продавцов | ✅ | `UserController::followToggle`, `Follow` | JSON/XHR | R+ | `notifyFollowers` — insert на каждого подписчика |
| Поддержка (тикеты) | ✅ | `SupportController` | HTML | R+ | |

## 7. Контент и live

| Функция | Статус | Источник | Ответ | Мобайл | Примечания |
|---|---|---|---|---|---|
| Stories | ✅ | `StoryController::store/delete`, `Story` | HTML | N (чтение) | Чтения-API нет — рендер в HTML главной; видео ≤50 MB без транскодинга/превью |
| Shorts | ❌ | нет сущности; «reels» — только UI-свайп (`app.js:4032`) | — | N | Единый формат Stories/Shorts **не реализован** |
| Live Commerce | 🟡 | `StreamController`: P2P WebRTC (mesh), сигнализация через MySQL polling 1 с, только Google STUN, **нет TURN** | JSON | N | Не масштабируется, плохо работает в мобильных сетях |
| Live-магазин, комментарии, лайки, розыгрыш | ✅ | `StreamController::{shop,comments,comment,like,joinGiveaway,drawGiveaway}` | JSON | R+ | Polling 2.5–4 с |
| Цифровые курсы | ✅ | `DigitalController`, Cloudflare Stream (signed playback) | HTML + JSON | R+ / решение IAP | Покупка цифрового контента на iOS/Android — вопрос правил сторов |

## 8. Вовлечение и монетизация

| Функция | Статус | Источник | Мобайл | Примечания |
|---|---|---|---|---|
| Аукционы (English/Dutch/Continuous) | ✅ | `AuctionService`, `Auction/*`, `bin/auction_cron.php`, `/auctions/{id}/live` (JSON) | R+ | Таймер — клиентский `setInterval` |
| Бартер / «даром» | ✅ | типы `exchange` / `free` | R | |
| Конкурсы и розыгрыши | ✅ (за флагами) | `ContestController`, `Contest/ContestPolicy.php:21-37` (`contests_enabled` …) | R+ | |
| Реферальная программа | ✅ | `ReferralCapture` (`?ref=` → сессия), `Bonus::awardReferral` | R+ | Для мобайла нужен deferred deep link |
| Бонусы | ✅ | `Bonus`, `BonusController` | R+ | |
| Продвижение: Boost/Highlight/TopFixed/TopFixedXl/VIP | ✅ | `PaidServiceCatalog::definitions()`, оплата через Halyk | R+ / IAP? | Цены — константы политик (390–2690 ₸) |
| Реклама (Ads), TargetPush, MassPush | ✅ | `AdService`, `TargetPushService`, `MassPushService` | R+ | «Push» = только in-app уведомления |
| Business-пакет, AI-аддоны | ✅ | `BusinessPackageService` (оплата из кошелька), Seller API ключи | R+ | |
| ThreeLifts | ❌ | только флаг | — | |
| Micro-tasks (gigs) | ✅ | `/api/v1/micro-tasks/*` (JSON, сессия, только staff — `BaseApiController.php:52-57`) | R+ | |

## 9. ИИ

| Функция | Статус | Источник | Мобайл | Примечания |
|---|---|---|---|---|
| Текстовый чат Зак | ✅ | `/ai/chat` (JSON), гости по `guest_token` | R+ | Нужна токен-авторизация вместо cookie+CSRF |
| Стриминг | 🟡 | `/ai/chat/stream` — SSE поверх POST, но **псевдо-стрим** (ответ режется по 24 символа, `AiAssistantController.php:410-420`) | R+ | |
| Голосовой ввод (STT) | ✅ | `/ai/voice`, whisper.cpp; webm/ogg/m4a только при `ffmpeg_path` | R+ | Мобайлу — WAV или включить ffmpeg |
| Озвучка (TTS) | ✅ | `/ai/tts` → Azure / edge-tts / браузер; аудио `/ai/tts/audio/{token}` привязано к сессии | R+ | |
| Wake word «Эй, Зак» | 🟡 | только браузерный Web Speech (`app.js:5797-5903`) | N | Для мобайла — отдельный on-device движок |
| Анализ изображений | ✅ | `/ai/image` (llava), только авторизованные | R+ | Файлы публично в `public/uploads/ai/` |
| Поиск товаров, цена, спрос, статусы заказов/доставки | ✅ | `app/Services/AI/Tools/*` | R | |
| Черновик объявления + подтверждение | ✅ | `CreateListingDraftTool`, `/ai/action/confirm` (TTL 900 с) | R | Единственное подтверждаемое действие |
| Virtual Try-On | ✅ (незакоммичено) | `/api/v1/try-on/*`, envelope `{success,data,error}`, Idempotency-Key | R+ | «Mobile-ready» по форме, но сессия+CSRF; bearer запланирован (`TryOnController.php:14`) |

## 10. Платформа

| Функция | Статус | Примечания |
|---|---|---|
| Локализация RU | ✅ | `lang/ru.php` (~4266 ключей) |
| Локализация KK | 🟡 | те же ключи, но ~159 значений идентичны русским; нет fallback kk→ru по ключу |
| Локализация EN | ❌ | только в AI (STT/TTS/детектор языка) |
| Хардкод русского текста | 🟡 | ~158 строк в контроллерах, ~52 JSON-сообщения в сервисах, ~140 строк во views вне `t()` |
| PWA/manifest/service worker | ❌ | |
| Режим заглушки (stub mode) | ✅ | `index.php:58-103`, `Auth::hasSiteAccess` — влияет на мобильный доступ |
