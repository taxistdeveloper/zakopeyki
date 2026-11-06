# AI API

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/ai/chat` | guest/user | JSON: message, guest_token?, context?, language? |
| POST | `/ai/chat/stream` | guest/user | SSE: meta → delta → done \| error |
| GET | `/ai/chat/messages` | owner/guest | conversation_id, after_id?, guest_token? |
| POST | `/ai/chat/feedback` | owner/guest | message_id, rating 1–5, reason?, comment? |
| POST | `/ai/voice` | guest/user | Голосовой ввод / STT: multipart audio (`audio`) или JSON transcript (`transcript`, `confidence`, `language`, `transcribe_only`). Возвращает `SttResult` или сквозной ответ диалога Zak. |
| POST | `/ai/tts` | guest/user | Синтез речи / TTS: JSON `{ text, language?, voice?, conversation_id? }`. Возвращает `TtsResult` (нормализованный текст `clean_text`, метаданные воспроизведения и аудио/браузерный маркер). |
| GET | `/ai/tts/audio/{token}` | владелец токена | Выдача синтезированного MP3 из закрытого кеша по подписанной ссылке (см. ниже). |
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

`multipart/form-data`: `audio` (файл), `language` (`ru`|`kk`|`en`, также `ru-RU` и т.п.), `transcribe_only` (`1` — только текст, без передачи в Zak), `client_request_id` (необязательно, `[A-Za-z0-9_-]{8,80}`), `guest_token`, `context`.

- Веб-клиент всегда шлёт `transcribe_only=1`, а распознанный текст отправляет обычным сообщением чата (`sendAiMessage`) — тот же путь, что для текста и Web Speech: пузырь пользователя, SSE, контекст, NLU, RAG, подтверждения.
- `client_request_id` — защита от повторной отправки одного аудиофрагмента: повтор с тем же id от того же владельца (пользователь / гость / сессия) в течение 10 минут → `409 duplicate_request`. Без id запрос обрабатывается как раньше.

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
| 409 | — (`error: duplicate_request`) | повтор `client_request_id` |
| 400 | — (`error: invalid_request_id`) | некорректный `client_request_id` |

## Синтез речи (TTS) — POST /ai/tts

Служит для нормализации текста ответа Zak под речевой движок (очистка Markdown/HTML, развёртывание валют/чисел, сохранение специфических символов казахского языка) и подготовки параметров синтеза.

### Запрос
```json
{
  "text": "Смартфон стоит **45 000 ₸** [подробнее](/item/12)",
  "language": "ru",
  "voice": null,
  "conversation_id": 42
}
```

### Ответ (`200 OK`)
```json
{
  "ok": true,
  "tts": {
    "clean_text": "Смартфон стоит 45 тысяч тенге подробнее.",
    "original_text": "Смартфон стоит **45 000 ₸** [подробнее](/item/12)",
    "language": "ru",
    "voice": null,
    "provider": "browser",
    "use_browser": true,
    "audio_url": null,
    "audio_base64": null,
    "duration_ms": 2500,
    "request_id": "tts_672...",
    "warnings": []
  }
}
```

При `provider: "edge_neural"` поле `audio_url` — подписанная ссылка `/ai/tts/audio/{token}`:

- аудио хранится в `storage/cache/tts` (закрыт для HTTP), имя файла — HMAC-SHA256 от секрета сервера и всех параметров синтеза (движок, голос, язык, скорость, текст), по тексту не вычисляется;
- токен `<id>.<expires>.<hmac>` привязан к владельцу (пользователь или PHP-сессия гостя) и живёт `AI_TTS_URL_TTL` (30 мин); чужой, просроченный, изменённый или угаданный токен → `404 Not found` без уточнения причины;
- ответ: `audio/mpeg`, `Cache-Control: private, max-age=300`, поддержка `Range`; файл хранится `AI_TTS_CACHE_TTL` (24 ч), очистка — `bin/ai_maintenance.php`;
- если серверный синтез не удался, ответ честно переходит на браузерный синтез: `provider: "browser"`, `use_browser: true`, `audio_url: null`, `warnings: ["server_tts_failed", "fallback_browser"]`;
- голос берётся только из белого списка `ai.tts.voices`; иной голос игнорируется с предупреждением `voice_not_allowed`.

Объект `tts` также автоматически возвращается в стандартных ответах `POST /ai/chat` и `POST /ai/chat/stream` (событие `done`), позволяя клиенту мгновенно озвучивать ответ по клику на кнопку динамика 🔊 без дополнительных сетевых вызовов.

## Ошибки

- `400` — некорректный запрос
- `403` — нет доступа к диалогу или функция отключена feature flag (`tts.enabled`)
- `422` — ошибка валидации входных данных (пустой текст или превышение лимита символов)
- `429` — превышен лимит запросов rate limiter (`retry_after`)
- `401` — confirm / image требуют авторизации (login)
