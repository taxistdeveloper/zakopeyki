# CDEK print forms — Phase 10

> OpenAPI: `WaybillRequestDto`, `WaybillPrintResponseDto`, `WaybillDto`, `GET /v2/print/orders/{uuid}.pdf`.  
> Не создаёт CDEK order и не меняет оплату. `PRINT_FORM` **не** доказательство доставки.

## 1. Поддерживаемые формы

Только enum `WaybillRequestDto.type`:

`tpl_china`, `tpl_armenia`, `tpl_russia`, `tpl_english`, `tpl_italian`, `tpl_korean`, `tpl_latvian`, `tpl_lithuanian`, `tpl_german`, `tpl_turkish`, `tpl_czech`, `tpl_thailand`, `tpl_invoice`.

По умолчанию `tpl_russia` (`CDEK_PRINT_DEFAULT_TYPE`). Штрихкоды `POST /v2/print/barcodes` на этом этапе не включены.

Идемпотентность: одна строка на `delivery_order_id + form_type` (`UNIQUE`).

## 2. API Zakopeyki

| Method | Path | Кто |
|---|---|---|
| GET | `/delivery/{id}/print-forms` | buyer, seller, admin |
| POST | `/delivery/{id}/print-forms` | то же; `form_type` с whitelist |
| GET | `/delivery/{id}/print-forms/{formId}` | статус |
| GET | `/delivery/{id}/print-forms/{formId}/file` | PDF, только `READY` |

Frontend получает: `id`, `form_type`, `status`, даты, `download_path` (путь Zakopeyki). Без CDEK token, `client_secret`, сырого `file_url`.

CDEK вызывается только сервером: `POST /v2/print/orders`, `GET /v2/print/orders/{uuid}`, `GET /v2/print/orders/{uuid}.pdf`.

## 3. Жизненный цикл

`REQUESTED` → `POST` → HTTP **202 / ACCEPTED = `PROCESSING`** (не PDF).  
`GET` entity.`url` (https) и не-202 → `READY`.  
`requests[].state = INVALID` или HTTP 400/401/403/404/409 → `FAILED`.  
429/5xx/timeout → остаётся `PROCESSING`, poll до 8 попыток, затем `FAILED`.  
Повторный `GET .pdf` = 404 после READY → `EXPIRED`.

Повтор кнопки при `REQUESTED|PROCESSING|READY` не делает второй POST.

## 4. PRINT_FORM webhook

`CdekWebhookService` сохраняет event (`event_hash` UNIQUE) и вызывает `applyWebhook`.  
Готовность только если в attributes есть https `url` (поле есть у `WaybillDto`). Файл в webhook **не** скачивается.  
Повтор того же event — duplicate, без новой строки.  
Ответ webhook остаётся HTTP 200 после приёма события (кроме 401 на токене).

## 5. Хранение

PDF в `storage/cdek-prints/{deliveryId}/{formId}.pdf` (вне public), лимит 8 МБ, сигнатура `%PDF`.  
Путь проверяется регулярным выражением `\d+/\d+\.pdf`.  
Временный `entity.url` хранится как metadata и **не** проксируется наружу (SSRF). Скачивание только через официальный `.pdf` endpoint клиента.

## 6. Права

Buyer и seller этой доставки и admin (`disputes`). Чужой заказ — 403. Нет CDEK UUID или ранний/отменённый статус — отказ.

## 7. Retry

`bin/cdek_print_poll.php` — не чаще, чем записи старше 3 с, максимум 8 попыток. Бесконечных повторов нет.

## 8. GAP

1. `PrintStatusDto.code` в OpenAPI — пустой enum. Коды READY/PROCESSING официально не зафиксированы; READY ставим только по `entity.url`.
2. Входящее тело `PRINT_FORM` в OpenAPI нет (есть только регистрация webhook). Берём `url` / uuid, если они пришли; иначе форма остаётся PROCESSING.
3. Срок жизни `url` и файла `.pdf` в документации не задан.
4. Нет шаблона Kazakhstan в enum — какой `type` нужен для KZ, подтвердить у CDEK.
5. Кто по правилам CDEK имеет право на накладную (только отправитель или и получатель) — не указано; доступ у обеих сторон сделки отмечен как допущение.
6. Баркоды (`BarcodeRequestDto`) не входят в этот этап.
