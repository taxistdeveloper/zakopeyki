import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/locale/locale_controller.dart';
import 'models.dart';

class CatalogRepository {
  const CatalogRepository(this._api);
  final ApiClient _api;

  Future<RemoteConfig> config() async => RemoteConfig.fromJson((await _api.get('/config')).data);

  Future<FeedPage> feed({String? type, String? query, String? cursor, int limit = 20}) async {
    final res = await _api.get('/products', query: {
      if (type != null && type.isNotEmpty) 'type': type,
      if (query != null && query.trim().length >= 2) 'q': query.trim(),
      'cursor': ?cursor,
      'limit': limit,
    });
    final items = ((res.data['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(ProductCard.fromJson)
        .toList();
    return FeedPage(items, res.meta['next_cursor'] as String?);
  }

  Future<ProductDetail> product(int id) async {
    final res = await _api.get('/products/$id');
    return ProductDetail.fromJson(res.data['product'] as Map<String, dynamic>);
  }

  Future<List<ProductCard>> favorites() async {
    final res = await _api.get('/me/favorites');
    return ((res.data['items'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(ProductCard.fromJson)
        .toList();
  }

  Future<bool> toggleFavorite(int productId) async {
    final res = await _api.post('/products/$productId/favorite');
    return res.data['is_favorite'] == true;
  }
}

final catalogRepositoryProvider = Provider<CatalogRepository>((ref) => CatalogRepository(ref.watch(apiClientProvider)));

/// Конфиг сервера; перезагружается при смене языка или статуса входа.
final remoteConfigProvider = FutureProvider<RemoteConfig>((ref) {
  ref.watch(localeProvider);
  ref.watch(authControllerProvider.select((s) => s is AuthAuthenticated));
  return ref.watch(catalogRepositoryProvider).config();
});

final productDetailProvider = FutureProvider.autoDispose.family<ProductDetail, int>((ref, id) {
  ref.watch(localeProvider);
  ref.watch(authControllerProvider.select((s) => s is AuthAuthenticated));
  return ref.watch(catalogRepositoryProvider).product(id);
});

final favoritesProvider = FutureProvider.autoDispose<List<ProductCard>>((ref) {
  ref.watch(localeProvider);
  final auth = ref.watch(authControllerProvider);
  if (auth is! AuthAuthenticated) return const [];
  return ref.watch(catalogRepositoryProvider).favorites();
});
