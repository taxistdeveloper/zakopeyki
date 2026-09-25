<?php

declare(strict_types=1);

/**
 * Демо-наполнение площадки: аккаунты, товары, аукционы, сторис, биржа, отзывы.
 * Идемпотентно — повторный запуск не плодит дубли, обновляет сторис и сроки лотов.
 *
 *   /Applications/MAMP/bin/php/php8.4.17/bin/php bin/seed_demo.php
 *
 * Пароль всех демо-аккаунтов: demo1234
 */

require __DIR__ . '/bootstrap.php';

use App\Helpers\ProductHelper;
use App\Models\Bonus;
use App\Models\BusinessPackage;
use App\Models\BusinessSubscription;
use App\Models\Chat;
use App\Models\DigitalProduct;
use App\Models\Favorite;
use App\Models\Follow;
use App\Models\MicroTask;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Review;
use App\Models\Story;
use App\Models\Stream;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AMLService;

final class DemoSeeder
{
    public const PASSWORD = 'demo1234';

    private PDO $db;
    private User $users;
    private Product $products;
    private Wallet $wallet;
    private Story $stories;
    private Follow $follows;
    private Favorite $favorites;
    private Chat $chat;
    private Notification $notes;
    private Review $reviews;
    private DigitalProduct $digital;

    /** @var array<string,int> */
    private array $ids = [];

    /** @var array<string,true> */
    private array $created = [];

    public function __construct()
    {
        $this->users = new User();
        $this->products = new Product();
        $this->wallet = new Wallet();
        $this->stories = new Story();
        $this->follows = new Follow();
        $this->favorites = new Favorite();
        $this->chat = new Chat();
        $this->notes = new Notification();
        $this->reviews = new Review();
        $this->digital = new DigitalProduct();
        $this->db = \App\Core\Database::connect();
    }

    public function run(): void
    {
        $this->ensureDirs();
        echo "=== Zakopeyki demo seed ===\n";

        $this->seedUsers();
        $this->seedFollows();
        $this->seedCatalog();
        $this->seedFavorites();
        $this->seedAuctions();
        $this->seedCourses();
        $this->seedGigs();
        $this->seedStories();
        $this->seedStreams();
        $this->seedDealsAndReviews();
        $this->seedChats();

        $this->printSummary();
    }

    private function seedUsers(): void
    {
        echo "Users...\n";

        foreach ($this->userCatalog() as $key => $row) {
            $id = $this->ensureUser($row);
            $this->ids[$key] = $id;
            $this->ensureWallet($id, (int) ($row['wallet'] ?? 250000));
            if (!empty($row['bonus'])) {
                (new Bonus())->awardRegistration($id);
            }
        }

        $shop = $this->ids['shop'];
        $fashion = $this->ids['fashion'];
        $this->users->promoteToBusiness($shop, [
            'business_entity_type' => 'ip',
            'business_name' => 'TechMarket Караганда',
            'bin' => '850101350123',
        ]);
        $this->users->promoteToBusiness($fashion, [
            'business_entity_type' => 'ip',
            'business_name' => 'Style Qazaq',
            'bin' => '900615450987',
        ]);
        $this->users->enableCourseAuthor($this->ids['teacher']);
        $this->ensureBusinessSub($shop);
        $this->ensureBusinessSub($fashion);
    }

    private function seedFollows(): void
    {
        echo "Follows...\n";
        $pairs = [
            ['buyer', 'shop'],
            ['buyer', 'fashion'],
            ['buyer', 'seller'],
            ['bidder', 'shop'],
            ['bidder', 'seller'],
            ['helper', 'master'],
            ['neighbor', 'seller'],
            ['seller', 'shop'],
            ['teacher', 'fashion'],
            ['master', 'neighbor'],
        ];
        foreach ($pairs as [$a, $b]) {
            if (!$this->follows->isFollowing($this->ids[$a], $this->ids[$b])) {
                $this->follows->toggle($this->ids[$a], $this->ids[$b]);
            }
        }
    }

    private function seedFavorites(): void
    {
        echo "Favorites...\n";
        $favStmt = $this->db->query(
            "SELECT id, user_id FROM products
             WHERE status = 'active' AND type IN ('new','used','auction')
               AND user_id IN (" . $this->demoUserIdList() . ")
             ORDER BY view_count DESC LIMIT 12"
        );
        $favRows = $favStmt->fetchAll();
        foreach (['buyer', 'bidder', 'helper'] as $key) {
            $uid = $this->ids[$key];
            foreach ($favRows as $row) {
                $pid = (int) $row['id'];
                if ((int) $row['user_id'] === $uid) {
                    continue;
                }
                if (!$this->favorites->isFavorite($uid, $pid)) {
                    $this->favorites->toggle($uid, $pid);
                }
            }
        }
    }

    private function seedCatalog(): void
    {
        echo "Catalog...\n";
        foreach ($this->productCatalog() as $item) {
            $this->ensureProduct($item);
        }
    }

    private function seedAuctions(): void
    {
        echo "Auction bids...\n";
        $rows = $this->db->query(
            "SELECT id, user_id, price, bid_step, current_bid
             FROM products
             WHERE type = 'auction' AND status = 'active'
               AND user_id IN (" . $this->demoUserIdList() . ")"
        )->fetchAll();

        $bidders = [$this->ids['bidder'], $this->ids['buyer'], $this->ids['neighbor']];
        foreach ($rows as $i => $row) {
            $pid = (int) $row['id'];
            $owner = (int) $row['user_id'];
            $step = max(500, (int) $row['bid_step']);
            $amount = max((int) $row['current_bid'], (int) $row['price']);
            $used = 0;
            foreach ($bidders as $uid) {
                if ($uid === $owner) {
                    continue;
                }
                $amount += $step;
                $this->ensureBid($pid, $uid, $amount);
                $used++;
                if ($used >= 2 + ($i % 2)) {
                    break;
                }
            }
            $this->db->prepare(
                'UPDATE products SET current_bid = ?, last_bid_at = NOW() - INTERVAL 20 MINUTE WHERE id = ?'
            )->execute([$amount, $pid]);
        }
    }

    private function seedCourses(): void
    {
        echo "Courses...\n";
        $stmt = $this->db->prepare(
            "SELECT * FROM products WHERE type = 'course' AND user_id = ?"
        );
        $stmt->execute([$this->ids['teacher']]);
        foreach ($stmt->fetchAll() as $product) {
            $dp = $this->digital->syncFromCourseProduct($product);
            $dpId = (int) ($dp['id'] ?? 0);
            if ($dpId < 1) {
                continue;
            }
            $lessons = [
                ['Excel с нуля', ['Интерфейс и горячие клавиши', 'Формулы SUM/IF/VLOOKUP', 'Сводные таблицы', 'Дашборд для отчёта']],
                ['SMM для бизнеса', ['Аватар и оффер', 'Контент-план на месяц', 'Таргет в Instagram', 'Разбор рекламного кабинета']],
                ['Английский для работы', ['Самопрезентация', 'Письма клиентам', 'Созвоны без паники', 'Лексика переговоров']],
            ];
            $title = (string) $product['title'];
            $pack = $lessons[0];
            foreach ($lessons as $candidate) {
                if (str_contains($title, explode(' ', $candidate[0])[0])) {
                    $pack = $candidate;
                    break;
                }
            }
            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM digital_lessons WHERE digital_product_id = ?');
            $countStmt->execute([$dpId]);
            if ((int) $countStmt->fetchColumn() > 0) {
                continue;
            }
            foreach ($pack[1] as $i => $lessonTitle) {
                $this->digital->saveLesson($dpId, [
                    'sort_order' => $i + 1,
                    'kind' => 'text',
                    'title' => $lessonTitle,
                    'body' => $lessonTitle . '. Практическое занятие с примерами из Караганды и Казахстана.',
                    'is_preview' => $i === 0 ? 1 : 0,
                ]);
            }
        }
    }

