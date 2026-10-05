# CDEK Data Mapping — Zakopeyki.kz

> Field names on the CDEK side are taken from `openapi_api_v2_integration.json` schemas.  
> If a CDEK field is not in OpenAPI, it is not invented.  
> Companion: `docs/cdek-integration-audit.md`.

**Legend**

- **R** = required by OpenAPI for that DTO/operation  
- **O** = optional in OpenAPI  
- **Cond** = conditionally required by CDEK/business rules (point XOR location; phones in practice; etc.) — where OpenAPI is silent, marked as GAP/Cond

---

## 1. Calculator — `POST /v2/calculator/tarifflist`

Request schema: `CalculatorTariffListRequestDto`  
Required: `from_location`, `to_location`, `packages`

| Zakopeyki field | CDEK field | Endpoint | R/O | Source | Validation | Transformation | When obtained |
|---|---|---|---|---|---|---|---|
| Sender city (resolved) | `from_location.code` | tarifflist | O* | `delivery_senders.city` → `GET /location/cities` | City must resolve | string city → int city code | Before quote |
| Sender country | `from_location.country_code` | tarifflist | O | sender / default `KZ` | ISO-ish 2-letter | uppercase | Before quote |
| Sender postal | `from_location.postal_code` | tarifflist | O | `delivery_senders.postal_code` | format | as string | Before quote |
| Sender address (if used) | `from_location.address` | tarifflist | O | street+building+apt | non-empty if used | concat | Before quote |
| Sender PVZ (if used) | `shipment_point` | tarifflist | O | sender `shipment_point` / `pvz_code` | valid point code | as-is | Before quote |
| Recipient city code | `to_location.code` | tarifflist | O* | `delivery_recipients.city` → cities API | must resolve | int | Buyer form |
| Recipient country | `to_location.country_code` | tarifflist | O | recipient / `KZ` | 2-letter | uppercase | Buyer form |
| Recipient postal | `to_location.postal_code` | tarifflist | O | recipient | — | string | Buyer form |
| Recipient address | `to_location.address` | tarifflist | O | recipient | — | concat | Buyer form |
| Recipient PVZ | `delivery_point` | tarifflist | O | `delivery_recipients.pvz_code` | active handout PVZ | as-is | Buyer form |
| Billed weight (g) | `packages[].weight` | tarifflist | R (`CalcPackageRequestDto.weight`) | `delivery_shipments.billed_gross_weight` / gross | >0 | kg→grams int | Listing + seller confirm |
| Package L/W/H (cm) | `packages[].length/width/height` | tarifflist | O | billed package dims | >0 if present | cm int | Listing / packaging |
| Order type config | `type` | tarifflist | O | `config/cdek.order_type` | 1 or 2 (GAP: enum not in OpenAPI) | int | Config |
| Currency | `currency` | tarifflist | O | `config/cdek.currency` (2=KZT in example) | int | as-is | Config |
| Lang | `lang` | tarifflist | O | `rus` | — | as-is | Config |

\*OpenAPI marks `from_location`/`to_location` objects required, but their properties are all optional (`CalculatorLocationDto.required=[]`). Practical requirement to identify localities is a **GAP** to confirm with CDEK (code typically sends `code`).

### Response mapping (`TariffCodeDto` in `tariff_codes[]`)

| CDEK field | Zakopeyki field | Notes |
|---|---|---|
| `tariff_code` | `delivery_quotes.service_code` = `cdek_{tariff_code}` | Required in TariffCodeDto |
| `tariff_name` | quote display name | Required |
| `delivery_mode` | mode filter (courier vs pvz) | Required; code maps modes 1,3 vs 2,4,6,7 |
| `delivery_sum` | base amount → INT tenge | Required |
| `period_min` / `period_max` | ETA display | Required |
| `delivery_date_range.min/max` | optional ETA dates | Optional |
| `calendar_min` / `calendar_max` | optional | Optional |

**Platform-only (NOT from CDEK):** packaging catalog price, irregular +500, fragile +300 may be added to `total_amount`. These must be shown separately or removed — they are not CDEK API fields.

---

## 2. Calculator — `POST /v2/calculator/tariff` (recommended revalidation)

Request: `CalculatorRequestDto`  
Required: `tariff_code`, `from_location`, `to_location`, `packages`

Same location/package mapping as tarifflist, plus:

| Zakopeyki field | CDEK field | R/O | When |
|---|---|---|---|
| Selected quote tariff | `tariff_code` | R | Before pay / before create |
| Selected extra services | `services[]` (`AdditionalServiceRequestDto`) | O | If used |

Response: `CalculatorResponseDto` required `delivery_sum`, `total_sum`, `currency`, `period_min`, `period_max`, `weight_calc`.

---

## 3. Calculator — `POST /v2/calculator/tariffAndService`

Request: `CalculatorTariffWithServicesRequestDto`  
Required: `from_location`, `to_location`, `packages`  
Use when service pricing must accompany tariff list. **Not used in code today.**

---

## 4. Order create — `POST /v2/orders`

Request: `OrderCreateRequestDto`  
OpenAPI required: `tariff_code`, `recipient`, `packages`

| Zakopeyki field | CDEK field | Endpoint | R/O | Source | Validation | Transformation | When obtained |
|---|---|---|---|---|---|---|---|
| Config order type | `type` | orders | O | `cdek.order_type` | GAP: meaning 1/2 | int | Config / CDEK confirm |
| `delivery_orders.order_number` | `number` | orders | O | generated DO-… | maxLength 40 | truncate | Bootstrap delivery |
| Selected tariff | `tariff_code` | orders | R | quote `cdek_(\d+)` | must parse | int | After quote select |
| Comment | `comment` | orders | O | system | max 255 | `Zakapeiku delivery #{id}` | Create time |
| Idempotency aid | `developer_key` | orders | O | `zk-del-{deliveryOrderId}` | stable per delivery | string | Create time |
| Seller contact name | `sender.name` | orders | R (SenderContactDto) | `delivery_senders.name` | non-empty | as-is | Before publish / seller form |
| Seller phone | `sender.phones[].number` | orders | Cond (PhoneDto.number R if phone present) | `delivery_senders.phone` | phone format | normalize | Seller form |
| Seller email | `sender.email` | orders | O | sender/user | email | as-is | Optional |
| Seller company | `sender.company` | orders | O | business profile? | — | as-is | GAP if needed |
| Seller passport/TIN | `sender.passport_*` / `tin` / `contragent_type` | orders | O | not collected | — | — | GAP |
| Buyer name | `recipient.name` | orders | R | `delivery_recipients.name` | non-empty | as-is | Buyer form |
| Buyer phone | `recipient.phones[].number` | orders | Cond | recipient.phone | phone | normalize | Buyer form |
| Buyer email | `recipient.email` | orders | O | recipient/user | email | as-is | Optional |
| Origin PVZ | `shipment_point` | orders | O XOR | seller point | valid reception point | as-is | Seller (weakly supported in DB) |
| Origin address | `from_location.address` | orders | R if location used | sender street+building+apt | non-empty | concat | Seller / listing |
| Origin city code | `from_location.code` | orders | O | cities API | — | int | Before create |
| Origin city/region/country/postal | `from_location.*` | orders | O | sender fields | — | map | Seller |
| Dest PVZ | `delivery_point` | orders | O XOR | `pvz_code` when mode pvz | handout+weight | as-is | Buyer |
| Dest address | `to_location.address` | orders | R if location used | recipient address | non-empty | concat | Buyer (door) |
| Dest city code | `to_location.code` | orders | O | cities API | — | int | Buyer |
| Package number | `packages[].number` | orders | R | system | string | e.g. `1` | Create |
| Package weight g | `packages[].weight` | orders | R | shipment billed weight | >0 | kg→g | Listing/seller |
| Package dims | `packages[].length/width/height` | orders | O | shipment | — | cm int | Listing/seller |
| Item name | `packages[].items[].name` | orders | R (ItemRequestDto) | `product_title` | max 255 | truncate | Product |
| Item ware_key | `packages[].items[].ware_key` | orders | R | `item-{deliveryOrderId}` | max 50 | synthesize | Create |
| Item amount | `packages[].items[].amount` | orders | R | qty (currently 1) | ≥1 | int | Shipment |
| Item weight | `packages[].items[].weight` | orders | R | item weight grams | >0 | kg→g | Listing |
| Declared cost | `packages[].items[].cost` | orders | R | product/declared | ≥0 | number | Product / policy |
| COD / due from recipient | `packages[].items[].payment.value` | orders | R (MoneyDto) | **0** (prepaid on platform) | 0 | fixed | Create |
| True goods seller (OpenAPI) | `seller` (SellerDto) | orders | O | marketplace seller legal? | — | name/inn/phone/address | **Not sent today — GAP** |
| Shipper name/address | `shipper_name` / `shipper_address` | orders | O | seller? | — | — | GAP |
| Delivery cost to collect | `delivery_recipient_cost` | orders | O | should be unused if prepaid | — | — | Prefer omit if prepaid |
| Extra services | `services[]` | orders | O | fragile etc. | codes from CDEK | map | If confirmed |
| Print hint | `print` | orders | O | enum WAYBILL/BARCODE | — | — | Later phase |

