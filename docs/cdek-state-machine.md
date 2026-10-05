# CDEK State Machine — Zakopeyki.kz

> Design document only. Companion: `docs/cdek-integration-audit.md`, `docs/cdek-data-mapping.md`.  
> CDEK status codes referenced below are those already mapped in code and/or common CDEK codes used by `CdekLogisticsProvider` / `CdekReconciliationService`. OpenAPI `OrderStatusDto` defines `code` as string **without an enum** — full official list is a **GAP** if not in `Интеграция.docx`.

---

## 1. Principles

1. **Two FSMs exist today:** P2P `orders` (goods escrow) and `delivery_orders` (logistics).
2. **CDEK order creation is gated by delivery payment**, not by product escrow.
3. Calculator and PVZ search **never** create CDEK orders.
4. Status transitions must be **idempotent** and **monotonic** where possible (no downgrade from `DELIVERED`).
5. Webhook retries must not corrupt state (`delivery_webhook_events.event_hash` UNIQUE).

---

## 2. Existing delivery FSM (system of record for logistics)

```
DELIVERY_DATA_COLLECTION
        │  seller sender + buyer recipient (+ shipment)
        ▼
DELIVERY_DATA_COMPLETE
        │  request quotes
        ▼
DELIVERY_QUOTE_REQUESTED
        │  CDEK tarifflist OK
        ▼
DELIVERY_QUOTE_RECEIVED
        │  buyer selects quote
        ▼
DELIVERY_ORDER_READY_FOR_PAYMENT
        │  initiate payment (server amount from quote)
        ▼
DELIVERY_PAYMENT_PENDING
        │  acquirer webhook verified (server) — NOT browser redirect
        ▼
DELIVERY_PAID
        │  canCreateCdekOrder() === true
        │  (Phase 8) POST /v2/orders
        ▼
CDEK_ORDER_PENDING ──► DELIVERY_ORDER_CREATED
        │  CDEK ACCEPTED/CREATED/…
        ▼
DELIVERY_ACCEPTED
        │
        ├─► SHIPMENT_RECEIVED  (defined; rarely mapped from CDEK today)
        │
        ▼
IN_TRANSIT
        │  includes READY_FOR_PICKUP collapsed to IN_TRANSIT in current mapper
        ▼
DELIVERED

Side states: CANCELLED, EXCEPTION, CDEK_ORDER_FAILED, REFUND_REQUIRED
```

### Phase 7 barrier

`DELIVERY_PAID` **does not** auto-call CDEK create.  
Registration only after `DeliveryPaymentService::canCreateCdekOrder()` (paid + quote + A/B/shipment).  
See `docs/cdek-payment-flow.md`.

### Status meanings

| Status | Meaning | Who drives |
|---|---|---|
| `DELIVERY_DATA_COLLECTION` | Point A/B incomplete | Seller/Buyer forms |
| `DELIVERY_DATA_COMPLETE` | Enough data to quote | System after saves |
| `DELIVERY_QUOTE_REQUESTED` | Calculator call in flight/done start | System |
| `DELIVERY_QUOTE_RECEIVED` | Quotes stored (= DELIVERY_CALCULATED) | System |
| `DELIVERY_ORDER_READY_FOR_PAYMENT` | Quote selected | Buyer |
| `DELIVERY_PAYMENT_PENDING` | Acquirer session opened | Buyer + gateway |
| `DELIVERY_PAID` | **100% delivery paid confirmed** | Gateway webhook only |
| `CDEK_ORDER_PENDING` | Create accepted / waiting uuid | Phase 8 |
| `DELIVERY_ORDER_CREATED` | CDEK uuid stored | System after POST /orders |
| `DELIVERY_ACCEPTED` | CDEK accepted for processing | Webhook/reconcile |
| `SHIPMENT_RECEIVED` | Parcel at CDEK | Webhook (mapping gap today) |
| `IN_TRANSIT` | Moving / at pickup point | Webhook/reconcile |
| `DELIVERED` | Delivered to recipient | Webhook/reconcile |
| `CANCELLED` | Cancelled before/during logistics | System/ops |
| `EXCEPTION` | Problem / not delivered | Webhook/reconcile |
| `CDEK_ORDER_FAILED` | Create failed after paid | System |
| `REFUND_REQUIRED` | Money held, ops/refund path | System |

