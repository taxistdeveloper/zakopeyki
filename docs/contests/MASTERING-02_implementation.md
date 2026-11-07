# MASTERING-02 — Реализация модуля «Конкурсы и розыгрыши» и пакета безопасности

Дата: 2026-11-07 · Основание: [MASTERING-01_audit.md](MASTERING-01_audit.md) · Статус: реализовано, проверено локально, **не закоммичено**.

## 1. Принятые решения (вопросы раздела «Требуют согласования» MASTERING-01)

Пользователь дал общее разрешение («всё делай»). Там, где аудит предлагал вариант, применён рекомендованный. Каждое решение можно поменять.

| # | Вопрос | Применено | Как изменить |
|---|---|---|---|
| 1 | Префикс модуля | `contest` (`App\Services\Contest\*`, таблицы `contest_*`) | — |
| 2 | Миграции | Единый DDL в `ContestSchema::statements()`: таблицы создаются на лету (как принято в проекте), а `database/migrate_contests.sql` генерируется из того же источника (`php bin/contests_schema_sql.php`) | — |
| 3 | Жалобы | Отдельная таблица `contest_reports` с очередью в `/admin/contests` | — |
| 4 | Розыгрыш в Live | Добавлен выключатель `live_giveaway_enabled` (**по умолчанию выключен**). Результат сохраняется один раз на эфир (`stream_giveaway_draws`); `ORDER BY RAND()` заменён на `random_int` | `/admin/stream` → флажок |
| 5 | Кто организатор | `business_verified` (проверенный ИП/ТОО) | `/admin/contests` → «Кто может быть организатором» |
| 6 | Журнал событий | Только для модуля: запись идёт лишь при живых акциях организатора | — |
| 7 | Пакет F-1…F-4 | Выполнен первым пакетом | — |
| 8 | QR | Собственный генератор `App\Helpers\QrCode`, без новых зависимостей | — |
| 9 | Модератор | Новое право `contests` для менеджеров | `/admin/users/{id}` → доступы |

**Юридический принцип соблюдён:** модуль (`contests_enabled`) и обе механики (`contests_method_jury_enabled`, `contests_method_random_enabled`) **по умолчанию выключены**. Включить механику может только администратор, отметив «получено юридическое заключение»; изменение пишется в журнал. Платное участие, участие с покупкой, продажа шансов и дополнительные шансы за приглашения **не реализованы вовсе**.

## 2. Пакет безопасности

| Код | Что сделано | Файлы |
|---|---|---|
| F-1 | Ограничение частоты: вход (IP + email), 2FA, регистрация, сброс пароля, повторная отправка письма, вступление в акцию, жалобы. Переиспользован `RateLimiter` | `app/Services/Security/AuthThrottle.php`, `AuthController` |
| F-2 | Подтверждение email: колонки `email_verified_at`, `email_verify_token` (SHA-256), `email_verify_expires`; письмо при регистрации; `/verify-email/{token}`; повторная отправка из профиля. Google OAuth сразу отмечает email подтверждённым | `User`, `AuthController`, `profile/index.php`, `routes.php` |
| F-3 | Блокировка: `blocked_at`, `blocked_reason`, `blocked_by`; вход запрещён; активная сессия завершается в течение минуты (`Auth::enforceAccountState`); кнопки в админке, запись в журнал | `User`, `Auth`, `index.php`, `AdminController`, `admin/user-show.php` |
| F-4 | Выключатель Live-розыгрыша, однократный сохраняемый результат с блокировкой `FOR UPDATE` | `LiveGiveawayPolicy`, `Stream`, `StreamController`, `admin/stream.php`, `live-setup-modal.php` |
| — | `.htaccess`: закрыты `bin/`, `logs/`, `tmp/`, `_vas*_extract/`, скрытые файлы (в том числе `.git`), корневые `*.md/*.txt/*.mp3/*.zip/*.sql/*.xml/*.log/*.sh` (кроме `robots.txt` и `sitemap*.xml`), `openapi*.json`, `tmp_*.html`. В `bin/bootstrap.php` добавлен запрет запуска не из CLI | `.htaccess`, `bin/bootstrap.php` |
| — | QR для 2FA и абонемента в зал генерируется локально: секрет TOTP больше не уходит на `api.qrserver.com` | `Totp::qrImageUrl` |
| — | Если `config/mail.php` отсутствует, письма не отправляются, но страница не падает (раньше `require` давал фатальную ошибку) | `Mail::configured()` |

