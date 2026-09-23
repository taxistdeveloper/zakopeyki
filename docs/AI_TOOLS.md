# AI Tools

Tools вызываются только через `ToolManager` (права + audit в `ai_tool_calls`).

| Tool | Permission | Backend |
|------|------------|---------|
| `search_products` | guest | `Product::searchAdvanced` |
| `get_order_status` | user + ownership | `Order` |
| `get_delivery_status` | user + ownership | `DeliveryOrder::findByP2pOrderId` |
| `get_favorites` | user | `Favorite::forUser` |
| `suggest_price` | guest | `PriceRecommendationService` |
| `analyze_listing_images` | user | `VisionService` (Ollama vision) |
| `create_listing_draft` | user + confirm | `ListingDraftService` → publish via token |
| `get_sales_statistics` | user | orders + products продавца |
| `analyze_demand` | guest | аналоги / медиана / просмотры |
| `generate_sales_recommendations` | user | stats + demand tips |

Критические действия (публикация, возврат, спор) — через `ActionConfirmationService` + `POST /ai/action/confirm`.

Запрещено: прямые UPDATE/INSERT из LLM; выдуманные товары в ответе.

## API

| Method | Path |
|--------|------|
| POST | `/ai/chat` |
| POST | `/ai/chat/stream` (SSE) |
| POST | `/ai/image` |
| POST | `/ai/voice` |
| POST | `/ai/action/confirm` |
| GET | `/admin/ai` |
