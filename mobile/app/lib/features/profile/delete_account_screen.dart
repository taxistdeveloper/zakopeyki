import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app.dart';
import '../../core/api/api_client.dart';
import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';

class DeletionBlocker {
  const DeletionBlocker(this.code, this.message);
  final String code;
  final String message;
}

class DeletionStatus {
  const DeletionStatus({required this.canDelete, required this.blockers, required this.requiresPassword});
  final bool canDelete;
  final List<DeletionBlocker> blockers;
  final bool requiresPassword;

  static List<DeletionBlocker> parseBlockers(Object? raw) => ((raw as List?) ?? const [])
      .whereType<Map<String, dynamic>>()
      .map((b) => DeletionBlocker((b['code'] ?? '').toString(), (b['message'] ?? '').toString()))
      .toList();

  factory DeletionStatus.fromJson(Map<String, dynamic> j) => DeletionStatus(
        canDelete: j['can_delete'] == true,
        blockers: parseBlockers(j['blockers']),
        requiresPassword: j['requires_password'] == true,
      );
}

final deletionStatusProvider = FutureProvider.autoDispose<DeletionStatus>((ref) async {
  final res = await ref.watch(apiClientProvider).get('/me/deletion');
  return DeletionStatus.fromJson(res.data);
});

/// Удаление аккаунта в приложении (App Store 5.1.1(v), Google Play account deletion).
/// Правила (блокеры, обезличивание) — на сервере: AccountDeletionService.
class DeleteAccountScreen extends ConsumerStatefulWidget {
  const DeleteAccountScreen({super.key});

  @override
  ConsumerState<DeleteAccountScreen> createState() => _DeleteAccountScreenState();
}

class _DeleteAccountScreenState extends ConsumerState<DeleteAccountScreen> {
  final _password = TextEditingController();
  bool _confirmed = false;
  bool _busy = false;
  bool _reauthRequired = false;
  String? _error;
  List<DeletionBlocker>? _blockersFromServer;

  @override
  void dispose() {
    _password.dispose();
    super.dispose();
  }

  Future<void> _delete(DeletionStatus status) async {
    final l = AppLocalizations.of(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiClientProvider).post('/me/delete', body: {
        'confirm': true,
        if (status.requiresPassword) 'password': _password.text,
      });
      await ref.read(authControllerProvider.notifier).onAccountDeleted();
      if (mounted) context.go('/');
      showMessageAfterNavigation(l.accountDeleted);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.code == 'reauth_required') _reauthRequired = true;
        if (e.code == 'deletion_blocked') _blockersFromServer = DeletionStatus.parseBlockers(e.data['blockers']);
        _error = e.fields['password'] ?? errorText(context, e);
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reauth() async {
    await ref.read(authControllerProvider.notifier).logout();
    if (mounted) context.go('/profile');
    if (mounted) context.push('/login');
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(l.deleteAccountTitle)),
      body: ref.watch(deletionStatusProvider).when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(deletionStatusProvider)),
            data: (status) => _body(context, l, status),
          ),
    );
  }

  Widget _body(BuildContext context, AppLocalizations l, DeletionStatus status) {
    final theme = Theme.of(context);
    final blockers = _blockersFromServer ?? status.blockers;
    final blocked = blockers.isNotEmpty;
    final canSubmit = !blocked &&
        _confirmed &&
        !_busy &&
        !_reauthRequired &&
        (!status.requiresPassword || _password.text.isNotEmpty);

    return SafeArea(
      child: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Icon(Icons.warning_amber_rounded, size: 48, color: theme.colorScheme.error),
          const SizedBox(height: 12),
          Text(l.deleteAccountIntro, style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          for (final point in [l.deleteAccountPoint1, l.deleteAccountPoint2, l.deleteAccountPoint3, l.deleteAccountPoint4])
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Text('•  '),
                Expanded(child: Text(point)),
              ]),
            ),
          if (blocked) ...[
            const SizedBox(height: 16),
            Card(
              color: theme.colorScheme.errorContainer,
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(l.deleteAccountBlocked,
                      style: theme.textTheme.titleSmall?.copyWith(color: theme.colorScheme.onErrorContainer)),
                  const SizedBox(height: 8),
                  for (final b in blockers)
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 2),
                      child: Text('•  ${b.message}', style: TextStyle(color: theme.colorScheme.onErrorContainer)),
                    ),
                ]),
              ),
            ),
          ] else ...[
            const SizedBox(height: 20),
            if (status.requiresPassword)
              TextField(
                controller: _password,
                obscureText: true,
                autofillHints: const [AutofillHints.password],
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(labelText: l.password, helperText: l.deleteAccountPasswordHint),
              )
            else if (_reauthRequired) ...[
              Text(l.deleteAccountReauthHint),
              const SizedBox(height: 12),
              OutlinedButton(onPressed: _reauth, child: Text(l.deleteAccountReauthButton)),
            ],
            const SizedBox(height: 12),
            CheckboxListTile(
              value: _confirmed,
              onChanged: (v) => setState(() => _confirmed = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              title: Text(l.deleteAccountConfirm),
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 16),
            FilledButton(
              style: FilledButton.styleFrom(
                backgroundColor: theme.colorScheme.error,
                foregroundColor: theme.colorScheme.onError,
              ),
              onPressed: canSubmit ? () => _delete(status) : null,
              child: _busy
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                  : Text(l.deleteAccountButton),
            ),
          ],
        ],
      ),
    );
  }
}
