# CDEK Integration Audit — Zakopeyki.kz

> **Stage:** technical audit & design only. **No implementation in this stage.**  
> **Sources:** current codebase + `openapi_api_v2_integration.json`.  
> **Missing source:** `Интеграция.docx` was **not found** in the workspace or common user folders → see §26 / §27.  
> **Date of audit:** 2026-10-05.

**Business non-negotiable:** CDEK order MUST NOT be created until 100% of delivery cost payment is confirmed server-side. Buyer = delivery customer. Seller = sender. Zakopeyki = P2P platform operator / technical intermediary. CDEK = logistics provider.

---

## 1. Current Zakopeyki architecture

Custom PHP MVC (not Laravel/Symfony):

| Layer | Location |
|---|---|
| Front controller | `index.php` |
| Routes | `config/routes.php` → `App\Core\Router` |
| Controllers | `app/Controllers/*` |
| Models | `app/Models/*` (PDO via `App\Core\Model`) |
| Services | `app/Services/*` |
| Views | `app/Views/*` |
| Config | `config/*.php` (+ `.example` templates) |
| Schema | `database/*.sql` + runtime `ensureTable()` / `ensureColumns()` on models |
| Money | integer KZT (no decimals) |

Roles on `users.role`: `user | manager | admin`. **Seller/buyer are per-deal roles**, not global account roles (`orders.buyer_id` / `orders.seller_id`, `products.user_id`).

Two money flows already exist:

1. **P2P product escrow** — `orders` + `payments` + `wallets` (FreedomPay / wallet).
2. **Delivery service** — `delivery_orders` + `delivery_payments` (separate FreedomPay `zk-del-*`), bootstrapped after product payment.

CDEK is already partially implemented under `app/Services/Cdek/*` + `app/Services/Delivery/*`.

---

## 2. Existing delivery functionality

### What exists

- Listing ship-from + package params: `product_listing_shipping`, `ListingShippingService`, UI `lot-shipping-block.php`.
- Fulfillment modes: `delivery | pickup | both`.
- Delivery FSM after P2P escrow: `DeliveryService` + `delivery_orders`.
- Provider adapter: `LogisticsProviderInterface` → `CdekLogisticsProvider` / `StubLogisticsProvider`.
- Quotes via **`POST /v2/calculator/tarifflist`** (not `/tariff`, not `/tariffAndService`).
- City resolve: `GET /v2/location/cities`.
- PVZ cache: `cdek_delivery_points` + `bin/cdek_sync_delivery_points.php` + `GET /delivery/cdek/points`.
- Order create: `POST /v2/orders` via `CdekOrderService` **only from `onDeliveryPaid`**.
- Webhook ingest: `POST /webhooks/delivery/status` (ORDER_STATUS).
- Reconciliation cron: `bin/cdek_reconcile_orders.php`.
- Unit tests under `tests/Unit/Services/Cdek/` and Delivery.

### Gaps vs required business scenario

| Requirement | Current state |
|---|---|
| Seller must complete CDEK point-A before publishing CDEK listing | Listing collects ship-from + dims if `fulfillment_mode` is delivery/both, but does **not** bind to CDEK specifically; no CDEK city_code / shipment_point validation at publish |
| Pickup-only = no CDEK | Listing supports `pickup`, but **checkout radios ignore `fulfillment_mode`** |
| Buyer fills point-B then calculator | Exists on `/delivery/{id}` **after** product payment — not at checkout |
| Show CDEK price only | Mostly yes; platform may **add** packaging price, irregular handling (+500), fragile (+300) on top of CDEK `delivery_sum` |
| No CDEK order before paid delivery | **Already enforced** (`onDeliveryPaid` → `createLogisticsOrder`) |
| Buyer = delivery customer in CDEK docs/AVR | Local AVR marks buyer as `customer`; CDEK API account is still **platform credentials**; `order_type` default **1 = IM** |

---

## 3. Existing payment flow

### Product payment

