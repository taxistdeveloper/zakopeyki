# AI Architecture — zakopeyki.kz

См. также: `ARCHITECTURE_AUDIT.md`, `AI_IMPLEMENTATION_PLAN.md`.

## Pipeline

```
USER → AiAssistantController → ProcessAiMessageJob → AIOrchestrator
  → LanguageDetector → IntentDetector → ContextManager
  → PermissionChecker → ToolManager → Platform Services (MySQL)
  → ResponseBuilder (AiResponse) → ai_messages / audit / usage
```

## Key classes

| Class | Role |
|-------|------|
| `AIOrchestrator` | Центральный pipeline |
| `IntentDetector` | NLU + параметры (цена, город, бренд) |
| `ToolManager` | Безопасный вызов tools + audit |
| `SearchProductsTool` | `Product::searchAdvanced` |
| `GetOrderStatusTool` | Order + ownership |
| `GetDeliveryStatusTool` | DeliveryOrder + ownership |
| `OllamaProvider` | LLMProviderInterface |
| `PromptInjectionGuard` | Фильтр недоверенного ввода |
| `SpeechToTextInterface` | STT контракт распознавания речи |
| `ClientTranscriptSttProvider` | Web Speech API нормализатор (ru, kk, en) |
| `ServerAudioSttProvider` | Серверный STT по реальному аудио на whisper.cpp (локально, без внешних сервисов): WAV → whisper-cli + Silero VAD, явный язык ru/kk/en, тайм-аут, лимит параллельных процессов, confidence = средняя вероятность токенов |
| `AudioValidator` | Валидация magic bytes, форматов, размера и длительности аудио |
| `SttResult` | Единый нормализованный DTO результатов STT |
| `TextToSpeechInterface` | TTS контракт синтеза речи |
| `BrowserTtsProvider` | Web Speech Synthesis адаптер (серверная нормализация текста + клиентское воспроизведение) |
| `MockTtsProvider` | Mock/offline провайдер для тестирования и симуляции синтеза |
| `SpeechTextNormalizer` | Очистка Markdown/HTML, транскрипция валют (₸, KZT, тенге), чисел, пунктуации с сохранением казахских фонем |
| `TtsResult` | Единый нормализованный DTO результатов TTS |
| `TtsFactory` | Фабрика провайдеров синтеза речи по конфигурации (`ai.tts`) |
| `EdgeTtsProvider` | Серверный синтез через edge-tts (неофициальный endpoint Microsoft Edge — коммерческие условия не подтверждены, по умолчанию выключен), белый список голосов, честный fallback на браузер |
| `TtsAudioCache` | Закрытый аудиокеш TTS: HMAC-ключи, подписанные ссылки с привязкой к владельцу и сроком, TTL, flock + атомарная запись |

## Source of truth

Товары, цены, заказы, доставка — **только MySQL / сервисы**. LLM не генерирует карточки товаров.

## Config

`config/ai.php` — `orchestrator_enabled`, models, rate limits, `performance.*`, redis optional.

## Performance

- Context: `max_history_messages`, `max_history_chars`, `max_memory_items`
- Cache: KB search TTL, category index, embedding vectors (`storage/cache/ai`)
- Vision async: `performance.vision_async` / `AI_VISION_ASYNC=1` + worker queue `vision`

## Migrate

```bash
php bin/ai_migrate.php
```
