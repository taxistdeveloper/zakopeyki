<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class UnskilledTaskValidator
{
    private PDO $pdo;

    /** Стоп-слова квалифицированного труда */
    private array $stopWords = [
        'электрик', 'электрика', 'электромонтаж', 'проводк', 'розетк', 'щиток',
        'сантехник', 'сантехника', 'труб', 'смесител', 'канализац', 'унитаз',
        'диагностика', 'автодиагностика', 'пайка', 'паять', 'микросхем',
        'ремонт пк', 'ремонт компьютера', 'ремонт ноутбука', 'ремонт телефона',
        'юрист', 'юридическ', 'адвокат', 'бухгалтер', 'бухгалтерия', 'учет',
        'нотариус', 'нотариальн', 'массаж', 'массажист', 'косметолог',
        'программист', 'разработка сайта', 'установка кондиционера', 'фреон',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{is_valid: bool, errors: list<string>}
     */
    public function validate(string $title, string $description, int $categoryId): array
    {
        $errors = [];

        $stmt = $this->pdo->prepare('SELECT `id`, `name`, `is_unskilled_only` FROM `micro_categories` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $categoryId]);
        $category = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$category) {
            $errors[] = t('gigs.err_category_missing');
        } elseif ((int) $category['is_unskilled_only'] !== 1) {
            $errors[] = t('gigs.err_category_skilled');
        } else {
            $childStmt = $this->pdo->prepare('SELECT COUNT(*) FROM `micro_categories` WHERE `parent_id` = :id');
            $childStmt->execute(['id' => $categoryId]);
            if ((int) $childStmt->fetchColumn() > 0) {
                $errors[] = t('gigs.err_category_not_leaf');
            }
        }

        $categoryName = (string) ($category['name'] ?? '');
        $fullText = mb_strtolower($categoryName . ' ' . $description, 'UTF-8');

        foreach ($this->stopWords as $word) {
            if (mb_strpos($fullText, $word, 0, 'UTF-8') !== false) {
                $errors[] = t('gigs.err_stopword', ['word' => $word]);
                break;
            }
        }

        $cleanDescription = trim($description);

        if (mb_strlen($cleanDescription, 'UTF-8') < 10) {
            $errors[] = t('gigs.err_desc_len');
        }

        return [
            'is_valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    public function findStopWord(string $text): ?string
    {
        $fullText = mb_strtolower($text, 'UTF-8');
        foreach ($this->stopWords as $word) {
            if (mb_strpos($fullText, $word, 0, 'UTF-8') !== false) {
                return $word;
            }
        }
        return null;
    }
}