Ещё не исправлено: `allow_simulated_payments=true`, CSP/HSTS, CSRF-исключение сессионного micro-tasks API, гонка в `EscrowService::settle()`, `Model::all($order)`. Это общие платформенные задачи, к модулю они не относятся.

## 3. Модуль: состав

| Компонент | Файл |
|---|---|
| Политика, флаги, статусная машина, справочники | `app/Services/Contest/ContestPolicy.php` |
| DDL (10 таблиц) | `app/Services/Contest/ContestSchema.php` → `database/migrate_contests.sql` |
| Конструктор, версии правил, модерация, жизненный цикл, закрытие | `ContestService.php` |
| Участие, антифрод, ручная проверка | `ContestEntryService.php` |
| Серверная проверка условий | `ContestConditionVerifier.php` |
| Итоги (жюри / проверяемый розыгрыш), вручение, споры | `ContestResultService.php`, `RandomDraw.php` |
| Журнал событий (подписки) | `ContestEventRecorder.php` (вызывается из `Follow::toggle`) |
| Реферальная атрибуция (без начислений) | `ContestReferralTracker.php`, `app/Helpers/ReferralCapture.php` |
| Жалобы | `ContestReportService.php` |
| Аналитика (воронка) | `ContestAnalyticsService.php` |
| Уведомления (in-app + email победителю) | `ContestNotifier.php` |
| Модели | `app/Models/Contest*.php` |
| Контроллеры | `ContestController.php`, `AdminContestController.php` |
| Представления | `app/Views/contests/*`, `app/Views/admin/contests/*`, `app/Helpers/ContestView.php` |
| Cron | `scripts/cron_contests.php` |
| Переводы ru/kk | `lang/modules/{ru,kk}/{contests,security}.php` (новый механизм модульных словарей в `Lang::load`) |

### Маршруты
Публичные: `GET /contests`, `GET /contests/{slug}`, `GET /contests/{id}/qr.svg`.
Участник: `POST /contests/{slug}/join|withdraw|report`, `POST /contests/{slug}/winners/{id}/confirm|dispute`.
Организатор: `GET /contests/my`, `GET /contests/new`, `POST /contests`, `GET /contests/{id}/edit`, `POST /contests/{id}/update|submit|cancel`, `GET /contests/{id}/manage`, `POST /contests/{id}/entries/{entryId}/review`, `POST /contests/{id}/results`, `POST /contests/{id}/winners/{winnerId}/handed`.
Админка (право `contests`; настройки — только admin): `/admin/contests`, `/admin/contests/settings`, `/admin/contests/{id}`, `…/approve|reject|suspend|resume|cancel`, `…/winners/{id}/resolve`, `/admin/contests/reports/{id}/resolve`.

### Жизненный цикл
`draft → pending_moderation → (rejected) → scheduled → active → closed → results_published → completed`, а также `suspended`, `cancelled`, `disputed`. Переходы — `ContestPolicy::TRANSITIONS`. Модератор не может одобрить собственную акцию. Переходы по времени выполняет cron и лениво — каждое открытие страницы.

