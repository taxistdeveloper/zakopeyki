# AI API

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/ai/chat` | guest/user | JSON: message, guest_token?, context?, language? |
| POST | `/ai/chat/stream` | guest/user | SSE: meta → delta → done \| error |
| GET | `/ai/chat/messages` | owner/guest | conversation_id, after_id?, guest_token? |
| POST | `/ai/chat/feedback` | owner/guest | message_id, rating 1–5, reason?, comment? |
| POST | `/ai/voice` | guest/user | Голосовой ввод / STT: multipart audio (`audio`) или JSON transcript (`transcript`, `confidence`, `language`, `transcribe_only`). Возвращает `SttResult` или сквозной ответ диалога Zak. |
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

## Распознавание речи (STT) — POST /ai/voice, аудио

`multipart/form-data`: `audio` (файл), `language` (`ru`|`kk`|`en`, также `ru-RU` и т.п.), `transcribe_only` (`1` — только текст, без передачи в Zak), `guest_token`, `context`.

- Формат определяется по magic bytes, заголовку клиента не доверяем. Сервер (whisper.cpp) нативно принимает **WAV**; клиент `app.js` перекодирует запись MediaRecorder в WAV PCM16 16 кГц моно (`aiBlobToWav16k`). webm/ogg/mp4 принимаются, только если настроен `FFMPEG_PATH`.
- Лимиты: ≤ 10 МБ, 0,3–60 с.
- Ответ при `transcribe_only=1`: `{ ok, text, language, confidence, provider: "whisper_cpp", duration_ms, request_id, warnings, stt }`. `confidence` — средняя вероятность текстовых токенов whisper (не калиброванная оценка точности); `null`, если токенов нет.
- Без `transcribe_only` распознанный текст идёт в существующий диалог Zak (ProcessAiMessageJob → AIOrchestrator), ответ как у `/ai/chat`.

| HTTP | `warnings` (код) | Причина |
|------|------------------|---------|
| 400 | `unsupported_format`, `wav_required`, `exceeded_size` | неподдерживаемый формат / нет ffmpeg для webm / слишком большой файл |
| 422 | `no_speech_detected` | VAD не нашёл речь (тишина, шум) |
| 422 | `recording_too_short`, `exceeded_duration`, `corrupted_base64`, `whisper_exit_code_N` | некорректная запись / сбой whisper |
| 503 | `server_stt_not_configured` | whisper-cli или модель не установлены |
| 503 | `stt_busy` | заняты все слоты `WHISPER_MAX_CONCURRENT` |
| 503 | `stt_timeout` | распознавание дольше `WHISPER_TIMEOUT_SECONDS` |
| 403 | — (`error: voice_disabled`) | `AI_VOICE_ENABLED=0` или `AI_STT_ENABLED=0` |

## Ошибки

- `422` — валидация  
- `429` — rate limit (`retry_after`)  
- `403` — нет доступа к диалогу  
- `401` — confirm / image требуют login  
