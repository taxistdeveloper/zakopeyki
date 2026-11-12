import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';

import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _code = TextEditingController();
  String? _challenge;
  String? _error;
  Map<String, String> _fieldErrors = const {};
  bool _busy = false;
  bool _obscure = true;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    _code.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });
    final auth = ref.read(authControllerProvider.notifier);
    try {
      if (_challenge == null) {
        final result = await auth.login(email: _email.text.trim(), password: _password.text);
        if (result is LoginNeedsTwoFactor) {
          setState(() => _challenge = result.challengeToken);
          return;
        }
      } else {
        await auth.verifyTwoFactor(challengeToken: _challenge!, code: _code.text.trim());
      }
      if (mounted) context.pop();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = errorText(context, e);
        _fieldErrors = e.fields;
        if (e.code == 'two_factor_expired') _challenge = null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _signInWithApple() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref.read(authControllerProvider.notifier).signInWithApple();
      if (!mounted || result == null) return; // отмена пользователем
      if (result is LoginNeedsTwoFactor) {
        setState(() => _challenge = result.challengeToken);
        return;
      }
      context.pop();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Sign in with Apple — только на iOS (на Android потребуется web-поток Apple, пока не подключён).
  static bool get _appleAvailable => !kIsWeb && defaultTargetPlatform == TargetPlatform.iOS;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    final twoFactor = _challenge != null;

    return Scaffold(
      appBar: AppBar(title: Text(twoFactor ? l.twoFactorTitle : l.loginTitle)),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Form(
              key: _form,
              child: ListView(
                padding: const EdgeInsets.all(24),
                children: [
                  Icon(Icons.storefront, size: 56, color: theme.colorScheme.primary),
                  const SizedBox(height: 12),
                  Text(
                    twoFactor ? l.twoFactorHint : l.loginSubtitle,
                    textAlign: TextAlign.center,
                    style: theme.textTheme.bodyLarge,
                  ),
                  const SizedBox(height: 24),
                  if (!twoFactor) ...[
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.email, AutofillHints.username],
                      textInputAction: TextInputAction.next,
                      autocorrect: false,
                      decoration: InputDecoration(labelText: l.email, errorText: _fieldErrors['email']),
                      validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _password,
                      obscureText: _obscure,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.done,
                      onFieldSubmitted: (_) => _submit(),
                      decoration: InputDecoration(
                        labelText: l.password,
                        errorText: _fieldErrors['password'],
                        suffixIcon: IconButton(
                          tooltip: l.password,
                          icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (v) => (v == null || v.isEmpty) ? l.fieldRequired : null,
                    ),
                  ] else
                    TextFormField(
                      controller: _code,
                      autofocus: true,
                      keyboardType: TextInputType.visiblePassword,
                      autofillHints: const [AutofillHints.oneTimeCode],
                      onFieldSubmitted: (_) => _submit(),
                      decoration: InputDecoration(labelText: l.code),
                      validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
                    ),
                  if (_error != null) ...[
                    const SizedBox(height: 16),
                    Text(_error!, style: TextStyle(color: theme.colorScheme.error), textAlign: TextAlign.center),
                  ],
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: _busy
                        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                        : Text(twoFactor ? l.confirm : l.loginButton),
                  ),
                  if (!twoFactor) ...[
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton(onPressed: () => context.push('/forgot-password'), child: Text(l.forgotPassword)),
                    ),
                    if (_appleAvailable) ...[
                      Row(children: [
                        const Expanded(child: Divider()),
                        Padding(padding: const EdgeInsets.symmetric(horizontal: 12), child: Text(l.orDivider)),
                        const Expanded(child: Divider()),
                      ]),
                      const SizedBox(height: 16),
                      SizedBox(
                        height: 52,
                        child: SignInWithAppleButton(
                          text: l.appleSignIn,
                          style: theme.brightness == Brightness.dark
                              ? SignInWithAppleButtonStyle.white
                              : SignInWithAppleButtonStyle.black,
                          borderRadius: const BorderRadius.all(Radius.circular(14)),
                          onPressed: _busy ? () {} : _signInWithApple,
                        ),
                      ),
                    ],
                    const SizedBox(height: 24),
                    // Wrap: на узких экранах и с крупным шрифтом кнопка переносится на новую строку
                    Wrap(
                      alignment: WrapAlignment.center,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        Text(l.noAccount),
                        TextButton(onPressed: () => context.push('/register'), child: Text(l.createAccount)),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