    private function seedGigs(): void
    {
        echo "Gigs...\n";
        (new MicroTask())->ensureSchema();

        $leaf = $this->db->query(
            "SELECT id, name FROM micro_categories
             WHERE id NOT IN (SELECT DISTINCT parent_id FROM micro_categories WHERE parent_id IS NOT NULL)
             ORDER BY id"
        )->fetchAll();
        $byName = [];
        foreach ($leaf as $row) {
            $byName[(string) $row['name']] = (int) $row['id'];
        }

        $gigs = [
            ['helper', 'Разгрузка газели', 'Нужно разгрузить газель со стройматериалами у дома на Юго-Востоке. 2–3 часа, есть тележка.', 'Караганда, Юго-Восток, ул. Ермекова 32', 12000],
            ['buyer', 'Вынос дивана', 'Вынести старый угловой диван с 4 этажа без лифта и отнести к контейнерам.', 'Караганда, Майкудук, 3 мкр, д. 12', 8000],
            ['neighbor', 'Раздача листовок', 'Раздать 500 листовок у ТРЦ City Mall в субботу с 12:00 до 16:00.', 'Караганда, пр. Бухар-жырау 49', 6000],
            ['shop', 'Срочный курьер', 'Забрать коробку с сервисного центра и привезти в магазин на Бухар-жырау. Сегодня до 18:00.', 'Караганда, пр. Бухар-жырау 68', 4500],
            ['fashion', 'Мытьё окон', 'Помыть окна в шоуруме (витрина + 6 окон). Моющие средства есть на месте.', 'Караганда, ул. Гоголя 38', 9000],
            ['teacher', 'Поддерживающая уборка', 'Уборка двухкомнатной квартиры после гостей: полы, кухня, санузел.', 'Караганда, пр. Нуркена Абдирова 20', 10000],
            ['master', 'Погрузка мебели', 'Погрузить шкаф и комод в газель. Шкаф разобран, комод целый.', 'Караганда, Пришахтинск, ул. Шахтёров 7', 7000],
            ['seller', 'Прополка грядок', 'Прополоть 4 грядки на даче, около 3 часов работы.', 'Караганда, дачный массив Михайловка', 5000],
        ];

        foreach ($gigs as $gig) {
            [$ownerKey, $catName, $desc, $address, $price] = $gig;
            $catId = $byName[$catName] ?? (int) ($leaf[0]['id'] ?? 0);
            if ($catId < 1) {
                continue;
            }
            $this->ensureGig($this->ids[$ownerKey], $catId, $catName, $desc, $address, $price);
        }
    }

    private function seedStories(): void
    {
        echo "Stories...\n";
        $packs = [
            'shop' => [
                ['🔥 Сегодня −15% на наушники Sony', '#ef4444', '🔥', 'sony-wh-1000xm5'],
                ['Новый iPhone 15 уже в TechMarket', '#2563eb', '📱', 'iphone-15-128'],
            ],
            'fashion' => [
                ['Летняя капсула Style Qazaq', '#db2777', '👗', 'plate-summer'],
                ['Кроссовки Nike Dunk — последние размеры', '#111827', '👟', 'nike-dunk'],
            ],
            'seller' => [
                ['Отдам велик Trek — торг уместен', '#f59e0b', '🚲', 'trek-marlin'],
                ['Гитара Yamaha в идеале', '#7c3aed', '🎸', 'yamaha-f310'],
            ],
            'master' => [
                ['Чиню ПК и ноутбуки на выезд', '#059669', '🛠️', 'pc-repair'],
            ],
            'neighbor' => [
                ['Книги даром — забирайте сегодня', '#0ea5e9', '📚', 'free-books'],
            ],
            'teacher' => [
                ['Новый поток Excel стартует в понедельник', '#8b5cf6', '🎓', 'excel-course'],
            ],
            'buyer' => [
                ['Ищу хороший ноутбук в обмен на iPad', '#6366f1', '🔄', 'ipad-exchange'],
            ],
        ];

        foreach ($packs as $key => $stories) {
            $uid = $this->ids[$key] ?? 0;
            if ($uid < 1) {
                continue;
            }
            $active = $this->stories->byUser($uid);
            if ($active !== []) {
                continue;
            }
            foreach ($stories as $story) {
                [$caption, $color, $emoji, $slug] = $story;
                $image = $this->storyImage($slug, $caption, $color);
                $this->stories->create([
                    'user_id' => $uid,
                    'caption' => $caption,
                    'image' => $image,
                    'bg_color' => $color,
                    'emoji' => $emoji,
                ]);
                $this->created['story'] = true;
            }
        }
    }

    private function seedStreams(): void
    {
        echo "Streams...\n";
        $model = new Stream();
        $rows = [
            [
                'owner' => 'shop', 'slug' => 'live-techmarket', 'emoji' => '📱', 'color' => '#dc2626',
                'title' => 'TechMarket Live: iPhone 15 и Sony',
                'product' => 'iphone-15-128',
                'likes' => 86,
                'comments' => [
                    ['buyer', 'Айгерим', 'Есть чехол в комплекте?', 0],
                    ['shop', 'Алихан', 'Да, прозрачный + гарантия 12 мес.', 1],
                    ['bidder', 'Тимур', 'Можно самовывоз сегодня?', 0],
                    ['shop', 'Алихан', 'До 20:00 на Бухар-жырау 68.', 1],
                ],
                'viewers' => 14,
            ],
            [
                'owner' => 'fashion', 'slug' => 'live-styleqazaq', 'emoji' => '👗', 'color' => '#db2777',
                'title' => 'Style Qazaq: примерка Dunk и платьев',
                'product' => 'nike-dunk',
                'likes' => 64,
                'comments' => [
                    ['teacher', 'Асель', '42 есть в наличии?', 0],
                    ['fashion', 'Камила', '42 и 43 — да, 44 последний.', 1],
                    ['buyer', 'Айгерим', 'Платье в молочном тоже покажите!', 0],
                ],
                'viewers' => 11,
            ],
            [
                'owner' => 'seller', 'slug' => 'live-bike', 'emoji' => '🚲', 'color' => '#d97706',
                'title' => 'Велик Trek и гитара — торг в эфире',
                'product' => 'trek-marlin',
                'likes' => 41,
                'comments' => [
                    ['bidder', 'Тимур', 'По велику 100 тысяч возьмёте?', 0],
                    ['seller', 'Данияр', '105 и ваш, состояние после ТО.', 1],
                    ['neighbor', 'Нурлан', 'Гитару ещё не забрали?', 0],
                ],
                'viewers' => 8,
            ],
            [
                'owner' => 'master', 'slug' => 'live-repair', 'emoji' => '🛠️', 'color' => '#059669',
                'title' => 'Чиню ноут в прямом эфире',
                'product' => 'pc-repair',
                'likes' => 29,
                'comments' => [
                    ['neighbor', 'Нурлан', 'Сколько чистка с термопастой?', 0],
                    ['master', 'Ерлан', '7 000 ₸, выезд по городу сегодня.', 1],
                    ['helper', 'Мадина', 'А матрицу меняете?', 0],
                ],
                'viewers' => 6,
            ],
            [
                'owner' => 'teacher', 'slug' => 'live-excel', 'emoji' => '📊', 'color' => '#7c3aed',
                'title' => 'Excel Live: сводные за 20 минут',
                'product' => 'excel-course',
                'likes' => 53,
                'comments' => [
                    ['buyer', 'Айгерим', 'Запись останется?', 0],
                    ['teacher', 'Асель', 'Да, после эфира открою в курсе.', 1],
                    ['fashion', 'Камила', 'Для ИП тоже подойдёт?', 0],
                ],
                'viewers' => 19,
            ],
        ];

        foreach ($rows as $row) {
            $uid = $this->ids[$row['owner']] ?? 0;
            if ($uid < 1) {
                continue;
            }
            $find = $this->db->prepare(
                'SELECT id FROM streams WHERE user_id = ? AND title = ? LIMIT 1'
            );
            $find->execute([$uid, $row['title']]);
            $id = (int) $find->fetchColumn();
            $cover = $this->streamCover($row['slug'], $row['title'], $row['emoji'], $row['color']);
            $productId = $this->productIdBySlugOwner($row['product'], $uid);
            $setup = json_encode(['demo' => true, 'featured_product_id' => $productId ?: null], JSON_UNESCAPED_UNICODE);

            if ($id > 0) {
                $this->db->prepare(
                    'UPDATE streams SET is_live = 1, is_demo = 1, last_heartbeat = NOW(),
                     cover = ?, featured_product_id = ?, likes_count = ?, live_setup = ?,
                     video_file = NULL, video_url = NULL
                     WHERE id = ?'
                )->execute([$cover, $productId ?: null, $row['likes'], $setup, $id]);
            } else {
                $id = $model->create([
                    'user_id' => $uid,
                    'title' => $row['title'],
                    'description' => 'Демо-эфир: смотрите витрину и пишите в чат.',
                    'cover' => $cover,
                    'is_live' => true,
                    'is_demo' => true,
                    'featured_product_id' => $productId ?: null,
                    'live_setup' => ['demo' => true, 'featured_product_id' => $productId ?: null],
                ]);
                $this->db->prepare('UPDATE streams SET likes_count = ? WHERE id = ?')->execute([$row['likes'], $id]);
            }

            $hasComments = $this->db->prepare('SELECT COUNT(*) FROM stream_comments WHERE stream_id = ?');
            $hasComments->execute([$id]);
            if ((int) $hasComments->fetchColumn() === 0) {
                foreach ($row['comments'] as $comment) {
                    [$who, $name, $body, $isHost] = $comment;
                    $model->addComment($id, $this->ids[$who] ?? null, $name, $body, (bool) $isHost);
                }
            }

            for ($i = 1; $i <= (int) $row['viewers']; $i++) {
                $key = 'demo-viewer-' . $id . '-' . $i;
                $this->db->prepare(
                    'INSERT INTO stream_viewers (stream_id, viewer_key, last_seen)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE last_seen = NOW()'
                )->execute([$id, $key]);
            }
        }
        $model->keepDemoAlive();
    }