---

## 3. Mapping: brief proposed names → existing

| Brief proposal | Adopt? | Existing / action |
|---|---|---|
| DRAFT | Listing-level only | product draft / incomplete `product_listing_shipping` — **not** a delivery_orders status |
| PUBLISHED | Listing-level | `products.status=active` + shipping_ready |
| DELIVERY_SELECTED | Optional alias | checkout `delivery_method=cdek` + delivery bootstrap |
| DELIVERY_DATA_FILLED | Alias | `DELIVERY_DATA_COMPLETE` |
| DELIVERY_CALCULATED | Alias | `DELIVERY_QUOTE_RECEIVED` |
| PAYMENT_PENDING | Alias | `DELIVERY_PAYMENT_PENDING` |
| PAYMENT_PAID | Alias | `DELIVERY_PAID` |
| CDEK_ORDER_PENDING | **Exists** | Between `DELIVERY_PAID` and successful uuid persist |
| CDEK_ORDER_ACCEPTED | Alias | `DELIVERY_ACCEPTED` |
| CDEK_ORDER_CREATED | Alias | `DELIVERY_ORDER_CREATED` |
| CDEK_ORDER_FAILED | **Exists** | Persist error + allow retry/refund |
| DELIVERY_IN_PROGRESS | Alias | `IN_TRANSIT` (+ `SHIPMENT_RECEIVED`) |
| DELIVERED | Same | `DELIVERED` |
| CANCELLED | Same | `CANCELLED` |
| REFUND_REQUIRED | **Exists** | After paid+create-failed or CDEK cancel with money held |

**Decision:** Do not rename existing DB enums casually; use existing states.

---

## 4. P2P order FSM (goods) — parallel track

```
awaiting_payment → escrowed → shipped → delivered → completed
                     │
                     ├─ cancelled / refunded / dispute / return_*
```

| Event | Effect on P2P order today |
|---|---|
| Product paid | `escrowed` + bootstrap delivery |
| Delivery paid | **none** |
| CDEK IN_TRANSIT | **none** |
| CDEK DELIVERED | **none** |
| Seller manual tracking | `shipped` |
| Buyer confirm | `delivered`/`completed` |

**Conflict:** logistics can be `DELIVERED` while P2P remains `escrowed`. Phase decision: either bridge automatically or keep manual escrow confirmation with clear UX.

---

## 5. Zakopeyki delivery status ↔ CDEK status/event ↔ system action

| Zakopeyki status | CDEK status/event (from current mapper) | System action |
|---|---|---|
| `DELIVERY_PAID` | (none yet) | Allow `POST /v2/orders` |
| `DELIVERY_ORDER_CREATED` | Create response entity.uuid / request ACCEPTED | Store `logistics_order_id`; notify parties |
| `DELIVERY_ACCEPTED` | `ACCEPTED`, `CREATED`, `RECEIVED_AT_SHIPMENT_WAREHOUSE`, `READY_FOR_SHIPMENT_IN_SENDER_CITY`, `READY_FOR_SHIPMENT_IN_TRANSIT_CITY`, `PASSED_TO_CARRIER_AT_SENDER_CITY` | Transition; write tracking |
| `SHIPMENT_RECEIVED` | *(intended; not produced by current mapper)* | Future: map warehouse-received codes explicitly |
| `IN_TRANSIT` | `TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY`, `SENT_TO_RECIPIENT_CITY`, `ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE`, `ACCEPTED_AT_TRANSIT_WAREHOUSE`, `TAKEN_BY_COURIER`, `RECEIVED_AT_SENDER_WAREHOUSE`, `RETURNED_TO_SENDER_CITY`, plus pickup codes collapsed (`READY_FOR_PICKUP`, `ACCEPTED_AT_PICK_UP_POINT`, `POSTOMAT_*`) | Tracking update; buyer notify |
| `DELIVERED` | `DELIVERED`, `POSTOMAT_RECEIVED` | Set `delivered_at`; notify; **optional** bridge to P2P |
| `EXCEPTION` | `NOT_DELIVERED`, `INVALID`, `REMOVED_FROM_PICKUP_POINT` | Alert ops; stop happy-path; evaluate refund |
| (store only) | Webhook `ORDER_MODIFIED` | Save event; optional GET order |
| (store + alert) | Webhook `DELIV_PROBLEM` | Save; may force `EXCEPTION` |
| (store) | `DELIV_AGREEMENT`, `COURIER_INFO` | Save; UI optional |
| (store / refresh PVZ) | `OFFICE_AVAILABILITY` | Save; may enqueue points sync |
| (store) | `PRINT_FORM` | Save; unlock label download later |

