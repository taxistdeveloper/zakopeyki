<?php

namespace App\Models;

use App\Core\Model;

/**
 * База знаний RAG + сид FAQ для локального AI.
 */
class AiKnowledge extends Model
{
    protected string $table = 'ai_knowledge_base';
    private static bool $ensured = false;

    public function __construct()
    {
        parent::__construct();
        $this->ensureTable();
        $this->seedIfEmpty();
    }

    private function ensureTable(): void
    {
        if (self::$ensured) {
            return;
        }

        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS ai_knowledge_base (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                keywords VARCHAR(512) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                source VARCHAR(32) NOT NULL DEFAULT 'seed',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ai_kb_category (category),
                INDEX idx_ai_kb_active (is_active),
                FULLTEXT KEY ft_ai_knowledge_search (title, content, keywords)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::$ensured = true;
    }

    private function seedIfEmpty(): void
    {
        $count = (int) $this->db->query('SELECT COUNT(*) FROM ai_knowledge_base')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $articles = [
            [
                'escrow',
                'Как работает Безопасная сделка (Escrow)',
                'Безопасная сделка на zakopeyki.kz защищает деньги покупателя и продавца. При покупке средства покупателя задерживаются на специальном эскроу-счете. Продавец отправляет товар. После получения и проверки товара покупателем в пункте выдачи или при курьере, покупатель подтверждает приемку в приложении, и средства автоматически переводятся на карту продавца.',
                'эскроу, безопасная сделка, гарантия, оплата, задержка денег',
            ],
            [
                'escrow',
                'Отмена заказа и возврат средств',
                'Покупатель может отменить заказ до того момента, пока продавец не передал товар в службу доставки. В этом случае деньги разблокируются на карте покупателя автоматически в течение 15-60 минут (до 3 рабочих дней в зависимости от банка). Если товар уже отправлен, отмена возможна только через процедуру возврата или спор.',
                'возврат, отмена заказа, вернуть деньги, отменить покупку',
            ],
            [
                'escrow',
                'Как открыть спор по Безопасной сделке',
                'Если товар не соответствует описанию, поврежден или неполный комплект — откройте спор в карточке заказа кнопкой «Открыть спор», приложите фото/видео. Средства на эскроу замораживаются. Продавцу даётся 24–48 часов на ответ. Арбитр L2 рассматривает только переписку и доказательства внутри zakopeyki.kz. Договоренности в сторонних мессенджерах не учитываются.',
                'спор, арбитраж, жалоба, несоответствие, брак',
            ],
            [
                'moderation',
                'Причины блокировки объявлений',
                'Объявление может быть отклонено модератором по следующим причинам: 1) Указание контактных данных в описании или на фото (телефоны, ссылки); 2) Запрещенные товары (оружие, лекарства, контрафакт); 3) Некорректная категория или завышенная/заниженная цена; 4) Дублирование одного и того же объявления. Исправьте объявление в разделе «Мои объявления» → «Отклоненные» и сохраните — оно уйдёт на повторную проверку.',
                'модерация, заблокировали, отклонили объявление, запрещенные товары',
            ],
            [
                'delivery',
                'Сроки и способы доставки',
                'На zakopeyki.kz доступна курьерская доставка по городам Казахстана и доставка до пунктов выдачи (ПВЗ). Средний срок доставки между регионами составляет от 2 до 5 рабочих дней. Отслеживать статус доставки можно по трек-номеру в личном кабинете в разделе «Мои покупки» / «Мои заказы».',
                'доставка, курьер, пвз, трек номер, сколько идет посылка',
            ],
            [
                'security',
                'Защита от мошенников (Anti-Scam)',
                'Никогда не переходите по ссылкам для «оплаты курьера» или «получения средств», отправленным в WhatsApp или Telegram. Все транзакции, подтверждения и чаты проходят СТРОГО внутри zakopeyki.kz. Сотрудники поддержки никогда не просят CVC/CVV код карты или SMS-коды. При подозрении на мошенничество сразу попросите оператора и пришлите скриншот переписки.',
                'мошенники, фишинг, ватсап, whatsapp, развод, предоплата',
            ],
            [
                'seller',
                'Как получить деньги за проданный товар',
                'Средства покупателя удерживаются на эскроу-счете zakopeyki.kz. Они автоматически отправятся на вашу карту после того, как покупатель подтвердит получение товара кнопкой «Товар получен». Обычно зачисление занимает от нескольких минут до 24 часов в зависимости от банка. Привяжите карту в профиле / кошельке заранее.',
                'выплата, деньги продавцу, зачисление, карта, кошелек',
            ],
            [
                'seller',
                'Как разместить объявление',
                'Чтобы продать товар: войдите в аккаунт, откройте профиль и создайте объявление. Добавьте качественные фото, точное описание, цену и категорию. Не указывайте телефон и сторонние ссылки в тексте — это приведёт к отклонению модерацией. После сохранения объявление проходит проверку.',
                'продать, разместить, создать объявление, как продать',
            ],
            [
                'account',
                'Восстановление доступа',
                'Если забыли пароль — используйте «Забыли пароль?» на странице входа: на email придёт ссылка для сброса. Если потеряли доступ к номеру телефона или Google-аккаунту — напишите на support@zakopeyki.kz с подтверждением личности или попросите живого оператора в чате.',
                'пароль, доступ, восстановление, забыл пароль, аккаунт',
            ],
            [
                'account',
                'Верификация профиля',
                'Для повышения доверия подтвердите телефон и заполните профиль. При запросе документов служба поддержки может попросить фото документа для верификации продавца. Данные используются только для проверки и не публикуются.',
                'верификация, документы, подтверждение профиля',
            ],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO ai_knowledge_base (category, title, content, keywords, is_active, source)
             VALUES (?, ?, ?, ?, 1, \'seed\')'
        );

        foreach ($articles as $row) {
            $stmt->execute($row);
        }
    }

    /** @return list<array{id:int,category:string,title:string,content:string,relevance?:float}> */
    public function search(string $query, int $limit = 3): array
    {
        $cleaned = $this->sanitizeQuery($query);
        if ($cleaned === '') {
            return [];
        }

        $limit = max(1, min(10, $limit));
        $boolean = $this->buildBooleanQuery($cleaned);

        try {
            $sql = "SELECT id, category, title, content,
                           MATCH(title, content, keywords) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance
                    FROM ai_knowledge_base
                    WHERE is_active = 1
                      AND MATCH(title, content, keywords) AGAINST(? IN BOOLEAN MODE)
                    ORDER BY relevance DESC
                    LIMIT {$limit}";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$cleaned, $boolean]);
            $rows = $stmt->fetchAll() ?: [];
            if ($rows) {
                return $rows;
            }
        } catch (\Throwable $e) {
            // FULLTEXT недоступен — fallback ниже
        }

        $like = '%' . $cleaned . '%';
        $sql = "SELECT id, category, title, content, 0.50 AS relevance
                FROM ai_knowledge_base
                WHERE is_active = 1
                  AND (title LIKE ? OR keywords LIKE ? OR content LIKE ?)
                LIMIT {$limit}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll() ?: [];
    }

    public function addArticle(string $category, string $title, string $content, string $keywords, string $source = 'auto_learned'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_knowledge_base (category, title, content, keywords, is_active, source)
             VALUES (?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([$category, $title, $content, $keywords, $source]);
        return (int) $this->db->lastInsertId();
    }

    private function sanitizeQuery(string $query): string
    {
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $query) ?? '');
    }

    private function buildBooleanQuery(string $query): string
    {
        $words = preg_split('/\s+/u', $query) ?: [];
        $words = array_filter($words, static fn ($w) => mb_strlen($w, 'UTF-8') >= 3);
        if (!$words) {
            return $query . '*';
        }
        return implode(' ', array_map(static fn ($w) => '+' . $w . '*', $words));
    }

    public function pdo(): \PDO
    {
        return $this->db;
    }
}
