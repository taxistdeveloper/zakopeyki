import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Личные данные: имя, фамилия, логин, телефон.
class PersonalDataScreen extends StatefulWidget {
  const PersonalDataScreen({super.key});

  @override
  State<PersonalDataScreen> createState() => _PersonalDataScreenState();
}

class _PersonalDataScreenState extends State<PersonalDataScreen> {
  AccountProfile? _p;
  String? _error;
  bool _busy = false;
  Map<String, String> _fields = const {};
  final _first = TextEditingController();
  final _last = TextEditingController();
  final _login = TextEditingController();
  final _phone = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_first, _last, _login, _phone]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final p = await SessionScope.read(context).api.accountProfile();
      if (!mounted) return;
      setState(() {
        _p = p;
        _first.text = p.firstName;
        _last.text = p.lastName;
        _login.text = p.login;
        _phone.text = p.phone;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _save() async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _fields = const {};
    });
    final session = SessionScope.read(context);
    try {
      final (p, user) = await session.api.updateAccountProfile({
        'first_name': _first.text.trim(),
        'last_name': _last.text.trim(),
        'login': _login.text.trim(),
        'phone': _phone.text.trim(),
      });
      session.user = user;
      session.refreshUser();
      if (!mounted) return;
      setState(() => _p = p);
      showSnack(context, 'Сохранено');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _fields = e.fields);
      if (e.fields.isEmpty) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    return Scaffold(
      appBar: AppBar(title: const Text('Личные данные')),
      body: p == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12),
              children: [
                SectionCard(
                  child: Column(
                    children: [
                      TextField(
                        controller: _first,
                        textCapitalization: TextCapitalization.words,
                        decoration: InputDecoration(labelText: 'Имя', errorText: _fields['first_name']),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _last,
                        textCapitalization: TextCapitalization.words,
                        decoration: const InputDecoration(labelText: 'Фамилия'),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _login,
                        decoration: InputDecoration(
                          labelText: 'Логин',
                          prefixText: '@',
                          helperText: 'Латинские буквы, цифры и _',
                          errorText: _fields['login'],
                        ),
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        controller: _phone,
                        keyboardType: TextInputType.phone,
                        decoration: const InputDecoration(labelText: 'Телефон'),
                      ),
                    ],
                  ),
                ),
                SectionCard(
                  child: Row(
                    children: [
                      const Icon(Icons.mail_outline_rounded, color: Colors.black45),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(p.email),
                            Text(p.emailVerified ? 'Email подтверждён' : 'Email не подтверждён',
                                style: TextStyle(
                                    fontSize: 12.5,
                                    color: p.emailVerified ? const Color(0xFF16A34A) : const Color(0xFFF59E0B))),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
      bottomNavigationBar: p == null
          ? null
          : BottomActionBar(child: FilledButton(onPressed: _busy ? null : _save, child: busyLabel(_busy, 'Сохранить'))),
    );
  }
}

/// Пароль и двухфакторная аутентификация.
class SecurityScreen extends StatefulWidget {
  const SecurityScreen({super.key});

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  AccountProfile? _p;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final p = await SessionScope.read(context).api.accountProfile();
      if (mounted) setState(() => _p = p);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  // --- пароль ------------------------------------------------------------------

  Future<void> _changePassword() async {
    final p = _p!;
    final current = TextEditingController();
    final next = TextEditingController();
    final repeat = TextEditingController();
    String? error;
    var busy = false;
    final done = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(p.hasPassword ? 'Сменить пароль' : 'Задать пароль',
                  style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 16),
              if (p.hasPassword) ...[
                TextField(
                  controller: current,
                  obscureText: true,
                  autofillHints: const [AutofillHints.password],
                  decoration: const InputDecoration(labelText: 'Текущий пароль'),
                ),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: next,
                obscureText: true,
                autofillHints: const [AutofillHints.newPassword],
                decoration: const InputDecoration(labelText: 'Новый пароль', helperText: 'Не меньше 8 символов'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: repeat,
                obscureText: true,
                decoration: InputDecoration(labelText: 'Повторите пароль', errorText: error),
              ),
              const SizedBox(height: 20),
              FilledButton(
                onPressed: busy
                    ? null
                    : () async {
                        if (next.text != repeat.text) {
                          setLocal(() => error = 'Пароли не совпадают');
                          return;
                        }
                        setLocal(() {
                          busy = true;
                          error = null;
                        });
                        try {
                          final msg = await SessionScope.read(context)
                              .api
                              .changePassword(p.hasPassword ? current.text : null, next.text);
                          if (ctx.mounted) Navigator.pop(ctx, msg);
                        } on ApiException catch (e) {
                          setLocal(() {
                            busy = false;
                            error = e.fields.values.firstOrNull ?? e.message;
                          });
                        }
                      },
                child: busyLabel(busy, 'Сохранить'),
              ),
            ],
          ),
        ),
      ),
    );
    for (final c in [current, next, repeat]) {
      c.dispose();
    }
    if (done != null && mounted) {
      showSnack(context, done);
      _load();
    }
  }

  // --- 2FA ---------------------------------------------------------------------

  Future<void> _enable2fa() async {
    final api = SessionScope.read(context).api;
    String secret;
    String uri;
    try {
      (secret, uri) = await api.twoFactorSetup();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
      return;
    }
    if (!mounted) return;
    final codes = await Navigator.of(context).push<List<String>>(MaterialPageRoute(
      builder: (_) => _TwoFactorSetupScreen(secret: secret, uri: uri),
    ));
    if (codes == null || !mounted) return;
    await _showRecovery(codes);
    _load();
  }

  Future<void> _showRecovery(List<String> codes) async {
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        title: const Text('Резервные коды'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Сохраните их в надёжном месте. Каждый код можно использовать один раз, '
                'если телефон с аутентификатором недоступен.'),
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: const Color(0xFFF1F5F9), borderRadius: BorderRadius.circular(10)),
              child: SelectableText(codes.join('\n'),
                  style: const TextStyle(fontFamily: 'monospace', fontSize: 15, height: 1.5)),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              Clipboard.setData(ClipboardData(text: codes.join('\n')));
              showSnack(context, 'Коды скопированы');
            },
            child: const Text('Скопировать'),
          ),
          FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Я сохранил')),
        ],
      ),
    );
  }

  Future<void> _disable2fa() async {
    final p = _p!;
    final password = TextEditingController();
    final code = TextEditingController();
    String? error;
    var busy = false;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => Padding(
          padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Отключить 2FA', style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 6),
              const Text('Аккаунт станет защищён только паролем.', style: TextStyle(color: Colors.black54)),
              const SizedBox(height: 16),
              if (p.hasPassword) ...[
                TextField(controller: password, obscureText: true, decoration: const InputDecoration(labelText: 'Пароль')),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: code,
                keyboardType: TextInputType.visiblePassword,
                decoration: InputDecoration(labelText: 'Код из аутентификатора или резервный', errorText: error),
              ),
              const SizedBox(height: 20),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: const Color(0xFFDC2626)),
                onPressed: busy
                    ? null
                    : () async {
                        setLocal(() {
                          busy = true;
                          error = null;
                        });
                        try {
                          await SessionScope.read(context)
                              .api
                              .twoFactorDisable(p.hasPassword ? password.text : null, code.text.trim());
                          if (ctx.mounted) Navigator.pop(ctx, true);
                        } on ApiException catch (e) {
                          setLocal(() {
                            busy = false;
                            error = e.fields.values.firstOrNull ?? e.message;
                          });
                        }
                      },
                child: busyLabel(busy, 'Отключить'),
              ),
            ],
          ),
        ),
      ),
    );
    password.dispose();
    code.dispose();
    if (ok == true && mounted) {
      showSnack(context, 'Двухфакторная защита отключена');
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    return Scaffold(
      appBar: AppBar(title: const Text('Пароль и 2FA')),
      body: p == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12),
              children: [
                SectionCard(
                  padding: EdgeInsets.zero,
                  child: ListTile(
                    leading: const Icon(Icons.password_rounded, color: kBrand),
                    title: Text(p.hasPassword ? 'Сменить пароль' : 'Задать пароль'),
                    subtitle: p.hasPassword ? null : const Text('Сейчас вы входите только через Google'),
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: _changePassword,
                  ),
                ),
                SectionCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Row(
                        children: [
                          Icon(Icons.shield_outlined, color: p.twoFactor ? const Color(0xFF16A34A) : Colors.black45),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text('Двухфакторная аутентификация', style: TextStyle(fontWeight: FontWeight.w600)),
                                Text(p.twoFactor ? 'Включена' : 'Выключена',
                                    style: TextStyle(color: p.twoFactor ? const Color(0xFF16A34A) : Colors.black45)),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'При входе кроме пароля понадобится код из приложения-аутентификатора '
                        '(Google Authenticator, Microsoft Authenticator и др.).',
                        style: TextStyle(color: Colors.black54, fontSize: 13),
                      ),
                      const SizedBox(height: 12),
                      p.twoFactor
                          ? OutlinedButton(
                              onPressed: _disable2fa,
                              style: OutlinedButton.styleFrom(foregroundColor: const Color(0xFFDC2626)),
                              child: const Text('Отключить'),
                            )
                          : FilledButton(onPressed: _enable2fa, child: const Text('Включить')),
                    ],
                  ),
                ),
              ],
            ),
    );
  }
}