### Location XOR (operational rule used in code)

| Mode | Origin fields | Destination fields |
|---|---|---|
| Door-door | `from_location` | `to_location` |
| Door-PVZ | `from_location` | `delivery_point` |
| PVZ-door | `shipment_point` | `to_location` |
| PVZ-PVZ | `shipment_point` | `delivery_point` |

OpenAPI does not encode XOR as `oneOf`; confirmed as implementation convention + CDEK practice → still list as Cond/GAP if docx missing.

---

## 5. Order get — `GET /v2/orders/{uuid}` / `GET /v2/orders?im_number=`

Response entity: `OrderResponseDto`

| CDEK field | Zakopeyki storage | When |
|---|---|---|
| `uuid` | `delivery_orders.logistics_order_id` | After create / reconcile |
| `cdek_number` | `delivery_tracking.tracking_number` | When assigned |
| `number` | match `delivery_orders.order_number` | Correlation |
| `statuses[].code` | mapped FSM + tracking `carrier_status` | Webhook/reconcile |
| `statuses[].name/date_time/city` | tracking message/location/event_at | Webhook/reconcile |
| `delivery_detail` | optional store | Later |
| `delivery_problem` | EXCEPTION path | Webhook type / poll |

---

## 6. Delivery points — `GET /v2/deliverypoints`

Response items: `OfficeDto`

| CDEK field | Zakopeyki `cdek_delivery_points` | UI use |
|---|---|---|
| `code` | `code` (UNIQUE) | Buyer selects Point B / future seller Point A |
| `uuid` | stored if migrated | internal |
| `type` | `type` (PVZ/POSTAMAT/…) | filter |
| `location.city_code/city/...` | city fields | filter by city |
| `is_handout` | `is_handout` | recipient PVZ |
| `is_reception` | `is_reception` | sender PVZ |
| `weight_max` / dims max | stored / validate | package fit |
| `work_time*` | optional display | UI |
| `phones` | optional | UI |

Query filters available in OpenAPI: `country_code`, `city_code`, `type`, `is_handout`, `is_reception`, `weight_min/max`, `length/width/height`, pagination `page`/`size`, etc.

**Do not use a static hard-coded PVZ list.** Sync from API (existing CLI).

---

## 7. OAuth — `POST /v2/oauth/token`

| Zakopeyki config | CDEK field | Storage | Frontend |
|---|---|---|---|
| `CDEK_ACCOUNT` | `client_id` | env / server config | Forbidden |
| `CDEK_SECURE_PASSWORD` | `client_secret` | env | Forbidden |
| fixed | `grant_type` | code (`client_credentials` in implementation) | Forbidden |
| response | `access_token` | Redis/file cache | Forbidden |
| response | `expires_in` | TTL = expires_in − 60s | — |
| response | `token_type`, `scope`, `jti` | unused beyond auth header | — |

---

## 8. Webhooks — registration `POST /v2/webhooks`

| Zakopeyki | CDEK `WebhookDto` | R/O |
|---|---|---|
| Public HTTPS URL | `url` | R |
| Event kind | `type` enum | R |
| Returned id | `uuid` | R (readOnly on create response) |

Inbound payload fields: **GAP** (not in OpenAPI). Code currently reads `type`, event `uuid`, `attributes.number|uuid|cdek_number|code|status|…`.

---

## 9. Role → data ownership matrix

