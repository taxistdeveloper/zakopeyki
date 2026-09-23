<?php

declare(strict_types=1);

namespace App\Services\AI\Providers;

use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\OllamaClient;
use App\Services\AI\Support\AiCache;
use RuntimeException;

/**
 * LLM-провайдер на базе существующего OllamaClient + streaming/vision.
 */
final class OllamaProvider implements LLMProviderInterface
{
    private OllamaClient $client;
    private string $baseUrl;
    private string $defaultModel;
    private string $visionModel;
    private string $embeddingModel;
    private int $timeout;
    private array $config;

    public function __construct(?OllamaClient $client = null, ?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();
        $this->client = $client ?? OllamaClient::fromConfig();
        $this->baseUrl = rtrim((string) ($this->config['ollama_url'] ?? 'http://127.0.0.1:11434'), '/');
        $this->defaultModel = (string) ($this->config['model'] ?? 'qwen2.5:7b-instruct');
        $this->visionModel = (string) ($this->config['vision_model'] ?? 'llava');
        $this->embeddingModel = (string) ($this->config['embedding_model'] ?? 'nomic-embed-text');
        $this->timeout = (int) ($this->config['timeout'] ?? 60);
    }

    public function providerName(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        return $this->client->isAvailable();
    }

    public function chat(array $messages, ?float $temperature = null, bool $jsonMode = false, ?string $model = null): array
    {
        $previous = null;
        if ($model !== null && $model !== '') {
            $previous = $this->swapModel($model);
        }
        try {
            $result = $this->client->chat($messages, $temperature, $jsonMode);
            return [
                'content' => $result['content'],
                'total_duration' => $result['total_duration'],
                'eval_count' => $result['eval_count'],
                'model' => $model ?? $this->defaultModel,
            ];
        } finally {
            if ($previous !== null) {
                $this->swapModel($previous);
            }
        }
    }

    public function chatStream(array $messages, callable $onDelta, ?float $temperature = null, ?string $model = null): array
    {
        $payload = [
            'model' => $model ?? $this->defaultModel,
            'messages' => $messages,
            'stream' => true,
            'options' => [
                'temperature' => $temperature ?? (float) ($this->config['temperature'] ?? 0.1),
                'num_predict' => (int) ($this->config['num_predict'] ?? 512),
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new RuntimeException('Не удалось сериализовать streaming payload');
        }

        $ch = curl_init($this->baseUrl . '/api/chat');
        $buffer = '';
        $content = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$content, $onDelta): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '') {
                        continue;
                    }
                    $decoded = json_decode($line, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $delta = (string) ($decoded['message']['content'] ?? '');
                    if ($delta !== '') {
                        $content .= $delta;
                        $onDelta($delta);
                    }
                }
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok === false || $error) {
            throw new RuntimeException('Ollama stream error: ' . ($error ?: 'unknown'));
        }
        if ($code !== 200) {
            throw new RuntimeException("Ollama stream HTTP {$code}");
        }

        return [
            'content' => $content,
            'total_duration' => 0,
            'eval_count' => 0,
            'model' => $model ?? $this->defaultModel,
        ];
    }

    public function embed(string $text, ?string $model = null): array
    {
        $useModel = $model ?? $this->embeddingModel;
        $ttl = (int) ($this->config['performance']['cache_embed_ttl'] ?? 86400);
        $cacheKey = 'emb:' . $useModel . ':' . hash('sha256', $text);

        if ($ttl > 0) {
            $cache = new AiCache();
            $hit = $cache->get($cacheKey);
            if (is_array($hit) && $hit !== []) {
                return array_map('floatval', $hit);
            }
        }

        $response = $this->rawPost('/api/embeddings', [
            'model' => $useModel,
            'prompt' => $text,
        ]);
        if (!isset($response['embedding']) || !is_array($response['embedding'])) {
            throw new RuntimeException('Ollama embeddings: пустой ответ');
        }
        $vector = array_map('floatval', $response['embedding']);

        if ($ttl > 0) {
            (new AiCache())->set($cacheKey, $vector, $ttl);
        }

        return $vector;
    }

    public function vision(string $prompt, string $imageBase64OrPath, ?string $model = null): array
    {
        $image = $imageBase64OrPath;
        if (is_file($imageBase64OrPath)) {
            $raw = file_get_contents($imageBase64OrPath);
            if ($raw === false) {
                throw new RuntimeException('Не удалось прочитать изображение');
            }
            $image = base64_encode($raw);
        }

        $response = $this->rawPost('/api/chat', [
            'model' => $model ?? $this->visionModel,
            'stream' => false,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                    'images' => [$image],
                ],
            ],
        ]);

        $content = trim((string) ($response['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('Vision: пустой ответ модели');
        }

        return [
            'content' => $content,
            'model' => $model ?? $this->visionModel,
        ];
    }

    private function rawPost(string $path, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new RuntimeException('JSON encode failed');
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($jsonPayload),
            ],
        ]);
        $result = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException($error);
        }
        if ($code !== 200) {
            throw new RuntimeException("Ollama HTTP {$code}: " . (string) $result);
        }
        $decoded = json_decode((string) $result, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Ollama');
        }
        return $decoded;
    }

    private function swapModel(string $model): string
    {
        // OllamaClient хранит model privately — для intent/vision используем raw API выше.
        // Этот метод оставлен для совместимости; chat() использует client default.
        return $this->defaultModel;
    }

    private function loadConfig(): array
    {
        $path = dirname(__DIR__, 3) . '/config/ai.php';
        return is_file($path) ? require $path : [];
    }
}
