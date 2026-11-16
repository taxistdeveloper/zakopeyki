# AI Setup

## Требования

- PHP 8.1+
- MySQL 8+
- Ollama (локально) — опционально для LLM; поиск через tools работает без Ollama
- Redis — опционально

## Установка

1. Применить AI-схему:
   ```bash
   php bin/ai_migrate.php
   ```
2. (Опционально) проиндексировать knowledge для vector RAG:
   ```bash
   ollama pull nomic-embed-text
   ollama pull llava   # для vision
   php bin/ai_index_knowledge.php
   ```
3. Проверить `config/ai.php` (OLLAMA_URL, model, vision_model, embedding_model).
4. Healthcheck:
   ```bash
   php bin/ai_healthcheck.php
   ```
5. Worker (если `process_mode=async`):
   ```bash
   php bin/ai_worker.php
   ```
6. Learning (опционально, cron):
   ```bash
   php bin/ai_learning_worker.php --once
   php bin/ai_maintenance.php
   ```
7. Acceptance smoke:
   ```bash
   php bin/ai_acceptance.php
   ```

Rate limit: `config/ai.php` → `rate_limit.per_minute` / `per_hour`.

## Серверный STT (whisper.cpp)

Распознавание речи по реальному аудио выполняется локально на сервере, аудио никуда не передаётся.
Включение для клиентов: `AI_STT_PROVIDER=server_stt` (по умолчанию `web_speech` — браузерное распознавание).

1. Собрать whisper-cli (whisper.cpp, лицензия MIT; нужен cmake):
   ```bash
   git clone --depth 1 --branch v1.9.5 https://github.com/ggml-org/whisper.cpp.git
   cd whisper.cpp && cmake -B build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF -DWHISPER_BUILD_TESTS=OFF
   cmake --build build -j --config Release --target whisper-cli
   cp build/bin/whisper-cli /usr/local/bin/whisper-cli
   ```
   (`brew install whisper-cpp` на Intel/macOS 26 собирается из исходников и тянет весь llama.cpp — очень долго.)
2. Модели (MIT) в `storage/models/whisper/` (каталог в .gitignore):
   ```bash
   curl -L -o storage/models/whisper/ggml-small-q5_1.bin https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-small-q5_1.bin
   curl -L -o storage/models/whisper/ggml-silero-v5.1.2.bin https://huggingface.co/ggml-org/whisper-vad/resolve/main/ggml-silero-v5.1.2.bin
   ```
   SHA1 `ggml-small-q5_1.bin`: `6fe57ddcfdd1c6b07cdcc73aaf620810ce5fc771`; SHA256 `ggml-silero-v5.1.2.bin`: `29940d98d42b91fbd05ce489f3ecf7c72f0a42f027e4875919a28fb4c04ea2cf`.
3. Проверка: `php tools/run_stt_whisper_tests.php` (часть B — реальное аудио).

| Переменная | По умолчанию | Назначение |
|------------|--------------|------------|
| `AI_STT_PROVIDER` | `web_speech` | `server_stt` — клиент записывает аудио и шлёт на сервер |
| `WHISPER_CLI_PATH` | автопоиск `/usr/local/bin`, `/opt/homebrew/bin`, `/usr/bin` | путь к whisper-cli |
| `WHISPER_MODEL_PATH` | `storage/models/whisper/ggml-small-q5_1.bin` | модель распознавания |
| `WHISPER_VAD_MODEL_PATH` | `storage/models/whisper/ggml-silero-v5.1.2.bin` | Silero VAD; без него whisper выдумывает текст на тишине |
| `WHISPER_THREADS` | 4 | потоки на один запрос |
| `WHISPER_TIMEOUT_SECONDS` | 30 | тайм-аут процесса (минимум 5) |
| `WHISPER_MAX_CONCURRENT` | 2 | одновременных процессов whisper; сверх лимита — 503 `stt_busy` |
| `FFMPEG_PATH` | — | необязательно: перекодирование webm/ogg/mp4 → wav |

Почему такие настройки (замеры 2026-11-06, Intel i7-9750H, 4 потока, синтетическая речь edge-tts, по одному образцу на язык — выборка мала):