| Data class | Seller | Buyer | Product | Checkout | Payment | Calculator | Zakopeyki system |
|---|---|---|---|---|---|---|---|
| Point A / sender | **Owns** | — | listing copy | — | — | uses | validates |
| Point B / recipient | — | **Owns** | — | — | — | uses | validates |
| Weight/dims | Provides | — | listing | — | — | uses | may recommend packaging |
| Tariff choice | — | Selects | — | may select method | — | returns options | stores quote |
| Delivery price | — | Pays | — | — | confirms | **source of truth** | displays (+ optional fees) |
| CDEK order create | — | — | — | — | **gate** | — | server-only after paid |
| OAuth secrets | — | — | — | — | — | — | **only system** |
| P2P escrow amount | Receives (goods) | Pays (goods) | price | creates | confirms | unrelated | escrow |

---

## 10. Listing publish gate (CDEK path)

When seller offers CDEK (`fulfillment_mode` = `delivery` | `both`) for physical listing types (`used`/`new`/`auction`), before product stays/goes `active`:

| Required business data | Source | Maps toward |
|---|---|---|
| Contact name + phone | seller input / default copy | future `sender.name` / `phones` |
| Address (door) **or** `shipment_point` (PVZ) | seller | `from_location` XOR `shipment_point` |
| City resolvable to CDEK `code` | seller city → Location API / known codes | `from_location.code` |
| Weight + dims (or standard packaging) | seller | `packages[]` (kg→g / cm at API time) |
| Package count | seller (default 1) | future multi-package (**GAP**: still 1 package in create) |
| Declared value + currency | seller / product price | `packages[].items[].cost` |
| Fulfillment includes delivery | listing `fulfillment_mode` | platform gate → `cdek_ready=1` |

Pickup-only (`fulfillment_mode=pickup`): **no** CDEK mapping required; `cdek_ready=0`; do not block publish.

Server gate: `ListingShippingService::validateAndBuild()` → structured `{error_code, field, missing_fields}`.  
Ownership: `saveForOwnedProduct()` / `DeliveryModelService::saveListingPointA()`.  
Locked edit: if paid/created delivery exists for product → reject Point A/param changes (`shipping_locked`).

---

## 11. Explicit non-mappings (do not invent)

| Concept | Status |
|---|---|
| Zakopeyki as CDEK `recipient` | Forbidden |
| Zakopeyki as delivery payer in payload | Not a CDEK field; payment is off-API |
| Buyer as `sender` | Forbidden |
| Platform surcharges as CDEK `delivery_sum` | Must not overwrite CDEK sum |
| Passport fields | Optional in OpenAPI; not in current user model — collect only if CDEK requires |
| АВР legal template fields | **GAP** — confirm with CDEK; local `delivery_documents.avr_data` is platform-side only |

---

## 12. Phase 2 — фактическое соответствие entity → БД → CDEK

> Добавлено после реализации внутренней модели данных. Реальный `POST /v2/orders` **отключён** (`CDEK_ORDER_CREATE_ENABLED=0`).

### 12.1 Seller / Point A

| Zakopeyki entity | DB field | Назначение | Future CDEK field | Notes |
|---|---|---|---|---|
| Listing Point A | `product_listing_shipping.ship_contact_name` | ФИО отправителя на объявлении | `sender.name` | PII |
| Listing Point A | `product_listing_shipping.ship_phone` | Телефон отправителя | `sender.phones[].number` | PII |
| Listing Point A | `product_listing_shipping.ship_*` address | Адрес точки A | `from_location.address` / parts | PII |
| Listing Point A | `product_listing_shipping.origin_type` | `door` \| `pvz` | выбор `from_location` XOR `shipment_point` | |
| Listing Point A | `product_listing_shipping.shipment_point` | Код ПВЗ сдачи | `shipment_point` | |
| Listing Point A | `product_listing_shipping.cdek_city_code` | Код города CDEK | `from_location.code` | |
| Listing Point A | `product_listing_shipping.ship_latitude/longitude` | Координаты | `from_location.latitude/longitude` | optional |
| Listing Point A | `product_listing_shipping.cdek_ready` | Готовность Point A+package | — (platform) | |
| Listing Point A | `product_listing_shipping.declared_value` | Объявленная стоимость | `packages[].items[].cost` | |
| Delivery Point A | `delivery_senders.*` | Копия/уточнение на delivery order | same as above | UNIQUE per delivery_order |
| Delivery Point A | `delivery_senders.company` | Компания отправителя | `sender.company` | optional |
| Delivery Point A | `delivery_senders.origin_type` | door/pvz | XOR origin | |
| Delivery Point A | `delivery_senders.shipment_point` | ПВЗ сдачи | `shipment_point` | |
| Delivery Point A | `delivery_senders.cdek_city_code` | city code | `from_location.code` | |

