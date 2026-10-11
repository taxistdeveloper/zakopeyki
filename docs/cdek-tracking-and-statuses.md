# CDEK tracking и статусы — Phase 12

> Источник статуса: webhook `ORDER_STATUS` и документированный `GET /v2/orders/{uuid}`.  
> Отдельного tracking endpoint в изученном OpenAPI нет — он не добавлялся.  
> Tracking не вызывает `POST /v2/orders` и не меняет оплату.  
> HTTP 202 / `ACCEPTED` без статусов перевозки — не сдача посылки и не доставка.

## 1. Слои статуса

Они не склеиваются в одно поле:

| Слой | Где | Что означает |
|---|---|---|
| Покупка | `orders.status` (`purchase_status` в сводке) | Сделка товара |
| Оплата доставки | `delivery_orders.payment_status` | Оплата услуги доставки |
| Регистрация CDEK | `cdek_api_status` | `none` / `pending` / `accepted` / `created` / ошибка запроса |
| Перевозка | `cdek_status_code` + текст каталога | Последний известный код CDEK |
| Завершение | `completed` | `true` только если код из группы `DELIVERED` или FSM уже `DELIVERED` по такому коду |

`CREATED` и HTTP 202 не ставят «передано перевозчику» и не ставят `completed`.

## 2. Mapping

`OrderStatusDto.code` в OpenAPI — строка без enum. Ниже только коды, которые уже были в маппере проекта. Неизвестный код сохраняется в `cdek_status_code`, пользователю показывается «Статус уточняется», в `delivery_events` пишется `cdek_status_gap`.

Группа `READY_FOR_PICKUP` в FSM хранится как `IN_TRANSIT`: отдельного статуса пункта выдачи нет.

| CDEK code | Смысл | Группа | FSM | Покупатель | Продавец | Переход | GET |
|---|---|---|---|---|---|---|---|
| CREATED | Enum в OpenAPI нет. Для пользователя это регистрация, не сдача | REGISTERED | не меняется | Заказ зарегистрирован в CDEK. Передача посылки ещё не подтверждена. | то же | FSM не двигается | да |
| ACCEPTED | Маппер: приём к перевозке | ACCEPTED | DELIVERY_ACCEPTED | CDEK подтвердил приём отправления. | CDEK подтвердил приём вашего отправления. | вперёд по rank, не с DELIVERED | нет |
| RECEIVED_AT_SHIPMENT_WAREHOUSE | Маппер: складская приёмка | ACCEPTED | DELIVERY_ACCEPTED | то же, что ACCEPTED | то же | то же | нет |
| READY_FOR_SHIPMENT_IN_SENDER_CITY | Маппер: готово к отправке в городе отправителя | ACCEPTED | DELIVERY_ACCEPTED | то же | то же | то же | нет |
| READY_FOR_SHIPMENT_IN_TRANSIT_CITY | Маппер: готово к отправке в транзитном городе | ACCEPTED | DELIVERY_ACCEPTED | то же | то же | то же | нет |
| PASSED_TO_CARRIER_AT_SENDER_CITY | Маппер: передано перевозчику в городе отправителя | ACCEPTED | DELIVERY_ACCEPTED | то же | то же | то же | нет |
| TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY | Маппер: в пути | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | Отправление в пути. | вперёд по rank | нет |
| SENT_TO_RECIPIENT_CITY | Маппер: в пути | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | нет |
| ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE | Маппер: склад города получателя | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | нет |
| ACCEPTED_AT_TRANSIT_WAREHOUSE | Маппер: транзитный склад | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | нет |
| TAKEN_BY_COURIER | Маппер: у курьера, это не DELIVERED | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | нет |
| RECEIVED_AT_SENDER_WAREHOUSE | Маппер: склад отправителя | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | нет |
| RETURNED_TO_SENDER_CITY | Маппер относит к IN_TRANSIT. Возврат как процесс не реализован | IN_TRANSIT | IN_TRANSIT | Отправление в пути. | то же | то же | да |
| READY_FOR_PICKUP | Маппер: пункт выдачи | READY_FOR_PICKUP | IN_TRANSIT | Отправление в пункте выдачи. | Отправление в пункте выдачи у получателя. | FSM = IN_TRANSIT | нет |
| ACCEPTED_AT_PICK_UP_POINT | Маппер: принято в ПВЗ | READY_FOR_PICKUP | IN_TRANSIT | то же | то же | то же | нет |
| POSTOMAT_POSTED | Маппер: заложено в постамат, не получение | READY_FOR_PICKUP | IN_TRANSIT | то же | то же | то же | нет |
| POSTOMAT_SEIZED | Группа ПВЗ сохранена. Текст не утверждает выдачу: семантика в OpenAPI не описана | READY_FOR_PICKUP | IN_TRANSIT | Статус в пункте выдачи изменился. Уточняем детали. | то же | то же | да |
| DELIVERED | Маппер: финал | DELIVERED | DELIVERED | CDEK подтвердил завершение доставки. | то же | терминальный, откат запрещён | нет |
| POSTOMAT_RECEIVED | Маппер приравнивает к DELIVERED | DELIVERED | DELIVERED | то же | то же | то же | нет |
| NOT_DELIVERED | Маппер: проблема | EXCEPTION | EXCEPTION | CDEK сообщил о проблеме с доставкой. | то же | пока не DELIVERED | да |
| INVALID | Маппер: EXCEPTION. Может совпасть со state запроса создания | EXCEPTION | EXCEPTION | то же | то же | то же | да |
| REMOVED_FROM_PICKUP_POINT | Маппер: EXCEPTION. Возврат не реализован | EXCEPTION | EXCEPTION | то же | то же | то же | да |
| любой другой | не маппится | — | не меняется | Статус уточняется | Статус уточняется | нет | да |