    private function seedDealsAndReviews(): void
    {
        echo "Deals / reviews...\n";
        $deals = [
            [
                'seller' => 'shop',
                'buyer' => 'buyer',
                'title' => 'Демо: проданы наушники JBL Tune 510BT',
                'category' => 'Электроника и бытовая техника / Смартфоны и аксессуары',
                'price' => 28000,
                'slug' => 'jbl-sold',
                'rating' => 5,
                'buyer_text' => 'Наушники новые, упаковка целая, отправили в тот же день. Рекомендую TechMarket.',
                'seller_text' => 'Покупатель быстро подтвердила получение, без лишних вопросов.',
            ],
            [
                'seller' => 'seller',
                'buyer' => 'bidder',
                'title' => 'Демо: продан пылесос Xiaomi G10',
                'category' => 'Электроника и бытовая техника / Мелкая бытовая техника',
                'price' => 45000,
                'slug' => 'xiaomi-g10-sold',
                'rating' => 5,
                'buyer_text' => 'Как в описании, все насадки на месте. Сделка через эскроу прошла спокойно.',
                'seller_text' => 'Тимур адекватный, осмотрел и подтвердил сразу.',
            ],
            [
                'seller' => 'fashion',
                'buyer' => 'teacher',
                'title' => 'Демо: продано пальто зимнее',
                'category' => 'Одежда, обувь и аксессуары / Женская одежда и обувь',
                'price' => 39000,
                'slug' => 'coat-sold',
                'rating' => 4,
                'buyer_text' => 'Пальто красивое, размер сел. Доставка чуть задержалась, но всё ок.',
                'seller_text' => 'Приятная клиентка, спасибо за отзыв.',
            ],
            [
                'seller' => 'master',
                'buyer' => 'neighbor',
                'title' => 'Демо: услуга чистки ноутбука',
                'type' => 'service',
                'category' => 'Разное',
                'price' => 7000,
                'slug' => 'laptop-clean-sold',
                'rating' => 5,
                'buyer_text' => 'Ноут больше не греется, сделали в тот же день на выезде.',
                'seller_text' => 'Клиент встретил вовремя, работу принял сразу.',
            ],
        ];

        foreach ($deals as $deal) {
            $productId = $this->ensureProduct([
                'owner' => $deal['seller'],
                'type' => $deal['type'] ?? 'used',
                'category' => $deal['category'],
                'title' => $deal['title'],
                'description' => 'Завершённая демо-сделка. Товар передан покупателю.',
                'price' => $deal['price'],
                'location' => 'Караганда',
                'slug' => $deal['slug'],
                'status' => 'sold',
                'hours_ago' => 80,
            ]);
            $orderId = $this->ensureCompletedOrder(
                $productId,
                $this->ids[$deal['buyer']],
                $this->ids[$deal['seller']],
                (int) $deal['price']
            );
            if ($orderId > 0) {
                $this->reviews->createForOrder($orderId, $this->ids[$deal['buyer']], (int) $deal['rating'], $deal['buyer_text']);
                $this->reviews->createForOrder($orderId, $this->ids[$deal['seller']], 5, $deal['seller_text']);
            }
        }
    }

    private function seedChats(): void
    {
        echo "Chats...\n";
        $threads = [
            ['buyer', 'shop', 'iphone-15-128', 'Здравствуйте! iPhone 15 ещё в наличии? Можно самовывоз сегодня?', 'Да, в наличии. Ждём до 20:00 на Бухар-жырау 68.'],
            ['bidder', 'seller', 'trek-marlin', 'По велику торг уместен? Готов 95 000.', 'Могу 105 000, состояние отличное, обслуживал в веломастерской.'],
            ['neighbor', 'master', 'pc-repair', 'Ноут греется и выключается. Сможете завтра к вечеру?', 'Да, приеду после 18:00, чистка + термопаста 7 000 ₸.'],
        ];
        foreach ($threads as [$a, $b, $slug, $msg1, $msg2]) {
            $productId = $this->productIdBySlugOwner($slug, $this->ids[$b])
                ?: $this->productIdBySlugOwner($slug, $this->ids[$a]);
            $started = $this->chat->start($this->ids[$a], $this->ids[$b], $productId ?: 0);
            if (empty($started['ok'])) {
                continue;
            }
            $cid = (int) $started['conversation_id'];
            $st = $this->db->prepare('SELECT COUNT(*) FROM chat_messages WHERE conversation_id = ?');
            $st->execute([$cid]);
            if ((int) $st->fetchColumn() > 0) {
                continue;
            }
            $this->chat->send($cid, $this->ids[$a], $msg1);
            $this->chat->send($cid, $this->ids[$b], $msg2);
        }

        foreach (['buyer', 'seller', 'shop'] as $key) {
            $this->notes->createFor($this->ids[$key], 'Демо-аккаунт готов: пароль demo1234. Можно смотреть каталог, сторис и аукционы.');
        }
    }