Webhook type enum (registration): see OpenAPI `WebhookDto.type`.

---

## 6. Happy path (CDEK delivery)

```
Listing CDEK-ready (Point A)
  → Buyer buys product (escrow)     [NO CDEK order]
  → Buyer fills Point B
  → Calculator tarifflist
  → Buyer selects tariff
  → READY_FOR_PAYMENT
  → FreedomPay delivery charge
  → PAYMENT confirmed (server)
  → DELIVERY_PAID
  → POST /v2/orders
  → DELIVERY_ORDER_CREATED
  → ORDER_STATUS webhooks…
  → DELIVERED
```

---

## 7. Pickup-only path

```
Listing fulfillment=pickup
  → Checkout must NOT offer CDEK
  → No delivery_orders CDEK provider flow (or no bootstrap)
  → P2P escrow only / meetup
```

---

## 8. Failure paths

### A. Quote failure

Stay ≤ `DELIVERY_DATA_COMPLETE` / return to forms. No payment. No CDEK order.

### B. Payment failure / abandon

`DELIVERY_PAYMENT_PENDING` → remain or return to `READY_FOR_PAYMENT`. No CDEK order.

### C. Paid but CDEK create fails

```
DELIVERY_PAID
  → create fails (timeout/5xx/validation)
  → retry with lock + im_number lookup
  → if definitive failure: EXCEPTION or REFUND_REQUIRED
  → never create second distinct CDEK order for same payment
```

### D. Duplicate webhook

Insert collision on `event_hash` → ack duplicate, no transition.

### E. Late status after DELIVERED

Ignore downgrades; append tracking if needed.

### F. CDEK cancel / not delivered after paid

`EXCEPTION` / `CANCELLED` + finance decision (`REFUND_REQUIRED`) — **process not fully modeled**.

---

## 9. Idempotency & concurrency rules

| Rule | Implementation target |
|---|---|
| 1 P2P order → 1 delivery_orders row | UNIQUE `order_id` |
| 1 selected paid quote → 1 delivery payment success | UNIQUE idempotency_key / pg_order_id |
| 1 paid delivery → 1 CDEK registration | `logistics_order_id` + lock + `number`/`developer_key` |
| Calculator retries | Safe |
| Create retries | GET/find before POST |
| Webhook retries | event_hash |

---

## 10. Recommended minimal status set for next implementation phase

Keep existing delivery statuses; add only if needed:

1. `CDEK_ORDER_PENDING` — after paid, before uuid confirmed.  
2. `REFUND_REQUIRED` — money captured, logistics cannot proceed.  

Do **not** replace P2P order statuses with delivery statuses.

---

## 11. Open questions affecting the state machine

1. Should CDEK `DELIVERED` auto-move P2P `orders` to `shipped`/`delivered`?  
2. Is `READY_FOR_PICKUP` a first-class UI state (today collapsed to `IN_TRANSIT`)?  
3. Full official CDEK status code list (OpenAPI has no enum).  
4. Refund SLA when create fails after payment.  
5. Whether `order_type` IM vs delivery changes available statuses/documents.

---

## 12. State vs payment vs CDEK API cheat-sheet

| State | Calculator allowed | Delivery pay allowed | POST /orders allowed |
|---|---|---|---|
| DATA_* / QUOTE_* | Yes | No (until ready) | **No** |
| READY_FOR_PAYMENT | Revalidate optional | Yes | **No** |
| PAYMENT_PENDING | No side-effect | Waiting | **No** |
| PAID / ORDER_CREATED (retry) | No | No | **Yes (server)** |
| ACCEPTED+ | No | No | No (already created) |
| DELIVERED / CANCELLED | No | No | No |