| Модель | ru (4,7 с) | kk (4,0 с) | en (5,0 с) |
|--------|-----------|-----------|-----------|
| base (142 МБ) | 1,9 с, ошибки («Найдемняй фон») | 1,3 с, бессмыслица | 1,1 с, верно |
| small-q5_1 (190 МБ) | 2,8 с, верно | 3,4 с, частично (числа и «телефон» распознаны, остальное искажено) | 2,8 с, верно |

- 56 с аудио на small-q5_1 — 10,2 с, отсюда тайм-аут 30 с.
- Автоопределение языка распознало казахскую фразу как немецкую — язык всегда передаётся явно.
- Без VAD тишина распознавалась как «Редактор субтитров …», белый шум — как «(звук пива)»; с Silero VAD — пустой результат (`no_speech_detected`).
- Казахский у small-q5_1 недостаточен для продакшена; лучше модель medium/large-v3-turbo (не проверялись: больше и медленнее).

## TTS (синтез речи)

| Провайдер | Когда используется | Статус |
|-----------|-------------------|--------|
| `azure` | по умолчанию, если задан `AZURE_SPEECH_KEY` | лицензируемый (Azure AI Speech), рекомендуется |
| `browser` | по умолчанию без ключа Azure | голос ОС/браузера пользователя, сервер только нормализует текст |
| `edge_neural` | только явно `AI_TTS_PROVIDER=edge_neural` | **не для продакшена**: правовое основание не установлено (см. ниже) |

Явный `AI_TTS_PROVIDER` всегда важнее автоматического выбора.

### Подключение Azure AI Speech

1. В портале Azure создать ресурс Speech (регион ближе к пользователям, например `westeurope`), взять Key 1.
2. Задать переменные окружения веб-сервера (не в репозитории):
   `AZURE_SPEECH_KEY=<ключ>`, `AZURE_SPEECH_REGION=westeurope`
   (или `AZURE_SPEECH_ENDPOINT=https://<имя>.cognitiveservices.azure.com/cognitiveservices/v1` для ресурса с собственным доменом).
3. Проверка: `AZURE_SPEECH_KEY=… AZURE_SPEECH_REGION=… php tools/run_tts_azure_tests.php` — часть B делает реальный синтез ru/kk/en.

Детали: REST `POST https://{region}.tts.speech.microsoft.com/cognitiveservices/v1`, SSML, формат `audio-24khz-48kbitrate-mono-mp3`, тайм-аут 15 с. Ключ уходит только на домены `*.microsoft.com` / `*.azure.com` / `*.azure.us` по HTTPS, в ответы и логи не попадает. Голоса — `ai.tts.azure_voices` (официальный список Microsoft: ru-RU Dmitry/Svetlana/Dariya, kk-KZ Aigul/Daulet, en-US Jenny/Guy). Для kk-KZ Microsoft указывает: фонемы, пользовательский лексикон и виземы не поддерживаются — исправить произношение через SSML нельзя.
Обязательства по условиям Microsoft: пользователю раскрывается синтетическая природа голоса (подсказка «Голос синтезирован автоматически» на кнопке озвучивания); текст ответа передаётся в Microsoft как обработчику данных — учесть в политике конфиденциальности. Цена: $15 за 1 млн символов (S1), 0,5 млн символов/мес бесплатно (F0); кеш снижает повторные расходы.

### Аудиокеш

- Каталог `storage/cache/tts` (права 0700/0600, `storage/` закрыт в корневом `.htaccess`). Прежний публичный `public/storage/cache/tts` с именами `md5(voice_text).mp3` удалён.
- Секрет HMAC: `AI_TTS_CACHE_SECRET` (≥ 32 символа) или автоматически созданный `storage/keys/tts_cache.key` (0600). На нескольких серверах задайте одинаковый `AI_TTS_CACHE_SECRET`.
- `AI_TTS_CACHE_TTL` — срок хранения файла (по умолчанию 86400 с), `AI_TTS_URL_TTL` — срок жизни ссылки (1800 с).
- Очистка: `php bin/ai_maintenance.php` (удаляет просроченные файлы) + вероятностная очистка при записи.
- Одинаковый синтез переиспользуется между пользователями только как файл; доступ каждый получает своей подписанной ссылкой.