Каталог в коде: `App\Services\Cdek\CdekStatusCatalog`.

## 3. Polling и reconciliation

Webhook — основной триггер. `bin/cdek_reconcile_orders.php` (рекомендуется раз в 15 минут) берёт только заказы с UUID и незавершённым статусом, у которых `tracking_poll_after` уже наступил.

- Успешная сверка незавершённого заказа: следующий GET не раньше чем через 10 минут (`600` с). `EXCEPTION` — через 30 минут.
- `DELIVERED` / `CANCELLED` / `REFUND_REQUIRED` в выборку не попадают. После финала интервал ставится 6 часов на случай гонки.
- 429: пауза `1800 * попытка` секунд, потолок 6 часов.
- timeout / network / 5xx: `900 * попытка`, потолок 6 часов.
- 401/403 и 404: 1 час.
- После 8 неудач интервал не короче 6 часов. Частый опрос прекращается, редкая сверка остаётся.
- `claimTrackingPoll` ставит lease 120 секунд, второй процесс тот же заказ пропускает.
- Ошибка GET не меняет статус и не ставит `completed`.
- Пользовательская кнопка `POST /delivery/{id}/tracking/refresh` только сбрасывает `tracking_poll_after` на сейчас. Страница CDEK не вызывает.

Событие с `date_time` старше `last_carrier_event_at` пишется в историю и не двигает текущий статус. Более новый код с меньшим rank не откатывает `DELIVERED`.

## 4. API

| Method | Path | Поведение |
|---|---|---|
| GET | `/delivery/{id}/tracking` | Сводка из БД. CDEK не вызывается |
| POST | `/delivery/{id}/tracking/refresh` | Ставит сверку в очередь |

Доступ: покупатель своей доставки, продавец связанной, администратор с правом `disputes`. Чужой id — 403 без данных заказа.

Ответ: `delivery_order_id`, статус доставки, оплата, регистрация, текст перевозки, время, `eta` только из `planned_delivery_date`, `completed`, готовые печатные формы (`download_path` Zakopeyki). Нет token, `client_secret`, сырого payload, телефона и адреса другой стороны. Технический `cdek_status_code` — только администратору.

## 5. Уведомления

`Notification::createFor`, идемпотентность в `delivery_tracking_notices` (`delivery_order_id + kind + user_id`).

Письмо один раз на пару «доставка + вид + пользователь»:

- `registered` — код `CREATED`
- `accepted` — коды группы ACCEPTED
- `pickup` — `READY_FOR_PICKUP`, `ACCEPTED_AT_PICK_UP_POINT`, `POSTOMAT_POSTED`
- `problem` — `NOT_DELIVERED`, `INVALID`, `REMOVED_FROM_PICKUP_POINT`
- `delivered` — `DELIVERED`, `POSTOMAT_RECEIVED`

Промежуточный `IN_TRANSIT` писем не шлёт. Повтор webhook и повторный poll того же статуса не шлют второе. 401/404/429/5xx/timeout пользователю не пишутся. В тексте нет телефона и адреса.

## 6. GAPs и вопросы к CDEK

1. Официальный enum `OrderStatusDto.code` в OpenAPI пустой. Таблица выше — коды прежнего маппера, не словарь CDEK.
2. Нет отдельного tracking URL. Используется `GET /v2/orders/{uuid}` и webhook.
3. `POSTOMAT_SEIZED`: группа пункта выдачи сохранена, смысл кода в спецификации не описан.
4. `RETURNED_TO_SENDER_CITY` и `REMOVED_FROM_PICKUP_POINT` не запускают возврат. Возвраты вне этого этапа.
5. В FSM нет отдельного `READY_FOR_PICKUP`; в интерфейсе текст отдельный, в статусе доставки — `IN_TRANSIT`.
6. `INVALID` как код статуса и `INVALID` как state запроса создания — разные контуры. Tracking новый заказ не создаёт.
7. Успешная регистрация (Phase 8) по-прежнему может перевести FSM в `DELIVERY_ACCEPTED` до кода перевозки. Сводка при пустом `cdek_status_code` всё равно говорит, что передача посылки не подтверждена.
8. `planned_delivery_date` показывается как дата CDEK. Своя оценка срока не считается.
9. Если у заказа нет UUID, сверка не подбирает его по `cdek_number` и не создаёт заказ.