1. Checkout → `Order::createEscrow` / FreedomPay init (`zk-{buyer}-{hex}` or `zk-cart-...`).
2. Authoritative confirmation: **`POST /payments/freedompay/result`** (server-to-server, signature verified). Browser success/failure URLs are **not** source of truth.
3. On paid escrow: `orders.status = escrowed`, then `DeliveryService::bootstrapForPaidOrder($orderId)` (non-digital, non-direct).

### Delivery payment (separate)

1. Buyer selects quote → `DELIVERY_ORDER_READY_FOR_PAYMENT`.
2. `POST /delivery/{id}/pay` → FreedomPay with `pg_order_id = zk-del-...`.
3. Same result URL branches via `DeliveryPayment::isDeliveryPgOrderId()`.
4. `DeliveryPayment::completeFromGateway` (idempotent) → `DeliveryService::onDeliveryPaid` → **then** CDEK `POST /orders`.

Wallet is **not** used for delivery fee today (card / simulated card only).

---

## 4. Existing order flow

### P2P `orders.status` (product deal)

`awaiting_payment` → `escrowed` → `shipped` → `delivered` → `completed`  
(+ return/dispute/refund branches).

Manual seller tracking via `EscrowService::addTracking`. Buyer confirms delivery. Auto-complete after inspect window (`scripts/cron_escrow.php`).

### Delivery `delivery_orders.status` (logistics)

```
DELIVERY_DATA_COLLECTION
→ DELIVERY_DATA_COMPLETE
→ DELIVERY_QUOTE_REQUESTED
→ DELIVERY_QUOTE_RECEIVED
→ DELIVERY_ORDER_READY_FOR_PAYMENT
→ DELIVERY_PAYMENT_PENDING
→ DELIVERY_PAID
→ DELIVERY_ORDER_CREATED
→ DELIVERY_ACCEPTED
→ SHIPMENT_RECEIVED
→ IN_TRANSIT
→ DELIVERED
(+ CANCELLED, EXCEPTION)
```

**Critical:** CDEK webhooks update **only** `delivery_orders`. They do **not** advance P2P `orders` to `shipped`/`delivered`. Two FSMs are uncoupled.

---

## 5. CDEK API architecture

Source: `openapi_api_v2_integration.json` (bundled multi-schema dump: auth, location, orders/calculator, webhooks).

### Servers (from OpenAPI)

| Env | URL |
|---|---|
| Production | `https://api.cdek.ru` |
| Test (edu) | `https://api.edu.cdek.ru` |

Code currently uses base path with `/v2` suffix (`config/cdek.php.example`: `https://api.edu.cdek.ru/v2`).

### Endpoints relevant to Zakopeyki

| Endpoint | Method | Purpose | Used in code today |
|---|---|---|---|
| `/v2/oauth/token` | POST | OAuth2 client credentials | Yes |
| `/v2/calculator/tarifflist` | POST | List available tariffs + prices | Yes (primary quote) |
| `/v2/calculator/tariff` | POST | Price for one `tariff_code` | No |
| `/v2/calculator/tariffAndService` | POST | Tariffs + services | No |
| `/v2/calculator/alltariffs` | GET | Catalog of available tariffs/modes | No |
| `/v2/orders` | POST/GET/PATCH | Create / find / update order | POST+GET yes; PATCH no |
| `/v2/orders/{uuid}` | GET/DELETE | Get / delete order | GET yes; DELETE no |
| `/v2/deliverypoints` | GET | PVZ/postamat directory | Yes (sync CLI) |
| `/v2/location/cities` | GET | City codes | Yes |
| `/v2/location/*` other | GET | regions, suggest, postalcodes, coordinates | No |
| `/v2/webhooks` | GET/POST | Register webhooks | No (inbound only) |
| `/v2/webhooks/{uuid}` | GET/DELETE | Manage webhook | No |
| `/v2/print/orders` | POST | Waybill print | No |
| `/v2/print/barcodes` | POST | Barcode print | No |
| `/v2/intakes` | POST | Courier intake | No |

### OAuth (OpenAPI auth `RequestDto`)

Required query/object fields:

- `grant_type` (string)
- `client_id` (string)
- `client_secret` (string)

Response `AuthResponseDto` required: `access_token`, `token_type`, `expires_in`, `scope`, `jti`.

**GAP:** OpenAPI does not document default `expires_in` value or refresh-token flow. Code treats it as short-lived bearer with cache TTL = `expires_in - 60s`. No refresh_token field in schema → re-authorize with client credentials.

### Calculator choice (design)

| Endpoint | When to use |
|---|---|
| **`/calculator/tarifflist`** | Buyer chooses among modes (door/PVZ); show list of tariffs + `delivery_sum` + periods. **Current + recommended default.** |
| `/calculator/tariff` | Re-validate exact selected `tariff_code` before payment / before create (recommended hardening). |
| `/calculator/tariffAndService` | If extra services must be priced with tariff selection. |
| `/calculator/alltariffs` | Bootstrap/admin catalog of modes; not for live checkout pricing. |

### OrderCreateRequestDto — required (OpenAPI)

Required: `packages`, `recipient`, `tariff_code`.

Optional but operationally critical: `type`, `number`, `sender`, `from_location` / `shipment_point`, `to_location` / `delivery_point`, `seller`, `services`, `developer_key`, `packages[].items`, etc.

**XOR locations (from code + DTO shape, not an OpenAPI `oneOf`):** either point code or location object for origin/destination.

### Webhook types (`WebhookDto.type` enum)

`ORDER_STATUS`, `ORDER_MODIFIED`, `PRINT_FORM`, `RECEIPT`, `DOWNLOAD_PHOTO`, `PREALERT_CLOSED`, `ACCOMPANYING_WAYBILL`, `OFFICE_AVAILABILITY`, `DELIV_PROBLEM`, `DELIV_AGREEMENT`, `COURIER_INFO`.

**GAP:** OpenAPI defines webhook **registration** DTO only. **Inbound event payload schema is not present** in the provided OpenAPI file.

---

## 6. Business roles

| Role | Zakopeyki meaning | CDEK payload mapping (intended) |
|---|---|---|
| **Seller** | Listing owner / goods owner | `sender` (SenderContactDto) + ship-from location / shipment_point |
| **Buyer** | Goods buyer + **delivery customer** (pays delivery) | `recipient` (RecipientContactDto) + to_location / delivery_point; local `customer_id` |
| **Zakopeyki** | P2P operator, technical intermediary, API caller | Must **not** be modeled as goods seller; holds OAuth credentials; may appear only as technical account |
| **CDEK** | Logistics provider | Executes delivery |

### Separation checklist

| Concept | Must not be mixed with |
|---|---|
| Product payer | Delivery payer (both are buyer today, but **different payments**) |
| Delivery payer | Platform |
| Sender | Recipient |
| Platform operator | CDEK `seller` / IM shop unless CDEK confirms IM model |
| Escrow product money | Delivery fee (`delivery_payments`) |

---

## 7. End-to-end business flow

### Target rigid scenario

```
1. Seller creates listing
2. If CDEK delivery offered → mandatory Point A (sender) + package params before publish
3. If pickup-only → no CDEK requirements
4. Buyer purchases product (escrow) — CDEK order NOT created
5. If buyer chooses pickup → stop (no CDEK)
6. If buyer chooses CDEK → fill Point B (door / PVZ / mode from CDEK API)
7. Zakopeyki → POST /v2/calculator/tarifflist (and/or /tariff)
8. Show CDEK price to buyer
9. Buyer pays 100% delivery via FreedomPay
10. Server webhook confirms payment
11. Only then → POST /v2/orders
12. Persist uuid / cdek_number; process async statuses (webhook + reconcile)
```

### Current flow (as coded)

Product pay → bootstrap delivery → seller/buyer forms on `/delivery/{id}` → quote → delivery pay → CDEK create → webhook/reconcile.

Deviation: CDEK selection is a checkout radio (`delivery_method=cdek`) rather than a hard listing-bound CDEK mode; pickup listing can still checkout with carriers.