class _TwoFactorSetupScreen extends StatefulWidget {
  const _TwoFactorSetupScreen({required this.secret, required this.uri});

  final String secret;
  final String uri;

  @override
  State<_TwoFactorSetupScreen> createState() => _TwoFactorSetupScreenState();
}

class _TwoFactorSetupScreenState extends State<_TwoFactorSetupScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _openAuthenticator() async {
    final ok = await launchUrl(Uri.parse(widget.uri), mode: LaunchMode.externalApplication);
    if (!ok && mounted) showSnack(context, 'Приложение-аутентификатор не найдено — введите ключ вручную');
  }

  Future<void> _confirm() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final codes = await SessionScope.read(context).api.twoFactorConfirm(widget.secret, _code.text.trim());
      if (mounted) Navigator.of(context).pop(codes);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.fields['code'] ?? e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final grouped = RegExp('.{1,4}').allMatches(widget.secret).map((m) => m.group(0)).join(' ');
    return Scaffold(
      appBar: AppBar(title: const Text('Включение 2FA')),
      body: ListView(
        padding: const EdgeInsets.only(top: 12),
        children: [
          SectionCard(
            title: '1. Добавьте аккаунт в аутентификатор',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                FilledButton.tonalIcon(
                  onPressed: _openAuthenticator,
                  icon: const Icon(Icons.open_in_new_rounded),
                  label: const Text('Открыть аутентификатор'),
                ),
                const SizedBox(height: 12),
                const Text('или введите ключ вручную:', style: TextStyle(color: Colors.black54)),
                const SizedBox(height: 6),
                InkWell(
                  onTap: () {
                    Clipboard.setData(ClipboardData(text: widget.secret));
                    showSnack(context, 'Ключ скопирован');
                  },
                  child: Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(color: const Color(0xFFF1F5F9), borderRadius: BorderRadius.circular(10)),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(grouped,
                              style: const TextStyle(fontFamily: 'monospace', fontSize: 15, fontWeight: FontWeight.w600)),
                        ),
                        const Icon(Icons.copy_rounded, size: 18, color: Colors.black45),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
          SectionCard(
            title: '2. Введите код из аутентификатора',
            child: TextField(
              controller: _code,
              keyboardType: TextInputType.number,
              maxLength: 6,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              autofillHints: const [AutofillHints.oneTimeCode],
              style: const TextStyle(fontSize: 22, letterSpacing: 6, fontWeight: FontWeight.w700),
              decoration: InputDecoration(hintText: '000000', counterText: '', errorText: _error),
            ),
          ),
        ],
      ),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(onPressed: _busy ? null : _confirm, child: busyLabel(_busy, 'Подтвердить')),
      ),
    );
  }
}
