<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Helpers\GoogleOAuth;
use App\Models\User;

class AuthController extends Controller
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $this->view('auth/login', [
            'title' => t('auth.login_title'),
            'layout' => 'layouts/auth',
            'error' => $_SESSION['auth_error'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error']);
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = (new User())->findByEmail($email);

        if (!$user || empty($user['password']) || !password_verify($password, $user['password'])) {
            $this->view('auth/login', [
                'title' => t('auth.login_title'),
                'error' => t('auth.bad_credentials'),
                'email' => $email,
            ], 'layouts/auth');
            return;
        }

        Auth::login($user);
        $this->redirect('/');
    }

    public function registerForm(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }
        $this->view('auth/register', [
            'title' => t('auth.register_title'),
            'error' => $_SESSION['auth_error'] ?? null,
        ], 'layouts/auth');
        unset($_SESSION['auth_error']);
    }

    public function register(): void
    {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 6) {
            $this->view('auth/register', [
                'title' => t('auth.register_title'),
                'error' => t('auth.fill_fields'),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
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
            ], 'layouts/auth');
            return;
        }

        $id = $users->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'phone' => $phone,
        ]);

        $user = $users->find($id);
        Auth::login($user);
        $this->redirect('/profile');
    }

    public function googleRedirect(): void
    {
        if (Auth::check()) {
            $this->redirect('/');
        }

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
                $user = $users->find((int) $byEmail['id']);
            } else {
                $id = $users->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => null,
                    'google_id' => $googleId,
                ]);
                $user = $users->find($id);
            }
        }

        if (!$user) {
            $_SESSION['auth_error'] = t('auth.google_failed');
            $this->redirect('/login');
        }

        Auth::login($user);
        $this->redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/');
    }
}
