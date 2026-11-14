import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import '../widgets/common.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _accept = false;
  bool _busy = false;
  String? _error;
  Map<String, String> _fields = const {};

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    if (!_accept) {
      setState(() => _error = 'Примите условия оферты и политику конфиденциальности');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _fields = const {};
    });
    try {
      await SessionScope.read(context).register(
        name: _name.text.trim(),
        email: _email.text.trim(),
        password: _password.text,
        phone: _phone.text.trim(),
      );
      if (!mounted) return;
      showSnack(context, 'Аккаунт создан. Подтвердите email по ссылке из письма');
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      setState(() {
        _error = e.message;
        _fields = e.fields;
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final link = const TextStyle(color: kBrand, fontWeight: FontWeight.w600);
    return Scaffold(
      appBar: AppBar(title: const Text('Регистрация')),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Text('Создайте аккаунт', style: text.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            const Text('Покупайте с безопасной сделкой и продавайте свои вещи',
                style: TextStyle(color: Colors.black54)),
            const SizedBox(height: 20),
            TextField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.name],
              decoration: InputDecoration(labelText: 'Имя', errorText: _fields['name']),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.email],
              decoration: InputDecoration(labelText: 'Email', errorText: _fields['email']),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _phone,
              keyboardType: TextInputType.phone,
              textInputAction: TextInputAction.next,
              autofillHints: const [AutofillHints.telephoneNumber],
              decoration: InputDecoration(
                labelText: 'Телефон (необязательно)',
                hintText: '+7 7__ ___ __ __',
                errorText: _fields['phone'],
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _password,
              obscureText: _obscure,
              autofillHints: const [AutofillHints.newPassword],
              onSubmitted: (_) => _submit(),
              decoration: InputDecoration(
                labelText: 'Пароль',
                helperText: 'Не меньше 8 символов',
                errorText: _fields['password'],
                suffixIcon: IconButton(
                  onPressed: () => setState(() => _obscure = !_obscure),
                  icon: Icon(_obscure ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                ),
              ),
            ),
            const SizedBox(height: 8),
            CheckboxListTile(
              value: _accept,
              onChanged: (v) => setState(() => _accept = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              title: Text.rich(TextSpan(
                style: const TextStyle(fontSize: 14),
                children: [
                  const TextSpan(text: 'Принимаю '),
                  TextSpan(
                    text: 'оферту',
                    style: link,
                    recognizer: TapGestureRecognizer()
                      ..onTap = () => openWeb(context, '/offer', title: 'Оферта'),
                  ),
                  const TextSpan(text: ' и '),
                  TextSpan(
                    text: 'политику конфиденциальности',
                    style: link,
                    recognizer: TapGestureRecognizer()
                      ..onTap = () => openWeb(context, '/privacy', title: 'Конфиденциальность'),
                  ),
                ],
              )),
            ),
            if (_error != null) ...[
              const SizedBox(height: 4),
              Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
            ],
            const SizedBox(height: 16),
            FilledButton(onPressed: _busy ? null : _submit, child: busyLabel(_busy, 'Зарегистрироваться')),
          ],
        ),
      ),
    );
  }
}
