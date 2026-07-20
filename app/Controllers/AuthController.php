<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
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
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = (new User())->findByEmail($email);

        if (!$user || !password_verify($password, $user['password'])) {
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
        $this->view('auth/register', ['title' => t('auth.register_title')], 'layouts/auth');
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

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/');
    }
}
