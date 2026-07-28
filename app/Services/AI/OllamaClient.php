<?php

namespace App\Services\AI;

use RuntimeException;

class OllamaClient
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(?string $baseUrl = null, ?string $model = null, ?int $timeout = null)
    {
        $cfg = $this->config();
        $this->baseUrl = rtrim($baseUrl ?? (string) $cfg['ollama_url'], '/');
        $this->model = $model ?? (string) $cfg['model'];
        $this->timeout = $timeout ?? (int) $cfg['timeout'];
    }

    public static function fromConfig(): self
    {
        return new self();
    }

    private function config(): array
    {
        static $cfg;
        if ($cfg === null) {
            $path = dirname(__DIR__, 3) . '/config/ai.php';
            $cfg = is_file($path) ? require $path : [];
        }
        return $cfg;
    }

    /**
     * @param list<array{role:string,content:string}> $messages
     * @return array{content:string,total_duration:int,eval_count:int}
     */
    public function chat(array $messages, ?float $temperature = null, bool $jsonMode = false): array
    {
        $cfg = $this->config();
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'options' => [
                'temperature' => $temperature ?? (float) ($cfg['temperature'] ?? 0.1),
                'num_predict' => (int) ($cfg['num_predict'] ?? 512),
            ],
            'stream' => false,
        ];

        if ($jsonMode) {
            $payload['format'] = 'json';
        }

        $response = $this->sendRequest($this->baseUrl . '/api/chat', $payload);

        if (!isset($response['message']['content'])) {
            throw new RuntimeException('Некорректный ответ Ollama: нет message.content');
        }

        return [
            'content' => trim((string) $response['message']['content']),
            'total_duration' => (int) ($response['total_duration'] ?? 0),
            'eval_count' => (int) ($response['eval_count'] ?? 0),
        ];
    }

    /** @return list<float> */
    public function embeddings(string $prompt): array
    {
        $response = $this->sendRequest($this->baseUrl . '/api/embeddings', [
            'model' => $this->model,
            'prompt' => $prompt,
        ]);

        if (!isset($response['embedding']) || !is_array($response['embedding'])) {
            throw new RuntimeException('Не удалось получить эмбеддинг от Ollama');
        }

        return $response['embedding'];
    }

    /** @return array{models?: list<array>} */
    public function listModels(): array
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => min(10, $this->timeout),
        ]);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            throw new RuntimeException($error ?: "Ollama /api/tags HTTP {$httpCode}");
        }

        $decoded = json_decode((string) $result, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Некорректный JSON от /api/tags');
        }

        return $decoded;
    }

    public function isAvailable(): bool
    {
        static $cached = null;
        static $cachedAt = 0;
        if ($cached !== null && (time() - $cachedAt) < 60) {
            return $cached;
        }
        try {
            $this->listModels();
            $cached = true;
        } catch (\Throwable $e) {
            $cached = false;
        }
        $cachedAt = time();
        return $cached;
    }

    private function sendRequest(string $url, array $payload): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            throw new RuntimeException('Не удалось сериализовать payload для Ollama');
        }

        $ch = curl_init($url);
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
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException("Ошибка подключения к Ollama ({$url}): {$error}");
        }

        if ($httpCode !== 200) {
            throw new RuntimeException("Ollama API HTTP {$httpCode}: " . (string) $result);
        }

        $decoded = json_decode((string) $result, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ошибка декодирования JSON ответа Ollama: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
