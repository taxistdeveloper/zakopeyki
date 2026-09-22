<?php

declare(strict_types=1);

/**
 * Конфигурация DI-контейнера Zakopeyki AI Platform.
 *
 * Сервисы регистрируются здесь по мере реализации блоков (B → J).
 * Возвращает полностью настроенный контейнер.
 */

use App\Core\Container;
use App\Core\Env;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$container = new Container();

// -------------------------------------------------------------------------
// Параметры окружения
// -------------------------------------------------------------------------
$container->setParameter('app.env', Env::get('APP_ENV', 'production'));
$container->setParameter('app.debug', Env::getBool('APP_DEBUG', false));
$container->setParameter('app.base_path', dirname(__DIR__));
$container->setParameter('storage.path', dirname(__DIR__) . '/storage');

$container->setParameter('db.host', Env::get('DB_HOST', 'localhost'));
$container->setParameter('db.port', Env::getInt('DB_PORT', 3306));
$container->setParameter('db.database', Env::get('DB_DATABASE', 'zakopeyki'));
$container->setParameter('db.username', Env::get('DB_USERNAME', 'root'));
$container->setParameter('db.password', Env::get('DB_PASSWORD', ''));

$container->setParameter('redis.host', Env::get('REDIS_HOST', 'localhost'));
$container->setParameter('redis.port', Env::getInt('REDIS_PORT', 6379));
$container->setParameter('redis.db', Env::getInt('REDIS_DB', 0));

$container->setParameter('openai.api_key', Env::get('OPENAI_API_KEY', ''));
$container->setParameter('openai.model', Env::get('OPENAI_MODEL', 'gpt-4o'));
$container->setParameter('openai.vision_model', Env::get('OPENAI_VISION_MODEL', 'gpt-4o'));

$container->setParameter('google.vision_key_path', Env::get('GOOGLE_VISION_KEY_PATH', ''));
$container->setParameter('google.stt_api_key', Env::get('GOOGLE_STT_API_KEY', ''));

$container->setParameter('yandex.stt_api_key', Env::get('YANDEX_STT_API_KEY', ''));
$container->setParameter('yandex.folder_id', Env::get('YANDEX_FOLDER_ID', ''));

$container->setParameter('platform.api_url', Env::get('PLATFORM_API_URL', 'https://api.zakopeyki.kz'));
$container->setParameter('platform.api_key', Env::get('PLATFORM_API_KEY', ''));

$container->setParameter('encryption.key', Env::get('ENCRYPTION_KEY', ''));

// -------------------------------------------------------------------------
// Регистрация сервисов
//
// BLOCK B: Core Schemas + ConfidenceEngine
// BLOCK C: RedisService, DatabaseService, ContextStateManager, UserMemoryManager
// BLOCK D: IntentEngine pipeline, CatalogEntityResolver, MoneyParser, LanguageDetector
// BLOCK E: ToolRegistry, PermissionEngine, ConfirmationEngine, DecisionEngine,
//          ToolExecutor, AIOrchestrator
// BLOCK F: PlatformAPIClient + APIs, SearchIntelligence, RecommendationEngine,
//          RankingEngine
// BLOCK G: KnowledgeEngine, ListingAIGenerator, VisionAnalyzer, PriceIntelligence,
//          SellerAnalyticsEngine, DemandForecastEngine
// BLOCK H: ModerationEngine, FraudDetectionEngine, Security middleware
// BLOCK I: EventBus, FeedbackEngine, DatasetEngine, ML training/registry/deployment
// BLOCK J: ExperimentEngine, Observability, Audit, VoicePipeline, E2E runner
// -------------------------------------------------------------------------

return $container;
