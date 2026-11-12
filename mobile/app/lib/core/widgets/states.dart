import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../l10n/app_localizations.dart';
import '../api/api_exception.dart';
import '../auth/auth_controller.dart';

/// Понятное сообщение об ошибке: текст сервера, либо локальный для сетевых проблем.
String errorText(BuildContext context, Object error) {
  final l = AppLocalizations.of(context);
  if (error is ApiException) {
    if (error.isNetwork) return l.networkError;
    if (error.code == 'apple_unavailable') return l.appleUnavailable;
    if (error.message.isNotEmpty) return error.message;
  }
  return l.loadError;
}

class ErrorView extends ConsumerWidget {
  const ErrorView({super.key, required this.error, this.onRetry});
  final Object error;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    if (error is ApiException && (error as ApiException).isSiteClosed) {
      // Участники закрытого теста входят и получают доступ к каталогу
      final isGuest = ref.watch(authControllerProvider) is AuthGuest;
      return MessageView(
        icon: Icons.storefront_outlined,
        title: l.siteClosedTitle,
        text: l.siteClosedText,
        action: isGuest ? FilledButton(onPressed: () => context.push('/login'), child: Text(l.loginButton)) : null,
      );
    }
    return MessageView(
      icon: Icons.cloud_off_outlined,
      title: l.loadError,
      text: errorText(context, error),
      action: onRetry == null ? null : FilledButton.tonal(onPressed: onRetry, child: Text(l.retry)),
    );
  }
}

class MessageView extends StatelessWidget {
  const MessageView({super.key, required this.icon, required this.title, this.text, this.action});
  final IconData icon;
  final String title;
  final String? text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 56, color: theme.colorScheme.primary),
            const SizedBox(height: 16),
            Text(title, style: theme.textTheme.titleMedium, textAlign: TextAlign.center),
            if (text != null && text != title) ...[
              const SizedBox(height: 8),
              Text(text!, style: theme.textTheme.bodyMedium, textAlign: TextAlign.center),
            ],
            if (action != null) ...[const SizedBox(height: 20), action!],
          ],
        ),
      ),
    );
  }
}
