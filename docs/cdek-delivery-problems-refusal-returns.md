# CDEK: проблемы доставки, отказ и clientReturn — Phase 13

Проблема доставки, отказ отправления, клиентский возврат CDEK и возврат товара на Zakopeyki — разные процессы. Этот этап не двигает оплату и не создаёт новый CDEK order.

## 1. Четыре процесса

| Процесс | Что это | Что делает код |
|---|---|---|
| A. Проблема доставки | Webhook `DELIV_PROBLEM` и `OrderResponseDto.delivery_problem[]` | Сохраняет код и дату. Текст: «Требуется уточнение по доставке». FSM, отказ и clientReturn не запускаются |
| B. Refusal | `POST /v2/orders/{uuid}/refusal` | Endpoint есть. Рабочий вызов закрыт: в OpenAPI нет допустимых статусов |
| C. clientReturn | `POST /v2/orders/{uuid}/clientReturn` | Тело по схеме только `tariff_code`. Рабочий вызов закрыт по той же причине. Это не возврат товара на площадке |
| D. Возврат товара | Сделка покупателя и продавца | Не реализуется. `marketplace_return` всегда недоступен |

## 2. Что есть в OpenAPI

`POST /v2/orders/{uuid}/refusal` — тела нет. Ответ `202` `ResponseDtoRootEntityDto` или `400`.

`POST /v2/orders/{uuid}/clientReturn` — `CreateClientReturnRequestDto`: обязателен `tariff_code` (int). Ответ тот же `202` / `400`.

`GET /v2/orders/{uuid}` — заказ, `delivery_problem[]` (`code`, `create_date`), `requests[]`, `related_entities` (`return_order`, `client_return_order`).

`GET /v2/orders` — query только `cdek_number` и `im_number`.

`GET /v2/orders/{orderUuid}/intakes` — список заборов. Для допуска refusal/clientReturn схема его не использует, из пользовательского сценария не вызывается.

`PATCH /v2/orders` — `OrderUpdateRequestDto`, обязательны `recipient` и `type`. Для отказа и возврата не используется: это изменение заказа, а не документированная операция возврата.

`WebhookDto.type` включает `DELIV_PROBLEM`. Схемы входящего тела события в OpenAPI нет. Сохраняются только `attributes.code`, если это короткий код, и дата. Остальные поля, включая возможные адреса и телефоны, не пишутся.

`RequestInfoDto.state`: `ACCEPTED`, `SUCCESSFUL`, `INVALID`. Другие строки подтверждением не считаются.

HTTP 202 и `ACCEPTED` — запрос принят в обработку (`SUBMITTED`), не выполнен. `SUCCESSFUL` — внутренний `CONFIRMED`. `INVALID` / HTTP 400 — `FAILED`. Timeout и обрыв сети — `UNKNOWN_RESULT`, повторный POST запрещён. Дальше только `GET /v2/orders/{uuid}`.

## 3. Внутренние статусы запроса

`REQUESTED`, `VALIDATING`, `SUBMITTED`, `CONFIRMED`, `FAILED`, `CANCELLED`, `UNKNOWN_RESULT`.

Это не статусы CDEK и не статус коммерческого возврата. Сейчас запрос, который не проходит проверку права, сохраняется как `CANCELLED` с `error_code=right_unconfirmed` и в CDEK не уходит.

## 4. Права и подтверждение

Смотреть проблему и список действий могут покупатель своей доставки, продавец связанной сделки и администратор с правом `disputes`.

Кнопок refusal/clientReturn на странице нет. POST без `confirm=1` не создаёт запрос и не вызывает CDEK.

Даже с подтверждением вызов закрыт: OpenAPI не говорит, покупатель, продавец или платформа имеет право на refusal и clientReturn, и не говорит, при каком статусе заказа это можно. Zakopeyki не подставляет себя отправителем или заказчиком.

Локально терминальные `DELIVERED`, `CANCELLED`, `REFUND_REQUIRED` отклоняются до этой проверки. Это запрет платформы, не список разрешённых статусов CDEK.

## 5. Идемпотентность и timeout

У refusal нет тела и нет idempotency key. У clientReturn в схеме тоже нет такого поля. Внутренний ключ `sha256(delivery_id|type|user_id)` не даёт exactly-once на стороне CDEK.

Повтор с тем же ключом возвращает уже сохранённую строку и не делает POST. Параллельный запрос того же типа ловит lock на 30 секунд. HTTP-клиент для этих методов создан с `allow_retry=false`, но рабочий код до него не доходит, пока `CdekDeliveryActionPolicy::mayCallCdek()` равен `false`.

После timeout слепой повтор запрещён. Сверка — `GET /v2/orders/{uuid}` по `request_uuid`. Связанный `client_return_order` сохраняется как UUID и не становится новым заказом Zakopeyki.

## 6. API Zakopeyki

| Метод | Путь |
|---|---|
| GET | `/delivery/{id}/problems` |
| GET | `/delivery/{id}/cdek-actions` |
| POST | `/delivery/{id}/cdek-actions/refusal` |
| POST | `/delivery/{id}/cdek-actions/client-return` |
| GET | `/delivery/{id}/cdek-actions/{actionId}` |

Ответ действия: id, тип, внутренний state, текст, код ошибки. Без token, сырого тела CDEK и телефона.

## 7. Аудит

`delivery_events`: `cdek_problem_received`, `cdek_action_blocked`. В payload только тип операции. Секреты и сырой webhook не пишутся.

## 8. GAPs

1. При каких статусах заказа CDEK принимает refusal?
2. При каких статусах принимается clientReturn, и чей это `tariff_code` — тариф исходного заказа или отдельный тариф возврата?
3. Кто инициатор по договору: получатель, отправитель или владелец API-аккаунта?
4. Какова схема входящего `DELIV_PROBLEM`? `DeliveryProblemResponseDto` содержит только `code` без enum и `create_date`.
5. Какой webhook или поле GET подтверждает, что refusal выполнен? `requests[].state=SUCCESSFUL` — единственное известное слово, но не сказано, что оно приходит именно для refusal.
6. `OrderDelayReason` содержит только `create_date`. Связь с проблемой не описана.
7. Нужно ли после подтверждённого refusal переводить FSM в `CANCELLED`? Сейчас статус доставки не меняется.
8. `PATCH /v2/orders` и `GET .../intakes` не используются для этих операций.
9. Возврат денег и политика возврата товара на площадке не входят в этап.
