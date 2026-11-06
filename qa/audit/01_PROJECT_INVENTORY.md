# 01. ИНВЕНТАРИЗАЦИЯ ПРОЕКТА ZAKOPEYKI.KZ

**Дата формирования:** 2026-11-02  
**Версия аудита:** 1.0 (Фаза A — Исследование)  
**Отдел:** Independent QA Department Zakopeyki.kz

---

## 1. Фактический технологический стек

* **Язык & Среда исполнения:** PHP 8.3 (CLI / Web, NTS).
* **Архитектурный паттерн:** Custom Lightweight MVC (чистый PHP, без сторонних фреймворков Laravel/Symfony/Yii).
* **Автозагрузка:** PSR-4 подобная через `spl_autoload_register` в `index.php` (`App\` -> `app/`, `Tests\` -> `tests/`). Composer dependencies в рантайме не используются.
* **База данных:** MySQL 8.0 (InnoDB, `utf8mb4_unicode_ci`, нативный PDO).
* **Маршрутизация:** Регулярные выражения в `app/Core/Router.php`, файл конфигурации `config/routes.php` (406 строк маршрутов). Отдельный изолированный класс `app/ApiRouter.php` (для микрозадач).
* **Безопасность сессий:** Session Cookie (httponly, Lax, secure при HTTPS), CSRF-токены (`App\Core\Csrf`), защита от перебора и брутфорса, ActivityLogger.
* **Клиентский стек:** Нативный JS (`public/assets/js/app.js`), Tailwind CSS / кастомные стили, Server-Side Rendered PHP шаблоны (`app/Views/*`).
* **Фоновые процессы / CLI:** PHP-скрипты в `bin/*` и `scripts/*` (Cron, Workers, Seeding, AML Sync, CDEK Reconciliation).

---

## 2. Структура директорий проекта

| Директория | Назначение | Содержимое |
|---|---|---|
| `app/Core/` | Ядро платформы | `Auth`, `Controller`, `Model`, `Router`, `View`, `Database`, `Csrf`, `Lang` |
| `app/Controllers/` | HTTP-контроллеры | 39 контроллеров, обрабатывающих все сценарии |
| `app/Models/` | Модели данных (PDO SQL) | 52 модели данных (без тяжелого ORM) |
| `app/Services/` | Сервисный слой бизнес-логики | 42 сервиса и поддиректории (`AI/`, `Cdek/`, `Delivery/`, `Auction/`, `Business/`, `PaidServices/`, `Finance/`, `Streaming/` и др.) |
| `app/Views/` | Представления (HTML/PHP) | 30 директорий шаблонов (каталог, профиль, сделки, админка, стримы, корзина и др.) |
| `app/Helpers/` | Утилиты и хелперы | Санитизация, логирование, форматирование, загрузка файлов |
| `config/` | Конфигурационные файлы | `app.php`, `database.php`, `routes.php`, `ai.php`, `aml.php`, `halyk.php`, `cdek.php.example` и др. |
| `database/` | Схема и миграции | `schema.sql`, `seed.sql`, 58 файлов инкрементальных миграций `migrate_*.sql` |
| `bin/` | Системные и сервисные скрипты | CLI демоны, воркеры ИИ, крон аукционов, синхронизация СДЭК/AML |
| `scripts/` | Регулярные cron-задачи | Плановые запуски эскроу, платных услуг, push-рассылок |
| `tests/` | Набор автоматизированных тестов | `tests/Unit/Services/*` (65 тестовых файлов), `tests/Integration/*` |
| `tools/` | Автономные раннеры тестов | 12 автономных PHP-скриптов `run_*_tests.php` без внешних зависимостей |
| `public/` | Точка входа веб-сервера | `favicon.ico`, `assets/`, `uploads/` |
| `docs/` | Внутренняя документация | Спецификации CDEK API v2, документация AI-подсистемы |

---

## 3. Карта обнаруженных модулей и функций

| № | Модуль | Ключевые функции | Связанные контроллеры / сервисы | База данных (таблицы) | Статус готовности |
|---|---|---|---|---|---|
| 1 | **Аутентификация & Сессии** | Регистрация, вход, 2FA (TOTP), Google OAuth, выход, восстановление пароля, Remember Me | `AuthController`, `Core\Auth` | `users`, `password_resets` | Реализован |
| 2 | **Профиль & Пользователи** | Настройки, аватар, смена пароля, личные данные, удаление аккаунта, подписки | `ProfileController`, `UserController` | `users`, `follows`, `activity_logs` | Реализован |
| 3 | **Каталог & Объявления** | Создание, редактирование, черновики, фото, типы лотов (новые, б/у, даром, обмен, услуги), валидация доставки | `ProductController`, `CatalogController`, `ProfileController`, `ListingShippingService` | `products`, `product_listing_shipping`, `product_views` | Реализован |
| 4 | **Поиск & Фильтры** | Поиск по названию/описанию, фильтры по категориям, типам лотов, городам | `CatalogController`, `Product` | `products` (`ft_search`, LIKE) | Базовый |
| 5 | **Корзина & Оформление** | Добавление/удаление, изменение количества, мультивендорная корзина, расчет сумм | `CartController`, `CheckoutController`, `Services\Cart` | Сессия / `cart` | Реализован |
| 6 | **Эскроу (Безопасная сделка)** | Холдирование средств, переход статусов, сдача на доставку, подтверждение получения | `OrderController`, `EscrowService` | `orders`, `wallets`, `wallet_transactions` | Реализован (выявлен расхождение комиссии) |
| 7 | **Споры & Возвраты (Disputes)** | Открытие спора, доказательства, встречное предложение продавца, арбитраж админа, возврат/частичный возврат | `OrderController`, `ReturnService` | `orders`, `order_return_events` | Реализован (окно 3 дня вместо 24ч) |
| 8 | **Доставка СДЭК (CDEK v2)** | Расчет стоимости (door/PVZ), выбор ПВЗ, валидация точки Б, печатные формы, трекинг, вебхуки | `DeliveryController`, `Services\Cdek\*`, `Services\Delivery\*` | `delivery_orders`, `cdek_delivery_points`, `delivery_tracking_notices` | Высокий уровень покрытия тестами |
| 9 | **Платежи (Halyk ePay)** | Формирование `halyk.pay`, подпись, обработка `postlink`, идемпотентность, симуляция | `PaymentController`, `HalykEpay\Client`, `IntentStore` | `payments`, `delivery_payments` | Реализован тестовый режим |
| 10 | **Платежи (FreedomPay)** | Legacy эквайринг (Hosted Checkout) | `PaymentController`, `FreedomPay\Client` | `payments` | Legacy (депрекейтирован) |
| 11 | **Кошелек (Wallet)** | Баланс, история операций, пополнение, вывод средств | `WalletController`, `Models\Wallet` | `wallets`, `wallet_transactions` | Реализована только симуляция |
| 12 | **Бонусы & Лояльность** | Welcome-бонус, начисления за продажи/подписчиков/лоты, реферальная программа, Gym Pass | `BonusController`, `Models\Bonus` | `bonus_balances`, `bonus_transactions` | Накопительный, нет списания в заказы |
| 13 | **Аукционы** | Английский, голландский, непрерывный аукционы, ставки, холдирование, автопродление, выкуп | `AuctionController`, `AuctionService`, `Auction\Strategy*` | `products`, `bids` | Реализован |
| 14 | **Stories & Shorts** | Публикация коротких медиаисторий, автопривязка товаров, срок жизни 24ч, уведомления | `StoryController`, `Models\Story` | `stories` | Реализован |
| 15 | **Live Commerce (Эфиры)** | Прямой эфир, видеопоток, витрина товаров, лайки, чат в реальном времени, метаданные розыгрыша | `StreamController`, `Models\Stream` | `streams`, `stream_signals` | Розыгрыш реализован только визуально |
| 16 | **Чат (P2P)** | Личные сообщения между покупателем и продавцом, привязка к товару/заказу/истории, поллинг | `ChatController`, `Models\Chat` | `chat_conversations`, `chat_messages` | Реализован |
| 17 | **Служба поддержки** | Тикет-система, категории обращений, диалог с оператором, смена статусов | `SupportController`, `Models\SupportTicket` | `support_tickets`, `support_messages` | Реализован |
| 18 | **AI-Ассистент (ZAK)** | RAG-база знаний, распознавание намерений, голосовой ввод (транскрипт), анализ фото (Vision), генерация лотов | `AiAssistantController`, `Services\AI\*` | `ai_conversations`, `ai_messages`, `ai_knowledge_base` | Реализован через Ollama |
| 19 | **Платные услуги (VAS)** | Boost, Highlight, TopFixed, TopFixed XL, VIP, ThreeLifts, Таргетированные и Массовые Push, Баннеры | `PromoteController`, `BoostController`, `HighlightController`, `AdController`, `*PushController` | Таблицы `*_orders`, `ad_campaigns`, `push_campaigns` | Реализован с админ-флагами |
| 20 | **Бизнес-аккаунты** | Заявка на бизнес, тарифные пакеты, импорт XML/CSV, управление API-ключами продавца | `BusinessController`, `BusinessApiController`, `BusinessPackageService` | `business_subscriptions`, `seller_api_keys`, `catalog_import_jobs` | Реализован |
| 21 | **Цифровые товары & Курсы** | Уроки, видеоуроки, сертификаты, студия автора, вебхуки Cloudflare Stream | `DigitalController`, `DigitalProduct`, `DigitalAccessService` | `digital_products`, `digital_lessons`, `digital_certificates` | Реализован |
| 22 | **Микрозадачи & Гиги** | Биржа задач, отклики, назначение исполнителя, подтверждение выполнения | `MicroTaskController`, `MicroTaskOfferController`, `MicroTaskCompletionController` | `micro_tasks`, `micro_task_offers` | Реализован (только staff доступ) |
| 23 | **AML / Комплаенс** | Проверка ИИН/БИН по черным спискам АФМ РК, валидация контрольной суммы ИИН | `AMLService`, `AmlListSyncService`, `bin/sync_aml.php` | `users` (`aml_status`, `iin`, `bin`), Redis/XML | Реализован |
| 24 | **Административная панель** | Модерация лотов, рекламы, пушей, споров, пользователей, настройка фиче-флагов, журнал финансов | `AdminController`, `AdminTaskController`, `AdminLibraryController` | Все таблицы + `settings`, `finance_journal`, `activity_logs` | Развитая админка с правами |
| 25 | **Режим заглушки (Stub Mode)** | Блокировка доступа для неавторизованных до официального открытия платформы | `index.php`, `Setting`, `Auth::hasSiteAccess` | `settings` (`stub_mode`, `stub_opens_at`) | Активен по умолчанию |

