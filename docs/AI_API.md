# AI API

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/ai/chat` | guest/user | JSON: message, guest_token?, context?, language? |
| POST | `/ai/chat/stream` | guest/user | SSE: meta → delta → done \| error |
| GET | `/ai/chat/messages` | owner/guest | conversation_id, after_id?, guest_token? |
| POST | `/ai/chat/feedback` | owner/guest | message_id, rating 1–5, reason?, comment? |
| POST | `/ai/voice` | guest/user | transcript (Web Speech) |
| POST | `/ai/image` | user | multipart image + message; optional async pending |
| POST | `/ai/action/confirm` | user | token → publish_listing_draft и др. |

## Admin

| Method | Path | Permission |
|--------|------|------------|
| GET | `/admin/ai` | ai_chats |
| POST | `/admin/ai/prompt-rollback` | ai_chats |
| POST | `/admin/ai/prompt-approve` | ai_chats |
| POST | `/admin/ai/reindex` | ai_chats |
| POST | `/admin/ai/learning-process` | ai_chats |

## Ошибки

- `422` — валидация  
- `429` — rate limit (`retry_after`)  
- `403` — нет доступа к диалогу  
- `401` — confirm / image требуют login  