---

## 8. Data model mapping

See companion file: [`docs/cdek-data-mapping.md`](cdek-data-mapping.md).

High-level:

| Zakopeyki source | CDEK target |
|---|---|
| `delivery_senders.*` | `sender` + `from_location` / `shipment_point` |
| `delivery_recipients.*` | `recipient` + `to_location` / `delivery_point` |
| `delivery_shipments.*` | `packages[]` (+ dims/weight) |
| product title / declared cost | `packages[].items[]` (`name`, `cost`, `payment.value=0`) |
| selected quote `service_code` (`cdek_{tariff}`) | `tariff_code` |
| `delivery_orders.order_number` | `number` (im_number lookup) |
| `developer_key` = `zk-del-{id}` | `developer_key` + header |
| local AVR `seller` | **not sent** to CDEK today; OpenAPI has optional `seller` (SellerDto) |
| buyer as customer | local only; not a dedicated CDEK “customer” object |

---

## 9. API endpoint mapping

| Zakopeyki action | CDEK endpoint | Timing |
|---|---|---|
| OAuth | `POST /v2/oauth/token` | Before any API call (cached) |
| Resolve city code | `GET /v2/location/cities` | Quote / order build |
| Sync PVZ | `GET /v2/deliverypoints` | Cron + optional on-demand |
| Buyer selects PVZ (UI) | Local `cdek_delivery_points` (sourced from API) | Buyer form |
| Get quotes | `POST /v2/calculator/tarifflist` | After Point A+B known |
| Revalidate quote | Recommended: `POST /v2/calculator/tariff` | Before pay / before create |
| Create order | `POST /v2/orders` | **After delivery payment confirmed** |
| Fetch order | `GET /v2/orders/{uuid}` or `GET /v2/orders?im_number=` | Retry / reconcile |
| Register webhooks | `POST /v2/webhooks` | Ops setup (not coded) |
| Inbound events | Zakopeyki `POST /webhooks/delivery/status` | Async |
| Print forms | `POST /v2/print/orders` etc. | Later phase (GAP if required for AVR) |

---

## 10. State machine

See companion file: [`docs/cdek-state-machine.md`](cdek-state-machine.md).

Proposed alignment: keep existing `delivery_orders` statuses as system of record for logistics; map CDEK `OrderStatusDto.code` into them; optionally bridge to P2P `orders` in a later phase (product decision).

Suggested proposed names from the brief vs existing:

| Brief name | Existing closest |
|---|---|
| DRAFT | listing draft / no delivery row |
| PUBLISHED | product `active` + listing shipping ready |
| DELIVERY_SELECTED | checkout `delivery_method=cdek` / delivery bootstrap |
| DELIVERY_DATA_FILLED | `DELIVERY_DATA_COMPLETE` |
| DELIVERY_CALCULATED | `DELIVERY_QUOTE_RECEIVED` |
| PAYMENT_PENDING | `DELIVERY_PAYMENT_PENDING` |
| PAYMENT_PAID | `DELIVERY_PAID` |
| CDEK_ORDER_PENDING | between paid and create ack |
| CDEK_ORDER_ACCEPTED / CREATED | `DELIVERY_ORDER_CREATED` / `DELIVERY_ACCEPTED` |
| CDEK_ORDER_FAILED | `EXCEPTION` (+ reason) |
| DELIVERY_IN_PROGRESS | `IN_TRANSIT` (+ `SHIPMENT_RECEIVED`) |
| DELIVERED | `DELIVERED` |
| CANCELLED | `CANCELLED` |
| REFUND_REQUIRED | **not modeled** as delivery status today |

---

## 11. Payment → CDEK order dependency

### Hard gate (already present)

```
FreedomPay result (pg_result=1)
  → DeliveryPayment::completeFromGateway (idempotent)
    → DeliveryService::onDeliveryPaid
      → status=DELIVERY_PAID
      → createLogisticsOrder
        → refuses unless DELIVERY_PAID | DELIVERY_ORDER_CREATED
        → skips if logistics_order_id set
        → CdekOrderService::create → POST /v2/orders
```