### 12.2 Shipment / Package

| Zakopeyki entity | DB field | Назначение | Future CDEK field |
|---|---|---|---|
| Shipment | `delivery_shipments.billed_gross_weight` | Вес к тарифу (kg) | `packages[].weight` (grams) |
| Shipment | `delivery_shipments.billed_length/width/height` | Габариты | `packages[].length/width/height` |
| Shipment | `delivery_shipments.package_count` | Число мест | multiple `packages[]` — **GAP**: сейчас 1 package |
| Shipment | `delivery_shipments.description` | Описание | `packages[].comment` / item name |
| Shipment | `delivery_shipments.declared_cost` | Стоимость товара | `packages[].items[].cost` |
| Shipment | `delivery_shipments.declared_currency` | Валюта объявленной стоимости | platform; CDEK MoneyDto без currency в item |
| Shipment | `delivery_shipments.product_title` | Название | `packages[].items[].name` |

### 12.3 Buyer / Point B

| Zakopeyki entity | DB field | Назначение | Future CDEK field |
|---|---|---|---|
| Point B | `delivery_recipients.name/phone/email` | Получатель = заказчик доставки | `recipient.*` |
| Point B | `delivery_recipients.delivery_mode` | `courier` \| `pvz` | XOR `to_location` / `delivery_point` |
| Point B | `delivery_recipients.street/...` | Адрес двери | `to_location.address` |
| Point B | `delivery_recipients.pvz_code` | Код ПВЗ | `delivery_point` |
| Point B | `delivery_recipients.delivery_point` | Явный CDEK delivery_point | `delivery_point` |
| Point B | `delivery_recipients.cdek_city_code` | city code | `to_location.code` |
| Point B | `delivery_recipients.latitude/longitude` | coords | `to_location.latitude/longitude` |
| Customer link | `delivery_orders.customer_id` / `buyer_user_id` | Покупатель = customer | **нет отдельного CDEK customer object** |

### 12.4 Delivery Quote (snapshot)

| Zakopeyki entity | DB field | Назначение | Future CDEK field |
|---|---|---|---|
| Quote | `delivery_quotes.total_amount` | Снимок цены покупателю (INT KZT) | derived from `delivery_sum` (+ platform extras) |
| Quote | `delivery_quotes.cdek_delivery_sum` | Чистая сумма CDEK | `TariffCodeDto.delivery_sum` |
| Quote | `delivery_quotes.tariff_code` | Выбранный тариф | `tariff_code` |
| Quote | `delivery_quotes.cdek_delivery_mode` | Режим доставки | `delivery_mode` |
| Quote | `delivery_quotes.service_code` | `cdek_{tariff}` | maps to `tariff_code` |
| Quote | `delivery_quotes.snapshot_json` | Полный снимок запроса/ответа | calculator response |
| Quote | `delivery_quotes.services_json` | Доп. услуги | `services[]` |
| Quote | `delivery_quotes.valid_until` | TTL | — |
| Quote | `delivery_quotes.quote_status` | active/superseded/paid_snapshot/… | — |
| Quote | `delivery_quotes.request_payload_hash` | Идемпотентность calc | — |
| Link | `delivery_orders.quote_id` | Выбранный quote | — |

### 12.5 Delivery Order + payment + CDEK ids

| Zakopeyki entity | DB field | Назначение | Future CDEK field |
|---|---|---|---|
| Delivery Order | `delivery_orders.id` | Внутренний ID | — |
| Delivery Order | `delivery_orders.order_id` | P2P `orders.id` (UNIQUE) | — |
| Delivery Order | `delivery_orders.order_number` | Внутренний номер | `number` / `im_number` |
| Delivery Order | `delivery_orders.seller_user_id` | Продавец | not a CDEK role field |
| Delivery Order | `delivery_orders.buyer_user_id` | Покупатель/заказчик | not a CDEK role field |
| Delivery Order | `delivery_orders.status` | Внутренний FSM | mapped from statuses |
| Delivery Order | `delivery_orders.payment_status` | unpaid/paid | — |
| Delivery Order | `delivery_orders.payment_id` | `delivery_payments.id` | — |
| Delivery Order | `delivery_orders.logistics_order_id` | legacy uuid storage | `entity.uuid` |
| Delivery Order | `delivery_orders.cdek_uuid` | CDEK UUID (UNIQUE) | `entity.uuid` |
| Delivery Order | `delivery_orders.cdek_number` | Номер накладной | `cdek_number` |
| Delivery Order | `delivery_orders.cdek_request_uuid` | request uuid async | `requests[].request_uuid` |
| Delivery Order | `delivery_orders.cdek_api_status` | `none\|pending\|accepted\|created\|failed` | async processing |
| Delivery Order | `delivery_orders.cdek_status_code` | last status code | `statuses[].code` |
| Delivery Order | `delivery_orders.create_idempotency_key` | UNIQUE anti-dupe create | `developer_key` correlation |
| Delivery Order | `delivery_orders.last_synced_at` | sync timestamp | — |
| Delivery Order | `delivery_orders.last_error_code/message` | ошибка без PII | mapped errors |
| Payment | `delivery_payments.*` | Оплата 100% доставки | off-API; gates create |

