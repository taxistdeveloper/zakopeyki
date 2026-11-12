# PHASE 0 — Аудит (сводка)

Дата: 2026-11-09. Код не изменялся. «[не подтв.]» — требует проверки.

## 1. Стек
- Чистый PHP 8 (`declare(strict_types=1)`), без фреймворка и Composer; autoload `App\` в `index.php`.
- Роутинг: `app/Core/Router.php` (только `get()` и `post()`), маршруты `config/routes.php`.
- MySQL 8 / PDO без ORM; Redis опционален; Ollama локально (`config/ai.php`).
- Frontend: PHP-шаблоны `app/Views/*`, Tailwind CDN (`app/Views/layouts/main.php`), ванильный JS `public/assets/js/app.js`.
- Тесты: PHPUnit 10.5 (`tools/phpunit.phar`, `phpunit.xml`, только suite `tests/Unit`).
- CI нет (`.github` отсутствует). Деплой: `bin/deploy.sh` = `git pull`.
- Окружения формально не разделены; флаги в `config/app.php` (`debug`, `allow_simulated_payments`, `stub_mode`). Секреты — `getenv()` и `config/*.php.example`.

## 2. Каталог и фото
- `products` (`database/schema.sql:19`): `category` = `"Раздел / Подкатегория"`, `image` + `images` (TEXT), `characteristics` — текст `Имя: значение`, ≤12 строк (`app/Helpers/ProductHelper.php:26`).
- Вариантов (размер/цвет) нет. Поля «тип одежды» нет.
- Дерево категорий `ProductHelper::PRODUCT_CATEGORY_TREE`: «Одежда, обувь и аксессуары» → «Мужская одежда и обувь», «Женская одежда и обувь», «Аксессуары», «Спортивная одежда»; «Детские товары» → «Детская одежда и обувь». По категории тип примерки не определить.
- Фото товаров публичны: `public/uploads/products` (`app/Models/Product.php:596`, `app/Controllers/ProfileController.php:1035`).
- Валидация загрузок: `UploadHelper::isAllowedUpload` — расширение + MIME + `getimagesize` (`app/Helpers/UploadHelper.php:47`). Есть `applyWatermark` [не подтв., применяется ли к фото товаров].
- Прецедент приватных файлов: `public/uploads/disputes/.htaccess` (deny all) + выдача владельцу через `app/Controllers/OrderController.php:296`. `storage/` закрыт в корневом `.htaccess`.

## 3. Авторизация и API
- Сессия (httponly, SameSite=Lax), `App\Core\Csrf` (поле `_csrf` или заголовок `X-CSRF-Token`), роли `user|manager|admin`, права менеджера — JSON, `Auth::can()` / `Auth::requirePermission()`.
- JSON API: `app/Controllers/BaseApiController.php` — конверт `{success, data, error}`.
- Bearer-ключи есть только у продавцов (`SellerApiKey`, `BusinessApiController::requireApiUser`). Токенов покупателя нет.

## 4. Переиспользуемое
- Заготовка примерки **для продавцов**: `BusinessPackagePolicy::FEATURE_AI_TRYON`, `/business/ai/tryon`, `/api/v1/business/ai/tryon`, `BusinessAiOperationService::run` (идемпотентность `operation_key`, `GET_LOCK`, резерв квоты), сейчас честно отвечает `feature_not_implemented`.
- `app/Services/AI/Core/RateLimiter.php` (Redis → MySQL → файл).
- `App\Models\Setting` (key/value, `getBool`), паттерн тумблеров `app/Views/admin/business.php`.
- HTTP-клиент с инжектируемым транспортом для тестов: `app/Services/Cdek/Client.php:34`.
- Очередь-образец: `ai_queue_jobs` (`app/Models/AiQueue.php`) + `bin/ai_worker.php`; кроны `scripts/cron_*.php`.
- Локализация `t()`, `lang/ru.php`, `lang/kk.php`.

## 5. Риски
1. Закон РК № 94-V «О персональных данных и их защите»: согласие, локализация баз ПД в РК, трансграничная передача; фото лица может квалифицироваться как биометрия. **Требуется юрист** [не подтв.].
2. Нет типа одежды и вариантов; фото б/у вещей низкого качества; возможный водяной знак.
3. Оплата зарубежным API из РК не подтверждена.
4. Хостинг (cron/CLI, staging) неизвестен.
5. `allow_simulated_payments => true` в `config/app.php` (вне модуля, но опасно для прода).
6. Зависимость от молодого поставщика — снимается адаптером и резервом.

## 6. AI API (официальные источники, 2026-11-09)
| Поставщик | Цена | Free | Async | Категории | Данные |
|---|---|---|---|---|---|
| [Genlook](https://genlook.app/developers) | $0.04 PAYG → $0.015 | 10 кр. + `mock` | polling | одежда, обувь, очки, украшения, часы, сумки, шапки | ЕС, ≤7 дн., удаление по пользователю |
| [FASHN](https://docs.fashn.ai/api-reference/tryon-v1-6) | v1.6 $0.075 | нет | polling + webhook | tops/bottoms/one-pieces; Max — обувь, аксессуары | выходы 3 дня, без обучения |
| [Vertex VTO](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/image/generate-virtual-try-on-images) | $0.06 | [не подтв.] | sync | одежда | регион ЕС |
| Kling | $0.07 | [не подтв.] | [не подтв.] | одежда | Сингапур, [не подтв.] |
| WEARFITS | [не подтв.] | 10 кр. | webhook HMAC | одежда, обувь | лимит 60/ч |
| TryOn Virtual | $0.07–0.15/сессия | 25/мес | виджет | широкие | — |
| TryItOn | [не подтв.] | [не подтв.] | polling | широкие | 72 ч |

## 7. Open-source
CatVTON, IDM-VTON, OOTDiffusion, FitDiT, StableVITON — CC BY-NC-SA; OpenTryOn — CC BY-NC; OmniTry — Apache-2.0, но база FLUX.1-Fill-dev (NC). Leffa — MIT, но OpenPose (NC) и обучение на VITON-HD (CC BY-NC) → только после юр. проверки. Break-even self-hosted (L4 ≈ $588/мес): ~15k генераций/мес по GPU, реалистично 20–30k с эксплуатацией.
