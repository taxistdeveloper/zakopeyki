import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import 'catalog_repository.dart';
import 'feed_controller.dart';

/// Переключить избранное с синхронизацией ленты/карточки/списка избранного.
/// Гостя отправляет на вход.
Future<void> toggleFavorite(BuildContext context, WidgetRef ref, int productId) async {
  final l = AppLocalizations.of(context);
  final messenger = ScaffoldMessenger.of(context);
  if (ref.read(authControllerProvider) is! AuthAuthenticated) {
    context.push('/login');
    return;
  }
  try {
    final value = await ref.read(catalogRepositoryProvider).toggleFavorite(productId);
    ref.read(feedControllerProvider.notifier).markFavorite(productId, value);
    ref.invalidate(favoritesProvider);
    ref.invalidate(productDetailProvider(productId));
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(value ? l.addedToFavorites : l.removedFromFavorites)));
  } on ApiException catch (e) {
    if (!context.mounted) return;
    messenger.showSnackBar(SnackBar(content: Text(errorText(context, e))));
  }
}