### 12.6 Status layers (не смешивать)

| Layer | Where | Examples |
|---|---|---|
| P2P order | `orders.status` | escrowed, shipped, completed |
| Delivery payment | `delivery_payments.status` | pending, paid, failed |
| Delivery FSM | `delivery_orders.status` | `DELIVERY_PAID`, `CDEK_ORDER_PENDING`, `IN_TRANSIT`, `DELIVERED`, `CDEK_ORDER_FAILED`, `REFUND_REQUIRED` |
| CDEK API async | `delivery_orders.cdek_api_status` | none → pending → created/failed |
| CDEK carrier | `delivery_orders.cdek_status_code` + `delivery_tracking` | ACCEPTED, DELIVERED, … (**enum GAP** in OpenAPI) |

### 12.7 Remaining GAPs (unchanged / still open)

1. `Интеграция.docx` missing.
2. Inbound webhook payload schema not in OpenAPI.
3. `order_type` 1 vs 2 legal meaning.
4. Whether passport/`seller` (SellerDto) required for KZ.
5. Multi-package `packages[]` (currently one row/package).
6. Real CDEK create still deferred by config flag.

---

## 13. Phase 4 — Объявление → Point A → Shipment → future CDEK

> Реализован publish gate продавца. Point B / quote / payment / CDEK create — **не** этот этап.

### 13.1 Who fills what

| Field | Seller enters | From CDEK API | Snapshot on listing | Required if CDEK on |
|---|---|---|---|---|
| `fulfillment_mode` | yes | — | yes | yes (delivery/both/pickup) |
| `ship_contact_name` | yes (or default copy) | — | yes | yes |
| `ship_phone` | yes | — | yes | yes |
| `ship_country/region/city/street/building/apartment` | yes | — | yes | city+street (door) |
| `ship_postal_code` | yes | — | yes | format if present |
| `origin_type` door\|pvz | yes | — | yes | yes |
| `shipment_point` | yes (PVZ picker) | codes from local PVZ directory (synced from CDEK) | yes | if origin=pvz |
| `cdek_city_code` | auto via `/delivery/cdek/cities` → Client Location | **yes** (resolved) | yes | yes |
| `ship_latitude/longitude` | optional | optional | yes | no |
| `item_weight` (kg) | yes | — | yes | yes (exact mode) |
| L/W/H (cm) | yes | — | yes | yes (exact) / via packaging |
| `package_count` | yes | — | yes | yes (default 1) |
| `shipment_description` | yes | — | yes | no (recommended) |
| `declared_value` / `declared_currency` | yes | — | yes | recommended |
| `cdek_ready` | system | — | yes | must be 1 to publish CDEK path |
| `shipping_version` | system bump on change | — | yes | — |

User profile `users.ship_*` is **only a template**. On save with `use_default_ship_from`, values are **copied** into `product_listing_shipping`. Later profile edits do **not** mutate published listing Point A.

### 13.2 Units

| Internal (listing / DB) | CDEK API (future create/calc) |
|---|---|
| weight kg (`DECIMAL`) | grams int (`packages[].weight`) |
| dims cm | cm int |
| declared_value INT tenge | `items[].cost` number |
| currency `KZT` | platform; CDEK MoneyDto item has no currency field |

### 13.3 Edit rules after publish

| Situation | Behavior |
|---|---|
| No delivery order / unpaid collection | Point A + shipment may update; `shipping_version++` |
| Paid / CDEK pending / order created / in transit | **Block** silent Point A/param change (`shipping_locked`) — cancel/recreate later stage |
| Switching pickup → CDEK | Must fill Point A; publish/update rejected until valid |

