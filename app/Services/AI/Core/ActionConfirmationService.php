<?php

declare(strict_types=1);

namespace App\Services\AI\Core;

use App\Core\Database;

final class ActionConfirmationService
{
    public function __construct(private readonly int $ttlSeconds = 900)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(int $userId, string $action, array $payload, ?int $conversationId = null): string
    {
        $token = bin2hex(random_bytes(24));
        $expires = date('Y-m-d H:i:s', time() + $this->ttlSeconds);
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO ai_action_confirmations
             (token, user_id, conversation_id, action, payload_json, status, expires_at)
             VALUES (?, ?, ?, ?, ?, \'pending\', ?)'
        );
        $stmt->execute([
            $token,
            $userId,
            $conversationId,
            $action,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $expires,
        ]);
        return $token;
    }

    /**
     * @return array{ok:bool,action?:string,payload?:array,error?:string}
     */
    public function confirm(string $token, int $userId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'SELECT * FROM ai_action_confirmations WHERE token = ? LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => false, 'error' => 'Токен подтверждения не найден.'];
        }
        if ((int) $row['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Нет доступа.'];
        }
        if (($row['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => 'Действие уже обработано.'];
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $pdo->prepare('UPDATE ai_action_confirmations SET status = \'expired\' WHERE token = ?')->execute([$token]);
            return ['ok' => false, 'error' => 'Срок подтверждения истёк.'];
        }

        $pdo->prepare(
            'UPDATE ai_action_confirmations SET status = \'confirmed\', confirmed_at = NOW() WHERE token = ? AND status = \'pending\''
        )->execute([$token]);

        return [
            'ok' => true,
            'action' => (string) $row['action'],
            'payload' => json_decode((string) $row['payload_json'], true) ?: [],
        ];
    }
}