    /** @param array<string,mixed> $row */
    private function ensureUser(array $row): int
    {
        $existing = $this->users->findByEmail($row['email']);
        if ($existing) {
            $id = (int) $existing['id'];
        } else {
            $id = $this->users->create([
                'name' => $row['name'],
                'email' => $row['email'],
                'password' => self::PASSWORD,
                'phone' => $row['phone'],
                'login' => $row['login'],
            ]);
            $this->created['user'] = true;
        }

        $avatar = $this->avatarImage($row['login'], $row['name'], $row['color']);
        $this->db->prepare(
            'UPDATE users SET
                name = ?, first_name = ?, last_name = ?, login = ?, phone = ?, bio = ?,
                avatar = ?, avatar_file = ?, site_access = 1,
                ship_city = ?, ship_region = ?, ship_country = \'KZ\',
                ship_contact_name = ?, ship_phone = ?
             WHERE id = ?'
        )->execute([
            $row['name'],
            $row['first'],
            $row['last'],
            $row['login'],
            $row['phone'],
            $row['bio'],
            mb_strtoupper(mb_substr($row['first'], 0, 1)),
            $avatar,
            $row['city'],
            'Карагандинская область',
            $row['name'],
            $row['phone'],
            $id,
        ]);

        if (!$this->users->verifyPassword($id, self::PASSWORD)) {
            $this->users->updatePassword($id, self::PASSWORD);
        }

        try {
            $this->users->setAmlStatus($id, AMLService::STATUS_CLEAR, $row['iin']);
        } catch (Throwable $e) {
            // IIN может быть занят
        }

        return $id;
    }

    /** @param array<string,mixed> $item */
    private function ensureProduct(array $item): int
    {
        $userId = $this->ids[$item['owner']];
        $title = (string) $item['title'];
        $find = $this->db->prepare('SELECT id FROM products WHERE user_id = ? AND title = ? LIMIT 1');
        $find->execute([$userId, $title]);
        $existingId = (int) $find->fetchColumn();

        $type = (string) $item['type'];
        $category = ProductHelper::normalizeCategory($item['category'] ?? 'Разное', $type);
        if ($type === 'course') {
            $category = (string) ($item['category'] ?? 'Запись');
        }
        $image = $this->productImage((string) $item['slug'], $title, $type, (string) ($item['emoji'] ?? '🛒'));
        $hours = (int) ($item['hours_ago'] ?? 6);
        $whatsapp = preg_replace('/\D/', '', (string) ($this->userCatalog()[$item['owner']]['phone'] ?? '77070000000'));

        if ($existingId > 0) {
            if ($type === 'auction') {
                $this->refreshAuctionWindow($existingId, $item);
            }
            $this->db->prepare(
                'UPDATE products SET image = COALESCE(image, ?), images = COALESCE(images, ?), view_count = GREATEST(view_count, ?) WHERE id = ?'
            )->execute([$image, json_encode([$image]), (int) ($item['views'] ?? 40), $existingId]);
            return $existingId;
        }

        $payload = [
            'user_id' => $userId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'description' => $item['description'],
            'price' => $item['price'] ?? 0,
            'quantity' => $item['quantity'] ?? 1,
            'exchange_for' => $item['exchange_for'] ?? null,
            'price_label' => $item['price_label'] ?? null,
            'bid_step' => $item['bid_step'] ?? 1000,
            'auction_kind' => $item['auction_kind'] ?? 'english',
            'auction_reserve' => $item['auction_reserve'] ?? null,
            'auction_buy_now' => $item['auction_buy_now'] ?? null,
            'auction_min_price' => $item['auction_min_price'] ?? null,
            'auction_step_interval' => $item['auction_step_interval'] ?? null,
            'auction_start_at' => $item['auction_start_at'] ?? null,
            'auction_end_at' => $item['auction_end_at'] ?? null,
            'location' => $item['location'] ?? 'Караганда',
            'whatsapp' => $whatsapp,
            'image' => $image,
            'images' => [$image],
        ];
        if ($type === 'auction') {
            $payload['auction_start_at'] = date('Y-m-d H:i:s', strtotime('-2 hours'));
            $payload['auction_end_at'] = $item['auction_end_at'] ?? date('Y-m-d H:i:s', strtotime('+' . ((int) ($item['ends_in_hours'] ?? 18)) . ' hours'));
        }

        $id = $this->products->create($payload);
        $status = (string) ($item['status'] ?? 'active');
        $this->db->prepare(
            'UPDATE products SET status = ?, view_count = ?, created_at = DATE_SUB(NOW(), INTERVAL ? HOUR) WHERE id = ?'
        )->execute([$status, (int) ($item['views'] ?? random_int(24, 640)), $hours, $id]);
        $this->created['product'] = true;
        return $id;
    }

    /** @param array<string,mixed> $item */
    private function refreshAuctionWindow(int $id, array $item): void
    {
        $end = $item['auction_end_at'] ?? date('Y-m-d H:i:s', strtotime('+' . ((int) ($item['ends_in_hours'] ?? 18)) . ' hours'));
        $this->db->prepare(
            "UPDATE products SET auction_end_at = ?, status = 'active'
             WHERE id = ? AND type = 'auction'"
        )->execute([$end, $id]);
    }

    private function ensureBid(int $productId, int $userId, int $amount): void
    {
        $st = $this->db->prepare('SELECT id FROM bids WHERE product_id = ? AND user_id = ? LIMIT 1');
        $st->execute([$productId, $userId]);
        if ($st->fetchColumn()) {
            return;
        }
        $this->products->createBid($productId, $userId, $amount);
    }

