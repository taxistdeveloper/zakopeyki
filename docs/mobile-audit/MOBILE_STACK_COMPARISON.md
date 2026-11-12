# MOBILE_STACK_COMPARISON — сравнение технологий и рекомендация

> MASTERING-00 · 2026-11-09. **Оценки — экспертные суждения, а не измерения.** Бенчмарки производительности на устройствах на этом этапе не проводились. Версии фреймворков меняются быстро — перед стартом сверить с официальными release notes (React Native, Expo, Flutter).
> Публичные данные на ноябрь 2026 (вторичные источники, требуют проверки): в React Native New Architecture стала единственной (с 0.82, окт. 2025); Expo — рекомендуемый официальной документацией способ старта (SDK 55–57 в 2026); Flutter — ветка 3.4x. Google Play с 31.08.2026 требует targetSdk 36 (Android 16) для новых приложений и обновлений.

## 1. Исходные требования, установленные аудитом

| Требование | Откуда | Влияние на выбор |
|---|---|---|
| Backend — PHP-монолит без внутреннего API; мобильному нужен новый JSON-слой | API_INVENTORY | Стек клиента не влияет на backend; важна генерация клиента из OpenAPI |
| Камера, мультифото, видео до 50 MB (Stories), фоновая загрузка | `StoryController.php:14-17`, `ProfileController.php:32-35` | Нужны зрелые плагины камеры/видео/upload |
| Лента коротких видео (Stories, будущие Shorts) | FEATURE_AUDIT §7 | Производительность прокрутки видео |
| Live Commerce → потребуется SFU (LiveKit / Cloudflare Realtime и т.п.) | `StreamController`, `app.js:1957` (нет TURN) | Наличие официального SDK SFU |
| Платежи Halyk — JS-виджет `halyk.pay()` | `Views/payments/halyk-sim.php` | WebView / Custom Tabs / SFSafariViewController |
| Push FCM/APNs (с нуля) | не найдено в коде | Оба стека поддерживают |
| AI: SSE поверх POST, запись голоса (WAV, т.к. ffmpeg не настроен), воспроизведение MP3, on-device wake word | `AiAssistantController`, `config/ai.php:96-108` | Аудио-плагины, SSE-клиент, Porcupine/аналог |
| KK/RU/EN, ₸, Asia/Almaty | `lang/*`, `config/app.php` | ICU/intl |
| Команда: PHP + vanilla JS (`app.js` ~8 000 строк), разработка в Cursor + Claude | репозиторий | Кривая обучения, качество AI-генерации кода |
| Частые изменения продукта (60+ миграций, много флагов) | `database/`, `PaidServiceCatalog` | Важны OTA-обновления JS-слоя и feature flags |

## 2. Варианты

1. **React Native (New Architecture) + Expo (TypeScript), EAS Build/Submit/Update.**
2. **Flutter (Dart).**
3. **Нативная разработка: Kotlin (Jetpack Compose) + Swift (SwiftUI)** — контрольный вариант.
4. Kotlin Multiplatform (Compose Multiplatform) — рассмотрен, **не включён в финальное сравнение**: даёт выгоду при наличии Kotlin-экспертизы, а UI для iOS на Compose менее зрелый; у команды нет Kotlin-опыта. PWA/Capacitor — отклонены: на iOS ограничены push/фон/камера-видео, а текущий web без PWA-основы (нет manifest/SW).

## 3. Сравнительная таблица (1 — плохо, 5 — отлично)

