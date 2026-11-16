import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/catalog_repository.dart';

/// Регистрация по email. Проверки дублируют серверные только для удобства —
/// окончательное решение и тексты ошибок приходят с сервера (AccountService).
class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _phone = TextEditingController();
  late final TapGestureRecognizer _offerTap = TapGestureRecognizer()..onTap = _openOffer;
  bool _accept = false;
  bool _busy = false;
  bool _obscure = true;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  static final _emailRe = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _password.dispose();
    _phone.dispose();
    _offerTap.dispose();
    super.dispose();
  }

  void _openOffer() {
    final url = ref.read(remoteConfigProvider).value?.offerUrl;
    if (url != null && url.isNotEmpty) launchUrl(Uri.parse(url), mode: LaunchMode.inAppBrowserView);
  }

  Future<void> _submit() async {
    final l = AppLocalizations.of(context);
    final valid = _form.currentState?.validate() ?? false;
    if (!_accept) {
      setState(() => _error = l.offerRequired);
      return;
    }
    if (!valid) return;
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });
    try {
      final bonus = await ref.read(authControllerProvider.notifier).register(
            name: _name.text.trim(),
            email: _email.text.trim(),
            password: _password.text,
            phone: _phone.text.trim(),
            acceptOffer: _accept,
          );
      if (!mounted) return;
      context.go('/');
      if (bonus != null && bonus > 0) showMessageAfterNavigation(l.registrationBonus(bonus));
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.fields;
        _error = e.fields.isEmpty ? errorText(context, e) : null;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: Text(l.registerTitle)),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Form(
              key: _form,
              child: AutofillGroup(
                child: ListView(
                  padding: const EdgeInsets.all(24),
                  children: [
                    TextFormField(
                      controller: _name,
                      textCapitalization: TextCapitalization.words,
                      autofillHints: const [AutofillHints.name],
                      textInputAction: TextInputAction.next,
                      decoration: InputDecoration(labelText: l.name, errorText: _fieldErrors['name']),
                      validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autocorrect: false,
                      autofillHints: const [AutofillHints.email],
                      textInputAction: TextInputAction.next,
                      decoration: InputDecoration(labelText: l.email, errorText: _fieldErrors['email']),
                      validator: (v) {
                        final value = v?.trim() ?? '';
                        if (value.isEmpty) return l.fieldRequired;
                        return _emailRe.hasMatch(value) ? null : l.invalidEmail;
                      },
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _password,
                      obscureText: _obscure,
                      autofillHints: const [AutofillHints.newPassword],
                      textInputAction: TextInputAction.next,
                      decoration: InputDecoration(
                        labelText: l.password,
                        helperText: l.passwordHint,
                        errorText: _fieldErrors['password'],
                        suffixIcon: IconButton(
                          tooltip: l.password,
                          icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (v) => (v ?? '').length < 8 ? l.passwordTooShort : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      textInputAction: TextInputAction.done,
                      decoration: InputDecoration(labelText: l.phoneOptional),
                    ),
                    const SizedBox(height: 12),
                    CheckboxListTile(
                      value: _accept,
                      onChanged: (v) => setState(() {
                        _accept = v ?? false;
                        if (_accept && _error == l.offerRequired) _error = null;
                      }),
                      controlAffinity: ListTileControlAffinity.leading,
                      contentPadding: EdgeInsets.zero,
                      title: Text.rich(TextSpan(children: [
                        TextSpan(text: '${l.acceptOfferPrefix} '),
                        TextSpan(
                          text: l.offerLink,
                          style: TextStyle(color: theme.colorScheme.primary, decoration: TextDecoration.underline),
                          recognizer: _offerTap,
                        ),
                      ])),
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 8),
                      Text(_error!, style: TextStyle(color: theme.colorScheme.error), textAlign: TextAlign.center),
                    ],
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                          : Text(l.registerButton),
                    ),
                    const SizedBox(height: 8),
                    TextButton(onPressed: () => context.pop(), child: Text(l.haveAccount)),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
