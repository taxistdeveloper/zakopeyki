import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';

/// Запрос ссылки для сброса пароля. Сам сброс — по ссылке из письма на сайте.
class ForgotPasswordScreen extends ConsumerStatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  ConsumerState<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends ConsumerState<ForgotPasswordScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  bool _busy = false;
  String? _error;
  String? _done;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final message = await ref.read(authControllerProvider.notifier).forgotPassword(_email.text.trim());
      if (mounted) setState(() => _done = message);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fields['email'] ?? errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.forgotTitle)),
      body: SafeArea(
        child: _done != null
            ? MessageView(icon: Icons.mark_email_read_outlined, title: _done!)
            : Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 440),
                  child: Form(
                    key: _form,
                    child: ListView(
                      padding: const EdgeInsets.all(24),
                      children: [
                        Text(l.forgotHint, style: theme.textTheme.bodyLarge),
                        const SizedBox(height: 20),
                        TextFormField(
                          controller: _email,
                          autofocus: true,
                          keyboardType: TextInputType.emailAddress,
                          autocorrect: false,
                          autofillHints: const [AutofillHints.email],
                          onFieldSubmitted: (_) => _submit(),
                          decoration: InputDecoration(labelText: l.email, errorText: _error),
                          validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
                        ),
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _busy ? null : _submit,
                          child: _busy
                              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                              : Text(l.sendLink),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
      ),
    );
  }
}