    private function ensureGig(int $customerId, int $categoryId, string $title, string $desc, string $address, int $price): void
    {
        $st = $this->db->prepare(
            'SELECT id FROM micro_tasks WHERE customer_id = ? AND description = ? LIMIT 1'
        );
        $st->execute([$customerId, $desc]);
        $id = (int) $st->fetchColumn();
        $expires = date('Y-m-d H:i:s', strtotime('+5 days'));
        $image = $this->productImage('gig-' . md5($desc), $title, 'gig', '🧰');
        if ($id > 0) {
            $this->db->prepare(
                "UPDATE micro_tasks SET status = 'open', expires_at = ?, title = ?, category_id = ?,
                 address = ?, initial_price = ?, image = COALESCE(image, ?)
                 WHERE id = ?"
            )->execute([$expires, $title, $categoryId, $address, $price, $image, $id]);
            return;
        }
        $pin = (string) random_int(1000, 9999);
        $this->db->prepare(
            "INSERT INTO micro_tasks
                (customer_id, category_id, title, description, address, initial_price, completion_pin, status, expires_at, image)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)"
        )->execute([$customerId, $categoryId, $title, $desc, $address, $price, $pin, $expires, $image]);
        $this->created['gig'] = true;
    }

    private function ensureCompletedOrder(int $productId, int $buyerId, int $sellerId, int $amount): int
    {
        $st = $this->db->prepare(
            "SELECT id FROM orders WHERE product_id = ? AND buyer_id = ? AND seller_id = ? AND status = 'completed' LIMIT 1"
        );
        $st->execute([$productId, $buyerId, $sellerId]);
        $id = (int) $st->fetchColumn();
        if ($id > 0) {
            return $id;
        }
        $this->db->prepare(
            "INSERT INTO orders
                (product_id, buyer_id, seller_id, amount, quantity, payment_method, delivery_method,
                 status, escrow_hold, deal_mode, paid_at, confirmed_at, released_at, created_at)
             VALUES (?, ?, ?, ?, 1, 'wallet', 'kazpost', 'completed', 'released', 'escrow',
                     NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 6 DAY)"
        )->execute([$productId, $buyerId, $sellerId, $amount]);
        return (int) $this->db->lastInsertId();
    }

    private function ensureWallet(int $userId, int $target): void
    {
        $balance = $this->wallet->balance($userId);
        if ($balance >= $target) {
            return;
        }
        $need = $target - $balance;
        if ($need < 100) {
            return;
        }
        $this->wallet->deposit($userId, min($need, 5_000_000), 'demo', 'demo-seed');
    }

    private function ensureBusinessSub(int $userId): void
    {
        $pkg = (new BusinessPackage())->findBySlug('business-month');
        if (!$pkg) {
            return;
        }
        $subs = new BusinessSubscription();
        $active = $subs->activeForUser($userId);
        if ($active) {
            return;
        }
        $subs->createSubscription([
            'user_id' => $userId,
            'package_id' => (int) $pkg['id'],
            'starts_at' => date('Y-m-d H:i:s'),
            'ends_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'price_paid_kzt' => 0,
            'payment_meta' => 'demo-seed',
        ]);
    }

    private function productIdBySlugOwner(string $slug, int $userId): int
    {
        foreach ($this->productCatalog() as $item) {
            if (($item['slug'] ?? '') === $slug && $this->ids[$item['owner']] === $userId) {
                $st = $this->db->prepare('SELECT id FROM products WHERE user_id = ? AND title = ? LIMIT 1');
                $st->execute([$userId, $item['title']]);
                return (int) $st->fetchColumn();
            }
        }
        return 0;
    }

    private function demoUserIdList(): string
    {
        return implode(',', array_map('intval', array_values($this->ids)));
    }

    private function printSummary(): void
    {
        $in = $this->demoUserIdList();
        $products = (int) $this->db->query("SELECT COUNT(*) FROM products WHERE user_id IN ({$in})")->fetchColumn();
        $auctions = (int) $this->db->query("SELECT COUNT(*) FROM products WHERE type = 'auction' AND user_id IN ({$in}) AND status = 'active'")->fetchColumn();
        $stories = (int) $this->db->query("SELECT COUNT(*) FROM stories WHERE user_id IN ({$in}) AND expires_at > NOW()")->fetchColumn();
        $gigs = (int) $this->db->query("SELECT COUNT(*) FROM micro_tasks WHERE customer_id IN ({$in}) AND status = 'open'")->fetchColumn();

        echo "\nГотово. Демо-контент:\n";
        echo "  аккаунтов: " . count($this->ids) . "\n";
        echo "  товаров: {$products}\n";
        echo "  аукционов: {$auctions}\n";
        echo "  сторис: {$stories}\n";
        $streams = (int) $this->db->query("SELECT COUNT(*) FROM streams WHERE user_id IN ({$in}) AND is_live = 1")->fetchColumn();
        echo "  стримов: {$streams}\n";
        echo "  поручений биржи: {$gigs}\n";
        echo "\nВход (пароль у всех: " . self::PASSWORD . "):\n";
        foreach ($this->userCatalog() as $row) {
            printf("  %-28s  %s  %s\n", $row['email'], $row['login'], $row['name']);
        }
        echo "\nПолезно:\n";
        echo "  buyer@  — покупатель с кошельком\n";
        echo "  shop@   — бизнес TechMarket (новые товары)\n";
        echo "  seller@ — частник (б/у, обмен, аукционы)\n";
        echo "  teacher@ — автор курсов\n";
    }

    private function ensureDirs(): void
    {
        foreach (['products', 'avatars', 'stories', 'streams'] as $dir) {
            $path = dirname(__DIR__) . '/public/uploads/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    private function productImage(string $slug, string $title, string $type, string $emoji): string
    {
        $file = 'demo_' . preg_replace('/[^a-z0-9_-]+/i', '-', $slug) . '.png';
        $path = dirname(__DIR__) . '/public/uploads/products/' . $file;
        if (!is_file($path)) {
            $this->paintCard($path, 800, 600, $title, $emoji, $this->typeColor($type));
        }
        return $file;
    }

    private function storyImage(string $slug, string $caption, string $color): string
    {
        $file = 'demo_story_' . preg_replace('/[^a-z0-9_-]+/i', '-', $slug) . '.png';
        $path = dirname(__DIR__) . '/public/uploads/stories/' . $file;
        if (!is_file($path)) {
            $this->paintCard($path, 720, 1280, $caption, '✨', $color);
        }
        return $file;
    }

    private function streamCover(string $slug, string $title, string $emoji, string $color): string
    {
        $file = 'demo_stream_' . preg_replace('/[^a-z0-9_-]+/i', '-', $slug) . '.png';
        $path = dirname(__DIR__) . '/public/uploads/streams/' . $file;
        if (!is_file($path)) {
            $this->paintCard($path, 720, 1280, $title, $emoji, $color);
        }
        return $file;
    }

    private function avatarImage(string $login, string $name, string $color): string
    {
        $file = 'demo_avatar_' . $login . '.png';
        $path = dirname(__DIR__) . '/public/uploads/avatars/' . $file;
        if (!is_file($path)) {
            $this->paintAvatar($path, mb_strtoupper(mb_substr($name, 0, 1)), $color);
        }
        return $file;
    }

    private function typeColor(string $type): string
    {
        return match ($type) {
            'new' => '#2563eb',
            'used' => '#d97706',
            'auction' => '#dc2626',
            'free' => '#0284c7',
            'exchange' => '#4f46e5',
            'service' => '#059669',
            'course' => '#7c3aed',
            'gig' => '#0f766e',
            default => '#334155',
        };
    }

    private function paintCard(string $path, int $w, int $h, string $title, string $emoji, string $hex): void
    {
        $im = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = $this->hexRgb($hex);
        $bg = imagecolorallocate($im, $r, $g, $b);
        $bg2 = imagecolorallocate($im, min(255, $r + 40), min(255, $g + 30), min(255, $b + 20));
        $white = imagecolorallocate($im, 255, 255, 255);
        $muted = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        imagefilledellipse($im, (int) ($w * 0.85), (int) ($h * 0.15), (int) ($w * 0.7), (int) ($w * 0.7), $bg2);
        $font = $this->font();
        $label = $this->wrap($title, 18);
        if ($font) {
            $size = $h > 800 ? 28 : 22;
            imagettftext($im, $size, 0, 48, (int) ($h * 0.58), $white, $font, $label);
            imagettftext($im, $h > 800 ? 64 : 42, 0, 48, (int) ($h * 0.42), $muted, $font, $emoji);
            imagettftext($im, 13, 0, 48, $h - 40, $white, $font, 'zakopeyki.kz · демо');
        } else {
            imagestring($im, 5, 40, (int) ($h / 2), $title, $white);
        }
        imagepng($im, $path);
        imagedestroy($im);
    }

    private function paintAvatar(string $path, string $letter, string $hex): void
    {
        $im = imagecreatetruecolor(256, 256);
        [$r, $g, $b] = $this->hexRgb($hex);
        imagefilledrectangle($im, 0, 0, 256, 256, imagecolorallocate($im, $r, $g, $b));
        $white = imagecolorallocate($im, 255, 255, 255);
        $font = $this->font();
        if ($font) {
            $box = imagettfbbox(110, 0, $font, $letter);
            $x = (int) ((256 - ($box[2] - $box[0])) / 2 - $box[0]);
            $y = (int) ((256 - ($box[1] - $box[7])) / 2 - $box[7]);
            imagettftext($im, 110, 0, $x, $y, $white, $font, $letter);
        } else {
            imagestring($im, 5, 110, 110, $letter, $white);
        }
        imagepng($im, $path);
        imagedestroy($im);
    }

    private function font(): ?string
    {
        foreach ([
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/Library/Fonts/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Times New Roman.ttf',
        ] as $font) {
            if (is_file($font)) {
                return $font;
            }
        }
        return null;
    }

    /** @return array{0:int,1:int,2:int} */
    private function hexRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function wrap(string $text, int $width): string
    {
        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($try) > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return implode("\n", array_slice($lines, 0, 4));
    }

    /** @return array<string,array<string,mixed>> */
    private function userCatalog(): array
    {
        return [
            'buyer' => [
                'name' => 'Айгерим Нурланова', 'first' => 'Айгерим', 'last' => 'Нурланова',
                'email' => 'demo.buyer@zakopeyki.kz', 'login' => 'demo_buyer',
                'phone' => '77071110001', 'iin' => '900315400001',
                'city' => 'Караганда', 'color' => '#7c3aed', 'wallet' => 420000, 'bonus' => true,
                'bio' => 'Ищу выгодные лоты и б/у технику. Покупаю через безопасную сделку.',
            ],
            'seller' => [
                'name' => 'Данияр Сатпаев', 'first' => 'Данияр', 'last' => 'Сатпаев',
                'email' => 'demo.seller@zakopeyki.kz', 'login' => 'demo_seller',
                'phone' => '77072220002', 'iin' => '850101300002',
                'city' => 'Караганда', 'color' => '#d97706', 'wallet' => 180000, 'bonus' => true,
                'bio' => 'Продаю б/у технику и спорт. Всё проверяю перед встречей.',
            ],
            'shop' => [
                'name' => 'Алихан Сериков', 'first' => 'Алихан', 'last' => 'Сериков',
                'email' => 'demo.shop@zakopeyki.kz', 'login' => 'demo_shop',
                'phone' => '77073330003', 'iin' => '880520300003',
                'city' => 'Караганда', 'color' => '#2563eb', 'wallet' => 800000, 'bonus' => true,
                'bio' => 'ИП TechMarket. Новая техника с гарантией, доставка по городу.',
            ],
            'fashion' => [
                'name' => 'Камила Есенова', 'first' => 'Камила', 'last' => 'Есенова',
                'email' => 'demo.fashion@zakopeyki.kz', 'login' => 'demo_fashion',
                'phone' => '77074440004', 'iin' => '920814400004',
                'city' => 'Караганда', 'color' => '#db2777', 'wallet' => 260000, 'bonus' => true,
                'bio' => 'ИП Style Qazaq. Одежда и обувь — новые коллекции каждую неделю.',
            ],
            'master' => [
                'name' => 'Ерлан Жумабеков', 'first' => 'Ерлан', 'last' => 'Жумабеков',
                'email' => 'demo.master@zakopeyki.kz', 'login' => 'demo_master',
                'phone' => '77075550005', 'iin' => '830411300005',
                'city' => 'Караганда', 'color' => '#059669', 'wallet' => 150000, 'bonus' => true,
                'bio' => 'Мастер на выезд: ПК, сантехника, сборка мебели. Работаю по всему городу.',
            ],
            'teacher' => [
                'name' => 'Асель Каримова', 'first' => 'Асель', 'last' => 'Каримова',
                'email' => 'demo.teacher@zakopeyki.kz', 'login' => 'demo_teacher',
                'phone' => '77076660006', 'iin' => '910203400006',
                'city' => 'Караганда', 'color' => '#8b5cf6', 'wallet' => 210000, 'bonus' => true,
                'bio' => 'Преподаватель и автор курсов. Excel, SMM и английский для работы.',
            ],
            'bidder' => [
                'name' => 'Тимур Оспанов', 'first' => 'Тимур', 'last' => 'Оспанов',
                'email' => 'demo.bidder@zakopeyki.kz', 'login' => 'demo_bidder',
                'phone' => '77077770007', 'iin' => '870909300007',
                'city' => 'Караганда', 'color' => '#dc2626', 'wallet' => 350000, 'bonus' => true,
                'bio' => 'Охотник за лотами. Люблю английские аукционы и редкие вещи.',
            ],
            'helper' => [
                'name' => 'Мадина Бекова', 'first' => 'Мадина', 'last' => 'Бекова',
                'email' => 'demo.helper@zakopeyki.kz', 'login' => 'demo_helper',
                'phone' => '77078880008', 'iin' => '950612400008',
                'city' => 'Караганда', 'color' => '#0f766e', 'wallet' => 90000, 'bonus' => true,
                'bio' => 'Публикую разовые поручения на бирже: разгрузка, курьер, уборка.',
            ],
            'neighbor' => [
                'name' => 'Нурлан Касымов', 'first' => 'Нурлан', 'last' => 'Касымов',
                'email' => 'demo.neighbor@zakopeyki.kz', 'login' => 'demo_neighbor',
                'phone' => '77079990009', 'iin' => '790101300009',
                'city' => 'Караганда', 'color' => '#0284c7', 'wallet' => 70000, 'bonus' => true,
                'bio' => 'Отдаю лишние вещи даром и меняюсь с соседями.',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function productCatalog(): array
    {
        return [
            // --- новые (магазин) ---
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'iphone-15-128', 'emoji' => '📱',
                'category' => 'Электроника и бытовая техника / Смартфоны и аксессуары',
                'title' => 'iPhone 15 128GB Blue, новый, гарантия 12 мес',
                'description' => 'Запечатанный iPhone 15. Чек, гарантия официальная. Самовывоз с Бухар-жырау или доставка по Караганде.',
                'price' => 389000, 'location' => 'Караганда, пр. Бухар-жырау', 'views' => 412, 'hours_ago' => 5],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'galaxy-a55', 'emoji' => '📱',
                'category' => 'Электроника и бытовая техника / Смартфоны и аксессуары',
                'title' => 'Samsung Galaxy A55 256GB Awesome Navy',
                'description' => 'Новый, плёнка на экране. Комплект: зарядка, кабель, чехол в подарок.',
                'price' => 189000, 'location' => 'Караганда', 'views' => 188, 'hours_ago' => 12],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'sony-wh-1000xm5', 'emoji' => '🎧',
                'category' => 'Электроника и бытовая техника / Смартфоны и аксессуары',
                'title' => 'Sony WH-1000XM5 чёрные, новые',
                'description' => 'Флагманские наушники с шумоподавлением. Заводская упаковка, гарантия 1 год.',
                'price' => 145000, 'location' => 'Караганда', 'views' => 267, 'hours_ago' => 8],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'macbook-air-m2', 'emoji' => '💻',
                'category' => 'Электроника и бытовая техника / Компьютерная техника',
                'title' => 'MacBook Air 13" M2 8/256 Midnight',
                'description' => 'Новый ноутбук Apple. Тихий, лёгкий, держит день. Чек и гарантия.',
                'price' => 549000, 'location' => 'Караганда', 'views' => 331, 'hours_ago' => 20],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'ps5-slim', 'emoji' => '🎮',
                'category' => 'Электроника и бытовая техника / ТВ и видео',
                'title' => 'PlayStation 5 Slim + DualSense',
                'description' => 'Новая консоль, пломбы целые. Отдаём с игрой Astro Bot.',
                'price' => 329000, 'location' => 'Караганда', 'views' => 504, 'hours_ago' => 3],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'xiaomi-vacuum', 'emoji' => '🧹',
                'category' => 'Электроника и бытовая техника / Крупная бытовая техника',
                'title' => 'Робот-пылесос Xiaomi S10+',
                'description' => 'Новый, станция самоочистки в комплекте. Доставка по городу бесплатно.',
                'price' => 169000, 'location' => 'Караганда', 'views' => 143, 'hours_ago' => 28],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'ipad-10', 'emoji' => '📲',
                'category' => 'Электроника и бытовая техника / Компьютерная техника',
                'title' => 'iPad 10.9" 64GB Silver, новый',
                'description' => 'Для учёбы и заметок. Можно докупить клавиатуру и Pencil.',
                'price' => 199000, 'location' => 'Караганда', 'views' => 176, 'hours_ago' => 15],
            ['owner' => 'shop', 'type' => 'new', 'slug' => 'jbl-flip6', 'emoji' => '🔊',
                'category' => 'Электроника и бытовая техника / ТВ и видео',
                'title' => 'Колонка JBL Flip 6 Teal',
                'description' => 'Влагозащита IP67, 12 часов музыки. Новая, в коробке.',
                'price' => 42000, 'location' => 'Караганда', 'views' => 98, 'hours_ago' => 40],

            // --- одежда (бизнес) ---
            ['owner' => 'fashion', 'type' => 'new', 'slug' => 'nike-dunk', 'emoji' => '👟',
                'category' => 'Одежда, обувь и аксессуары / Спортивная одежда',
                'title' => 'Nike Dunk Low Panda, 42–44',
                'description' => 'Оригинал, коробка. Размеры 42, 43, 44. Примерка в шоуруме на Гоголя.',
                'price' => 89000, 'location' => 'Караганда, ул. Гоголя', 'views' => 220, 'hours_ago' => 7],
            ['owner' => 'fashion', 'type' => 'new', 'slug' => 'plate-summer', 'emoji' => '👗',
                'category' => 'Одежда, обувь и аксессуары / Женская одежда и обувь',
                'title' => 'Платье летнее льняное, S–M',
                'description' => 'Лён, свободный крой. Цвета: молоко и шалфей. Новое с биркой.',
                'price' => 18500, 'location' => 'Караганда', 'views' => 87, 'hours_ago' => 11],
            ['owner' => 'fashion', 'type' => 'new', 'slug' => 'man-coat', 'emoji' => '🧥',
                'category' => 'Одежда, обувь и аксессуары / Мужская одежда и обувь',
                'title' => 'Мужская куртка зима, размер L',
                'description' => 'Тёплая парка, капюшон с мехом. Новая коллекция 2026.',
                'price' => 34900, 'location' => 'Караганда', 'views' => 64, 'hours_ago' => 18],
            ['owner' => 'fashion', 'type' => 'new', 'slug' => 'bag-leather', 'emoji' => '👜',
                'category' => 'Одежда, обувь и аксессуары / Аксессуары',
                'title' => 'Сумка кожаная через плечо',
                'description' => 'Натуральная кожа, цвет кэмэл. Подарочная упаковка.',
                'price' => 27500, 'location' => 'Караганда', 'views' => 73, 'hours_ago' => 22],

            // --- б/у ---
            ['owner' => 'seller', 'type' => 'used', 'slug' => 'iphone-13-used', 'emoji' => '📱',
                'category' => 'Электроника и бытовая техника / Смартфоны и аксессуары',
                'title' => 'iPhone 13 128GB Midnight, батарея 87%',
                'description' => 'Телефон в идеале, стекла родные. Полный комплект + чехол. Любые проверки.',
                'price' => 165000, 'location' => 'Караганда, Юго-Восток', 'views' => 301, 'hours_ago' => 9],
            ['owner' => 'seller', 'type' => 'used', 'slug' => 'trek-marlin', 'emoji' => '🚲',
                'category' => 'Транспорт и запчасти / Велосипеды и самокаты',
                'title' => 'Горный велосипед Trek Marlin 5, рама M',
                'description' => 'Обслуживался в мастерской весной. Покрышки почти новые, тормоза дисковые.',
                'price' => 110000, 'location' => 'Караганда, Майкудук', 'views' => 154, 'hours_ago' => 16],
            ['owner' => 'seller', 'type' => 'used', 'slug' => 'yamaha-f310', 'emoji' => '🎸',
                'category' => 'Хобби, спорт и отдых / Музыкальные инструменты',
                'title' => 'Акустическая гитара Yamaha F310',
                'description' => 'Отличное состояние, чехол в комплекте. Струны новые.',
                'price' => 38000, 'location' => 'Караганда', 'views' => 91, 'hours_ago' => 30],
            ['owner' => 'seller', 'type' => 'used', 'slug' => 'lenovo-i5', 'emoji' => '💻',
                'category' => 'Электроника и бытовая техника / Компьютерная техника',
                'title' => 'Ноутбук Lenovo IdeaPad i5/16/512',
                'description' => 'Для учёбы и офиса. Клавиатура кириллица, батарея ~5 часов.',
                'price' => 145000, 'location' => 'Караганда, Пришахтинск', 'views' => 122, 'hours_ago' => 14],
            ['owner' => 'neighbor', 'type' => 'used', 'slug' => 'sofa-corner', 'emoji' => '🛋️',
                'category' => 'Дом и сад / Мебель',
                'title' => 'Угловой диван серый, без пятен',
                'description' => 'Очень удобный, механизм еврокнижка. Самовывоз, помогу вынести.',
                'price' => 55000, 'location' => 'Караганда, Майкудук', 'views' => 77, 'hours_ago' => 26],
            ['owner' => 'neighbor', 'type' => 'used', 'slug' => 'stroller', 'emoji' => '🍼',
                'category' => 'Детские товары / Коляски и автокресла',
                'title' => 'Коляска 2 в 1 Happy Baby',
                'description' => 'После одного ребёнка. Люлька + прогулочный блок, дождевик.',
                'price' => 42000, 'location' => 'Караганда', 'views' => 58, 'hours_ago' => 33],
            ['owner' => 'buyer', 'type' => 'used', 'slug' => 'canon-2000d', 'emoji' => '📷',
                'category' => 'Электроника и бытовая техника / Фото- и видеотехника',
                'title' => 'Canon EOS 2000D + китовый объектив',
                'description' => 'Снимала для себя. Пробег небольшой, карта 64 ГБ в комплекте.',
                'price' => 98000, 'location' => 'Караганда, Юго-Восток', 'views' => 84, 'hours_ago' => 19],
            ['owner' => 'bidder', 'type' => 'used', 'slug' => 'scooter-pro2', 'emoji' => '🛴',
                'category' => 'Транспорт и запчасти / Велосипеды и самокаты',
                'title' => 'Электросамокат Xiaomi Pro 2',
                'description' => 'Пробег 640 км, батарея живая. Зарядка родная.',
                'price' => 89000, 'location' => 'Караганда', 'views' => 140, 'hours_ago' => 10],

            // --- аукционы ---
            ['owner' => 'seller', 'type' => 'auction', 'slug' => 'iphone-12-lot', 'emoji' => '📱',
                'category' => 'Разное',
                'title' => 'Лот: iPhone 12 Pro 128GB Graphite',
                'description' => 'Состояние 8/10, батарея 84%. Старт 90 000 ₸, шаг 2 000 ₸. Автовыкуп 140 000 ₸.',
                'price' => 90000, 'bid_step' => 2000, 'auction_kind' => 'english',
                'auction_buy_now' => 140000, 'auction_reserve' => 110000,
                'ends_in_hours' => 6, 'location' => 'Караганда', 'views' => 210],
            ['owner' => 'shop', 'type' => 'auction', 'slug' => 'ps4-lot', 'emoji' => '🎮',
                'category' => 'Разное',
                'title' => 'Лот: PlayStation 4 Slim 1TB + 2 геймпада',
                'description' => 'Витринный образец магазина. 3 игры в комплекте. Шаг 1 500 ₸.',
                'price' => 70000, 'bid_step' => 1500, 'auction_kind' => 'english',
                'auction_buy_now' => 110000, 'ends_in_hours' => 20,
                'location' => 'Караганда', 'views' => 166],
            ['owner' => 'bidder', 'type' => 'auction', 'slug' => 'watch-lot', 'emoji' => '⌚',
                'category' => 'Разное',
                'title' => 'Лот: часы Casio Edifice, коллекционные',
                'description' => 'Редкая комплектация, коробка и документы. Резерв 45 000 ₸.',
                'price' => 25000, 'bid_step' => 1000, 'auction_kind' => 'english',
                'auction_reserve' => 45000, 'ends_in_hours' => 36,
                'location' => 'Караганда, Юго-Восток', 'views' => 95],
            ['owner' => 'seller', 'type' => 'auction', 'slug' => 'dutch-bike', 'emoji' => '🚲',
                'category' => 'Разное',
                'title' => 'Голландский лот: велосипед Stern 26"',
                'description' => 'Цена снижается каждые 15 минут. Заберите, когда цифра станет вашей.',
                'price' => 48000, 'bid_step' => 1000, 'auction_kind' => 'dutch',
                'auction_min_price' => 18000, 'auction_step_interval' => 900,
                'ends_in_hours' => 10, 'location' => 'Караганда', 'views' => 72],
            ['owner' => 'neighbor', 'type' => 'auction', 'slug' => 'tools-lot', 'emoji' => '🧰',
                'category' => 'Разное',
                'title' => 'Лот: набор инструментов 108 предметов',
                'description' => 'Хром-ванадий, почти не пользовался. Шаг 1 000 ₸.',
                'price' => 15000, 'bid_step' => 1000, 'auction_kind' => 'english',
                'ends_in_hours' => 48, 'location' => 'Караганда, Пришахтинск', 'views' => 61],
            ['owner' => 'fashion', 'type' => 'auction', 'slug' => 'bag-lot', 'emoji' => '👜',
                'category' => 'Разное',
                'title' => 'Лот: винтажная сумка, кожа Италии',
                'description' => 'Непрерывные торги. Побеждает последняя ставка до закрытия.',
                'price' => 12000, 'bid_step' => 500, 'auction_kind' => 'continuous',
                'inactivity_timeout_seconds' => 600, 'ends_in_hours' => 14,
                'location' => 'Караганда', 'views' => 88],

            // --- даром / обмен ---
            ['owner' => 'neighbor', 'type' => 'free', 'slug' => 'free-books', 'emoji' => '📚',
                'category' => 'Разное',
                'title' => 'Отдам книги: фантастика и детективы, 20 шт',
                'description' => 'Коробка книг в хорошем состоянии. Забирать с Пришахтинска сегодня-завтра.',
                'price' => 0, 'location' => 'Караганда, Пришахтинск', 'views' => 49, 'hours_ago' => 4],
            ['owner' => 'neighbor', 'type' => 'free', 'slug' => 'free-chair', 'emoji' => '🪑',
                'category' => 'Разное',
                'title' => 'Офисный стул даром, самовывоз',
                'description' => 'Кресло крутится, одно колесо пошатывается. Отдам за самовывоз.',
                'price' => 0, 'location' => 'Караганда, Майкудук', 'views' => 33, 'hours_ago' => 21],
            ['owner' => 'buyer', 'type' => 'free', 'slug' => 'free-kids', 'emoji' => '🧸',
                'category' => 'Разное',
                'title' => 'Детские вещи 2–3 года даром',
                'description' => 'Куртка, комбинезон и ботинки. Чистое, после одного ребёнка.',
                'price' => 0, 'location' => 'Караганда', 'views' => 41, 'hours_ago' => 13],
            ['owner' => 'buyer', 'type' => 'exchange', 'slug' => 'ipad-exchange', 'emoji' => '🔄',
                'category' => 'Разное',
                'title' => 'Обменяю iPad Air 4 на рабочий ноутбук',
                'description' => 'Планшет 64 ГБ, чехол-клавиатура. Ищу ноутбук для учёбы, i5 или лучше.',
                'price' => 0, 'exchange_for' => 'ноутбук i5 / 16 ГБ для учёбы',
                'location' => 'Караганда, Юго-Восток', 'views' => 70, 'hours_ago' => 17],
            ['owner' => 'seller', 'type' => 'exchange', 'slug' => 'snowboard-exchange', 'emoji' => '🏂',
                'category' => 'Разное',
                'title' => 'Сноуборд Burton 156 на горный велик',
                'description' => 'Борд в идеале, крепления в комплекте. Интересует велик рама M.',
                'price' => 0, 'exchange_for' => 'горный велосипед, рама M',
                'location' => 'Караганда', 'views' => 55, 'hours_ago' => 29],
            ['owner' => 'bidder', 'type' => 'exchange', 'slug' => 'ps-exchange', 'emoji' => '🎮',
                'category' => 'Разное',
                'title' => 'Меняю PS4 на Nintendo Switch OLED',
                'description' => 'Приставка + 2 геймпада + 4 диска. Доплачу разницу деньгами.',
                'price' => 0, 'exchange_for' => 'Nintendo Switch OLED',
                'location' => 'Караганда', 'views' => 63, 'hours_ago' => 8],

            // --- услуги ---
            ['owner' => 'master', 'type' => 'service', 'slug' => 'pc-repair', 'emoji' => '🛠️',
                'category' => 'Разное',
                'title' => 'Ремонт ПК и чистка ноутбуков на выезд',
                'description' => 'Диагностика 0 ₸. Чистка + термопаста 7 000. Замена матрицы, SSD, Windows.',
                'price' => 7000, 'location' => 'Караганда, выезд', 'views' => 190, 'hours_ago' => 6],
            ['owner' => 'master', 'type' => 'service', 'slug' => 'plumber', 'emoji' => '🔧',
                'category' => 'Разное',
                'title' => 'Сантехник: смесители, засоры, установка',
                'description' => 'Приезд в течение 2 часов по городу. Засор ванны от 5 000 ₸.',
                'price' => 5000, 'location' => 'Караганда', 'views' => 111, 'hours_ago' => 25],
            ['owner' => 'teacher', 'type' => 'service', 'slug' => 'math-tutor', 'emoji' => '📘',
                'category' => 'Разное',
                'title' => 'Репетитор математики, 5–11 класс и ЕНТ',
                'description' => 'Очно и онлайн. Первое занятие — диагностика бесплатно.',
                'price' => 4000, 'location' => 'Караганда / онлайн', 'views' => 80, 'hours_ago' => 14],
            ['owner' => 'teacher', 'type' => 'service', 'slug' => 'photo-session', 'emoji' => '📸',
                'category' => 'Разное',
                'title' => 'Фотосъёмка для объявлений и каталога',
                'description' => '20 обработанных кадров за час. Выезд к вам или в студии на Гоголя.',
                'price' => 15000, 'location' => 'Караганда', 'views' => 52, 'hours_ago' => 31],
            ['owner' => 'master', 'type' => 'service', 'slug' => 'sofa-clean', 'emoji' => '✨',
                'category' => 'Разное',
                'title' => 'Химчистка дивана на дому',
                'description' => 'Сухая пена, сохнет 4 часа. Угловой диван — 12 000 ₸.',
                'price' => 12000, 'location' => 'Караганда', 'views' => 69, 'hours_ago' => 27],

            // --- курсы ---
            ['owner' => 'teacher', 'type' => 'course', 'slug' => 'excel-course', 'emoji' => '📊',
                'category' => 'Запись',
                'title' => 'Excel для работы: от таблиц до сводных',
                'description' => "Практический курс для офиса и ИП.\n1. Интерфейс и горячие клавиши\n2. Формулы SUM, IF, VLOOKUP\n3. Сводные таблицы\n4. Дашборд для руководителя",
                'price' => 9900, 'location' => 'Онлайн', 'views' => 240, 'hours_ago' => 40],
            ['owner' => 'teacher', 'type' => 'course', 'slug' => 'smm-course', 'emoji' => '📣',
                'category' => 'Запись и трансляция',
                'title' => 'SMM с нуля: контент и таргет',
                'description' => "Как вести Instagram и получать заявки.\n1. Аватар и оффер\n2. Контент-план на месяц\n3. Таргет без слива бюджета\n4. Разбор кабинета",
                'price' => 14900, 'location' => 'Онлайн', 'views' => 178, 'hours_ago' => 50],
            ['owner' => 'teacher', 'type' => 'course', 'slug' => 'english-course', 'emoji' => '🗣️',
                'category' => 'Онлайн-трансляция',
                'title' => 'Английский для работы: письма и созвоны',
                'description' => "Живые эфиры 2 раза в неделю.\n1. Самопрезентация\n2. Письма клиентам\n3. Созвоны без паники\n4. Лексика переговоров",
                'price' => 12000, 'location' => 'Онлайн', 'views' => 96, 'hours_ago' => 12],
        ];
    }
}

try {
    (new DemoSeeder())->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка сида: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
