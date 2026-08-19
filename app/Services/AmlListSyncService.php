<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

class AmlListSyncService
{
    /** @var array<string, mixed> */
    private array $config;
    private PDO $pdo;
    private ?object $redis;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(PDO $pdo, array $config, ?object $redis = null)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->redis = $redis;
    }

    /**
     * @return array{ok: bool, imported: int, error?: string}
     */
    public function sync(): array
    {
        (new AMLService($this->pdo, $this->redis))->ensureSchema();

        $tempFile = (string) ($this->config['temp_file'] ?? sys_get_temp_dir() . '/temp_afm_list.xml');
        $dir = dirname($tempFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'imported' => 0, 'error' => 'Не удалось создать каталог storage/aml'];
        }

        $source = $this->resolveSource();
        if ($source === null) {
            return [
                'ok' => false,
                'imported' => 0,
                'error' => 'Не задан AML_XML_URL и нет файла storage/aml/afm_list.xml',
            ];
        }

        try {
            $this->downloadTo($source, $tempFile);
            $imported = $this->importFile($tempFile);
            return ['ok' => true, 'imported' => $imported];
        } catch (Throwable $e) {
            return ['ok' => false, 'imported' => 0, 'error' => $e->getMessage()];
        } finally {
            if (is_file($tempFile) && realpath($tempFile) !== realpath($source)) {
                @unlink($tempFile);
            }
        }
    }

    public function importFile(string $path): int
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('XML-файл пуст или не найден');
        }

        $newTable = 'aml_blacklisted_persons_new';
        $oldTable = 'aml_blacklisted_persons_old';
        $liveTable = 'aml_blacklisted_persons';

        $this->pdo->exec("DROP TABLE IF EXISTS `{$newTable}`");
        $this->pdo->exec("CREATE TABLE `{$newTable}` LIKE `{$liveTable}`");

        $redisTemp = (string) ($this->config['redis']['temp_key'] ?? 'aml:blacklisted_iins:temp');
        $redisMain = (string) ($this->config['redis']['main_key'] ?? 'aml:blacklisted_iins');
        if ($this->redis !== null && method_exists($this->redis, 'del')) {
            $this->redis->del($redisTemp);
        }

        $records = [];
        $seen = [];
        $total = 0;
        $chunkSize = max(50, (int) ($this->config['chunk_size'] ?? 500));
        $insertSql = "INSERT IGNORE INTO `{$newTable}` (`iin`, `full_name`, `list_type`) VALUES ";

        $reader = new XMLReader();
        if (!$reader->open($path, null, LIBXML_NONET)) {
            throw new RuntimeException('Не удалось открыть XML');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if (!in_array($reader->localName, ['person', 'Person', 'item', 'entry', 'record', 'PhysicalPerson', 'Individual'], true)) {
                    continue;
                }

                $node = @new SimpleXMLElement($reader->readOuterXml());
                $row = $this->extractPerson($node);
                if ($row === null || isset($seen[$row['iin']])) {
                    continue;
                }
                $seen[$row['iin']] = true;
                $records[] = $row;
                $total++;

                if (count($records) >= $chunkSize) {
                    $this->flushChunk($insertSql, $records, $redisTemp);
                    $records = [];
                }
            }
        } finally {
            $reader->close();
        }

        if ($records !== []) {
            $this->flushChunk($insertSql, $records, $redisTemp);
        }

        if ($total === 0) {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$newTable}`");
            if ($this->redis !== null && method_exists($this->redis, 'del')) {
                $this->redis->del($redisTemp);
            }
            throw new RuntimeException('В XML не найдено ни одного ИИН из 12 цифр. Проверьте формат файла АФМ.');
        }

        $this->pdo->exec("DROP TABLE IF EXISTS `{$oldTable}`");
        $this->pdo->exec(
            "RENAME TABLE `{$liveTable}` TO `{$oldTable}`, `{$newTable}` TO `{$liveTable}`"
        );
        $this->pdo->exec("DROP TABLE IF EXISTS `{$oldTable}`");

        if ($this->redis !== null && method_exists($this->redis, 'exists') && method_exists($this->redis, 'rename')) {
            try {
                if ($this->redis->exists($redisTemp)) {
                    $this->redis->rename($redisTemp, $redisMain);
                }
            } catch (Throwable $e) {
                error_log('AML Redis rename: ' . $e->getMessage());
            }
        }

        return $total;
    }

    private function extractPerson(SimpleXMLElement $node): ?array
    {
        $candidates = [];
        foreach (['iin', 'IIN', 'Iin', 'bin', 'BIN', 'IinBin'] as $tag) {
            if (isset($node->{$tag})) {
                $candidates[] = $node->{$tag};
            }
        }
        $iin = '';
        foreach ($candidates as $c) {
            if ($c === null) {
                continue;
            }
            $iin = preg_replace('/\D/', '', (string) $c) ?? '';
            if (strlen($iin) === 12) {
                break;
            }
        }
        if (strlen($iin) !== 12) {
            foreach ($node->xpath('.//*') ?: [] as $el) {
                $name = strtolower($el->getName());
                if (!in_array($name, ['iin', 'bin', 'иин', 'бин', 'iinbin'], true)) {
                    continue;
                }
                $iin = preg_replace('/\D/', '', (string) $el) ?? '';
                if (strlen($iin) === 12) {
                    break;
                }
            }
        }
        if (strlen($iin) !== 12) {
            return null;
        }

        $lastName = trim((string) ($node->last_name ?? $node->surname ?? $node->LastName ?? ''));
        $firstName = trim((string) ($node->first_name ?? $node->name ?? $node->FirstName ?? ''));
        $middleName = trim((string) ($node->middle_name ?? $node->patronymic ?? $node->Patronymic ?? ''));
        $fullName = trim($lastName . ' ' . $firstName . ' ' . $middleName);
        if ($fullName === '') {
            $fullName = trim((string) ($node->full_name ?? $node->fio ?? $node->FIO ?? $node->Name ?? ''));
        }
        if ($fullName === '') {
            $fullName = 'ФИО не указано';
        }

        $listType = 'person';
        $binRaw = preg_replace('/\D/', '', (string) ($node->bin ?? $node->BIN ?? '')) ?? '';
        if ($binRaw === $iin && ($node->iin ?? $node->IIN ?? null) === null) {
            $listType = 'organization';
        }

        return [
            'iin' => $iin,
            'full_name' => mb_substr($fullName, 0, 255),
            'list_type' => $listType,
        ];
    }

    /**
     * @param list<array{iin: string, full_name: string, list_type: string}> $records
     */
    private function flushChunk(string $baseSql, array $records, string $redisTempKey): void
    {
        $placeholders = [];
        $params = [];
        $iins = [];
        foreach ($records as $index => $row) {
            $placeholders[] = "(:iin_{$index}, :name_{$index}, :type_{$index})";
            $params[":iin_{$index}"] = $row['iin'];
            $params[":name_{$index}"] = $row['full_name'];
            $params[":type_{$index}"] = $row['list_type'];
            $iins[] = $row['iin'];
        }

        $stmt = $this->pdo->prepare($baseSql . implode(', ', $placeholders));
        $stmt->execute($params);

        if ($this->redis === null || $iins === []) {
            return;
        }
        try {
            if (method_exists($this->redis, 'pipeline')) {
                $pipeline = $this->redis->pipeline();
                foreach ($iins as $iin) {
                    $pipeline->sAdd($redisTempKey, $iin);
                }
                $pipeline->exec();
            } elseif (method_exists($this->redis, 'sAdd')) {
                foreach ($iins as $iin) {
                    $this->redis->sAdd($redisTempKey, $iin);
                }
            }
        } catch (Throwable $e) {
            error_log('AML Redis SADD: ' . $e->getMessage());
        }
    }

    private function resolveSource(): ?string
    {
        $urls = $this->config['xml_urls'] ?? [];
        if (!is_array($urls)) {
            return null;
        }
        foreach ($urls as $url) {
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    private function downloadTo(string $source, string $dest): void
    {
        if (!preg_match('#^https?://#i', $source)) {
            if (!is_file($source)) {
                throw new RuntimeException('Локальный XML не найден: ' . $source);
            }
            if (realpath($source) !== realpath($dest)) {
                if (!copy($source, $dest)) {
                    throw new RuntimeException('Не удалось скопировать XML');
                }
            }
            return;
        }

        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            throw new RuntimeException('Не удалось открыть временный файл');
        }

        $ch = curl_init($source);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 90),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => (string) ($this->config['user_agent'] ?? 'Zakopeyki-AML-Sync/1.0'),
        ]);
        $ok = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $http !== 200) {
            @unlink($dest);
            throw new RuntimeException('Ошибка скачивания XML (HTTP ' . $http . ') ' . $err);
        }
    }
}
