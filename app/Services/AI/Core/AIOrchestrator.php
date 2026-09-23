<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Core\Database;
use App\Models\AiSupport;
use App\Services\AI\DTO\AiRequest;
use App\Services\AI\DTO\AiResponse;
use App\Services\AI\DTO\IntentResult;
use App\Services\AI\Enum\Intent;
use App\Services\AI\Memory\MemoryManager;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\RagEngine;
use App\Services\AI\Tools\AnalyzeListingImagesTool;
use App\Services\AI\Tools\CreateListingDraftTool;
use App\Services\AI\Tools\AnalyzeDemandTool;
use App\Services\AI\Tools\GenerateSalesRecommendationsTool;
use App\Services\AI\Tools\GetDeliveryStatusTool;
use App\Services\AI\Tools\GetFavoritesTool;
use App\Services\AI\Tools\GetOrderStatusTool;
use App\Services\AI\Tools\GetSalesStatisticsTool;
use App\Services\AI\Tools\SearchProductsTool;
use App\Services\AI\Tools\SuggestPriceTool;
use App\Services\CatalogAiAssistant;

/**
 * Центральный оркестратор AI-платформы.
 * Pipeline: language → intent → context → memory → tools → validate → response → memory update.
 */
final class AIOrchestrator
{
    private ToolManager $tools;
    private IntentDetector $intents;
    private ContextManager $context;
    private PermissionChecker $permissions;
    private LanguageDetector $languages;
    private ModelRouter $router;
    private AiSupport $support;
    private RagEngine $rag;
    private PromptInjectionGuard $injectionGuard;
    private MemoryManager $memory;
    private ListingParamsExtractor $listingExtractor;
    private array $config;

    public function __construct(
        ?ToolManager $tools = null,
        ?IntentDetector $intents = null,
        ?ContextManager $context = null,
        ?PermissionChecker $permissions = null,
        ?AiSupport $support = null,
        ?MemoryManager $memory = null,
    ) {
        $this->config = AiConfig::all();
        $this->router = new ModelRouter(new OllamaProvider());
        $this->tools = $tools ?? new ToolManager($permissions ?? new PermissionChecker());
        $this->intents = $intents ?? new IntentDetector($this->router->provider());
        $this->context = $context ?? new ContextManager();
        $this->permissions = $permissions ?? new PermissionChecker();
        $this->languages = new LanguageDetector();
        $this->support = $support ?? new AiSupport();
        $this->rag = new RagEngine(null, $this->router->provider());
        $this->injectionGuard = new PromptInjectionGuard();
        $this->memory = $memory ?? new MemoryManager();
        $this->listingExtractor = new ListingParamsExtractor();

        if ($tools === null) {
            $this->tools->registerMany([
                new SearchProductsTool(),
                new GetOrderStatusTool(),
                new GetDeliveryStatusTool(),
                new GetFavoritesTool(),
                new SuggestPriceTool(),
                new AnalyzeListingImagesTool(),
                new CreateListingDraftTool(),
                new GetSalesStatisticsTool(),
                new AnalyzeDemandTool(),
                new GenerateSalesRecommendationsTool(),
            ]);
        }
    }

