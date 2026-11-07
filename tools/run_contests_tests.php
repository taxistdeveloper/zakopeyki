<?php

declare(strict_types=1);

/**
 * Тесты модуля «Конкурсы и розыгрыши» и пакета безопасности MASTERING-02.
 *
 *   php tools/run_contests_tests.php            — чистые тесты + интеграция (нужна БД)
 *   php tools/run_contests_tests.php --unit     — только чистые тесты
 *
 * Интеграция создаёт временных пользователей/акции с меткой и удаляет их в конце,
 * настройки модуля восстанавливаются в исходное состояние.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$GLOBALS['appConfig'] = require $root . '/config/app.php';
date_default_timezone_set($GLOBALS['appConfig']['timezone'] ?? 'Asia/Almaty');
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $f = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($f)) {
            require $f;
        }
    }
});
$_SESSION = [];
\App\Core\Lang::boot();

use App\Helpers\QrCode;
use App\Services\Contest\ContestPolicy;
use App\Services\Contest\RandomDraw;

$pass = 0;
$fail = 0;
function check(bool $cond, string $desc): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  [PASS] {$desc}\n";
    } else {
        $fail++;
        echo "  [FAIL] {$desc}\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "== RandomDraw ==\n";
$c = RandomDraw::newCommitment();
check(strlen($c['seed']) === 64 && RandomDraw::commitFor($c['seed']) === $c['commit'], 'commit = SHA-256(seed)');
$ids = range(101, 160);
$w1 = RandomDraw::pick($c['seed'], $ids, 5);
$w2 = RandomDraw::pick($c['seed'], array_reverse($ids), 5);
check($w1 === $w2, 'детерминизм и независимость от порядка входа');
check(count($w1) === 5 && count(array_unique($w1)) === 5 && array_diff($w1, $ids) === [], '5 разных победителей из допущенных');
check(RandomDraw::pick($c['seed'], [7, 8], 5) !== [] && count(RandomDraw::pick($c['seed'], [7, 8], 5)) === 2, 'победителей не больше, чем участий');
check(RandomDraw::pick($c['seed'], [], 3) === [], 'пустой список → нет победителей');
$other = RandomDraw::newCommitment();
check(RandomDraw::pick($other['seed'], $ids, 5) !== $w1, 'другой seed → другой результат');
$counts = array_fill(0, 10, 0);
for ($i = 0; $i < 4000; $i++) {
    $s = hash('sha256', 'dist' . $i);
    $counts[RandomDraw::pick($s, range(0, 9), 1)[0]]++;
}
check(min($counts) > 330 && max($counts) < 470, 'равномерность первого места (χ-оценка грубо): ' . implode(',', $counts));
$threw = false;
try {
    RandomDraw::pick('nothex', $ids, 1);
} catch (\InvalidArgumentException) {
    $threw = true;
}
check($threw, 'некорректный seed отклоняется');

echo "== ContestPolicy ==\n";
check(ContestPolicy::canTransition('draft', 'pending_moderation', ['organizer']), 'организатор: черновик → модерация');
check(!ContestPolicy::canTransition('pending_moderation', 'active', ['organizer']), 'организатор не может сам одобрить');
check(ContestPolicy::canTransition('pending_moderation', 'active', ['moderator']), 'модератор одобряет');
check(!ContestPolicy::canTransition('active', 'closed', ['organizer']), 'закрытие приёма — только система/админ');
check(!ContestPolicy::canTransition('active', 'cancelled', ['organizer']), 'организатор не отменяет идущую акцию');
check(ContestPolicy::canTransition('closed', 'results_published', ['organizer']), 'организатор подводит итоги после закрытия');
check(!ContestPolicy::canTransition('results_published', 'closed', ['admin']), 'итоги нельзя «откатить»');
check(!ContestPolicy::isPublicStatus('draft') && ContestPolicy::isPublicStatus('active'), 'черновик не публичен');
$pol = new ContestPolicy(new class extends \App\Models\Setting {
    public function __construct()
    {
    }
    public function getBool(string $key, ?bool $default = null): ?bool
    {
        return null;
    }
    public function get(string $key, $default = null): ?string
    {
        return null;
    }
});
check(!$pol->moduleEnabled() && $pol->enabledMechanics() === [], 'по умолчанию модуль и все механики выключены');
check($pol->organizerDenyReason(['id' => 1, 'role' => 'user']) === 'module_disabled', 'без флага создание запрещено');

echo "== QrCode ==\n";
// Ёмкость уровня M в байтовом режиме: v1=14, v2=26, v3=42, v6=106, v10=213 байт
foreach (['https://zakopeyki.kz/contests/x' => 29, str_repeat('a', 14) => 21, str_repeat('a', 100) => 41, str_repeat('b', 200) => 57] as $text => $size) {
    $m = QrCode::matrix($text);
    check(count($m) === $size, 'размер матрицы ' . $size . ' для ' . strlen($text) . ' байт');
    $finder = true;
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r, $cl]) {
        $finder = $finder && $m[$r][$cl] && $m[$r + 6][$cl + 6] && !$m[$r + 1][$cl + 1] && $m[$r + 3][$cl + 3];
    }
    check($finder, 'поисковые узоры на месте');
}
$svg = QrCode::svg('https://zakopeyki.kz/contests/test?ref=user_1');
check(str_starts_with($svg, '<svg') && str_contains($svg, 'viewBox'), 'SVG генерируется');
$threw = false;
try {
    QrCode::matrix(str_repeat('z', 300));
} catch (\InvalidArgumentException) {
    $threw = true;
}
check($threw, 'слишком длинный текст отклоняется');

if (in_array('--unit', $argv, true)) {
    echo "\nSummary: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "== Интеграция (локальная БД) ==\n";
try {
    $db = \App\Core\Database::connect();
} catch (\Throwable $e) {
    echo "  БД недоступна — интеграция пропущена\n";
    echo "\nSummary: {$pass} passed, {$fail} failed\n";
    exit($fail === 0 ? 0 : 1);
}

use App\Models\Contest;
use App\Models\ContestEntry;
use App\Models\ContestResult;
use App\Models\ContestTracking;
use App\Models\Follow;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contest\ContestEntryService;
use App\Services\Contest\ContestReportService;
use App\Services\Contest\ContestResultService;
use App\Services\Contest\ContestService;

$settings = new Setting();
$keys = [
    ContestPolicy::SETTING_ENABLED,
    ContestPolicy::SETTING_ORGANIZER_MODE,
    ContestPolicy::SETTING_REQUIRE_VERIFIED_EMAIL,
    ContestPolicy::SETTING_METHOD_PREFIX . 'jury_enabled',
    ContestPolicy::SETTING_METHOD_PREFIX . 'random_enabled',
];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = $settings->has($k) ? $settings->get($k) : null;
}

$tag = 'zkt' . bin2hex(random_bytes(3));
$userIds = [];
$contestIds = [];
$users = new User();

$mkUser = function (string $name, array $extra = []) use ($users, $db, $tag, &$userIds): array {
    $id = $users->create([
        'name' => $name . ' Test',
        'email' => $tag . '_' . strtolower($name) . '@example.invalid',
        'password' => bin2hex(random_bytes(8)),
        'login' => $tag . strtolower($name),
    ]);
    $userIds[] = $id;
    $sets = ["created_at = NOW() - INTERVAL 10 DAY", 'email_verified_at = NOW()'];
    $params = [];
    foreach ($extra as $col => $val) {
        if ($val === null) {
            $sets[] = "{$col} = NULL";
        } else {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
    }
    $params[] = $id;
    $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    $u = $users->find($id);
    $u['permissions'] = \App\Core\Auth::normalizePermissions($u['permissions'] ?? null, (string) $u['role']);
    return $u;
};
$refresh = static function (array $u) use ($users): array {
    $f = $users->find((int) $u['id']);
    $f['permissions'] = \App\Core\Auth::normalizePermissions($f['permissions'] ?? null, (string) $f['role']);
    return $f;
};

try {
    $settings->setBool(ContestPolicy::SETTING_ENABLED, true);
    $settings->set(ContestPolicy::SETTING_ORGANIZER_MODE, ContestPolicy::ORGANIZER_BUSINESS_VERIFIED);
    $settings->setBool(ContestPolicy::SETTING_REQUIRE_VERIFIED_EMAIL, true);
    $settings->setBool(ContestPolicy::SETTING_METHOD_PREFIX . 'jury_enabled', true);
    $settings->setBool(ContestPolicy::SETTING_METHOD_PREFIX . 'random_enabled', true);

    $org = $mkUser('Org', ['account_type' => 'business', 'business_status' => 'verified']);
    $admin = $mkUser('Adm', ['role' => 'admin']);
    $p1 = $mkUser('Pone');
    $p2 = $mkUser('Ptwo');
    $p3 = $mkUser('Pthree');
    $p4 = $mkUser('Pfour', ['email_verified_at' => null]);
    $plain = $mkUser('Plain');

    $policy = new ContestPolicy();
    check($policy->organizerDenyReason($plain) === 'business_required', 'обычный пользователь не может организовать (режим business_verified)');
    check($policy->organizerDenyReason($org) === null, 'проверенный бизнес может организовать');

    $service = new ContestService();
    $input = [
        'title' => 'Тестовый розыгрыш ' . $tag,
        'summary' => 'Кратко',
        'description' => 'Описание <script>alert(1)</script>',
        'prize_summary' => 'Сертификат',
        'mechanic' => 'random',
        'winners_count' => 2,
        'prizes' => ['Сертификат 10 000', 'Сертификат 5 000'],
        'rules_text' => str_repeat('Правила тестовой акции. ', 5),
        'starts_at' => date('Y-m-d H:i:s', time() - 600),
        'ends_at' => date('Y-m-d H:i:s', time() + 7200),
        'cond_follow_organizer' => '1',
        'cond_account_age_days' => 3,
        'region' => 'karaganda',
    ];
    $idem = bin2hex(random_bytes(16));
    $r = $service->createDraft($org, $input, null, $idem);
    check($r['ok'], 'черновик создан');
    $cid = (int) $r['id'];
    $contestIds[] = $cid;
    $r2 = $service->createDraft($org, $input, null, $idem);
    check($r2['ok'] && (int) $r2['id'] === $cid, 'повторная отправка формы идемпотентна');

    $bad = $service->validate(['title' => 'x', 'mechanic' => 'paid_entry'] + $input);
    check(isset($bad['errors']['title'], $bad['errors']['mechanic']), 'валидация: короткое название и неизвестная механика');

    $contests = new Contest();
    $contest = $contests->find($cid);
    check($contest['status'] === 'draft', 'статус draft');
    check(!$service->submit($contest, $org, false)['ok'], 'без согласия организатора отправка запрещена');
    check(!$service->submit($contest, $p1, true)['ok'], 'чужой пользователь не может отправить');
    check($service->submit($contest, $org, true)['ok'], 'отправлено на модерацию');
    $contest = $contests->find($cid);
    check($service->approve($contest, $org)['error'] === 'forbidden', 'организатор не может одобрить свою акцию');
    check($service->approve($contest, $admin)['ok'], 'админ одобрил');
    $contest = $contests->findWithOrganizer($cid);
    check($contest['status'] === 'active' && strlen((string) $contest['random_commit']) === 64, 'активна, commit опубликован');

    $entries = new ContestEntryService();
    $ctx = static fn (string $ip, string $vk): array => ['ip' => $ip, 'visitor_key' => $vk];
    $vk = static fn (): string => bin2hex(random_bytes(16));

    $res = $entries->join($contest, $p1, null, $ctx('10.0.0.1', $vk()));
    check(!$res['ok'] && $res['error'] === 'conditions' && $res['conditions']['follow_organizer']['status'] === 'not_met', 'без подписки — отказ по условию');
    $follow = new Follow();
    $follow->toggle((int) $p1['id'], (int) $org['id']);
    $sharedDevice = $vk();
    $res = $entries->join($contest, $p1, null, $ctx('10.0.0.1', $sharedDevice));
    check($res['ok'], 'p1 участвует после подписки');
    check(!$entries->join($contest, $p1, null, $ctx('10.0.0.1', $sharedDevice))['ok'], 'повторное участие невозможно');
    check($entries->join($contest, $org, null, $ctx('10.0.0.9', $vk()))['error'] === 'own_contest', 'организатор не участвует в своей акции');
    check($entries->join($contest, $p4, null, $ctx('10.0.0.4', $vk()))['conditions']['email_verified']['status'] === 'not_met', 'неподтверждённый email не допускается');
    $follow->toggle((int) $p2['id'], (int) $org['id']);
    check($entries->join($contest, $p2, null, $ctx('10.0.0.2', $sharedDevice))['ok'], 'p2 участвует (то же устройство, что у p1)');
    $follow->toggle((int) $p3['id'], (int) $org['id']);
    check($entries->join($contest, $p3, null, $ctx('10.0.0.3', $vk()))['ok'], 'p3 участвует');
    $follow->toggle((int) $p3['id'], (int) $org['id']); // отписка
    $follow->toggle((int) $p3['id'], (int) $org['id']); // повторная подписка
    $entryModel = new ContestEntry();
    $e1 = $entryModel->findForUser($cid, (int) $p1['id']);
    check(str_contains((string) $e1['fraud_flags'], 'same_device'), 'антифрод: одно устройство помечено у обоих');

    // Реферальная атрибуция: переход + регистрация, без собственных начислений
    $tracking = new ContestTracking();
    $visitId = $tracking->recordRefVisit($cid, (int) $p1['id'], $vk());
    check($visitId > 0 && $tracking->linkRegistration($visitId, (int) $p4['id']), 'переход по ссылке связан с регистрацией');
    check(!$tracking->linkRegistration($visitId, (int) $p4['id']), 'повторная атрибуция невозможна');
    check($tracking->refTotals($cid, (int) $p1['id'])['registrations'] === 1, 'счётчик регистраций по ссылке');
    $bonusRows = (int) $db->query("SELECT COUNT(*) FROM bonus_transactions WHERE user_id = " . (int) $p1['id'] . " AND type = 'referral'")->fetchColumn();
    check($bonusRows === 0, 'модуль не начисляет реферальные бонусы сам');

    // Закрытие приёма (окончание — строго после события отписки)
    sleep(1);
    $db->prepare('UPDATE contests SET ends_at = NOW() WHERE id = ?')->execute([$cid]);
    $contest = $service->syncTime($contests->findWithOrganizer($cid));
    check($contest['status'] === 'closed', 'по времени акция закрыта');
    $e3 = $entryModel->findForUser($cid, (int) $p3['id']);
    check($e3['status'] === 'disqualified' && str_contains((string) $e3['status_reason'], 'unfollowed_during_contest'), 'отписка во время акции → не допущен (по журналу)');
    $e1 = $entryModel->findForUser($cid, (int) $p1['id']);
    check($e1['status'] === 'pending', 'помеченная антифродом заявка ждёт ручной проверки');

    $results = new ContestResultService();
    $contest = $contests->findWithOrganizer($cid);
    check($results->execute($contest, $org)['error'] === 'pending_review', 'итоги невозможны до проверки помеченных заявок');
    check(!$entries->review($contest, (int) $e1['id'], 'confirm', '', $p2)['ok'], 'участник не может проверять заявки');
    $e2 = $entryModel->findForUser($cid, (int) $p2['id']);
    check($entries->review($contest, (int) $e1['id'], 'confirm', 'разные люди', $org)['ok'], 'организатор допустил p1');
    check($entries->review($contest, (int) $e2['id'], 'confirm', 'разные люди', $org)['ok'], 'организатор допустил p2');
    check(!$results->execute($contest, $p1)['ok'], 'участник не может подвести итоги');
    $r = $results->execute($contest, $org);
    check($r['ok'], 'розыгрыш проведён');
    check(!$results->execute($contests->findWithOrganizer($cid), $org)['ok'], 'повторное подведение итогов невозможно');

    $resModel = new ContestResult();
    $exec = $resModel->forContest($cid);
    $winners = $resModel->winners($cid);
    $eligible = (new ContestEntry())->confirmedIds($cid);
    $recomputed = RandomDraw::pick((string) $exec['seed'], $eligible, 2);
    check(RandomDraw::commitFor((string) $exec['seed']) === (string) $contest['random_commit'], 'раскрытый seed соответствует опубликованному commit');
    check(array_map(static fn ($w) => (int) $w['entry_id'], $winners) === $recomputed, 'результат воспроизводим независимо');
    check($exec['eligible_hash'] === RandomDraw::listHash($eligible), 'хэш списка допущенных сохранён');

    // Вручение
    $contest = $contests->findWithOrganizer($cid);
    $w = $winners[0];
    $winnerUser = (int) $w['user_id'] === (int) $p1['id'] ? $p1 : $p2;
    $loserUser = (int) $w['user_id'] === (int) $p1['id'] ? $p2 : $p1;
    check(!$results->markHandedOver($contest, (int) $w['id'], 'x', $p1)['ok'] || $winnerUser === $p1 && false, 'участник не отмечает вручение');
    check($results->markHandedOver($contest, (int) $w['id'], 'Передан лично', $org)['ok'], 'организатор отметил вручение');
    check(!$results->confirmReceived($contest, (int) $w['id'], $loserUser)['ok'], 'чужой победитель не подтверждает');
    check($results->confirmReceived($contest, (int) $w['id'], $winnerUser)['ok'], 'победитель подтвердил');
    $w2 = $winners[1];
    $w2User = (int) $w2['user_id'] === (int) $p1['id'] ? $p1 : $p2;
    check($results->disputePrize($contest, (int) $w2['id'], 'Приз не пришёл', $w2User)['ok'], 'второй победитель открыл спор');
    check($contests->find($cid)['status'] === 'disputed', 'акция в статусе «спор»');
    check($results->resolveDispute($contests->findWithOrganizer($cid), (int) $w2['id'], 'confirmed', 'Подтверждено трек-номером', $admin)['ok'], 'админ закрыл спор');
    check($contests->find($cid)['status'] === 'completed', 'все призы вручены → акция завершена');

    // Жалобы
    $reports = new ContestReportService();
    $contest = $contests->findWithOrganizer($cid);
    check($reports->submit($contest, $p3, 'unfair_result', 'Проверьте')['ok'], 'жалоба отправлена');
    check($reports->submit($contest, $p3, 'unfair_result', 'Ещё раз')['error'] === 'duplicate', 'дубликат жалобы отклонён');
    check($reports->submit($contest, $org, 'fraud', '')['error'] === 'own_contest', 'на свою акцию жаловаться нельзя');
    $rid = (int) $db->query('SELECT id FROM contest_reports WHERE contest_id = ' . $cid . ' LIMIT 1')->fetchColumn();
    check(!$reports->resolve($rid, 'resolved', 'ok', $p1)['ok'], 'участник не рассматривает жалобы');
    check($reports->resolve($rid, 'rejected', 'Нарушений нет', $admin)['ok'], 'админ рассмотрел жалобу');

    // Конкурс с жюри
    $jury = $input;
    $jury['title'] = 'Тестовый конкурс ' . $tag;
    $jury['mechanic'] = 'jury';
    $jury['winners_count'] = 1;
    $jury['criteria_text'] = 'Оригинальность и качество';
    $jury['cond_follow_organizer'] = '';
    $r = $service->createDraft($org, $jury, null, bin2hex(random_bytes(16)));
    $jid = (int) $r['id'];
    $contestIds[] = $jid;
    $service->submit($contests->find($jid), $org, true);
    $service->approve($contests->find($jid), $admin);
    $jc = $contests->findWithOrganizer($jid);
    check($entries->join($jc, $p1, '', $ctx('10.1.0.1', $vk()))['error'] === 'conditions', 'жюри: без работы участвовать нельзя');
    check($entries->join($jc, $p1, 'Моя работа', $ctx('10.1.0.1', $vk()))['ok'], 'жюри: p1 отправил работу');
    check($entries->join($jc, $p3, 'Работа p3', $ctx('10.1.0.3', $vk()))['ok'], 'жюри: p3 отправил работу');
    $settings->setBool(ContestPolicy::SETTING_METHOD_PREFIX . 'jury_enabled', false);
    check($entries->join($jc, $p2, 'Работа p2', $ctx('10.1.0.2', $vk()))['error'] === 'mechanic_disabled', 'выключение механики сразу останавливает приём');
    $settings->setBool(ContestPolicy::SETTING_METHOD_PREFIX . 'jury_enabled', true);
    $db->prepare('UPDATE contests SET ends_at = NOW() - INTERVAL 1 SECOND WHERE id = ?')->execute([$jid]);
    $jc = $service->syncTime($contests->findWithOrganizer($jid)) + $jc;
    $je1 = $entryModel->findForUser($jid, (int) $p1['id']);
    $je3 = $entryModel->findForUser($jid, (int) $p3['id']);
    check($results->execute($jc, $org, [1 => 999999])['error'] === 'invalid_picks', 'жюри: нельзя выбрать несуществующее участие');
    check($results->execute($jc, $org, [1 => (int) $je3['id']], [1 => 'Лучшая композиция'])['ok'], 'жюри: итоги опубликованы');
    check((int) $resModel->winners($jid)[0]['entry_id'] === (int) $je3['id'], 'жюри: победитель — выбранный');

    // Видимость
    $draftId = (int) $service->createDraft($org, ['title' => 'Скрытый черновик ' . $tag] + $input, null, bin2hex(random_bytes(16)))['id'];
    $contestIds[] = $draftId;
    $cat = $contests->catalog(['q' => $tag]);
    $slugs = array_column($cat['items'], 'id');
    check(!in_array($draftId, $slugs, true) && in_array($cid, array_map('intval', $slugs), true), 'черновик не попадает в каталог, опубликованная — попадает');
    check(!ContestService::canManage($contests->find($draftId), $p1), 'участник не управляет чужой акцией');
    check(ContestService::canManage($contests->find($draftId), $admin), 'админ управляет акцией');

    // Блокировка пользователя (F-3)
    check($users->block((int) $p2['id'], 'тест', (int) $admin['id']), 'блокировка пользователя');
    $p2b = $refresh($p2);
    check($policy->organizerDenyReason($p2b) === 'blocked', 'заблокированный не организует');
    $users->unblock((int) $p2['id']);
} catch (\Throwable $e) {
    $fail++;
    echo '  [FAIL] Исключение: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    // Очистка
    foreach ($contestIds as $id) {
        $id = (int) $id;
        $db->exec("DELETE w FROM contest_winners w WHERE w.contest_id = {$id}");
        $db->exec("DELETE FROM contest_result_executions WHERE contest_id = {$id}");
        $db->exec("DELETE c FROM contest_entry_conditions c JOIN contest_entries e ON e.id = c.entry_id WHERE e.contest_id = {$id}");
        $db->exec("DELETE FROM contest_entries WHERE contest_id = {$id}");
        $db->exec("DELETE FROM contest_reports WHERE contest_id = {$id}");
        $db->exec("DELETE FROM contest_views WHERE contest_id = {$id}");
        $db->exec("DELETE FROM contest_ref_visits WHERE contest_id = {$id}");
        $db->exec("DELETE FROM contest_rule_versions WHERE contest_id = {$id}");
        $db->exec("DELETE FROM contests WHERE id = {$id}");
    }
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $db->exec("DELETE FROM contest_platform_events WHERE actor_id IN ({$in}) OR target_id IN ({$in})");
        $db->exec("DELETE FROM user_follows WHERE follower_id IN ({$in}) OR following_id IN ({$in})");
        $db->exec("DELETE FROM bonus_transactions WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM bonus_balances WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM notifications WHERE user_id IN ({$in})");
        $db->exec("DELETE FROM activity_logs WHERE user_id IN ({$in}) OR (entity_type = 'contest' AND entity_id IN (" . ($contestIds ? implode(',', array_map('intval', $contestIds)) : '0') . '))');
        $db->exec("DELETE FROM users WHERE id IN ({$in})");
    }
    foreach ($saved as $k => $v) {
        if ($v === null) {
            // Setting::forget() чистит только кэш — удаляем строку явно
            $db->prepare('DELETE FROM settings WHERE `key` = ?')->execute([$k]);
            $settings->forget($k);
        } else {
            $settings->set($k, (string) $v);
        }
    }
    $left = (int) $db->query("SELECT COUNT(*) FROM users WHERE email LIKE '{$tag}%'")->fetchColumn();
    $restored = true;
    foreach ($saved as $k => $v) {
        $st = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $st->execute([$k]);
        $now = $st->fetchColumn();
        $restored = $restored && (($now === false ? null : (string) $now) === ($v === null ? null : (string) $v));
    }
    check($left === 0 && $restored, 'тестовые данные удалены, настройки восстановлены');
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
