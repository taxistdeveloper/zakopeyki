import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/format/money.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/widgets/product_tile.dart';
import 'commerce_repository.dart';

class CartScreen extends ConsumerWidget {
  const CartScreen({super.key});

  Future<void> _update(
    BuildContext context,
    WidgetRef ref,
    Future<CartState> Function() op,
  ) async {
    try {
      final next = await op();
      ref.invalidate(cartProvider);
      if (next.notice != null) {
        scaffoldMessengerKey.currentState?.showSnackBar(
          SnackBar(content: Text(next.notice!)),
        );
      }
    } on ApiException catch (e) {
      if (context.mounted) {
        scaffoldMessengerKey.currentState?.showSnackBar(
          SnackBar(content: Text(errorText(context, e))),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final repo = ref.read(commerceRepositoryProvider);
    if (ref.watch(authControllerProvider) is AuthGuest) {
      return Scaffold(
        appBar: AppBar(title: Text(l.navCart)),
        body: MessageView(
          icon: Icons.shopping_cart_outlined,
          title: l.loginRequired,
          action: FilledButton(
            onPressed: () => context.push('/login'),
            child: Text(l.loginButton),
          ),
        ),
      );
    }
    final async = ref.watch(cartProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l.navCart)),
      body: async.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) =>
            ErrorView(error: e, onRetry: () => ref.invalidate(cartProvider)),
        data: (cart) => cart.lines.isEmpty
            ? MessageView(
                icon: Icons.shopping_cart_outlined,
                title: l.emptyCart,
              )
            : RefreshIndicator(
                onRefresh: () => ref.refresh(cartProvider.future),
                child: ListView.separated(
                  padding: const EdgeInsets.all(12),
                  itemCount: cart.lines.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final line = cart.lines[i];
                    return _CartLineTile(
                      line: line,
                      onOpen: () => context.push('/product/${line.product.id}'),
                      onQty: (q) => _update(
                        context,
                        ref,
                        () => repo.setQty(line.product.id, q),
                      ),
                      onRemove: () => _update(
                        context,
                        ref,
                        () => repo.remove(line.product.id),
                      ),
                    );
                  },
                ),
              ),
      ),
      bottomNavigationBar: async.value == null || async.value!.lines.isEmpty
          ? null
          : SafeArea(
              minimum: const EdgeInsets.fromLTRB(16, 8, 16, 12),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      Text(
                        l.cartTotal,
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      const Spacer(),
                      Text(
                        formatTenge(async.value!.total),
                        style: Theme.of(context).textTheme.titleLarge
                            ?.copyWith(fontWeight: FontWeight.w800),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: () => context.push('/checkout/cart'),
                    child: Text(l.checkoutAll),
                  ),
                ],
              ),
            ),
    );
  }
}

class _CartLineTile extends StatelessWidget {
  const _CartLineTile({
    required this.line,
    required this.onOpen,
    required this.onQty,
    required this.onRemove,
  });
  final CartLine line;
  final VoidCallback onOpen;
  final void Function(int) onQty;
  final VoidCallback onRemove;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return Card(
      color: theme.colorScheme.surfaceContainerLow,
      child: InkWell(
        onTap: onOpen,
        child: Padding(
          padding: const EdgeInsets.all(8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 84,
                  height: 84,
                  child: ProductImage(url: line.product.coverUrl),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      line.product.title,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      formatTenge(line.lineTotal),
                      style: theme.textTheme.titleMedium?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    Row(
                      children: [
                        IconButton(
                          tooltip: '−',
                          onPressed: line.qty > 1
                              ? () => onQty(line.qty - 1)
                              : null,
                          icon: const Icon(Icons.remove_circle_outline),
                        ),
                        Text('${line.qty}', style: theme.textTheme.titleMedium),
                        IconButton(
                          tooltip: '+',
                          onPressed: line.qty < line.available
                              ? () => onQty(line.qty + 1)
                              : null,
                          icon: const Icon(Icons.add_circle_outline),
                        ),
                        const Spacer(),
                        IconButton(
                          tooltip: l.remove,
                          onPressed: onRemove,
                          icon: const Icon(Icons.delete_outline),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