### Forbidden patterns (must remain forbidden)

- Creating CDEK order from frontend events
- Treating payment form open / redirect as paid
- Creating CDEK order at quote time
- Creating CDEK order at product escrow time

### Recommended hardening (design only)

1. Explicit internal status `CDEK_ORDER_PENDING` between paid and accepted uuid.
2. Always re-check payment row `status=paid` inside create transaction.
3. Use `POST /calculator/tariff` to ensure paid amount still matches CDEK before create (policy decision if drift → refund path).
4. Keep create callable only from payment completion / safe server retry job — not public controller.

---

## 12. Webhook architecture

### Registration (OpenAPI)

`POST /v2/webhooks` body: `{ type, url }` → returns `uuid`.

### Recommended subscriptions for Zakopeyki

| Type | Needed? | Action |
|---|---|---|
| **ORDER_STATUS** | **Yes** | Drive delivery FSM + tracking |
| **DELIV_PROBLEM** | Yes | Store event; set EXCEPTION / ops alert |
| **ORDER_MODIFIED** | Yes (store) | Audit; optional reconcile fetch |
| **PRINT_FORM** | Optional | Persist print readiness for seller labels |
| **DELIV_AGREEMENT** | Optional | Store; may affect delivery schedule UI |
| **COURIER_INFO** | Optional | Store for buyer UI |
| **OFFICE_AVAILABILITY** | Optional | Trigger PVZ cache refresh |
| Others | Phase 2+ | Store raw, ignore until needed |

### Inbound endpoint requirements

Existing: `POST /webhooks/delivery/status`.

Must:

1. Auth via shared secret (`CDEK_WEBHOOK_TOKEN`) — **never open in production**.
2. Fast 2xx after durable insert.
3. Idempotent on `event_hash` (already UNIQUE).
4. Validate minimal payload fields (type/uuid/attributes) — inbound schema is **GAP**.
5. Heavy work async if queue available; today processing is sync but light (status+tracking). AI queue exists but is unrelated — either reuse pattern or keep sync + reconcile cron.

**GAP:** OpenAPI does not specify inbound webhook body fields. Current code expects CDEK-like `type` + `attributes` (number/uuid/cdek_number/status). Confirm with CDEK docs/docx.

---

## 13. P2P responsibility model

| Party | Responsible for | Not responsible for |
|---|---|---|
| Buyer | Paying delivery; providing recipient/Point B; being CDEK delivery customer (business intent) | Hosting CDEK API contract (technical account is platform’s — see GAP) |
| Seller | Providing sender/Point A; packing goods; handing over to CDEK | Paying delivery |
| Zakopeyki | UX, data transfer, payment collection for delivery fee, API calls, idempotency, status UX | Being party to goods sale delivery contract; inventing tariffs |
| CDEK | Logistics execution, tariffs, statuses, documents per CDEK rules | Marketplace escrow |

### Documents / AVR / finances

Local table `delivery_documents` with `document_type` default `avr_data` stores platform AVR JSON (`DeliveryService::avrPayload`).

OpenAPI has optional order fields that may relate to commercial docs:

- `seller` (SellerDto: name, inn, phone, ownership_form, address)
- `shipper_name`, `shipper_address`
- `date_invoice`
- print endpoints for waybill/barcode
- `CheckInfoDto` / `PaymentInfoDto` (cash/card) — COD/receipt oriented

**GAP (legal):** OpenAPI does **not** define how АВР is formed for a marketplace where the API account belongs to the platform but the delivery customer is the buyer. This **must be confirmed with CDEK** (§27). Do not assume `order_type=1` (IM) makes Zakopeyki the shop of record without confirmation.

---

## 14. Idempotency strategy

