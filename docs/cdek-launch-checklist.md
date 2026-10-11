# CDEK — проверка перед запуском

Фактическое состояние репозитория после фаз 1–13. Это не разрешение на production.

Интеграция **не готова к запуску**: не закрыты договорные и бухгалтерские вопросы, а часть операций CDEK сознательно не вызывается. Технический контур (оплата → заказ → статус) в коде есть и покрыт локальными тестами со stubs.

## 1. Что уже есть в коде

| Шаг | Где |
|---|---|
| Точка A до публикации; самовывоз не блокируется | `ListingShippingService` |
| Точка B, город и ПВЗ только после ответа CDEK | `CdekLocationService`, `BuyerPointBService` |
| Расчёт и сброс снимка при смене параметров | `CdekCalculatorService`, `invalidateQuotes` |
| Оплата доставки отдельно от товара; сумма и валюта с сервера | `DeliveryPaymentService` |
| Заказ CDEK только после PAID и повторной проверки | `CdekOrderRegistrationService` |
| HTTP 202 → `CDEK_ORDER_PENDING`, не «создан» | poll `bin/cdek_order_poll.php` |
| Webhook с общим секретом, hash события уникален | `CdekWebhookService` |
| Сверка GET `/v2/orders/{uuid}` | `bin/cdek_reconcile_orders.php` |
| Печатные формы только участникам сделки | `CdekPrintFormService` |
| Проблема доставки без авто-refusal | `CdekDeliveryProblemService` |
| Refusal и clientReturn в рабочем сценарии не вызываются | `CdekDeliveryActionPolicy::mayCallCdek()` = false |

Секреты читаются из `config/cdek.php` (в `.gitignore`) и env. `config/cdek.php.example` секретов не содержит. `CDEK_ORDER_CREATE_ENABLED` по умолчанию выключен.

Схема БД поднимается методами `ensureTable` / `ensureTableColumns` при обращении к моделям, не отдельными migration-файлами. Уникальные ключи: номер доставки, один delivery на заказ товара, `create_idempotency_key`, `cdek_uuid`, платёжный `pg_order_id` и idempotency, hash webhook, печатная форма, уведомление трекинга, проблема и действие CDEK.

Фоновые команды (их запускает cron владельца, не этот аудит):

- `php bin/cdek_order_poll.php` — опрос 202
- `php bin/cdek_reconcile_orders.php` — сверка незавершённых, лучше раз в 15 минут
- `php bin/cdek_print_poll.php` — PDF
- `php bin/cdek_healthcheck.php` — проверка конфигурации, без создания заказа

## 2. Перед включением production

1. Юрист, бухгалтер и CDEK подтверждают пункты из `docs/cdek-known-gaps.md`. Пока они открыты, production не включать.
2. Скопировать `config/cdek.php.example` в `config/cdek.php` на сервере. Файл не коммитить.
3. Задать env, без значений в git:
   - `CDEK_ACCOUNT`, `CDEK_SECURE_PASSWORD` — боевой договор, не тестовый аккаунт
   - `CDEK_API_URL=https://api.cdek.ru/v2`
   - `CDEK_TEST_MODE=0`
   - `CDEK_WEBHOOK_TOKEN` — длинная случайная строка
   - `CDEK_WEBHOOK_URL` — HTTPS URL `POST /webhooks/delivery/status`
   - `CDEK_ORDER_CREATE_ENABLED=0` до отдельного решения включить создание
   - `CDEK_CURRENCY=2` (KZT в калькуляторе CDEK), если договор в тенге
4. При `CDEK_TEST_MODE=0` пустой webhook token отклоняет входящие события. При `test_mode=1` пустой token события пропускает. На production `test_mode` должен быть 0.
5. Зарегистрировать webhook в кабинете CDEK типами, которые код обрабатывает: `ORDER_STATUS`, `PRINT_FORM`, `DELIV_PROBLEM`. OpenAPI не задаёт лимит подписок. Не создавать больше двух активных подписок, пока CDEK письменно не подтвердит больший лимит.
6. Cron для трёх poll-скриптов. Логи без тел запросов с адресами и без токена.
7. Проверить, что сайт отдаёт webhook по HTTPS и что CSRF его не режет (маршрут webhook в исключениях роутера).
8. Только после пунктов 1–7 и отдельного решения владельца: `CDEK_ORDER_CREATE_ENABLED=1`.
9. Первый заказ — на тестовом договоре и `api.edu.cdek.ru`, не на боевых деньгах.

## 3. Переключение среды

| | Тест | Production |
|---|---|---|
| URL | `https://api.edu.cdek.ru/v2` | `https://api.cdek.ru/v2` |
| `CDEK_TEST_MODE` | `1` | `0` |
| Учётные данные | тестовый договор CDEK в env | боевой договор в env |
| Создание заказа | выключено, пока не нужен пробный заказ | выключено, пока не закрыты GAP |
| Webhook token | обязателен, если тестовый URL доступен извне | обязателен |

Смена URL без смены токена и без `test_mode=0` оставляет приём webhook открытым. Это нельзя делать на публичном сервере.

## 4. Откат

1. `CDEK_ORDER_CREATE_ENABLED=0`. Новые заказы CDEK перестают создаваться. Уже созданные UUID не удаляются.
2. Вернуть предыдущий `config/cdek.php` / env. Код отката схемы не выполняет: колонки `ensureTable` остаются.
3. Не вызывать `DELETE /v2/orders/{uuid}` массово. Отмена конкретного заказа — отдельное решение CDEK и владельца.
4. Оплаты доставки этим переключателем не возвращаются. Возврат денег в коде этого контура нет.

## 5. Что этот аудит не делал

Реальные заказы CDEK, боевые и тестовые платные вызовы, миграции production и смена env на сервере не выполнялись. Тесты ходят в stubs.
