import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import 'catalog_repository.dart';
import 'favorite_action.dart';
import 'feed_controller.dart';
import 'listing_type_label.dart';
import 'widgets/product_tile.dart';

class FeedScreen extends ConsumerStatefulWidget {
  const FeedScreen({super.key});

  @override
  ConsumerState<FeedScreen> createState() => _FeedScreenState();
}

class _FeedScreenState extends ConsumerState<FeedScreen> {
  final _scroll = ScrollController();
  final _search = TextEditingController();
  Timer? _debounce;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 600) {
        ref.read(feedControllerProvider.notifier).loadMore();
      }
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _scroll.dispose();
    _search.dispose();
    super.dispose();
  }

  void _onSearch(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), () {
      ref.read(feedControllerProvider.notifier).setQuery(value);
    });
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final state = ref.watch(feedControllerProvider);
    final types = ref.watch(remoteConfigProvider).value?.listingTypes ?? const [];
    final controller = ref.read(feedControllerProvider.notifier);

    final signedIn = ref.watch(authControllerProvider) is AuthAuthenticated;
    return Scaffold(
      floatingActionButton: signedIn && state.error == null
          ? FloatingActionButton(
              tooltip: l.newListing,
              onPressed: () => context.push('/listing/new'),
              child: const Icon(Icons.add),
            )
          : null,
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: controller.refresh,
          child: CustomScrollView(
            controller: _scroll,
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
                  child: TextField(
                    controller: _search,
                    onChanged: _onSearch,
                    onSubmitted: controller.setQuery,
                    textInputAction: TextInputAction.search,
                    decoration: InputDecoration(
                      hintText: l.searchHint,
                      prefixIcon: const Icon(Icons.search),
                      suffixIcon: _search.text.isEmpty
                          ? null
                          : IconButton(
                              tooltip: MaterialLocalizations.of(context).deleteButtonTooltip,
                              icon: const Icon(Icons.close),
                              onPressed: () {
                                _search.clear();
                                controller.setQuery('');
                                setState(() {});
                              },
                            ),
                    ),
                  ),
                ),
              ),
              if (types.isNotEmpty)
                SliverToBoxAdapter(
                  child: SizedBox(
                    height: 48,
                    child: ListView(
                      scrollDirection: Axis.horizontal,
                      padding: const EdgeInsets.symmetric(horizontal: 12),
                      children: [
                        _TypeChip(label: l.allTypes, selected: state.filter.type == null, onTap: () => controller.setType(null)),
                        for (final t in types)
                          _TypeChip(
                            label: listingTypeLabel(context, t.code, t.label),
                            selected: state.filter.type == t.code,
                            onTap: () => controller.setType(t.code),
                          ),
                      ],
                    ),
                  ),
                ),
              ..._content(context, state, l),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _content(BuildContext context, FeedState state, AppLocalizations l) {
    if (state.loading && state.items.isEmpty) {
      return const [SliverFillRemaining(child: Center(child: CircularProgressIndicator()))];
    }
    if (state.error != null) {
      return [
        SliverFillRemaining(
          hasScrollBody: false,
          child: ErrorView(error: state.error!, onRetry: ref.read(feedControllerProvider.notifier).refresh),
        ),
      ];
    }
    if (state.loaded && state.items.isEmpty) {
      return [SliverFillRemaining(hasScrollBody: false, child: MessageView(icon: Icons.search_off, title: l.emptyFeed))];
    }
    return [
      SliverPadding(
        padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
        sliver: SliverGrid.builder(
          gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
            maxCrossAxisExtent: 260,
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 0.64,
          ),
          itemCount: state.items.length,
          itemBuilder: (context, i) {
            final p = state.items[i];
            return ProductTile(
              product: p,
              onTap: () => context.push('/product/${p.id}'),
              onFavorite: () => toggleFavorite(context, ref, p.id),
            );
          },
        ),
      ),
      SliverToBoxAdapter(
        child: SizedBox(
          height: 64,
          child: state.loadingMore ? const Center(child: CircularProgressIndicator()) : null,
        ),
      ),
    ];
  }
}

class _TypeChip extends StatelessWidget {
  const _TypeChip({required this.label, required this.selected, required this.onTap});
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4),
        child: ChoiceChip(label: Text(label), selected: selected, onSelected: (_) => onTap()),
      );
}