| Layer | Mechanism (existing / proposed) |
|---|---|
| One delivery per P2P order | UNIQUE `delivery_orders.order_id` |
| One payment intent | UNIQUE `delivery_payments.idempotency_key`, UNIQUE `pg_order_id` |
| Paid once | `completeFromGateway` short-circuit if already paid |
| One CDEK registration | Skip if `logistics_order_id` set; Redis/file lock; `developer_key`; lookup by `im_number` (`number`); recover on similar-order error |
| Quote ≠ order | Calculator never creates orders |
| Webhook replay | UNIQUE `event_hash` |
| Timeout after POST | Do not blind-retry POST; GET by uuid / im_number first |
| Status monotonicity | Ranked transitions; no rollback from DELIVERED |

Proposed additions:

- Persist `cdek_request_uuid` from create response `requests[].request_uuid`.
- Dedicated unique constraint on `developer_key` / `order_number` already used as correlation.
- Outbox table for “paid → create order” jobs if sync create fails.

---

## 15. Security model

| Secret | Storage | Frontend? |
|---|---|---|
| `CDEK_ACCOUNT` / `client_id` | env + `config/cdek.php` (gitignored) | **Never** |
| `CDEK_SECURE_PASSWORD` / `client_secret` | env only | **Never** |
| OAuth `access_token` | Redis/file server cache | **Never** |
| `CDEK_WEBHOOK_TOKEN` | env | **Never** (only server compare) |
| FreedomPay secrets | `config/freedompay.php` | **Never** |
| Buyer/seller PII | DB; ACL by buyer/seller/disputes | Only to authorized party |

ACL today:

- Seller edits sender only.
- Buyer edits recipient / pays / selects quote only.
- Delivery show: buyer or seller or disputes staff.

Risks to fix in later phases:

1. `test_mode` with empty webhook token = open endpoint.
2. Example file ships **demo edu credentials** — OK for example, must not be production defaults committed as live secrets.
3. Ensure `/delivery/cdek/points` and delivery APIs never leak other users’ recipient PII (currently login-gated search of PVZ is OK; delivery show is party-gated).
4. Do not expose full CDEK raw payloads with PII in client JS.

---

## 16. Test environment

| Setting | Value |
|---|---|
| Base URL | `https://api.edu.cdek.ru` (+ `/v2` as used by client) |
| Config | `CDEK_TEST_MODE=1`, `CDEK_API_URL=...edu...` |
| Credentials | Edu account from CDEK (env); example keys in `cdek.php.example` are edu placeholders |
| Webhook | Use token even in test if possible; never rely on open mode in shared staging |

---

## 17. Production environment

| Setting | Value |
|---|---|
| Base URL | `https://api.cdek.ru` (+ `/v2`) |
| Config | `CDEK_TEST_MODE=0`, production account/secret via env |
| Credentials | From CDEK integrator LK — **not hardcoded**, **not in git** |
| Webhook token | Mandatory non-empty |
| Switch | env-only; no code change to flip env |

---

## 18. Required database changes

Likely (design; not applied now):

1. **Listing ↔ CDEK readiness:** flag `cdek_ready` / validated `from_city_code` / optional `shipment_point` on `product_listing_shipping`.
2. **Sender PVZ support:** columns for `shipment_point` / `use_pvz_as_shipment_point` on `delivery_senders` (code already partially expects them).
3. **CDEK correlation:** `cdek_uuid`, `cdek_number`, `cdek_request_uuid`, `last_cdek_status_code` on `delivery_orders` (some may already live in tracking).
4. **Failed create / refund:** `cdek_create_attempts`, `cdek_last_error`, status path for `REFUND_REQUIRED`.
5. **Webhook types beyond ORDER_STATUS:** ensure `delivery_webhook_events` stores type + raw payload (largely exists).
6. **Outbound webhook registry:** table for registered CDEK webhook uuids (optional).
7. **Do not duplicate** existing quote/payment uniqueness — extend carefully.

---

## 19. Required backend changes

