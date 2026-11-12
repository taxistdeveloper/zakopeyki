import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/auth/auth_controller.dart';
import 'core/locale/locale_controller.dart';
import 'core/widgets/theme.dart';
import 'features/auth/forgot_password_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/register_screen.dart';
import 'features/catalog/feed_screen.dart';
import 'features/catalog/product_screen.dart';
import 'features/commerce/cart_screen.dart';
import 'features/commerce/checkout_screen.dart';
import 'features/commerce/commerce_repository.dart';
import 'features/commerce/orders_screen.dart';
import 'features/favorites/favorites_screen.dart';
import 'features/listings/listing_form_screen.dart';
import 'features/listings/my_listings_screen.dart';
import 'features/profile/delete_account_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/shell/home_shell.dart';
import 'l10n/app_localizations.dart';

final _rootKey = GlobalKey<NavigatorState>();
final scaffoldMessengerKey = GlobalKey<ScaffoldMessengerState>();

/// Показать сообщение после смены экрана: SnackBar, показанный до навигации,
/// исчезает вместе с закрываемым экраном. Поэтому — на следующем кадре.
void showMessageAfterNavigation(String text) {
  WidgetsBinding.instance.addPostFrameCallback((_) {
    scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(text)));
  });
}

final routerProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    navigatorKey: _rootKey,
    initialLocation: '/',
    routes: [
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/', builder: (_, _) => const FeedScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/favorites', builder: (_, _) => const FavoritesScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/cart', builder: (_, _) => const CartScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/profile', builder: (_, _) => const ProfileScreen())]),
        ],
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/product/:id',
        builder: (_, state) => ProductScreen(productId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0),
      ),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/login',
        pageBuilder: (_, _) => const MaterialPage(fullscreenDialog: true, child: LoginScreen()),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/register', builder: (_, _) => const RegisterScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/forgot-password', builder: (_, _) => const ForgotPasswordScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/delete-account', builder: (_, _) => const DeleteAccountScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/my-listings', builder: (_, _) => const MyListingsScreen()),
      GoRoute(parentNavigatorKey: _rootKey, path: '/checkout/cart', builder: (_, _) => const CheckoutScreen(target: CheckoutTarget.cart())),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/checkout/product/:id',
        builder: (_, state) => CheckoutScreen(
          target: CheckoutTarget.product(
            int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            qty: int.tryParse(state.uri.queryParameters['qty'] ?? '') ?? 1,
          ),
        ),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/orders', builder: (_, _) => const OrdersScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/order/:id',
        builder: (_, state) => OrderScreen(orderId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0),
      ),
      GoRoute(parentNavigatorKey: _rootKey, path: '/listing/new', builder: (_, _) => const ListingFormScreen()),
      GoRoute(
        parentNavigatorKey: _rootKey,
        path: '/listing/:id/edit',
        builder: (_, state) => ListingFormScreen(listingId: int.tryParse(state.pathParameters['id'] ?? '')),
      ),
    ],
  );
});

class ZakopeykiApp extends ConsumerWidget {
  const ZakopeykiApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(routerProvider);
    final locale = ref.watch(localeProvider);

    // Сообщить пользователю, что сессия завершилась (refresh отклонён сервером)
    ref.listen(authControllerProvider, (prev, next) {
      if (prev is AuthAuthenticated && next is AuthGuest && next.sessionExpired) {
        final ctx = _rootKey.currentContext;
        if (ctx != null) {
          scaffoldMessengerKey.currentState?.showSnackBar(
            SnackBar(content: Text(AppLocalizations.of(ctx).sessionExpired)),
          );
        }
      }
    });

    return MaterialApp.router(
      title: 'Zakopeyki.kz',
      debugShowCheckedModeBanner: false,
      scaffoldMessengerKey: scaffoldMessengerKey,
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      locale: locale,
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      routerConfig: router,
    );
  }
}