| Критерий | RN + Expo | Flutter | Native ×2 | Комментарий / риски |
|---|---|---|---|---|
| Совместимость с backend/API (REST+JSON, OpenAPI-клиент, SSE POST) | 5 | 4 | 5 | TS-клиент из OpenAPI (orval/openapi-typescript) — зрелый; в Dart генераторы есть, но слабее. SSE поверх POST — в RN через `react-native-sse`/fetch streaming, во Flutter — `http` stream |
| Камера, медиа, загрузка, фон | 4 | 4 | 5 | Expo Camera/ImagePicker/Video, `react-native-vision-camera`; Flutter `camera`, `image_picker`, `video_player`. Фоновая загрузка на iOS везде требует нативной настройки (URLSession background) |
| Видео-лента (Stories/Shorts) | 4 | 5 | 5 | Flutter рисует всё сам — стабильный 60 fps; в RN — `expo-video` + FlashList, достижимо, но требует дисциплины |
| Live (SFU SDK) | 4 | 4 | 5 | LiveKit имеет официальные SDK для RN и Flutter; Cloudflare Realtime — WebRTC-клиент в обоих |
| Push | 5 | 5 | 5 | FCM + APNs; Expo Notifications или `@react-native-firebase/messaging`; `firebase_messaging` |
| Платёжный WebView/Custom Tabs | 5 | 4 | 5 | `react-native-webview`/`expo-web-browser`; `webview_flutter`/`flutter_custom_tabs` |
| Zak: голос, SSE, TTS, wake word | 4 | 4 | 5 | Запись WAV/PCM, воспроизведение MP3 — обе платформы; Porcupine (платно) поддерживает RN и Flutter |
| Производительность / размер | 4 | 4 | 5 | Бинарь RN/Flutter больше нативного на ~5–15 MB [П]; для маркетплейса некритично |
| Качество UX / нативность | 4 | 4 | 5 | RN использует нативные компоненты; Flutter — свой рендер (единообразно, но «не совсем нативно») |
| Экосистема и риски зависимостей | 4 | 4 | 5 | RN: много пакетов, риск заброшенных библиотек снижается за счёт Expo SDK; Flutter: pub.dev, контроль Google |
| Сборка/тесты/публикация | 5 | 4 | 3 | EAS Build в облаке (iOS без локального Xcode-пайплайна), EAS Submit, **EAS Update (OTA)**. Flutter: Codemagic/fastlane; OTA — только сторонний Shorebird. Native — два пайплайна |
| Требования к команде | 5 | 3 | 1 | Команда знает JS → TS/React учится быстрее, чем Dart; native — два разных специалиста |
| Продуктивность с Cursor + Claude | 5 | 4 | 4 | [П] Объём обучающих данных по TS/React крупнейший → выше качество генерации |
| Долгосрочная стоимость | 4 | 4 | 2 | Native ≈ ×1.7–2 по трудозатратам на функцию [П] |
| Переиспользование (дизайн-система, контракты, web) | 4 | 3 | 2 | TS-типы из OpenAPI можно использовать и в будущем web-фронте; Flutter Web для маркетплейса (SEO) не подходит |
| **Итого (невзвешенно)** | **66** | **60** | **62** | Native выигрывает по качеству, проигрывает по стоимости/команде |

## 4. Ключевые риски по вариантам

**RN + Expo:** обновления SDK раз в ~4 месяца требуют регулярных апгрейдов; нативные модули вне Expo SDK (vision-camera, LiveKit, Porcupine) требуют dev-client/config plugins; производительность длинных видеолент требует профилирования на слабых Android. OTA-обновления не должны менять нативный функционал (правила App Store 3.3.1/2.5.2 соблюдаются, если меняется только JS/ассеты).

**Flutter:** Dart — новый язык для команды; OTA только через сторонний сервис; WebView/платформенные view — исторически болезненная зона; генерация API-клиента и экосистема форм/валидации слабее.

**Native:** две кодовые базы, два специалиста, двойное тестирование, выше стоимость сопровождения; для текущей команды нереалистично.

## 5. Рекомендация

**Основной вариант: React Native (New Architecture) + Expo (TypeScript), с EAS Build/Submit/Update и dev-client.**

Обоснование:
1. Backend требует нового версионированного JSON-API; TypeScript-клиент, сгенерированный из OpenAPI, даёт строгие общие контракты и быструю синхронизацию с изменениями сервера.
2. Команда уже пишет на JavaScript; ИИ-инструменты (Cursor + Claude) наиболее продуктивны в TS/React.
3. Продукт меняется часто (флаги, платные услуги, конкурсы) — OTA-обновления JS-слоя через EAS Update сокращают цикл исправлений без повторного ревью нативной части.
4. Все тяжёлые требования (камера, видео, SFU-live, push, платёжный WebView, аудио для Зака) покрываются официальными или широко используемыми модулями.
5. EAS снимает сложность сборки и подписи iOS для небольшой команды.

**Резервный вариант: Flutter** — выбирать, если (а) в команду приходит Flutter-разработчик, или (б) прототип видеоленты на RN не достигает плавности на целевых бюджетных Android-устройствах (см. критерий проверки ниже).

**Критерий валидации до фиксации стека (spike, 3–5 дней):** на RN+Expo собрать прототип: вертикальная лента 20 видео (`expo-video` + FlashList) на бюджетном Android (2–3 ГБ ОЗУ) и iPhone; загрузка видео 50 MB с прогрессом; запись WAV → `/ai/voice` в dev-окружении; открытие Halyk-виджета в тестовом режиме во WebView/Custom Tab на **локальном/staging** backend. Если прокрутка < 50 fps или есть утечки памяти — повторить spike на Flutter.

Решение утверждает владелец продукта (см. RISKS_AND_OPEN_QUESTIONS, Q1).