    public function handle(AiRequest $request): AiResponse
    {
        $started = hrtime(true);
        $message = $request->effectiveMessage();
        $message = $this->injectionGuard->sanitizeUserText($message);

        if ($message === '') {
            return new AiResponse(
                responseType: 'ERROR',
                message: 'Введите сообщение.',
                requestId: $request->requestId,
            );
        }

        $role = $this->permissions->roleFor($request->userId);
        $memories = $this->memory->loadUserMemories($request->userId);
        $ctxBundle = $this->context->build($request, $role, $memories);
        $intentResult = $this->intents->detect($message, $request->languageHint, $request->pageContext);

        // Фото в запросе → image analysis / listing
        if ($request->imagePaths !== [] && $intentResult->intent !== Intent::HumanEscalate) {
            if (in_array($intentResult->intent, [Intent::CreateListing, Intent::Unknown, Intent::GeneralQuestion, Intent::Support], true)
                || preg_match('/(продать|продаю|объявлен|sell)/ui', $message)
            ) {
                $intentResult = new IntentResult(
                    Intent::ImageAnalysis,
                    max($intentResult->confidence, 0.85),
                    'image_override',
                    $intentResult->parameters,
                    $intentResult->language
                );
            }
        }

        $this->audit($request, 'AI_INTENT_DETECTED', [
            'intent' => $intentResult->intent->value,
            'confidence' => $intentResult->confidence,
            'method' => $intentResult->method,
            'parameters' => $intentResult->parameters,
            'language' => $intentResult->language,
        ]);

        $toolCtx = [
            'user_id' => $request->userId,
            'role' => $role,
            'request_id' => $request->requestId,
            'conversation_id' => $request->conversationId,
            'context' => $ctxBundle,
        ];

        $response = match ($intentResult->intent) {
            Intent::Greeting => $this->replyGreeting($intentResult, $memories),
            Intent::HumanEscalate => $this->escalate($request, $intentResult),
            Intent::SearchProduct, Intent::SearchCategory, Intent::Recommendations =>
                $this->handleSearch($request, $intentResult, $toolCtx, $memories),
            Intent::OrderStatus, Intent::DealStatus, Intent::PaymentStatus =>
                $this->handleOrder($request, $intentResult, $toolCtx),
            Intent::DeliveryStatus => $this->handleDelivery($request, $intentResult, $toolCtx),
            Intent::Favorites => $this->handleFavorites($request, $intentResult, $toolCtx),
            Intent::PriceRecommendation => $this->handlePrice($request, $intentResult, $toolCtx),
            Intent::CreateListing, Intent::AnalyzeListing =>
                $this->handleCreateListing($request, $intentResult, $toolCtx),
            Intent::ImageAnalysis => $this->handleImageAnalysis($request, $intentResult, $toolCtx),
            Intent::SellerAnalytics => $this->handleSellerAnalytics($request, $intentResult, $toolCtx),
            Intent::SalesRecommendation => $this->handleSalesRecommendations($request, $intentResult, $toolCtx),
            Intent::DemandAnalysis => $this->handleDemand($request, $intentResult, $toolCtx),
            default => $this->handleSupport($request, $intentResult, $message, $memories),
        };

        $this->memory->observeInteraction(
            $request->userId,
            $message,
            $intentResult->intent->value,
            $intentResult->parameters
        );

        $aiMessageId = null;
        if ($request->conversationId) {
            $aiMessageId = $this->support->addMessage(
                $request->conversationId,
                $response->action === 'escalated' ? 'system' : 'ai',
                $response->message,
                $response->confidence,
                null,
                [
                    'response_type' => $response->responseType,
                    'products' => $response->products,
                    'actions' => $response->actions,
                    'data' => $response->data,
                    'intent' => $response->intent,
                    'request_id' => $request->requestId,
                ]
            );
        }

        $latency = (int) ((hrtime(true) - $started) / 1_000_000);
        $this->logUsage($request, $intentResult, $latency, 'ok');

        return new AiResponse(
            responseType: $response->responseType,
            message: $response->message,
            products: $response->products,
            suggestions: $response->suggestions,
            actions: $response->actions,
            data: $response->data,
            citations: $response->citations,
            confidence: $response->confidence,
            intent: $response->intent,
            action: $response->action,
            aiMessageId: $aiMessageId,
            language: $intentResult->language,
            requestId: $request->requestId,
        );
    }

    private function replyGreeting(IntentResult $intent, array $memories = []): AiResponse
    {
        $msg = match ($intent->language) {
            'kk' => 'Сәлеметсіз бе! Мен ZAK — zakopeyki.kz AI-көмекшісімін. Тауар іздеуге, жариялауға немесе тапсырыс мәртебесін тексеруге көмектесемін.',
            'en' => 'Hello! I am ZAK, the zakopeyki.kz AI assistant. I can search listings, help you sell, and check your order status.',
            default => 'Здравствуйте! Я ZAK — AI-ассистент zakopeyki.kz. Помогу найти товар, оформить объявление или проверить статус заказа.',
        };
        if ($memories !== []) {
            $msg .= ' ' . match ($intent->language) {
                'kk' => 'Сіздің қалауларыңызды ескеремін.',
                'en' => 'I will take your preferences into account.',
                default => 'Учту ваши сохранённые предпочтения.',
            };
        }

        return new AiResponse(
            responseType: 'TEXT',
            message: $msg,
            suggestions: $this->defaultSuggestions($intent->language),
            confidence: $intent->confidence,
            intent: $intent->intent->value,
        );
    }

