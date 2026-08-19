<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Атомарный SET NX EX без обязательного Redis (файловый fallback).
 * Сигнатура set() совместима с phpredis.
 */
class MicroTaskLock
{
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        $ttl = 10;
        $nx = false;
        if (is_array($options)) {
            $nx = isset($options['NX']) || isset($options['nx'])
                || in_array('NX', $options, true) || in_array('nx', $options, true);
            $ttl = (int) ($options['EX'] ?? $options['ex'] ?? $ttl);
        }

        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zk_lock_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key) . '.lock';
        $now = time();

        $fh = fopen($file, 'c+');
        if ($fh === false) {
            return false;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($fh) ?: '';
            $data = json_decode($raw, true);
            $expires = is_array($data) ? (int) ($data['expires'] ?? 0) : 0;
            if ($nx && $expires > $now) {
                return false;
            }

            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode([
                'value' => (string) $value,
                'expires' => $now + max(1, $ttl),
            ]));
            fflush($fh);

            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
