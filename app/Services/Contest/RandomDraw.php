<?php

declare(strict_types=1);

namespace App\Services\Contest;

/**
 * Проверяемый случайный выбор победителей (commit–reveal).
 *
 * 1. До старта акции генерируется секретный seed (32 байта); публикуется только commit = SHA-256(seed).
 * 2. После закрытия формируется упорядоченный список ID допущенных участий; listHash = SHA-256("id1,id2,...").
 * 3. Победители выбираются детерминированно: частичная перетасовка Фишера–Йетса, где каждое
 *    случайное число = первые 6 байт HMAC-SHA256(seed, listHash ":" counter) с отбраковкой
 *    (rejection sampling) — без смещения по модулю.
 * 4. После публикации результатов раскрывается seed: любой может проверить, что
 *    SHA-256(seed) = commit и что алгоритм даёт тех же победителей.
 *
 * Организатор не знает seed заранее и не может повлиять на результат, а платформа не может
 * подменить seed после публикации commit.
 */
final class RandomDraw
{
    public const ALGORITHM = 'hmac-sha256-fisher-yates-v1';
    private const RANGE = 281474976710656; // 2^48

    /** @return array{seed:string, commit:string} seed — 64 hex */
    public static function newCommitment(): array
    {
        $seed = bin2hex(random_bytes(32));
        return ['seed' => $seed, 'commit' => self::commitFor($seed)];
    }

    public static function commitFor(string $seedHex): string
    {
        return hash('sha256', self::seedBytes($seedHex));
    }

    /** @param list<int> $ids */
    public static function listHash(array $ids): string
    {
        $ids = array_map('intval', $ids);
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(',', $ids));
    }

    /**
     * @param list<int> $eligibleIds
     * @return list<int> выбранные ID в порядке мест (1-е место первым)
     */
    public static function pick(string $seedHex, array $eligibleIds, int $count): array
    {
        $pool = array_values(array_unique(array_map('intval', $eligibleIds)));
        sort($pool, SORT_NUMERIC);
        $n = count($pool);
        $count = max(0, min($count, $n));
        if ($count === 0) {
            return [];
        }

        $key = self::seedBytes($seedHex);
        $listHash = hash('sha256', implode(',', $pool));
        $counter = 0;
        $winners = [];

        for ($i = 0; $i < $count; $i++) {
            $remaining = $n - $i;
            $limit = intdiv(self::RANGE, $remaining) * $remaining;
            do {
                $mac = hash_hmac('sha256', $listHash . ':' . $counter, $key, true);
                $counter++;
                $v = 0;
                for ($b = 0; $b < 6; $b++) {
                    $v = ($v << 8) | ord($mac[$b]);
                }
            } while ($v >= $limit);

            $j = $i + ($v % $remaining);
            [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
            $winners[] = $pool[$i];
        }

        return $winners;
    }

    private static function seedBytes(string $seedHex): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $seedHex)) {
            throw new \InvalidArgumentException('Seed must be 64 lowercase hex chars');
        }
        return (string) hex2bin($seedHex);
    }
}
