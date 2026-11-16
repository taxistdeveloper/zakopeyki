import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_exception.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/locale/locale_controller.dart';
import 'catalog_repository.dart';
import 'models.dart';

class FeedFilter {
  const FeedFilter({this.type, this.query = ''});
  final String? type;
  final String query;

  FeedFilter copyWith({String? type, bool clearType = false, String? query}) =>
      FeedFilter(type: clearType ? null : (type ?? this.type), query: query ?? this.query);
}

class FeedState {
  const FeedState({
    this.filter = const FeedFilter(),
    this.items = const [],
    this.nextCursor,
    this.loading = false,
    this.loadingMore = false,
    this.error,
    this.loaded = false,
  });

  final FeedFilter filter;
  final List<ProductCard> items;
  final String? nextCursor;
  final bool loading;
  final bool loadingMore;
  final ApiException? error;
  final bool loaded;

  bool get hasMore => nextCursor != null;

  FeedState copyWith({
    FeedFilter? filter,
    List<ProductCard>? items,
    String? nextCursor,
    bool clearCursor = false,
    bool? loading,
    bool? loadingMore,
    ApiException? error,
    bool clearError = false,
    bool? loaded,
  }) =>
      FeedState(
        filter: filter ?? this.filter,
        items: items ?? this.items,
        nextCursor: clearCursor ? null : (nextCursor ?? this.nextCursor),
        loading: loading ?? this.loading,
        loadingMore: loadingMore ?? this.loadingMore,
        error: clearError ? null : (error ?? this.error),
        loaded: loaded ?? this.loaded,
      );
}

/// Лента с фильтрами и курсорной подгрузкой.
class FeedController extends Notifier<FeedState> {
  int _generation = 0;

  @override
  FeedState build() {
    ref.watch(localeProvider);
    ref.watch(authControllerProvider.select((s) => s is AuthAuthenticated));
    Future.microtask(refresh);
    return const FeedState(loading: true);
  }

  Future<void> setType(String? type) {
    state = state.copyWith(filter: state.filter.copyWith(type: type, clearType: type == null));
    return refresh();
  }

  Future<void> setQuery(String query) {
    if (query.trim() == state.filter.query.trim()) return Future.value();
    state = state.copyWith(filter: state.filter.copyWith(query: query));
    return refresh();
  }

  Future<void> refresh() async {
    final gen = ++_generation;
    state = state.copyWith(loading: true, clearError: true);
    try {
      final page = await ref
          .read(catalogRepositoryProvider)
          .feed(type: state.filter.type, query: state.filter.query);
      if (gen != _generation) return;
      state = state.copyWith(items: page.items, nextCursor: page.nextCursor, clearCursor: page.nextCursor == null, loading: false, loaded: true);
    } on ApiException catch (e) {
      if (gen != _generation) return;
      state = state.copyWith(loading: false, error: e, items: const [], clearCursor: true, loaded: true);
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore) return;
    final gen = _generation;
    state = state.copyWith(loadingMore: true);
    try {
      final page = await ref
          .read(catalogRepositoryProvider)
          .feed(type: state.filter.type, query: state.filter.query, cursor: state.nextCursor);
      if (gen != _generation) return;
      final known = state.items.map((e) => e.id).toSet();
      state = state.copyWith(
        items: [...state.items, ...page.items.where((p) => !known.contains(p.id))],
        nextCursor: page.nextCursor,
        clearCursor: page.nextCursor == null,
        loadingMore: false,
      );
    } on ApiException {
      if (gen != _generation) return;
      state = state.copyWith(loadingMore: false);
    }
  }

  /// Синхронизировать флажок избранного в уже загруженной ленте.
  void markFavorite(int productId, bool value) {
    state = state.copyWith(
      items: [for (final p in state.items) p.id == productId ? p.copyWith(isFavorite: value) : p],
    );
  }
}

final feedControllerProvider = NotifierProvider<FeedController, FeedState>(FeedController.new);
