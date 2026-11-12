<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use App\Helpers\ActivityLogger;
use App\Helpers\Mail;
use App\Models\Bonus;
use App\Models\Notification;
use App\Models\User;
use App\Services\Contest\ContestReferralTracker;
use PDO;

/**
 * Общая логика аккаунтов для сайта (AuthController) и мобильного API:
 * регистрация, письма подтверждения и сброса пароля, вход через внешние провайдеры (Google, Apple).
 * Правила не дублируются — оба канала вызывают этот сервис.
 */
final class AccountService
{
    public const MIN_PASSWORD = 8;

    private static bool $appleColumnEnsured = false;

    public function __construct(private ?User $users = null)
    {
        $this->users = $users ?? new User();
    }

    // ---------------------------------------------------------------- регистрация

    /**
     * @param array{name?: string, email?: string, password?: string, phone?: string, accept_offer?: bool} $input
     * @return array{ok: true, user: array<string, mixed>, bonus: int}|array{ok: false, error: string, field?: string}
     *   error — ключ причины: invalid_email | offer_required | fill_fields | weak_password | email_taken
     */
    public function register(array $input, ?string $referralCode = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $phone = trim((string) ($input['phone'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'invalid_email', 'field' => 'email'];
        }
        if (empty($input['accept_offer'])) {
            return ['ok' => false, 'error' => 'offer_required', 'field' => 'accept_offer'];
        }
        if ($name === '' || $email === '') {
            return ['ok' => false, 'error' => 'fill_fields', 'field' => $name === '' ? 'name' : 'email'];
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            return ['ok' => false, 'error' => 'weak_password', 'field' => 'password'];
        }
        if ($this->users->findByEmail($email)) {
            return ['ok' => false, 'error' => 'email_taken', 'field' => 'email'];
        }

        $id = $this->users->create([
            'name' => mb_substr($name, 0, 120),
            'email' => $email,
            'password' => $password,
            'phone' => $phone,
        ]);
        $bonus = $this->afterAccountCreated($id, $referralCode);

        $user = $this->users->find($id) ?? [];
        $this->sendVerificationEmail($user);
        ActivityLogger::info('auth.register', 'Регистрация: ' . $email, 'user', $id, ['email' => $email, 'bonus' => $bonus]);

        return ['ok' => true, 'user' => $user, 'bonus' => $bonus];
    }

    /** Бонус за регистрацию, реферал, учёт в конкурсах. Возвращает сумму бонуса. */
    public function afterAccountCreated(int $userId, ?string $referralCode): int
    {
        $bonusResult = (new Bonus())->awardRegistration($userId);
        $this->applyReferral($userId, $referralCode);
        ContestReferralTracker::onRegistered($userId);

        $amount = (int) ($bonusResult['amount'] ?? 0);
        if (!empty($bonusResult['ok']) && empty($bonusResult['skipped']) && $amount > 0) {
            (new Notification())->createFor($userId, t('bonuses.notify_registration', ['amount' => Bonus::format($amount)]));

            return $amount;
        }

        return 0;
    }

    public function applyReferral(int $newUserId, ?string $code): void
    {
        $code = trim((string) $code);
        if ($code === '' || $newUserId <= 0) {
            return;
        }
        $referrer = $this->users->findByReferralCode($code);
        if (!$referrer || (int) $referrer['id'] === $newUserId) {
            return;
        }
        $referrerId = (int) $referrer['id'];
        $this->users->setReferredBy($newUserId, $referrerId);
        $result = (new Bonus())->awardReferral($referrerId, $newUserId);
        if (!empty($result['ok']) && empty($result['skipped']) && ($result['amount'] ?? 0) > 0) {
            (new Notification())->createFor($referrerId, t('bonuses.notify_referral', ['amount' => Bonus::format((int) $result['amount'])]));
        }
    }

    // ---------------------------------------------------------------- письма

    /** @param array<string, mixed>|null $user */
    public function sendVerificationEmail(?array $user): bool
    {
        if (!$user || !empty($user['email_verified_at']) || empty($user['email']) || !Mail::configured()) {
            return false;
        }
        try {
            $token = $this->users->createEmailVerifyToken((int) $user['id'], 86400);
            $mail = new Mail();
            $url = $mail->absoluteUrl('/verify-email/' . $token);
            $name = trim((string) ($user['name'] ?? '')) ?: (string) $user['email'];
            $html = $mail->render('emails/password-reset', [
                'name' => $name,
                'resetUrl' => $url,
                'greeting' => t('auth.reset_mail_greeting', ['name' => $name]),
                'body' => t('auth.verify_mail_body'),
                'cta' => t('auth.verify_mail_cta'),
                'expiry' => t('auth.verify_mail_expiry'),
                'linkHint' => t('auth.reset_mail_link_hint'),
                'footer' => t('auth.reset_mail_footer'),
            ]);
            $text = t('auth.verify_mail_text', ['name' => $name, 'url' => $url]);

            return $mail->send((string) $user['email'], t('auth.verify_mail_subject'), $text, $html);
        } catch (\Throwable $e) {
            ActivityLogger::exception($e, 'auth.verify_mail_failed');

            return false;
        }
    }

    /**
     * Отправить письмо сброса, если email зарегистрирован. Ответ вызывающему одинаковый
     * в обоих случаях (не раскрываем существование аккаунта). Возвращает URL для локальной отладки.
     */
    public function requestPasswordReset(string $email): ?string
    {
        $user = $this->users->findByEmail($email);
        if (!$user) {
            return null;
        }
        $token = $this->users->createPasswordResetToken((int) $user['id'], 3600);
        $mail = new Mail();
        $resetUrl = $mail->absoluteUrl('/reset-password/' . $token);
        $name = trim((string) ($user['name'] ?? '')) ?: $email;
        $html = $mail->render('emails/password-reset', [
            'name' => $name,
            'resetUrl' => $resetUrl,
            'greeting' => t('auth.reset_mail_greeting', ['name' => $name]),
            'body' => t('auth.reset_mail_body'),
            'cta' => t('auth.reset_mail_cta'),
            'expiry' => t('auth.reset_mail_expiry', ['minutes' => '60']),
            'linkHint' => t('auth.reset_mail_link_hint'),
            'footer' => t('auth.reset_mail_footer'),
        ]);
        $text = t('auth.reset_mail_text', ['name' => $name, 'url' => $resetUrl, 'minutes' => '60']);
        $mail->send($email, t('auth.reset_mail_subject'), $text, $html);

        return $resetUrl;
    }

    // ---------------------------------------------------------------- внешние провайдеры

    /**
     * Найти/связать/создать пользователя по проверенной личности Google или Apple.
     *
     * Порядок (как в web-входе через Google): по ID провайдера → по подтверждённому провайдером
     * email (связывание) → создание нового аккаунта.
     *
     * @return array{ok: true, user: array<string, mixed>, created: bool, bonus: int}|array{ok: false, error: string}
     */
    public function resolveExternalIdentity(
        string $provider,
        string $subject,
        ?string $email,
        bool $emailVerified,
        string $name = '',
        ?string $referralCode = null
    ): array {
        if (!in_array($provider, ['google', 'apple'], true) || $subject === '') {
            return ['ok' => false, 'error' => 'invalid_identity'];
        }
        $email = $email !== null ? strtolower(trim($email)) : null;

        $user = $this->findByProviderId($provider, $subject);
        if ($user) {
            return ['ok' => true, 'user' => $user, 'created' => false, 'bonus' => 0];
        }

        if ($email === null || $email === '') {
            // Apple отдаёт email только при первой авторизации — без него новый аккаунт не создать
            return ['ok' => false, 'error' => 'email_missing'];
        }
        if (!$emailVerified) {
            return ['ok' => false, 'error' => 'email_unverified'];
        }

        $byEmail = $this->users->findByEmail($email);
        if ($byEmail) {
            $this->linkProviderId($provider, (int) $byEmail['id'], $subject);
            $this->users->markEmailVerified((int) $byEmail['id']);
            ActivityLogger::info('auth.link_' . $provider, 'Аккаунт связан с ' . $provider, 'user', (int) $byEmail['id']);

            return ['ok' => true, 'user' => $this->users->find((int) $byEmail['id']) ?? $byEmail, 'created' => false, 'bonus' => 0];
        }

        $name = trim($name);
        if ($name === '') {
            $local = (string) strstr($email, '@', true);
            $name = $local !== '' && !str_contains($email, 'privaterelay.appleid.com') ? $local : 'Пользователь';
        }
        $id = $this->users->create([
            'name' => mb_substr($name, 0, 120),
            'email' => $email,
            'password' => null,
            'google_id' => $provider === 'google' ? $subject : null,
        ]);
        if ($provider === 'apple') {
            $this->linkProviderId('apple', $id, $subject);
        }
        $this->users->markEmailVerified($id);
        $bonus = $this->afterAccountCreated($id, $referralCode);
        ActivityLogger::info('auth.register', 'Регистрация через ' . $provider, 'user', $id, ['provider' => $provider]);

        return ['ok' => true, 'user' => $this->users->find($id) ?? [], 'created' => true, 'bonus' => $bonus];
    }

    /** @return array<string, mixed>|null */
    private function findByProviderId(string $provider, string $subject): ?array
    {
        if ($provider === 'google') {
            return $this->users->findByGoogleId($subject);
        }
        $this->ensureAppleColumn();
        $stmt = Database::connect()->prepare('SELECT id FROM users WHERE apple_id = ? LIMIT 1');
        $stmt->execute([$subject]);
        $id = $stmt->fetchColumn();

        return $id ? $this->users->find((int) $id) : null;
    }

    private function linkProviderId(string $provider, int $userId, string $subject): void
    {
        if ($provider === 'google') {
            $this->users->linkGoogleId($userId, $subject);

            return;
        }
        $this->ensureAppleColumn();
        Database::connect()
            ->prepare('UPDATE users SET apple_id = ? WHERE id = ? AND (apple_id IS NULL OR apple_id = \'\')')
            ->execute([$subject, $userId]);
    }

    private function ensureAppleColumn(): void
    {
        if (self::$appleColumnEnsured) {
            return;
        }
        $db = Database::connect();
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'apple_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$col) {
            $db->exec('ALTER TABLE users ADD COLUMN apple_id VARCHAR(64) NULL DEFAULT NULL AFTER google_id');
            $db->exec('CREATE UNIQUE INDEX users_apple_id_unique ON users (apple_id)');
        }
        self::$appleColumnEnsured = true;
    }
}
