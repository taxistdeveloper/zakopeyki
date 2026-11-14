import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../widgets/common.dart';

/// Удаление аккаунта (требование Google Play для приложений с регистрацией).
/// Правила — на сервере (AccountDeletionService): незавершённые сделки, деньги на кошельке и т.п. блокируют удаление.
class DeleteAccountScreen extends StatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  State<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends State<DeleteAccountScreen> {
  static const _danger = Color(0xFFDC2626);

  bool? _canDelete;
  List<String> _blockers = const [];
  bool _requiresPassword = true;
  String? _error;
  bool _understand = false;
  bool _busy = false;
  String? _fieldError;
  final _password = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final d = await SessionScope.read(context).api.deletionStatus();
      if (!mounted) return;
      setState(() {
        _canDelete = d['can_delete'] == true;
        _requiresPassword = d['requires_password'] != false;
        _blockers = ((d['blockers'] as List?) ?? []).map((b) => (b as Map)['message'].toString()).toList();
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _delete() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Удалить аккаунт навсегда?'),
        content: const Text('Вы больше не сможете войти, восстановить аккаунт будет нельзя.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: _danger),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Удалить'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() {
      _busy = true;
      _fieldError = null;
    });
    final session = SessionScope.read(context);
    try {
      await session.api.deleteAccount(password: _requiresPassword ? _password.text : null);
      await session.signOutLocally();
      if (!mounted) return;
      Navigator.of(context).popUntil((r) => r.isFirst);
      showSnack(context, 'Аккаунт удалён');
    } on ApiException catch (e) {
      if (!mounted) return;
      switch (e.code) {
        case 'wrong_password':
          setState(() => _fieldError = e.fields['password'] ?? e.message);
        case 'reauth_required':
          await _reauth();
        case 'deletion_blocked':
          showSnack(context, e.message);
          _load();
        default:
          showSnack(context, e.message);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Аккаунт без пароля (вход через Google): сервер просит свежий вход — не старше 10 минут.
  Future<void> _reauth() async {
    final go = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Подтвердите вход'),
        content: const Text('Для удаления войдите в аккаунт ещё раз, затем сразу повторите удаление.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Войти заново')),
        ],
      ),
    );
    if (go != true || !mounted) return;
    final session = SessionScope.read(context);
    await session.logout();
    if (!mounted) return;
    final ok = await openLogin(context);
    if (ok && mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Удаление аккаунта')),
      body: _canDelete == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12, bottom: 24),
              children: [
                SectionCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: const [
                      Row(children: [
                        Icon(Icons.warning_amber_rounded, color: _danger),
                        SizedBox(width: 8),
                        Text('Это действие необратимо', style: TextStyle(fontWeight: FontWeight.w700)),
                      ]),
                      SizedBox(height: 8),
                      Text('Имя, email, телефон, ИИН/БИН, адреса, фото, пароль и вход через Google будут стёрты, '
                          'объявления сняты с публикации, избранное, подписки и уведомления удалены. '
                          'Завершённые сделки и платежи останутся в истории второй стороны без ваших данных.'),
                    ],
                  ),
                ),
                if (_blockers.isNotEmpty)
                  SectionCard(
                    title: 'Сначала нужно',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        for (final b in _blockers)
                          Padding(
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Icon(Icons.block_rounded, size: 18, color: _danger),
                                const SizedBox(width: 8),
                                Expanded(child: Text(b)),
                              ],
                            ),
                          ),
                      ],
                    ),
                  )
                else
                  SectionCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (_requiresPassword) ...[
                          TextField(
                            controller: _password,
                            obscureText: true,
                            autofillHints: const [AutofillHints.password],
                            onChanged: (_) => setState(() {}),
                            decoration: InputDecoration(labelText: 'Пароль', errorText: _fieldError),
                          ),
                          const SizedBox(height: 8),
                        ],
                        CheckboxListTile(
                          value: _understand,
                          onChanged: (v) => setState(() => _understand = v ?? false),
                          controlAffinity: ListTileControlAffinity.leading,
                          contentPadding: EdgeInsets.zero,
                          title: const Text('Я понимаю, что аккаунт нельзя будет восстановить'),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
      bottomNavigationBar: _canDelete == true && _blockers.isEmpty
          ? BottomActionBar(
              child: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: _danger),
                onPressed: !_understand || _busy || (_requiresPassword && _password.text.isEmpty) ? null : _delete,
                child: busyLabel(_busy, 'Удалить аккаунт'),
              ),
            )
          : null,
    );
  }
}