1. Bind checkout to listing `fulfillment_mode` (pickup vs CDEK).
2. Force `logistics_providers.code=cdek` when CDEK path selected (not stub).
3. Listing validation: CDEK Point A completeness before publish when CDEK offered.
4. Confirm/change `order_type` after CDEK legal confirmation (1 vs 2).
5. Optionally populate OpenAPI `seller` / legal fields if required for docs.
6. Register webhooks via API; handle DELIV_PROBLEM etc.
7. Optional `/calculator/tariff` revalidation before pay/create.
8. Harden create-order as outbox/worker; never from public route.
9. Bridge or explicitly document separation of P2P vs delivery FSM.
10. Remove/lock open webhook in non-local envs.
11. Align packaging catalog provider to CDEK when CDEK selected.

---

## 20. Required frontend changes

1. Listing form: explicit CDEK vs pickup (no mix); block publish without Point A when CDEK.
2. Checkout: honor listing fulfillment; hide CDEK if pickup-only; default correctly.
3. Delivery UI: Point B (address / PVZ map-list from synced points); show **CDEK** price breakdown; show platform surcharges separately if any remain.
4. Payment UX: clear that logistics order appears only after paid confirmation.
5. Tracking UI from `delivery_tracking` / CDEK statuses.
6. Seller labels/waybills — later phase if print APIs used.

---

## 21. Required background jobs/queues

| Job | Purpose | Exists? |
|---|---|---|
| `bin/cdek_sync_delivery_points.php` | Refresh PVZ | Yes |
| `bin/cdek_reconcile_orders.php` | Poll open orders | Yes |
| `bin/cdek_healthcheck.php` | Auth/calc smoke | Yes |
| Paid→create outbox worker | Retry create after payment if CDEK down | **No** |
| Webhook registration/maintenance | Ensure subscriptions | **No** |
| Escrow cron | Product inspect deadlines | Yes (unrelated) |

No general marketplace queue; AI queue is separate. Prefer dedicated PHP CLI + cron for CDEK outbox unless a shared queue is introduced.

---

## 22. Required webhook endpoints

| Endpoint | Direction | Status |
|---|---|---|
| `POST /webhooks/delivery/status` | CDEK → Zakopeyki | Exists |
| CDEK `POST /v2/webhooks` | Zakopeyki → CDEK register | Missing |
| FreedomPay `POST /payments/freedompay/result` | Acquirer → Zakopeyki | Exists (delivery branch) |

---

## 23. Required monitoring/logging

Existing: `delivery_api_logs`, `delivery_events`, `delivery_webhook_events`.

Add/ensure:

- Metrics: quote errors, create failures, payment→create latency, webhook duplicates, reconcile drift.
- Alerts: create failed after paid; webhook auth failures; token cache failures.
- Never log `client_secret`, access tokens, full card data.
- Correlate: `delivery_order_id`, `order_number`, `cdek_uuid`, `pg_order_id`.

---

## 24. Error handling

Map via `CdekErrorMapper` (exists). Categories:

| Class | Platform behavior |
|---|---|
| Auth failures | Refresh token; alert |
| Validation (origin/dest/package) | Return to user form; no pay |
| Calculator errors | No quote; no pay |
| Duplicate order | Recover existing uuid |
| Create timeout | Reconcile by im_number; do not double POST |
| Webhook unknown order | Store event; alert |
| Payment amount mismatch | Reject pay / refund path |

---

## 25. Retry strategy

| Operation | Retry policy |
|---|---|
| OAuth | On 401 once per request (exists) |
| Calculator | Safe to retry (no side effects) |
| Create order | Lock + im_number/uuid recovery; exponential backoff via cron/outbox |
| Webhook processing | Idempotent; safe re-delivery |
| FreedomPay result | Idempotent complete |
| PVZ sync | Periodic full/partial sync |

---

## 26. Unknowns / GAPs

