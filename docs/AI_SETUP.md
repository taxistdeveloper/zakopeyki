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