### Голоса (проверено реальным синтезом 2026-11-06, edge-tts 7.3.1)

| Голос | Результат |
|-------|-----------|
| `kk-KZ-AigulNeural` | работает: MP3 37 КБ на фразу ~6,3 с; числа читаются по-казахски («12345» → «он екі мың үш жүз қырық бес» по обратному распознаванию whisper) |
| `kk-KZ-DauletNeural` | работает (36 КБ, ~5,6–6,5 с) |
| `kk-KZ-DariyaNeural` | **не существует** (`NoAudioReceived`) — удалён из кода |
| `ru-RU-DmitryNeural`, `ru-RU-SvetlanaNeural` | работают на тексте без чисел; **на 2026-11-06 ~22:40 отклоняют тексты с многозначными числами** («Сумма: 15 тысяч тенге.», «Номер 12345.» → `NoAudioReceived`), «Сумма: 100.» проходит. Причина на стороне сервиса не известна; провайдер в этом случае переходит на браузерный синтез с `server_tts_failed` |

Нормализация для казахского исправлена: «тг» → «теңге» (раньше русское «тенге»), «№» → «нөмір», разряды «1 299 990 ₸» склеиваются только перед валютой (модель «iPhone 15 256 ГБ» не трогается).

### TTS: коммерческие ограничения Edge TTS (источники проверены 2026-11-06)

| Вопрос | Результат | Источник | Риск |
|--------|-----------|----------|------|
| Лицензия библиотеки edge-tts | LGPL-3.0 (srt_composer.py — MIT). Касается только кода, права на сервис Microsoft не даёт | github.com/rany2/edge-tts (LICENSE), pypi.org/project/edge-tts | низкий |
| Коммерческое использование сервиса | Официального разрешения нет. Условия Microsoft Edge (§2.8) запрещают использовать токены браузера для обращения к сервису Microsoft отдельно от браузера; edge-tts делает именно это (встроенный `TRUSTED_CLIENT_TOKEN`, заголовки Edge) | explore.microsoft.com/en-us/edge/terms | высокий |
| Неофициальный endpoint | `speech.platform.bing.com/.../readaloud` не документирован как публичный API | learn.microsoft.com/en-us/legal/microsoft-edge/privacy | высокий |
| Кеширование / хранение аудио | Официальными источниками не установлено | — | высокий (нет основания) |
| Ограничения доступа, лимиты | Не опубликованы, SLA нет; наблюдаемый отказ русских голосов на текстах с числами | — | высокий (эксплуатационный) |
| Использование в Zakopeyki.kz | Правовое основание не установлено; нужна консультация юриста или письменное подтверждение Microsoft | — | высокий |

Лицензируемая альтернатива с теми же голосами (`kk-KZ-AigulNeural`, `kk-KZ-DauletNeural`, `ru-RU-DmitryNeural`, `en-US-JennyNeural`) — **Azure AI Speech**: $15 за 1 млн символов (S1, eastus, Retail Prices API), бесплатно 0,5 млн символов/мес (F0); требуется раскрывать пользователю синтетическую природу голоса (Code of Conduct for Microsoft AI Services); явного пункта о кешировании аудио в официальных условиях не найдено. Другие варианты с казахским: Yandex SpeechKit (kk-KZ: amira, madi, saule, zhanar; условия на выходной аудиопоток не проверены), Piper (самостоятельный хостинг; голоса kk_KZ issai/iseke/raya — CC-BY-4.0, движок piper1-gpl — GPL-3.0). Google Cloud TTS казахского не поддерживает; MMS-TTS (facebook/mms-tts-kaz) — CC-BY-NC-4.0, коммерчески нельзя.

## Проверка сценариев

Поиск:
`Найди iPhone 15 до 300000 в Алматы`

Объявление:
`Хочу продать iPhone 15, 256 ГБ, батарея 91%, цена 280 тысяч, Алматы`
→ черновик + кнопки Опубликовать / Отмена

Фото (кнопка 📷 в чате, нужен логин + Ollama llava):
загрузить фото + «Хочу продать этот товар»

Цена:
`Какую цену поставить на iPhone 15?`