    private function handleSearch(AiRequest $request, IntentResult $intent, array $toolCtx, array $memories = []): AiResponse
    {
        $params = $intent->parameters;
        if (empty($params['query']) && empty($params['max_price']) && empty($params['type'])) {
            $params = array_merge(
                $this->intents->extractSearchParamsHeuristic($request->effectiveMessage()),
                $params
            );
        }

        // Персонализация из памяти: город / бюджет если не указаны
        foreach ($memories as $mem) {
            if (empty($params['location']) && preg_match('/регион:\s*(.+?)(?:\.|$)/ui', $mem, $m)) {
                $params['location'] = trim($m[1]);
            }
            if (empty($params['max_price']) && preg_match('/до\s+(\d[\d\s]*)\s*₸/u', $mem, $m)) {
                $params['max_price'] = (int) preg_replace('/\s+/', '', $m[1]);
            }
        }

        $result = $this->tools->execute('search_products', $params, $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось выполнить поиск.'),
                confidence: $intent->confidence,
                intent: $intent->intent->value,
            );
        }

        $data = $result['data'] ?? [];
        $products = $data['products'] ?? [];
        $filters = $data['filters_applied'] ?? [];

        $personalNote = '';
        if ($memories !== [] && (!empty($filters['location']) || !empty($filters['max_price']))) {
            $personalNote = match ($intent->language) {
                'en' => ' I used your saved preferences where relevant.',
                'kk' => ' Қалауларыңызды ескердім.',
                default => ' Учёл ваши сохранённые предпочтения.',
            };
        }

        if ($products === []) {
            $msg = match ($intent->language) {
                'kk' => 'Сұранысыңыз бойынша белсенді хабарландыру табылмады. Сүзгіні жеңілдетіп көріңіз.',
                'en' => 'No active listings matched your filters. Try simplifying the query.',
                default => 'По вашим параметрам активных объявлений не найдено. Попробуйте упростить запрос или убрать часть фильтров.',
            };
            return new AiResponse(
                responseType: 'PRODUCT_LIST',
                message: $msg . $personalNote,
                products: [],
                data: ['filters' => $filters, 'source' => 'mysql.products'],
                confidence: $intent->confidence,
                intent: $intent->intent->value,
                suggestions: $this->defaultSuggestions($intent->language),
            );
        }

        $parts = [];
        if (!empty($filters['query'])) {
            $parts[] = '«' . $filters['query'] . '»';
        }
        if (!empty($filters['max_price'])) {
            $parts[] = 'до ' . number_format((int) $filters['max_price'], 0, '', ' ') . ' ₸';
        }
        if (!empty($filters['location'])) {
            $parts[] = 'в ' . $filters['location'];
        }
        $criteria = $parts !== [] ? implode(', ', $parts) : 'вашему запросу';

        $msg = match ($intent->language) {
            'kk' => 'Табылды ' . count($products) . ' нұсқа (' . $criteria . '). Деректер каталогтан алынды.',
            'en' => 'Found ' . count($products) . ' listing(s) for ' . $criteria . '. Results come from the live catalog.',
            default => 'Нашёл ' . count($products) . ' вариант(ов) по запросу: ' . $criteria . '. Это реальные объявления из каталога.',
        };