### 13.4 GAP (Phase 4)

- Full cancel/recreate UX when listing changes after paid delivery — documented, not implemented.
- AI `publishDraft` still bypasses shipping gate — **GAP**.
- Multi-package CDEK payload still single package.
- Seller legal `SellerDto` / passport — not collected.

---

## 14. Phase 5 — Buyer → Point B → CDEK Delivery Point / адрес → future Calculator → future OrderCreate

> Реализован checkout Point B покупателя. **Quote calc / payment / CDEK create — не этот этап.**

### 14.1 Flow (роль)

```
Buyer (заказчик + получатель)
  → выбирает delivery_method=cdek
  → delivery_mode = pvz | courier
  → город (+ cdek_city_code из Location API)
  → ПВЗ (delivery_point) XOR адрес двери (to_location)
  → ФИО / телефон получателя
  → snapshot на orders.buyer_delivery_json
  → после оплаты товара: bootstrap delivery_order → delivery_recipients
  → (следующий этап) Calculator tarifflist/tariff
  → (позже) OrderCreateRequest.recipient + to_location XOR delivery_point
```

Point A (продавец) и Point B (покупатель) **не смешиваются**.

### 14.2 Snapshot / persistence

| Этап | Хранение | Примечание |
|---|---|---|
| Checkout (до escrow) | `orders.buyer_delivery_json` | JSON snapshot v1; не зависит от профиля |
| После bootstrap | `delivery_recipients.*` | Копия Point B на delivery order |
| Профиль `users.*` | только prefill UI | Изменение профиля **не** меняет сохранённый Point B |

Fingerprint Point B используется в `DeliveryService::saveBuyerData`: при изменении → `invalidateQuotes('address_changed')`.  
При apply из checkout: `auto_quote=false` (расчёт — следующий этап).

### 14.3 Обязательные поля Point B

| Поле | ПВЗ | Дверь | Получатель | Future CDEK | R/O/GAP |
|---|---|---|---|---|---|
| `name` | — | — | **R** | `recipient.name` | R (OpenAPI RecipientContactDto) |
| `phone` | — | — | **R** (платформа) | `recipient.phones[].number` | Cond/GAP: OpenAPI PhoneDto.number R if phone present; бизнес считает phone обязательным |
| `email` | — | — | O | `recipient.email` | O |
| `delivery_mode` | `pvz` | `courier` | — | XOR mode | platform |
| `city` | **R** | **R** | — | city name / resolve | Cond |
| `cdek_city_code` | Cond (из справочника ПВЗ) | **R** (платформа) | — | `to_location.code` | O* в CalculatorLocationDto; **R для calc readiness** (платформа) |
| `country` | default `KZ` | default `KZ` | — | `to_location.country_code` | O |
| `region` | O | O | — | (не отдельное поле в LocationDto) | GAP если CDEK потребует |
| `postal_code` | O | O (формат если задан) | — | `to_location.postal_code` | O |
| `street` (+ building/apartment) | — | **R** street | — | `to_location.address` (concat) | Cond: door XOR point |
| `pvz_code` / `delivery_point` | **R** + directory check | — | — | `delivery_point` | Cond XOR |
| `pvz_name` | snapshot display | — | — | — | platform |
| `latitude`/`longitude` | from PVZ dir if present | O | — | `to_location.latitude/longitude` | O |
| `notes` | O | O | O | `comment`? | GAP: не маппим пока в create |

\*Свойства `CalculatorLocationDto` в OpenAPI все optional; практическая идентификация города — **GAP** (мы требуем `cdek_city_code` для двери).

### 14.4 Validation (server)

`BuyerPointBService::validateForCheckout` + `DeliveryPointValidator::validatePointB($input, requireCdekCodes=true)`:

1. Non-CDEK (`pickup` / kazpost / …) → Point B не требуется.
2. CDEK: товар существует; покупатель ≠ продавец; listing `cdek_ready`; вес+габариты.
3. Получатель: name, phone; city.
4. ПВЗ: код **перепроверяется** через `CdekDeliveryPointsSyncService::validateCode` (не доверяем frontend); канонические code/city/coords из справочника.
5. Дверь: street + `cdek_city_code`; postal format если указан.
6. Ownership на update: только `buyer_user_id`; чужой delivery → `forbidden`.
7. После `DELIVERY_PAID` / CDEK create statuses / `cdek_uuid` → `point_b_locked` (**GAP**: полная матрица статусов позже).