1. **`Интеграция.docx` missing** — cannot verify narrative business rules beyond OpenAPI + code.
2. **Inbound webhook payload schema** not in OpenAPI.
3. **`OrderCreateRequestDto.type` enum values** not described in OpenAPI (code comment: 1=IM, 2=delivery).
4. **Legal АВР party** when API account is platform but customer is buyer — not defined in OpenAPI.
5. Whether OpenAPI optional `seller` must be buyer, seller, or platform for KZ docs.
6. Whether passport fields are required for KZ domestic shipments (schemas optional).
7. Exact meaning of `delivery_recipient_cost` when delivery is prepaid on platform (`payment.value=0` already used).
8. Platform surcharges (+handling/fragile) vs pure CDEK amount — product/compliance decision.
9. Whether intakes (`/v2/intakes`) are required for door pickup from seller.
10. Print forms needed for seller handoff or not.
11. Cart multi-seller CDEK (one method for many sellers) complexity.
12. Direct deals currently skip delivery bootstrap.

---

## 27. Questions requiring CDEK confirmation

1. For P2P marketplace: should `type` be **1 (IM)** or **2 (delivery)** when Zakopeyki is only a technical intermediary and **buyer pays** delivery?
2. Who appears on CDEK financial documents / АВР: buyer, seller, or contract holder (platform)?
3. Must `seller` (SellerDto) be filled, and with whose INN/bin?
4. Confirm inbound webhook payload examples for `ORDER_STATUS`, `DELIV_PROBLEM`, etc.
5. Recommended auth for webhooks (shared secret vs IP allowlist vs signature) — OpenAPI only shows registration URL.
6. Is `developer_key` sufficient for deduplication alongside `number`?
7. KZ-specific constraints (currency code `2` KZT already used — confirm).
8. Are intakes mandatory for “courier from sender address”?
9. Production credential issuance process and webhook URL allowlisting.

---

## 28. Implementation plan by phases

### Phase 0 — Prerequisites (this stage output)

- Audit docs (this file + mapping + state machine).
- Obtain `Интеграция.docx` + CDEK answers to §27.
- Decide `order_type` and document party model.

### Phase 1 — Align listing & checkout to P2P CDEK rules

- Enforce Point A at publish for CDEK listings.
- Separate pickup vs CDEK completely.
- Wire checkout to listing fulfillment; select CDEK provider.

### Phase 2 — Quote correctness

- Keep `/tarifflist`; add `/tariff` revalidation.
- Transparent price UI (CDEK sum vs platform extras or remove extras).
- City codes / PVZ validation hardening.

### Phase 3 — Payment → create hardening

- Outbox for create-after-pay.
- Stronger correlation fields; monitoring.
- Confirm no public create path.

### Phase 4 — Webhooks & statuses

- Register ORDER_STATUS (+ DELIV_PROBLEM).
- Expand handlers; confirm inbound schema.
- Optional bridge to P2P escrow statuses.

### Phase 5 — Documents & ops

- Print forms / labels if required.
- Intakes if required.
- Seller/buyer document UX.
- Production cutover checklist.

### Phase 6 — Hardening

- Load tests, chaos retries, security review, runbooks.

---

## Appendix A — Reusable components (do not reinvent)

- `App\Services\Cdek\*` (auth, client, calculator, order, webhook, points, reconcile, money)
- `App\Services\Delivery\DeliveryService` + providers + `ShippingQuoteGuard`
- `ListingShippingService` / `ProductListingShipping`
- `DeliveryPayment` + FreedomPay branching
- Migrations for delivery / points / webhooks / listing shipping
- CLI binaries `bin/cdek_*.php`
- Unit tests in `tests/Unit/Services/Cdek`

## Appendix B — Potential architecture conflicts

1. `order_type=1` (IM) vs P2P “platform is not the shop”.
2. Checkout `delivery_method` independent of listing `fulfillment_mode`.
3. Dual FSM: delivery DELIVERED ≠ order delivered.
4. Stub provider still default in several paths.
5. Platform fee add-ons on CDEK calculator result.
6. `sender_id` column reused for user id then delivery_senders id.
7. Cart shared delivery_method across sellers.

## Appendix C — Files likely to change in later phases

See final summary in chat response (full list). Primary clusters: `app/Services/Cdek/*`, `app/Services/Delivery/*`, `app/Services/Listing/*`, `DeliveryController`, checkout/profile views, `config/cdek.php.example`, delivery migrations, webhook/routes, tests.
