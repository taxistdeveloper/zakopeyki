<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\ActivityLogger;
use App\Helpers\GoogleOAuth;
use App\Helpers\Mail;
use App\Helpers\Totp;
use App\Models\User;
use App\Services\Security\AuthThrottle;

class AuthController extends Controller
{
    private const PENDING_2FA_TTL = 300;

    public function loginForm(): void
    {
        // Google OAuth callback приходит на /login (redirect_uri в Console)
        if (isset($_GET['code']) || isset($_GET['error'])) {
            $this->googleCallback();
            return;
        }

        if (Auth::check()) {
            $this->redirect('/');
        }
        unset($_SESSION['pending_2fa']);
        $this->view('auth/login', [
            'title' => t('auth.login_title'),
            'layout' => 'layouts/auth',
            'error' => $_SESSION['auth_error'] ?? null,
            'success' => $_SESSION['auth_success'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error'], $_SESSION['auth_success']);
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        $throttle = (new AuthThrottle())->hitAll([
            ['login_ip', AuthThrottle::ip()],
            ['login_email', $email],
        ]);
        if (!$throttle['allowed']) {
            $this->view('auth/login', [
                'title' => t('auth.login_title'),
                'error' => t('auth.too_many_attempts', ['minutes' => (string) max(1, (int) ceil(($throttle['retry_after'] ?? 60) / 60))]),
                'email' => $email,
                'remember' => $remember,
            ], 'layouts/auth');
            return;
        }

        $user = (new User())->findByEmail($email);

        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            ActivityLogger::warning('auth.login_failed', 'Неудачная попытка входа: ' . $email, null, null, [
                'email' => $email,
            ]);
            $this->view('auth/login', [
                'title' => t('auth.login_title'),
                'error' => t('auth.bad_credentials'),
                'email' => $email,
                'remember' => $remember,
            ], 'layouts/auth');
            return;
        }

        if ((new User())->isBlocked($user)) {
            ActivityLogger::warning('auth.login_blocked', 'Вход в заблокированный аккаунт', 'user', (int) $user['id']);
            $this->view('auth/login', [
                'title' => t('auth.login_title'),
                'error' => t('auth.account_blocked'),
                'email' => $email,
                'remember' => $remember,
            ], 'layouts/auth');
            return;
        }

        if ((new User())->hasTwoFactor($user)) {
            $this->beginTwoFactorChallenge((int) $user['id'], $remember);
            $this->redirect('/login/2fa');
        }

        Auth::login($user, $remember);
        ActivityLogger::info('auth.login', 'Вход в аккаунт', 'user', (int) $user['id']);
        $this->redirect('/');
    }

    public function twoFactorForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        if (!$this->pendingTwoFactorUserId()) {
            $this->redirect('/login');
        }

        $this->view('auth/two-factor', [
            'title' => t('auth.two_factor_title'),
            'error' => $_SESSION['auth_error'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error']);
    }

    public function twoFactorVerify(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $userId = $this->pendingTwoFactorUserId();
        if (!$userId) {
            $_SESSION['auth_error'] = t('auth.two_factor_expired');
            $this->redirect('/login');
        }

        $code = trim((string) ($_POST['code'] ?? ''));
        $throttle = (new AuthThrottle())->hit('two_factor', 'u' . $userId);
        if (!$throttle['allowed']) {
            $this->clearTwoFactorChallenge();
            $_SESSION['auth_error'] = t('auth.too_many_attempts', ['minutes' => (string) max(1, (int) ceil(($throttle['retry_after'] ?? 60) / 60))]);
            $this->redirect('/login');
        }
        $users = new User();
        $user = $users->find($userId);

        if (!$user || !$users->hasTwoFactor($user) || $users->isBlocked($user)) {
            $this->clearTwoFactorChallenge();
            $_SESSION['auth_error'] = t('auth.two_factor_expired');
            $this->redirect('/login');
        }

        $ok = Totp::verify((string) $user['two_factor_secret'], $code)
            || $users->consumeRecoveryCode($userId, $code);

        if (!$ok) {
            $this->view('auth/two-factor', [
                'title' => t('auth.two_factor_title'),
                'error' => t('auth.two_factor_invalid'),
            ], 'layouts/auth');
            return;
        }

        $remember = !empty(($_SESSION['pending_2fa'] ?? [])['remember']);
        $this->clearTwoFactorChallenge();
        Auth::login($user, $remember);
        ActivityLogger::info('auth.login', 'Вход после 2FA', 'user', (int) $user['id']);
        $this->redirect('/');
    }

    public function registerForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $this->captureReferralFromRequest();
        $this->view('auth/register', [
            'title' => t('auth.register_title'),
            'error' => $_SESSION['auth_error'] ?? null,
            'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
        ], 'layouts/auth');
        unset($_SESSION['auth_error']);
    }

    public function register(): void
    {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');
        $acceptOffer = !empty($_POST['accept_offer']);
        $this->captureReferralFromRequest();

        $throttle = (new AuthThrottle())->hit('register_ip', AuthThrottle::ip());
        if (!$throttle['allowed']) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.too_many_attempts', ['minutes' => (string) max(1, (int) ceil(($throttle['retry_after'] ?? 60) / 60))]),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
            ], 'layouts/auth');
            return;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.forgot_invalid_email'),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
            ], 'layouts/auth');
            return;
        }

        if (!$acceptOffer) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.offer_required'),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
            ], 'layouts/auth');
            return;
        }

        if ($name === '' || $email === '' || strlen($password) < 8) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.fill_fields'),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
            ], 'layouts/auth');
            return;
        }

        $users = new User();
        if ($users->findByEmail($email)) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.email_taken'),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'referralRef' => (string) ($_SESSION['referral_ref'] ?? ''),
            ], 'layouts/auth');
            return;
        }

        $id = $users->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'phone' => $phone,
        ]);

        $bonusResult = (new \App\Models\Bonus())->awardRegistration((int) $id);
        $this->applyReferralBonus((int) $id);
        \App\Services\Contest\ContestReferralTracker::onRegistered((int) $id);

        $user = $users->find($id);
        $this->sendVerificationEmail($user);
        Auth::login($user);
        ActivityLogger::info('auth.register', 'Регистрация: ' . $email, 'user', (int) $id, [
            'email' => $email,
            'bonus' => $bonusResult['amount'] ?? 0,
        ]);

        if (!empty($bonusResult['ok']) && empty($bonusResult['skipped']) && ($bonusResult['amount'] ?? 0) > 0) {
            $_SESSION['flash'] = t('bonuses.flash_registration', [
                'amount' => \App\Models\Bonus::format((int) $bonusResult['amount']),
            ]);
            (new \App\Models\Notification())->createFor(
                (int) $id,
                t('bonuses.notify_registration', [
                    'amount' => \App\Models\Bonus::format((int) $bonusResult['amount']),
                ])
            );
        }

        $appConfig = $GLOBALS['appConfig'] ?? [];
        if (!empty($appConfig['stub_mode']) && !Auth::hasSiteAccess()) {
            $opensTs = strtotime((string) ($appConfig['stub_opens_at'] ?? '2026-11-30 00:00:00')) ?: strtotime('2026-11-30 00:00:00');
            $opensLabel = (int) date('j', $opensTs) . ' ' . t('seller.month_' . (int) date('n', $opensTs));
            $_SESSION['stub_flash'] = 'Регистрация прошла успешно! Мы откроемся ' . $opensLabel . '.';
            $this->redirect('/');
        }

        $this->redirect('/profile');
    }

    public function googleRedirect(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $this->captureReferralFromRequest();

        $oauth = new GoogleOAuth();
        if (!$oauth->isConfigured()) {
            $_SESSION['auth_error'] = t('auth.google_not_configured');
            $this->redirect('/login');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state'] = $state;
        $this->redirect($oauth->authorizationUrl($state));
    }

    public function googleCallback(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $oauth = new GoogleOAuth();
        $error = trim((string) ($_GET['error'] ?? ''));
        $code = trim((string) ($_GET['code'] ?? ''));
        $state = trim((string) ($_GET['state'] ?? ''));
        $expectedState = (string) ($_SESSION['google_oauth_state'] ?? '');
        unset($_SESSION['google_oauth_state']);

        if ($error !== '' || $code === '' || $state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
            $_SESSION['auth_error'] = t('auth.google_failed');
            $this->redirect('/login');
        }

        if (!$oauth->isConfigured()) {
            $_SESSION['auth_error'] = t('auth.google_not_configured');
            $this->redirect('/login');
        }

        $token = $oauth->exchangeCode($code);
        if (!$token) {
            $_SESSION['auth_error'] = t('auth.google_failed');
            $this->redirect('/login');
        }

        $info = $oauth->fetchUserInfo($token['access_token']);
        if (!$info) {
            $_SESSION['auth_error'] = t('auth.google_failed');
            $this->redirect('/login');
        }

        $googleId = (string) $info['sub'];
        $email = strtolower(trim((string) $info['email']));
        $emailVerified = filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $info['email_verified'] === true
            || $info['email_verified'] === 'true';
        if (!$emailVerified) {
            $_SESSION['auth_error'] = t('auth.google_email_unverified');
            $this->redirect('/login');
        }

        $name = trim((string) ($info['name'] ?? ''));
        if ($name === '') {
            $name = trim(($info['given_name'] ?? '') . ' ' . ($info['family_name'] ?? ''));
        }
        if ($name === '') {
            $name = strstr($email, '@', true) ?: 'User';
        }

        $users = new User();
        $user = $users->findByGoogleId($googleId);

        if (!$user) {
            $byEmail = $users->findByEmail($email);
            if ($byEmail) {
                $users->linkGoogleId((int) $byEmail['id'], $googleId);
                // Google подтвердил владение этим email (email_verified проверен выше)
                $users->markEmailVerified((int) $byEmail['id']);
                $user = $users->find((int) $byEmail['id']);
            } else {
                $id = $users->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => null,
                    'google_id' => $googleId,
                    'role' => 'user',
                ]);
                $users->markEmailVerified((int) $id);
                $user = $users->find($id);
                $bonusResult = (new \App\Models\Bonus())->awardRegistration((int) $id);
                $this->applyReferralBonus((int) $id);
                \App\Services\Contest\ContestReferralTracker::onRegistered((int) $id);
                if (!empty($bonusResult['ok']) && empty($bonusResult['skipped']) && ($bonusResult['amount'] ?? 0) > 0) {
                    $_SESSION['flash'] = t('bonuses.flash_registration', [
                        'amount' => \App\Models\Bonus::format((int) $bonusResult['amount']),
                    ]);
                    (new \App\Models\Notification())->createFor(
                        (int) $id,
                        t('bonuses.notify_registration', [
                            'amount' => \App\Models\Bonus::format((int) $bonusResult['amount']),
                        ])
                    );
                }
            }
        }

        if (!$user) {
            $_SESSION['auth_error'] = t('auth.google_failed');
            $this->redirect('/login');
        }

        if ($users->isBlocked($user)) {
            ActivityLogger::warning('auth.login_blocked', 'Вход через Google в заблокированный аккаунт', 'user', (int) $user['id']);
            $_SESSION['auth_error'] = t('auth.account_blocked');
            $this->redirect('/login');
        }

        if (empty($user['email_verified_at']) && strcasecmp((string) $user['email'], $email) === 0) {
            $users->markEmailVerified((int) $user['id']);
            $user = $users->find((int) $user['id']) ?? $user;
        }

        if ($users->hasTwoFactor($user)) {
            $this->beginTwoFactorChallenge((int) $user['id']);
            $this->redirect('/login/2fa');
        }

        Auth::login($user);
        ActivityLogger::info('auth.login', 'Вход через Google', 'user', (int) $user['id']);
        $this->redirect('/');
    }

    public function logout(): void
    {
        ActivityLogger::info('auth.logout', 'Выход из аккаунта');
        Auth::logout();
        $this->redirect('/');
    }

    public function forgotPasswordForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $this->view('auth/forgot-password', [
            'title' => t('auth.forgot_title'),
            'error' => $_SESSION['auth_error'] ?? null,
            'success' => $_SESSION['auth_success'] ?? null,
            'resetUrl' => $_SESSION['auth_reset_url'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error'], $_SESSION['auth_success'], $_SESSION['auth_reset_url']);
    }

    public function forgotPassword(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->view('auth/forgot-password', [
                'title' => t('auth.forgot_title'),
                'error' => t('auth.forgot_invalid_email'),
                'email' => $email,
            ], 'layouts/auth');
            return;
        }

        $throttle = (new AuthThrottle())->hitAll([
            ['forgot_ip', AuthThrottle::ip()],
            ['forgot_email', $email],
        ]);
        if (!$throttle['allowed']) {
            $this->view('auth/forgot-password', [
                'title' => t('auth.forgot_title'),
                'error' => t('auth.too_many_attempts', ['minutes' => (string) max(1, (int) ceil(($throttle['retry_after'] ?? 60) / 60))]),
                'email' => $email,
            ], 'layouts/auth');
            return;
        }

        $users = new User();
        $user = $users->findByEmail($email);
        $resetUrl = null;
        if ($user) {
            $token = $users->createPasswordResetToken((int) $user['id'], 3600);
            $mail = new Mail();
            $resetUrl = $mail->absoluteUrl('/reset-password/' . $token);
            $name = trim((string) ($user['name'] ?? '')) ?: $email;
            $subject = t('auth.reset_mail_subject');
            $text = t('auth.reset_mail_text', [
                'name' => $name,
                'url' => $resetUrl,
                'minutes' => '60',
            ]);
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
            $mail->send($email, $subject, $text, $html);
        }

        // Одинаковый ответ — не раскрываем, существует ли email
        $_SESSION['auth_success'] = t('auth.forgot_sent');

        // На локали без рабочего SMTP показываем ссылку на экране
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $isLocal = str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');
        if ($isLocal && $resetUrl) {
            $_SESSION['auth_reset_url'] = $resetUrl;
        }

        $this->redirect('/forgot-password');
    }

    public function resetPasswordForm(string $token): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $users = new User();
        $user = $users->findByPasswordResetToken($token);
        if (!$user) {
            $this->view('auth/reset-password', [
                'title' => t('auth.reset_title'),
                'token' => $token,
                'invalid' => true,
                'error' => t('auth.reset_invalid'),
            ], 'layouts/auth');
            return;
        }

        $this->view('auth/reset-password', [
            'title' => t('auth.reset_title'),
            'token' => $token,
            'invalid' => false,
            'error' => $_SESSION['auth_error'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error']);
    }

    public function resetPassword(string $token): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $users = new User();
        $user = $users->findByPasswordResetToken($token);

        if (!$user) {
            $this->view('auth/reset-password', [
                'title' => t('auth.reset_title'),
                'token' => $token,
                'invalid' => true,
                'error' => t('auth.reset_invalid'),
            ], 'layouts/auth');
            return;
        }

        if (strlen($password) < 8) {
            $this->view('auth/reset-password', [
                'title' => t('auth.reset_title'),
                'token' => $token,
                'invalid' => false,
                'error' => t('flash.password_min'),
            ], 'layouts/auth');
            return;
        }

        if ($password !== $confirm) {
            $this->view('auth/reset-password', [
                'title' => t('auth.reset_title'),
                'token' => $token,
                'invalid' => false,
                'error' => t('flash.password_mismatch'),
            ], 'layouts/auth');
            return;
        }

        if (!$users->resetPasswordWithToken($token, $password)) {
            $this->view('auth/reset-password', [
                'title' => t('auth.reset_title'),
                'token' => $token,
                'invalid' => true,
                'error' => t('auth.reset_invalid'),
            ], 'layouts/auth');
            return;
        }

        $_SESSION['auth_success'] = t('auth.reset_success');
        $this->redirect('/login');
    }

    private function beginTwoFactorChallenge(int $userId, bool $remember = false): void
    {
        $_SESSION['pending_2fa'] = [
            'user_id' => $userId,
            'expires' => time() + self::PENDING_2FA_TTL,
            'remember' => $remember,
        ];
    }

    private function pendingTwoFactorUserId(): ?int
    {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($pending)) {
            return null;
        }
        $userId = (int) ($pending['user_id'] ?? 0);
        $expires = (int) ($pending['expires'] ?? 0);
        if ($userId < 1 || $expires < time()) {
            $this->clearTwoFactorChallenge();
            return null;
        }
        return $userId;
    }

    private function clearTwoFactorChallenge(): void
    {
        unset($_SESSION['pending_2fa']);
    }

    /** GET /verify-email/{token} — подтверждение email по одноразовой ссылке. */
    public function verifyEmail(string $token): void
    {
        $users = new User();
        $user = $users->findByEmailVerifyToken($token);
        if (!$user) {
            $_SESSION[Auth::check() ? 'flash' : 'auth_error'] = t('auth.verify_invalid');
            $this->redirect(Auth::check() ? '/profile' : '/login');
        }

        $users->markEmailVerified((int) $user['id']);
        ActivityLogger::info('auth.email_verified', 'Email подтверждён', 'user', (int) $user['id']);

        if (Auth::check() && (int) Auth::id() === (int) $user['id']) {
            $fresh = $users->find((int) $user['id']);
            if ($fresh) {
                Auth::refresh($fresh);
            }
            $_SESSION['flash'] = t('auth.verify_success');
            $this->redirect('/profile');
        }

        $_SESSION['auth_success'] = t('auth.verify_success');
        $this->redirect('/login');
    }

    /** POST /profile/email/verify-resend — повторная отправка письма. */
    public function resendVerification(): void
    {
        Auth::requireLogin();
        $users = new User();
        $user = $users->find((int) Auth::id());
        if (!$user || $users->isEmailVerified($user)) {
            $this->json(['ok' => true, 'already_verified' => true]);
        }

        $throttle = (new AuthThrottle())->hit('verify_resend', 'u' . (int) $user['id']);
        if (!$throttle['allowed']) {
            $this->json([
                'ok' => false,
                'message' => t('auth.too_many_attempts', ['minutes' => (string) max(1, (int) ceil(($throttle['retry_after'] ?? 60) / 60))]),
            ], 429);
        }

        $sent = $this->sendVerificationEmail($user);
        $this->json(['ok' => $sent, 'message' => $sent ? t('auth.verify_sent') : t('auth.verify_send_failed')], $sent ? 200 : 500);
    }

    private function sendVerificationEmail(?array $user): bool
    {
        if (!$user || !empty($user['email_verified_at']) || empty($user['email']) || !Mail::configured()) {
            return false;
        }
        try {
            $users = new User();
            $token = $users->createEmailVerifyToken((int) $user['id'], 86400);
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

    private function captureReferralFromRequest(): void
    {
        \App\Helpers\ReferralCapture::captureFromRequest();
    }

    private function applyReferralBonus(int $newUserId): void
    {
        $ref = trim((string) ($_SESSION['referral_ref'] ?? ''));
        unset($_SESSION['referral_ref']);
        if ($ref === '' || $newUserId <= 0) {
            return;
        }

        $users = new User();
        $referrer = $users->findByReferralCode($ref);
        if (!$referrer) {
            return;
        }

        $referrerId = (int) $referrer['id'];
        if ($referrerId === $newUserId) {
            return;
        }

        $users->setReferredBy($newUserId, $referrerId);
        $result = (new \App\Models\Bonus())->awardReferral($referrerId, $newUserId);
        if (!empty($result['ok']) && empty($result['skipped']) && ($result['amount'] ?? 0) > 0) {
            (new \App\Models\Notification())->createFor(
                $referrerId,
                t('bonuses.notify_referral', [
                    'amount' => \App\Models\Bonus::format((int) $result['amount']),
                ])
            );
        }
    }
}