### 14.5 API (существующий стиль)

| Method | Path | Назначение |
|---|---|---|
| GET | `/delivery/cdek/cities` | Resolve city → `cdek_city_code` (Location) |
| GET | `/delivery/cdek/points` | Список ПВЗ по городу/коду (локальный sync directory) |
| GET | `/delivery/{id}/recipient` | Сохранённый Point B (buyer/seller) |
| POST | `/delivery/{id}/recipient` | Update Point B → invalidate quotes; `auto_quote=false` из этого path |
| Checkout POST | `/checkout/...` | Gate: CDEK требует валидный Point B snapshot |

Checkout UI: CDEK → mode → city → PVZ|door → recipient → submit. Без валидного Point B оплата товара с `delivery_method=cdek` блокируется.

### 14.6 Mapping к будущим CDEK requests

| Snapshot / DB | Calculator (`to_location` / `delivery_point`) | OrderCreate |
|---|---|---|
| `cdek_city_code` | `to_location.code` | `to_location.code` |
| door `street+building+apartment` | `to_location.address` | `to_location.address` |
| `postal_code` / `country` | location fields | location fields |
| `pvz_code`/`delivery_point` | `delivery_point` | `delivery_point` |
| `name`/`phone`/`email` | — (не в calc) | `recipient.*` |
| Point A listing/shipment | `from_location` / `shipment_point` + `packages[]` | `sender` + `packages[]` |

### 14.7 GAP (Phase 5)

1. Полная матрица «когда можно менять Point B после оплаты» — сейчас грубый lock по paid/CDEK statuses.
2. Email обязателен ли для CDEK KZ — OpenAPI optional; не требуем.
3. Building/apartment/region обязательность в CDEK address — не подтверждена OpenAPI → optional.
4. Карта ПВЗ по координатам — только если UI карты уже есть (сейчас datalist/list).
5. Quote calc — см. Phase 6 / `docs/cdek-calculator.md`. Pay / OrderCreate — следующие этапы.
6. Прямой live `GET /v2/deliverypoints` на каждый checkout — используется локальный directory (sync); live filters — через sync service.

---

## 15. Phase 6 — Point A + Point B + Shipment → Calculator → Delivery Quote → deliveryAmountToPay

> Реализован серверный расчёт. **Оплата доставки и CDEK OrderCreate — не этот этап.**  
> Подробности: `docs/cdek-calculator.md`.

### 15.1 Chain

```
Zakopeyki Point A (delivery_senders / listing)
→ Zakopeyki Point B (delivery_recipients)
→ Shipment (delivery_shipments)
→ CDEK Calculator Request (CalculatorTariffListRequestDto)
→ POST /v2/calculator/tarifflist
→ CDEK Calculator Response (tariff_codes[])
→ Delivery Quote (delivery_quotes.*)
→ delivery_amount_to_pay (= total_amount, INT KZT, 100% buyer)
```

### 15.2 Endpoint choice

**Primary:** `POST /v2/calculator/tarifflist` — список тарифов для выбора покупателем.  
`/tariff` — revalidation перед оплатой (Phase 7).  
`/tariffAndService`, `/alltariffs` — не live checkout pricing.

### 15.3 Quote fields (snapshot)

| Field | Source |
|---|---|
| product/order ids | delivery_order |
| point_a / point_b / shipment | snapshot_json |
| tariff_code, service_name, cdek_delivery_mode | TariffCodeDto |
| cdek_delivery_sum | delivery_sum → INT |
| packaging/handling/extra | platform additives |
| **delivery_amount_to_pay** | = total_amount |
| currency | KZT |
| valid_until | internal TTL **7200s** (CDEK TTL **GAP**) |
| request_payload_hash | idempotency |
| quote_status | active / superseded / invalidated |

### 15.4 API

`POST /delivery/{id}/quotes/calculate` — buyer only; ignores client amounts.  
`POST /delivery/{id}/quote` — select tariff among active quotes.

### 15.5 Invalidation

Point A/B/shipment/weight/dims/mode change → `invalidateQuotes` → require new calculate.  
Same request_hash + shipping_version → reuse without duplicate chaos.

