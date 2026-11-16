import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/locale/locale_controller.dart';
import '../catalog/models.dart';

/// Что нужно сделать до публикации (правила, ИИН/БИН) и лимиты — с сервера (ListingService::formState).
class ListingFormInfo {
  const ListingFormInfo({
    required this.rulesAccepted,
    required this.taxIdKind,
    required this.taxIdOnFile,
    required this.maxPhotos,
    required this.canPublish,
    required this.defaultLocation,
    required this.types,
    this.publishBlockReason,
  });

  final bool rulesAccepted;
  final String taxIdKind; // iin | bin
  final bool taxIdOnFile;
  final int maxPhotos;
  final bool canPublish;
  final String? publishBlockReason;
  final String defaultLocation;
  final List<ListingTypeInfo> types;

  factory ListingFormInfo.fromJson(Map<String, dynamic> j) => ListingFormInfo(
        rulesAccepted: j['rules_accepted'] == true,
        taxIdKind: (j['tax_id_kind'] ?? 'iin').toString(),
        taxIdOnFile: j['tax_id_on_file'] == true,
        maxPhotos: (j['max_photos'] as num?)?.toInt() ?? 3,
        canPublish: j['can_publish'] != false,
        publishBlockReason: j['publish_block_reason'] as String?,
        defaultLocation: (j['default_location'] ?? '').toString(),
        types: ((j['types'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(ListingTypeInfo.fromJson)
            .toList(),
      );
}

class ListingTypeInfo {
  const ListingTypeInfo({
    required this.code,
    required this.label,
    required this.hasPrice,
    required this.hasCategory,
    required this.hasQuantity,
    required this.requiresExchangeFor,
  });

  final String code;
  final String label;
  final bool hasPrice;
  final bool hasCategory;
  final bool hasQuantity;
  final bool requiresExchangeFor;

  factory ListingTypeInfo.fromJson(Map<String, dynamic> j) => ListingTypeInfo(
        code: (j['code'] ?? '').toString(),
        label: (j['label'] ?? '').toString(),
        hasPrice: j['has_price'] == true,
        hasCategory: j['has_category'] == true,
        hasQuantity: j['has_quantity'] == true,
        requiresExchangeFor: j['requires_exchange_for'] == true,
      );
}

class CategoryGroup {
  const CategoryGroup(this.label, this.children);
  final String label;
  final List<CategoryOption> children;
}

class CategoryOption {
  const CategoryOption(this.value, this.label);
  final String value;
  final String label;
}

class MyListing {
  const MyListing({required this.card, required this.status, required this.editableInApp, required this.viewCount});
  final ProductCard card;
  final String status;
  final bool editableInApp;
  final int viewCount;

  factory MyListing.fromJson(Map<String, dynamic> j) => MyListing(
        card: ProductCard.fromJson(j),
        status: (j['status'] ?? '').toString(),
        editableInApp: j['editable_in_app'] == true,
        viewCount: (j['view_count'] as num?)?.toInt() ?? 0,
      );
}

class ExistingPhoto {
  const ExistingPhoto({required this.file, required this.url, required this.isCover});
  final String file;
  final String url;
  final bool isCover;
}

class EditableListing {
  const EditableListing({
    required this.id,
    required this.type,
    required this.editableInApp,
    required this.title,
    required this.description,
    required this.characteristics,
    required this.price,
    required this.quantity,
    required this.location,
    required this.photos,
    this.category,
    this.exchangeFor,
  });

  final int id;
  final String type;
  final bool editableInApp;
  final String title;
  final String description;
  final String characteristics;
  final int price;
  final int quantity;
  final String location;
  final String? category;
  final String? exchangeFor;
  final List<ExistingPhoto> photos;

  factory EditableListing.fromJson(Map<String, dynamic> j) => EditableListing(
        id: (j['id'] as num).toInt(),
        type: (j['type'] ?? '').toString(),
        editableInApp: j['editable_in_app'] == true,
        title: (j['title'] ?? '').toString(),
        description: (j['description'] ?? '').toString(),
        characteristics: (j['characteristics'] ?? '').toString(),
        price: (j['price'] as num?)?.toInt() ?? 0,
        quantity: (j['quantity'] as num?)?.toInt() ?? 1,
        location: (j['location'] ?? '').toString(),
        category: j['category'] as String?,
        exchangeFor: j['exchange_for'] as String?,
        photos: ((j['images'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map((p) => ExistingPhoto(
                  file: (p['file'] ?? '').toString(),
                  url: (p['url'] ?? '').toString(),
                  isCover: p['is_cover'] == true,
                ))
            .toList(),
      );
}

/// Фото в форме: уже загруженное (file) или новое с устройства (localPath).
class DraftPhoto {
  const DraftPhoto.existing(String this.file, this.url) : localPath = null;
  const DraftPhoto.local(String this.localPath)
      : file = null,
        url = null;

  final String? file;
  final String? url;
  final String? localPath;

  bool get isNew => localPath != null;
}

/// Данные формы для отправки. Валидация окончательно — на сервере (ListingService).
class ListingDraft {
  const ListingDraft({
    required this.type,
    required this.title,
    required this.description,
    required this.photos,
    required this.coverIndex,
    this.price,
    this.category,
    this.quantity,
    this.exchangeFor,
    this.location,
    this.characteristics,
    this.taxId,
    this.acceptRules = false,
  });

  final String type;
  final String title;
  final String description;
  final List<DraftPhoto> photos;
  final int coverIndex;
  final int? price;
  final String? category;
  final int? quantity;
  final String? exchangeFor;
  final String? location;
  final String? characteristics;
  final String? taxId;
  final bool acceptRules;

  /// Новая FormData на каждый вызов (повтор после обновления токена).
  FormData toFormData({required String taxIdKind}) {
    final cover = photos.isEmpty ? null : photos[coverIndex.clamp(0, photos.length - 1)];
    final newPhotos = photos.where((p) => p.isNew).toList();
    final map = <String, dynamic>{
      'type': type,
      'title': title,
      'description': description,
      'price': ?price?.toString(),
      'category': ?category,
      'quantity': ?quantity?.toString(),
      'exchange_for': ?exchangeFor,
      'location': ?location,
      'characteristics': ?characteristics,
      if (taxId != null && taxId!.isNotEmpty) taxIdKind: taxId,
      if (acceptRules) 'listing_rules_ack': '1',
      if (cover != null) 'cover': cover.isNew ? '__new__${newPhotos.indexOf(cover)}' : cover.file,
    };
    final form = FormData.fromMap(map);
    for (final p in photos.where((p) => !p.isNew)) {
      form.fields.add(MapEntry('keep_images[]', p.file!));
    }
    for (final p in newPhotos) {
      form.files.add(MapEntry('images[]', MultipartFile.fromFileSync(p.localPath!, filename: p.localPath!.split('/').last)));
    }
    return form;
  }
}

class ListingsRepository {
  const ListingsRepository(this._api);
  final ApiClient _api;

  Future<ListingFormInfo> form() async => ListingFormInfo.fromJson((await _api.get('/listings/form')).data);

  Future<List<CategoryGroup>> categories() async {
    final res = await _api.get('/categories');
    return ((res.data['categories'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map((g) => CategoryGroup(
              (g['label'] ?? '').toString(),
              ((g['children'] as List?) ?? const [])
                  .whereType<Map<String, dynamic>>()
                  .map((c) => CategoryOption((c['value'] ?? '').toString(), (c['label'] ?? '').toString()))
                  .toList(),
            ))
        .toList();
  }

  Future<List<MyListing>> mine() async {
    final res = await _api.get('/me/listings');
    return ((res.data['items'] as List?) ?? const []).whereType<Map<String, dynamic>>().map(MyListing.fromJson).toList();
  }

  Future<EditableListing> editable(int id) async =>
      EditableListing.fromJson((await _api.get('/me/listings/$id')).data['listing'] as Map<String, dynamic>);

  /// Создать (id == null) или обновить. Возвращает id и бонусные баллы (при создании).
  Future<({int id, int? bonus})> save(ListingDraft draft, {int? id, required String taxIdKind, void Function(int, int)? onProgress}) async {
    final res = await _api.postMultipart(
      id == null ? '/listings' : '/listings/$id',
      () => draft.toFormData(taxIdKind: taxIdKind),
      onProgress: onProgress,
    );
    return (id: (res.data['product_id'] as num).toInt(), bonus: (res.data['listing_bonus_points'] as num?)?.toInt());
  }

  Future<void> delete(int id) => _api.post('/listings/$id/delete');
}

final listingsRepositoryProvider = Provider<ListingsRepository>((ref) => ListingsRepository(ref.watch(apiClientProvider)));

final listingFormInfoProvider = FutureProvider.autoDispose<ListingFormInfo>((ref) {
  ref.watch(localeProvider);
  return ref.watch(listingsRepositoryProvider).form();
});

final categoriesProvider = FutureProvider<List<CategoryGroup>>((ref) {
  ref.watch(localeProvider);
  return ref.watch(listingsRepositoryProvider).categories();
});

final myListingsProvider = FutureProvider.autoDispose<List<MyListing>>((ref) {
  ref.watch(localeProvider);
  if (ref.watch(authControllerProvider) is! AuthAuthenticated) return const [];
  return ref.watch(listingsRepositoryProvider).mine();
});