### Ключевые гарантии
- **Условия только проверяемые:** подтверждённый email, возраст аккаунта, подписка на организатора (непрерывно до окончания, по журналу `contest_platform_events`), работа для жюри. Внешние соцсети условием быть не могут.
- **Однократные итоги:** `UNIQUE(contest_id)` в `contest_result_executions`, транзакция с `FOR UPDATE`, запрет при непроверенных заявках.
- **Проверяемый розыгрыш:** commit = SHA-256(seed) публикуется при одобрении, seed раскрывается после итогов. Алгоритм `hmac-sha256-fisher-yates-v1` детерминирован и воспроизводим.
- **Антифрод:** ограничение частоты; пометки `same_ip`, `same_device` (cookie `zk_vid`), `new_account`. Помеченные заявки после закрытия ждут ручного решения.
- **Рефералка:** модуль не начисляет вознаграждения. `?ref=` идёт в тот же `$_SESSION['referral_ref']`; начисление — существующий `Bonus::awardReferral`; участнику статусы показываются только для чтения.
- **ПДн:** организатор не видит email и IP участников (IP хранится как хэш с солью акции); победитель публикуется как «Имя Ф.»; удалённый аккаунт обезличивается.

## 4. Запуск в эксплуатацию

1. Применить схему: `mysql … < database/migrate_contests.sql`. Таблицы создадутся и сами при первом обращении, но явная миграция предпочтительнее.
2. Добавить cron, каждые 5 минут: `php scripts/cron_contests.php`.
3. Убедиться, что на сервере есть `config/mail.php`: без него не уходят письма подтверждения email и победителям.
4. Выдать менеджерам право «Конкурсы и акции (модерация)» в `/admin/users/{id}`.
5. **После юридического заключения:** в `/admin/contests` включить модуль и нужные механики.
6. Существующие пользователи (кроме входящих через Google) не имеют подтверждённого email. Пока требование включено (по умолчанию), участвовать они смогут только после подтверждения из профиля.

## 5. Проверки

- `php tools/run_contests_tests.php` — **91/91**: RandomDraw (детерминизм, равномерность, commit), политика переходов, QR, полный сценарий на локальной БД (модерация, участие, условия, отписка во время акции, антифрод, атрибуция, итоги, воспроизводимость, вручение, спор, жалобы, жюри, выключатель механики, блокировка). Тестовые данные и настройки удаляются.
- Существующие раннеры: CSRF 9/9, стрим и розыгрыш 12/12, эскроу 10/10, каскадная защита заказов 5/5.
- HTTP-смоук на встроенном PHP-сервере: все новые страницы отдают 200 без ошибок PHP под ролями admin, организатор и участник; участник получает 404 на чужом управлении и 403 в админке.
- QR проверен декодером браузера (`BarcodeDetector`) для версий 3, 4, 6, 8, 9, 10.
- Визуально проверены каталог, страница акции, кабинет, мастер, валидация, админка (ru и kk).
- PHPUnit не запускался: `tools/phpunit.phar` отсутствует в репозитории.

## 6. Ограничения и открытые вопросы

- **Юридические блокеры K-1…K-15 не сняты.** Модуль готов, но выключен.
- **D-1 (правила рефералки) не решён.** Существующая программа по-прежнему начисляет 500 баллов сразу при регистрации и не требует подтверждения email. Это не менялось: это продуктовое решение.
- Лайки и комментарии не могут быть условиями: таких сущностей на платформе нет.
- Текст уведомлений формируется в языке того, кто выполнил действие (существующее ограничение `notifications`).
- `AuthThrottle` берёт IP через `ActivityLogger::clientIp()`, который доверяет `X-Forwarded-For`. Для входа это компенсирует лимит по email. Для регистрации нужно настроить доверенный прокси.
- Реальная доставка писем и работа cron на сервере не проверялись (локально нет `config/mail.php`).
- `app/Views/partials/sidebar.php`, `admin/index.php` и `profile/index.php` изменены точечно. Общие `lang/ru.php`, `lang/kk.php`, `layouts/main.php`, `app.js` не тронуты: в них есть чужие незакоммиченные правки.