        return new AiResponse(
            responseType: 'PRODUCT_LIST',
            message: $msg . $personalNote,
            products: $products,
            data: [
                'filters' => $filters,
                'source' => 'mysql.products',
                'data_timestamp' => $data['data_timestamp'] ?? date('c'),
                'memories_used' => count($memories),
            ],
            confidence: $intent->confidence,
            intent: $intent->intent->value,
            suggestions: [
                ['label' => 'Дешевле', 'message' => 'покажи дешевле'],
                ['label' => 'Другой город', 'message' => 'покажи в Астане'],
            ],
        );
    }

    private function handleOrder(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->userId === null) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Чтобы проверить заказ, войдите в аккаунт.',
                intent: $intent->intent->value,
                confidence: $intent->confidence,
            );
        }

        $params = $intent->parameters;
        if (empty($params['order_id']) && !empty($toolCtx['context']['order_id'])) {
            $params['order_id'] = $toolCtx['context']['order_id'];
        }

        $result = $this->tools->execute('get_order_status', $params, $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось получить заказ.'),
                intent: $intent->intent->value,
                confidence: $intent->confidence,
            );
        }

        $data = $result['data'];
        $msg = "Заказ #{$data['order_id']}: {$data['status_label']}. {$data['next_step']}";

        return new AiResponse(
            responseType: 'ORDER_STATUS',
            message: $msg,
            data: $data,
            confidence: $intent->confidence,
            intent: $intent->intent->value,
            suggestions: [
                ['label' => 'Доставка', 'message' => 'где моя посылка'],
                ['label' => 'Возврат', 'message' => 'как оформить возврат'],
            ],
        );
    }

    private function handleDelivery(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->userId === null) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Чтобы отследить посылку, войдите в аккаунт.',
                intent: $intent->intent->value,
            );
        }

        $result = $this->tools->execute('get_delivery_status', $intent->parameters, $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Нет данных о доставке.'),
                intent: $intent->intent->value,
            );
        }

        $data = $result['data'];
        $msg = (string) ($data['explanation'] ?? 'Статус доставки получен из системы.');

        return new AiResponse(
            responseType: 'ORDER_STATUS',
            message: $msg,
            data: $data,
            confidence: $intent->confidence,
            intent: $intent->intent->value,
        );
    }

    private function handleFavorites(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        $result = $this->tools->execute('get_favorites', [], $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось открыть избранное.'),
                intent: $intent->intent->value,
            );
        }
        $products = $result['data']['products'] ?? [];
        $msg = $products === []
            ? 'В избранном пока пусто.'
            : 'Вот ваши избранные объявления (' . count($products) . ').';

        return new AiResponse(
            responseType: 'PRODUCT_LIST',
            message: $msg,
            products: $products,
            data: $result['data'],
            intent: $intent->intent->value,
            confidence: $intent->confidence,
        );
    }

    private function handleCreateListing(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->userId === null) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Чтобы создать объявление, войдите в аккаунт.',
                intent: $intent->intent->value,
                confidence: $intent->confidence,
            );
        }

        $params = array_merge(
            $this->listingExtractor->extract($request->effectiveMessage()),
            $intent->parameters
        );
        if (isset($params['category']) && in_array($params['category'], ['smartphone', 'laptop'], true)) {
            $params['category'] = $params['category'] === 'smartphone'
                ? 'Электроника / Телефоны'
                : 'Электроника / Ноутбуки';
        }

        // Если есть фото — сначала vision
        $vision = null;
        if ($request->imagePaths !== []) {
            $imgResult = $this->tools->execute('analyze_listing_images', [
                'image_path' => $request->imagePaths[0],
                'hint' => $request->effectiveMessage(),
            ], $toolCtx);
            if (!empty($imgResult['ok'])) {
                $vision = $imgResult['data']['analysis'] ?? null;
                $params['vision'] = $vision;
                $params['images'] = $request->imagePaths;
                if (empty($params['brand']) && !empty($vision['brand']['value'])) {
                    $params['brand'] = $vision['brand']['value'];
                }
                if (empty($params['model']) && !empty($vision['model']['value'])) {
                    $params['model'] = $vision['model']['value'];
                }
                if (empty($params['category']) && !empty($vision['suggested_category'])) {
                    $params['category'] = $vision['suggested_category'];
                }
                // Не сохраняем служебные category labels вроде smartphone
                if (isset($params['category']) && in_array($params['category'], ['smartphone', 'laptop'], true)) {
                    $params['category'] = $params['category'] === 'smartphone'
                        ? 'Электроника / Телефоны'
                        : 'Электроника / Ноутбуки';
                }
                if (empty($params['title'])) {
                    $params['title'] = trim(($params['brand'] ?? '') . ' ' . ($params['model'] ?? ''));
                }
                if (empty($params['condition']) && !empty($vision['condition']['display'])) {
                    $params['condition'] = $vision['condition']['display'];
                }
            }
        }

        // Рекомендация цены если нет явной
        if (empty($params['price'])) {
            $priceResult = $this->tools->execute('suggest_price', [
                'query' => $params['title'] ?? ($params['model'] ?? ''),
                'brand' => $params['brand'] ?? null,
                'model' => $params['model'] ?? null,
                'category' => $params['category'] ?? null,
                'type' => $params['type'] ?? 'used',
                'location' => $params['location'] ?? null,
            ], $toolCtx);
            if (!empty($priceResult['ok'])) {
                $params['price_recommendation'] = $priceResult['data'];
                if (!empty($priceResult['data']['recommended_price'])) {
                    $params['suggested_price'] = $priceResult['data']['recommended_price'];
                }
            }
        }

        $criticalMissing = [];
        if (empty($params['title']) && empty($params['brand']) && empty($params['model'])) {
            $criticalMissing[] = 'название / бренд и модель';
        }
        if ($criticalMissing !== []) {
            return new AiResponse(
                responseType: 'FORM',
                message: 'Чтобы подготовить объявление, уточните: ' . implode(', ', $criticalMissing) . '.',
                data: ['missing' => $criticalMissing, 'parsed' => $params],
                intent: $intent->intent->value,
                confidence: $intent->confidence,
                suggestions: [
                    ['label' => 'Пример', 'message' => 'Хочу продать iPhone 15, 256 ГБ, батарея 91%, цена 280 тысяч, Алматы'],
                ],
            );
        }

        $draftInput = $params;
        if (empty($draftInput['price']) && !empty($params['suggested_price'])) {
            $draftInput['price'] = $params['suggested_price'];
        }

        $result = $this->tools->execute('create_listing_draft', $draftInput, $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось создать черновик.'),
                intent: $intent->intent->value,
            );
        }

        $draft = $result['data']['draft'] ?? [];
        $token = $result['confirm_token'] ?? null;
        $priceRec = $draft['price_recommendation'] ?? ($params['price_recommendation'] ?? null);

        $lines = [
            'Подготовил черновик объявления (ещё не опубликовано):',
            '• ' . ($draft['title'] ?? ''),
            '• Категория: ' . ($draft['category'] ?? 'Разное'),
            '• Цена: ' . number_format((int) ($draft['price'] ?? 0), 0, '', ' ') . ' ₸',
            '• Город: ' . ($draft['location'] ?? ''),
        ];
        if (is_array($priceRec) && !empty($priceRec['recommended_price'])) {
            $lines[] = '• Рыночная ориентир. цена (медиана объявлений): '
                . number_format((int) $priceRec['recommended_price'], 0, '', ' ') . ' ₸';
            if (!empty($priceRec['limitation'])) {
                $lines[] = '• ' . $priceRec['limitation'];
            }
        }
        if (is_array($vision)) {
            $brandDisp = $vision['brand']['display'] ?? 'не определено';
            $modelDisp = $vision['model']['display'] ?? 'не определено';
            $lines[] = '• По фото: ' . $brandDisp . ' / ' . $modelDisp;
        }
        $lines[] = '';
        $lines[] = 'Опубликовать объявление?';

        $actions = [];
        if ($token) {
            $actions[] = [
                'type' => 'confirm',
                'label' => 'Опубликовать',
                'token' => $token,
                'action' => 'publish_listing_draft',
            ];
            $actions[] = [
                'type' => 'cancel',
                'label' => 'Отмена',
            ];
        }

        return new AiResponse(
            responseType: 'ACTION_CONFIRMATION',
            message: implode("\n", $lines),
            actions: $actions,
            data: [
                'draft' => $draft,
                'confirm_token' => $token,
                'vision' => $vision,
                'price_recommendation' => $priceRec,
            ],
            confidence: $intent->confidence,
            intent: $intent->intent->value,
        );
    }

    private function handleImageAnalysis(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->imagePaths === []) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Прикрепите фото товара — проанализирую и помогу с объявлением.',
                intent: $intent->intent->value,
            );
        }

        // Хочет продать? → listing flow
        if (preg_match('/(продать|продаю|объявлен|sell|сату)/ui', $request->effectiveMessage())
            || $intent->intent === Intent::CreateListing
        ) {
            return $this->handleCreateListing($request, new IntentResult(
                Intent::CreateListing,
                $intent->confidence,
                $intent->method,
                $intent->parameters,
                $intent->language
            ), $toolCtx);
        }

        $result = $this->tools->execute('analyze_listing_images', [
            'image_path' => $request->imagePaths[0],
            'hint' => $request->effectiveMessage(),
        ], $toolCtx);

        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось проанализировать фото.'),
                intent: $intent->intent->value,
            );
        }

        $a = $result['data']['analysis'] ?? [];
        $lines = [
            'Анализ фото (неутверждённые поля помечены как предположения):',
            '• Тип: ' . ($a['product_type'] ?: 'не определено'),
            '• Бренд: ' . ($a['brand']['display'] ?? 'не определено'),
            '• Модель: ' . ($a['model']['display'] ?? 'не определено'),
            '• Цвет: ' . ($a['color']['display'] ?? 'не определено'),
            '• Состояние: ' . ($a['condition']['display'] ?? 'не определено'),
        ];
        if (!empty($a['possible_defects'])) {
            $lines[] = '• Возможные дефекты: ' . implode(', ', $a['possible_defects']);
        }
        if (!empty($a['uncertainty_notes'])) {
            $lines[] = '• Оговорки: ' . implode('; ', $a['uncertainty_notes']);
        }

        return new AiResponse(
            responseType: 'IMAGE_ANALYSIS',
            message: implode("\n", $lines),
            data: $result['data'],
            confidence: $intent->confidence,
            intent: $intent->intent->value,
            suggestions: [
                ['label' => 'Создать объявление', 'message' => 'Хочу продать этот товар'],
                ['label' => 'Оценить цену', 'message' => 'Какую цену поставить?'],
            ],
        );
    }

    private function handlePrice(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        $params = array_merge(
            $this->listingExtractor->extract($request->effectiveMessage()),
            $intent->parameters
        );
        if (empty($params['query']) && !empty($toolCtx['context']['product_id'])) {
            // контекст страницы товара можно расширить позже
        }

        $result = $this->tools->execute('suggest_price', [
            'query' => $params['title'] ?? ($params['model'] ?? ($params['query'] ?? $request->effectiveMessage())),
            'brand' => $params['brand'] ?? null,
            'model' => $params['model'] ?? null,
            'category' => $params['category'] ?? null,
            'type' => $params['type'] ?? null,
            'location' => $params['location'] ?? null,
        ], $toolCtx);

        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось оценить цену.'),
                intent: $intent->intent->value,
            );
        }

        $d = $result['data'];
        if (empty($d['recommended_price'])) {
            return new AiResponse(
                responseType: 'PRICE_RECOMMENDATION',
                message: (string) ($d['limitation'] ?? 'Недостаточно данных для рекомендации цены.'),
                data: $d,
                intent: $intent->intent->value,
                confidence: 0.3,
            );
        }

        $msg = 'Ориентировочная цена по активным объявлениям: '
            . number_format((int) $d['recommended_price'], 0, '', ' ') . ' ₸'
            . ' (диапазон '
            . number_format((int) ($d['price_range']['min'] ?? 0), 0, '', ' ')
            . '–'
            . number_format((int) ($d['price_range']['max'] ?? 0), 0, '', ' ')
            . ' ₸, выборка ' . (int) $d['sample_size'] . ').';
        if (!empty($d['limitation'])) {
            $msg .= "\n" . $d['limitation'];
        }

        return new AiResponse(
            responseType: 'PRICE_RECOMMENDATION',
            message: $msg,
            data: $d,
            confidence: (float) ($d['confidence'] ?? 0.5),
            intent: $intent->intent->value,
        );
    }

    private function handleSellerAnalytics(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->userId === null) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Войдите в аккаунт продавца, чтобы увидеть статистику.',
                intent: $intent->intent->value,
            );
        }

        $result = $this->tools->execute('get_sales_statistics', ['days' => 30], $toolCtx);
        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Нет данных статистики.'),
                intent: $intent->intent->value,
            );
        }

        $d = $result['data'];
        $lines = [
            'Ваша статистика за ' . (int) $d['period_days'] . ' дней (реальные данные):',
            '• Активных объявлений: ' . (int) ($d['listings']['active'] ?? 0),
            '• Проданных лотов: ' . (int) ($d['listings']['sold'] ?? 0),
            '• Завершённых сделок: ' . (int) ($d['completed_sales'] ?? 0),
            '• GMV (completed): ' . number_format((int) ($d['gmv_completed'] ?? 0), 0, '', ' ') . ' ₸',
            '• Просмотры лотов: ' . (int) ($d['total_views'] ?? 0),
            '• ' . ($d['note'] ?? ''),
        ];

        return new AiResponse(
            responseType: 'TEXT',
            message: implode("\n", $lines),
            data: $d,
            confidence: $intent->confidence,
            intent: $intent->intent->value,
            suggestions: [
                ['label' => 'Рекомендации', 'message' => 'Что сделать, чтобы быстрее продать'],
                ['label' => 'Спрос', 'message' => 'Какой спрос на телефоны'],
            ],
        );
    }

    private function handleSalesRecommendations(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        if ($request->userId === null) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Войдите в аккаунт, чтобы получить персональные рекомендации продавца.',
                intent: $intent->intent->value,
            );
        }

        $params = array_merge(
            $this->listingExtractor->extract($request->effectiveMessage()),
            $intent->parameters
        );
        $result = $this->tools->execute('generate_sales_recommendations', [
            'query' => $params['model'] ?? ($params['title'] ?? ($params['query'] ?? '')),
            'category' => $params['category'] ?? null,
        ], $toolCtx);

        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось сформировать рекомендации.'),
                intent: $intent->intent->value,
            );
        }

        $tips = $result['data']['recommendations'] ?? [];
        $msg = "Рекомендации на основе ваших данных:\n• " . implode("\n• ", $tips);

        return new AiResponse(
            responseType: 'TEXT',
            message: $msg,
            data: $result['data'],
            confidence: $intent->confidence,
            intent: $intent->intent->value,
        );
    }

    private function handleDemand(AiRequest $request, IntentResult $intent, array $toolCtx): AiResponse
    {
        $params = array_merge(
            $this->intents->extractSearchParamsHeuristic($request->effectiveMessage()),
            $this->listingExtractor->extract($request->effectiveMessage()),
            $intent->parameters
        );
        $query = (string) ($params['query'] ?? $params['model'] ?? $params['title'] ?? '');
        if ($query === '' && preg_match('/спрос\s+на\s+(.+)$/ui', $request->effectiveMessage(), $m)) {
            $query = trim($m[1]);
        }
        if ($query === '') {
            $query = 'телефон';
        }

        $result = $this->tools->execute('analyze_demand', [
            'query' => $query,
            'category' => $params['category'] ?? null,
            'location' => $params['location'] ?? null,
        ], $toolCtx);

        if (empty($result['ok'])) {
            return new AiResponse(
                responseType: 'ERROR',
                message: (string) ($result['error'] ?? 'Не удалось оценить спрос.'),
                intent: $intent->intent->value,
            );
        }

        $d = $result['data'];
        $lines = [
            'Анализ спроса по каталогу («' . ($d['query'] ?: $d['category']) . '»):',
            '• Активных аналогов: ' . (int) $d['active_listings'],
            '• ' . ($d['demand_hint'] ?? ''),
        ];
        if (!empty($d['median_listing_price'])) {
            $lines[] = '• Медиана цены объявлений: ' . number_format((int) $d['median_listing_price'], 0, '', ' ') . ' ₸';
        }
        foreach ($d['recommendations'] ?? [] as $tip) {
            $lines[] = '• ' . $tip;
        }

        return new AiResponse(
            responseType: 'TEXT',
            message: implode("\n", $lines),
            data: $d,
            confidence: $intent->confidence,
            intent: $intent->intent->value,
        );
    }

    private function handleSupport(AiRequest $request, IntentResult $intent, string $message, array $memories = []): AiResponse
    {
        $articles = $this->rag->searchContext($message);
        if ($articles !== []) {
            $best = $articles[0];
            $text = (string) ($best['title'] ?? '') . "\n\n" . (string) ($best['content'] ?? '');
            return new AiResponse(
                responseType: 'TEXT',
                message: $text,
                citations: [(string) ($best['title'] ?? 'knowledge')],
                confidence: 0.7,
                intent: $intent->intent->value,
                suggestions: $this->defaultSuggestions($intent->language),
                data: ['rag_source' => $best['source'] ?? 'fulltext', 'memories_used' => count($memories)],
            );
        }

        try {
            $catalog = (new CatalogAiAssistant())->reply($message);
            return new AiResponse(
                responseType: !empty($catalog['products']) ? 'PRODUCT_LIST' : 'TEXT',
                message: (string) ($catalog['reply'] ?? ''),
                products: $catalog['products'] ?? [],
                suggestions: $catalog['suggestions'] ?? $this->defaultSuggestions($intent->language),
                confidence: 0.65,
                intent: $intent->intent->value,
            );
        } catch (\Throwable) {
            return new AiResponse(
                responseType: 'TEXT',
                message: 'Я не могу подтвердить эту информацию по имеющимся данным. Уточните вопрос или напишите «оператор».',
                intent: $intent->intent->value,
                confidence: 0.4,
            );
        }
    }

    private function escalate(AiRequest $request, IntentResult $intent): AiResponse
    {
        if ($request->conversationId) {
            $this->support->updateStatus($request->conversationId, 'human_escalated');
        }
        return new AiResponse(
            responseType: 'TEXT',
            message: 'Диалог переведён на оператора zakopeyki.kz. Специалист ответит в этом чате.',
            confidence: $intent->confidence,
            intent: $intent->intent->value,
            action: 'escalated',
        );
    }

    /** @return list<array{label:string,message:string}> */
    private function defaultSuggestions(string $lang): array
    {
        return match ($lang) {
            'kk' => [
                ['label' => 'Іздеу', 'message' => 'айфон 15 тап'],
                ['label' => 'Жеткізу', 'message' => 'менің жіберілімім қайда'],
                ['label' => 'Оператор', 'message' => 'оператор'],
            ],
            'en' => [
                ['label' => 'Search', 'message' => 'find iPhone 15'],
                ['label' => 'Delivery', 'message' => 'where is my package'],
                ['label' => 'Operator', 'message' => 'operator'],
            ],
            default => [
                ['label' => 'Найти iPhone', 'message' => 'найди iPhone 15 до 300000 в Алматы'],
                ['label' => 'Моя посылка', 'message' => 'где моя посылка'],
                ['label' => 'Оператор', 'message' => 'оператор'],
            ],
        };
    }

    private function audit(AiRequest $request, string $event, array $detail): void
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO ai_audit_logs (request_id, user_id, conversation_id, event, detail_json)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $request->requestId,
                $request->userId,
                $request->conversationId,
                $event,
                json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
        }
    }

    private function logUsage(AiRequest $request, IntentResult $intent, int $latencyMs, string $status): void
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'INSERT INTO ai_usage_logs
                 (request_id, user_id, conversation_id, provider, model, intent, latency_ms, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $request->requestId,
                $request->userId,
                $request->conversationId,
                'ollama',
                AiConfig::get('model'),
                $intent->intent->value,
                $latencyMs,
                $status,
            ]);
        } catch (\Throwable) {
        }
    }
}
