import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../session.dart';
import 'register_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  String? _challenge;
  String? _error;
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _google() async {
    final session = SessionScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final challenge = await session.loginWithGoogle();
      if (!mounted) return;
      if (challenge != null) {
        setState(() => _challenge = challenge);
        return;
      }
      Navigator.of(context).pop(true);
    } on GoogleCancelled {
      // пользователь закрыл выбор аккаунта
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    final session = SessionScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      if (_challenge == null) {
        final challenge = await session.login(_email.text.trim(), _password.text);
        if (challenge != null) {
          setState(() => _challenge = challenge);
          return;
        }
      } else {
        await session.verifyTwoFactor(_challenge!, _code.text.trim());
      }
      if (mounted) Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        if (e.code == 'two_factor_expired') {
          _challenge = null;
          _code.clear();
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _register() async {
    final ok = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => const RegisterScreen()),
    );
    if (ok == true && mounted) Navigator.of(context).pop(true);
  }

  Future<void> _forgot() async {
    final email = TextEditingController(text: _email.text.trim());
    final sent = await showDialog<String>(
      context: context,
      builder: (ctx) {
        var busy = false;
        String? error;
        return StatefulBuilder(
          builder: (ctx, setLocal) => AlertDialog(
            title: const Text('Восстановление пароля'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text('Пришлём ссылку для смены пароля на вашу почту.'),
                const SizedBox(height: 12),
                TextField(
                  controller: email,
                  autofocus: true,
                  keyboardType: TextInputType.emailAddress,
                  decoration: InputDecoration(labelText: 'Email', errorText: error),
                ),
              ],
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Отмена')),
              FilledButton(
                onPressed: busy
                    ? null
                    : () async {
                        setLocal(() {
                          busy = true;
                          error = null;
                        });
                        try {
                          final msg = await SessionScope.read(context).api.forgotPassword(email.text.trim());
                          if (ctx.mounted) Navigator.pop(ctx, msg);
                        } on ApiException catch (e) {
                          setLocal(() {
                            busy = false;
                            error = e.fields['email'] ?? e.message;
                          });
                        }
                      },
                child: const Text('Отправить'),
              ),
            ],
          ),
        );
      },
    );
    email.dispose();
    if (sent != null && mounted) showSnack(context, sent);
  }

  @override
  Widget build(BuildContext context) {
    final twoFactor = _challenge != null;
    final text = Theme.of(context).textTheme;

    return Scaffold(
      appBar: AppBar(title: Text(twoFactor ? 'Подтверждение входа' : 'Вход')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            if (!twoFactor) ...[
              Text('Закопейки', style: text.headlineMedium?.copyWith(fontWeight: FontWeight.w800)),
              const SizedBox(height: 4),
              const Text('Войдите, чтобы покупать, продавать и общаться',
                  style: TextStyle(color: Colors.black54)),
              const SizedBox(height: 24),
              TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autofillHints: const [AutofillHints.email],
                textInputAction: TextInputAction.next,
                decoration: const InputDecoration(labelText: 'Email'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _password,
                obscureText: _obscure,
                autofillHints: const [AutofillHints.password],
                onSubmitted: (_) => _submit(),
                decoration: InputDecoration(
                  labelText: 'Пароль',
                  suffixIcon: IconButton(
                    onPressed: () => setState(() => _obscure = !_obscure),
                    icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                  ),
                ),
              ),
            ] else ...[
              const Text(
                'Введите 6-значный код из приложения-аутентификатора или резервный код.',
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _code,
                autofocus: true,
                keyboardType: TextInputType.visiblePassword,
                autofillHints: const [AutofillHints.oneTimeCode],
                onSubmitted: (_) => _submit(),
                decoration: const InputDecoration(labelText: 'Код'),
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 12),
              Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],
            const SizedBox(height: 20),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : Text(twoFactor ? 'Подтвердить' : 'Войти'),
            ),
            if (twoFactor)
              TextButton(
                onPressed: () => setState(() {
                  _challenge = null;
                  _code.clear();
                  _error = null;
                }),
                child: const Text('Назад'),
              )
            else ...[
              const SizedBox(height: 12),
              const Row(
                children: [
                  Expanded(child: Divider()),
                  Padding(
                    padding: EdgeInsets.symmetric(horizontal: 12),
                    child: Text('или', style: TextStyle(color: Colors.black45)),
                  ),
                  Expanded(child: Divider()),
                ],
              ),
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: _busy ? null : _google,
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(50),
                  backgroundColor: Colors.white,
                  foregroundColor: Colors.black87,
                ),
                icon: const Text('G',
                    style: TextStyle(
                        fontSize: 20, fontWeight: FontWeight.w800, color: Color(0xFF4285F4))),
                label: const Text('Войти через Google'),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: _forgot,
                child: const Text('Забыли пароль?'),
              ),
              const Divider(height: 32),
              OutlinedButton(
                onPressed: _register,
                child: const Text('Создать аккаунт'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
