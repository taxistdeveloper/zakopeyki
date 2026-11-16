import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../app.dart';
import '../../core/api/api_exception.dart';
import '../../core/widgets/states.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/catalog_repository.dart';
import '../catalog/feed_controller.dart';
import '../catalog/listing_type_label.dart';
import 'listings_repository.dart';

/// Источник фото (в тестах подменяется — системный выбор фото тестом не управляется).
final imagePickerProvider = Provider<ImagePicker>((ref) => ImagePicker());

/// Создание (listingId == null) и редактирование объявления.
/// Проверки на клиенте — только для удобства; решение и тексты ошибок — ListingService на сервере.
class ListingFormScreen extends ConsumerStatefulWidget {
  const ListingFormScreen({super.key, this.listingId});
  final int? listingId;

  @override
  ConsumerState<ListingFormScreen> createState() => _ListingFormScreenState();
}

class _ListingFormScreenState extends ConsumerState<ListingFormScreen> {
  final _form = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _description = TextEditingController();
  final _price = TextEditingController();
  final _quantity = TextEditingController(text: '1');
  final _exchangeFor = TextEditingController();
  final _location = TextEditingController();
  final _characteristics = TextEditingController();
  final _taxId = TextEditingController();
  late final ImagePicker _picker = ref.read(imagePickerProvider);

  String _type = 'used';
  String? _category;
  final List<DraftPhoto> _photos = [];
  int _cover = 0;
  bool _acceptRules = false;
  bool _busy = false;
  double? _progress;
  String? _error;
  Map<String, String> _fieldErrors = const {};
  bool _initialized = false;
  bool _loadingExisting = false;

  bool get _isEdit => widget.listingId != null;

  @override
  void initState() {
    super.initState();
    if (_isEdit) _loadExisting();
  }

  Future<void> _loadExisting() async {
    setState(() => _loadingExisting = true);
    try {
      final e = await ref.read(listingsRepositoryProvider).editable(widget.listingId!);
      if (!mounted) return;
      setState(() {
        _type = e.type;
        _title.text = e.title;
        _description.text = e.description;
        _price.text = e.price > 0 ? e.price.toString() : '';
        _quantity.text = e.quantity.toString();
        _exchangeFor.text = e.exchangeFor ?? '';
        _location.text = e.location;
        _characteristics.text = e.characteristics;
        _category = e.category;
        _photos
          ..clear()
          ..addAll(e.photos.map((p) => DraftPhoto.existing(p.file, p.url)));
        final cover = e.photos.indexWhere((p) => p.isCover);
        _cover = cover < 0 ? 0 : cover;
        _initialized = true;
      });
    } on ApiException catch (err) {
      if (mounted) setState(() => _error = errorText(context, err));
    } finally {
      if (mounted) setState(() => _loadingExisting = false);
    }
  }

  @override
  void dispose() {
    for (final c in [_title, _description, _price, _quantity, _exchangeFor, _location, _characteristics, _taxId]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _addPhotos(ImageSource source, int maxPhotos) async {
    final left = maxPhotos - _photos.length;
    if (left <= 0) return;
    // Сжатие на устройстве: сервер принимает до 5 МБ на фото, а мобильный трафик дорог
    const maxSide = 2048.0;
    const quality = 85;
    final List<XFile> picked;
    if (source == ImageSource.camera) {
      final one = await _picker.pickImage(source: source, maxWidth: maxSide, maxHeight: maxSide, imageQuality: quality);
      picked = one == null ? const [] : [one];
    } else {
      picked = await _picker.pickMultiImage(maxWidth: maxSide, maxHeight: maxSide, imageQuality: quality, limit: left > 1 ? left : null);
    }
    if (!mounted || picked.isEmpty) return;
    setState(() {
      _photos.addAll(picked.take(left).map((x) => DraftPhoto.local(x.path)));
      _fieldErrors = Map.of(_fieldErrors)..remove('images');
    });
  }

  Future<void> _submit(ListingFormInfo info) async {
    final l = AppLocalizations.of(context);
    final valid = _form.currentState?.validate() ?? false;
    if (_photos.isEmpty) {
      setState(() => _fieldErrors = {..._fieldErrors, 'images': l.needPhoto});
      return;
    }
    if (!info.rulesAccepted && !_acceptRules) {
      setState(() => _error = l.listingRulesRequired);
      return;
    }
    if (!valid) return;

    final typeInfo = info.types.firstWhere((t) => t.code == _type, orElse: () => info.types.first);
    final draft = ListingDraft(
      type: _type,
      title: _title.text.trim(),
      description: _description.text.trim(),
      photos: List.of(_photos),
      coverIndex: _cover,
      price: typeInfo.hasPrice ? int.tryParse(_price.text.replaceAll(RegExp(r'\D'), '')) : null,
      category: typeInfo.hasCategory ? _category : null,
      quantity: typeInfo.hasQuantity ? int.tryParse(_quantity.text) : null,
      exchangeFor: typeInfo.requiresExchangeFor ? _exchangeFor.text.trim() : null,
      location: _location.text.trim().isEmpty ? null : _location.text.trim(),
      characteristics: _characteristics.text.trim(),
      taxId: info.taxIdOnFile ? null : _taxId.text.trim(),
      acceptRules: !info.rulesAccepted && _acceptRules,
    );

    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
      _progress = 0;
    });
    try {
      final saved = await ref.read(listingsRepositoryProvider).save(
            draft,
            id: widget.listingId,
            taxIdKind: info.taxIdKind,
            onProgress: (sent, total) {
              if (mounted && total > 0) setState(() => _progress = sent / total);
            },
          );
      ref
        ..invalidate(myListingsProvider)
        ..invalidate(listingFormInfoProvider)
        ..invalidate(productDetailProvider(saved.id));
      ref.read(feedControllerProvider.notifier).refresh();
      if (!mounted) return;
      final message = _isEdit ? l.listingSaved : l.listingPublished;
      context.go('/my-listings');
      showMessageAfterNavigation(saved.bonus != null && saved.bonus! > 0 ? '$message · ${l.listingBonus(saved.bonus!)}' : message);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _fieldErrors = e.fields;
        _error = e.fields.isEmpty ? errorText(context, e) : null;
      });
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(_isEdit ? l.editListing : l.newListing)),
      body: ref.watch(listingFormInfoProvider).when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (e, _) => ErrorView(error: e, onRetry: () => ref.invalidate(listingFormInfoProvider)),
            data: (info) {
              if (_loadingExisting) return const Center(child: CircularProgressIndicator());
              if (!_isEdit && !_initialized) {
                _initialized = true;
                _location.text = info.defaultLocation;
                if (!info.types.any((t) => t.code == _type) && info.types.isNotEmpty) _type = info.types.first.code;
              }
              return _formBody(context, l, info);
            },
          ),
    );
  }

  Widget _formBody(BuildContext context, AppLocalizations l, ListingFormInfo info) {
    final theme = Theme.of(context);
    final typeInfo = info.types.firstWhere((t) => t.code == _type, orElse: () => info.types.first);
    if (!info.canPublish && !_isEdit) {
      return MessageView(icon: Icons.block, title: l.publishBlocked, text: info.publishBlockReason);
    }

    return Form(
      key: _form,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
        children: [
          // ---- фото
          Text(l.photos, style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          Text(l.photosHint(info.maxPhotos), style: theme.textTheme.bodySmall),
          const SizedBox(height: 8),
          _PhotoStrip(
            photos: _photos,
            cover: _cover,
            max: info.maxPhotos,
            onCover: (i) => setState(() => _cover = i),
            onRemove: (i) => setState(() {
              _photos.removeAt(i);
              if (_cover >= _photos.length) _cover = 0;
            }),
            onAddGallery: () => _addPhotos(ImageSource.gallery, info.maxPhotos),
            onAddCamera: () => _addPhotos(ImageSource.camera, info.maxPhotos),
          ),
          if (_fieldErrors['images'] != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text(_fieldErrors['images']!, style: TextStyle(color: theme.colorScheme.error)),
            ),
          const SizedBox(height: 20),

          // ---- тип
          Text(l.listingType, style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final t in info.types)
                ChoiceChip(
                  label: Text(listingTypeLabel(context, t.code, t.label)),
                  selected: _type == t.code,
                  onSelected: _isEdit && !info.types.any((x) => x.code == _type)
                      ? null
                      : (_) => setState(() => _type = t.code),
                ),
            ],
          ),
          const SizedBox(height: 20),

          TextFormField(
            controller: _title,
            maxLength: 120,
            textCapitalization: TextCapitalization.sentences,
            decoration: InputDecoration(labelText: l.listingTitle, errorText: _fieldErrors['title']),
            validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
          ),
          const SizedBox(height: 8),
          TextFormField(
            controller: _description,
            minLines: 4,
            maxLines: 10,
            textCapitalization: TextCapitalization.sentences,
            decoration: InputDecoration(labelText: l.listingDescription, errorText: _fieldErrors['description'], alignLabelWithHint: true),
            validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
          ),
          const SizedBox(height: 12),

          if (typeInfo.hasPrice) ...[
            TextFormField(
              controller: _price,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: l.listingPrice, errorText: _fieldErrors['price']),
              validator: (v) => (int.tryParse(v ?? '') ?? 0) <= 0 ? l.fieldRequired : null,
            ),
            const SizedBox(height: 12),
          ],
          if (typeInfo.hasQuantity) ...[
            TextFormField(
              controller: _quantity,
              keyboardType: TextInputType.number,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(labelText: l.listingQuantity, errorText: _fieldErrors['quantity']),
              validator: (v) => (int.tryParse(v ?? '') ?? 0) < 1 ? l.fieldRequired : null,
            ),
            const SizedBox(height: 12),
          ],
          if (typeInfo.requiresExchangeFor) ...[
            TextFormField(
              controller: _exchangeFor,
              decoration: InputDecoration(labelText: l.listingExchangeFor, errorText: _fieldErrors['exchange_for']),
              validator: (v) => (v == null || v.trim().isEmpty) ? l.fieldRequired : null,
            ),
            const SizedBox(height: 12),
          ],
          if (typeInfo.hasCategory) ...[
            _CategoryField(value: _category, onChanged: (v) => setState(() => _category = v)),
            const SizedBox(height: 12),
          ],
          TextFormField(
            controller: _location,
            textCapitalization: TextCapitalization.words,
            decoration: InputDecoration(labelText: l.listingLocation),
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _characteristics,
            minLines: 2,
            maxLines: 8,
            decoration: InputDecoration(
              labelText: l.listingCharacteristics,
              helperText: l.listingCharacteristicsHint,
              errorText: _fieldErrors['characteristics'],
              alignLabelWithHint: true,
            ),
          ),

          if (!info.taxIdOnFile && !_isEdit) ...[
            const SizedBox(height: 16),
            TextFormField(
              controller: _taxId,
              keyboardType: TextInputType.number,
              maxLength: 12,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(
                labelText: info.taxIdKind == 'bin' ? l.binLabel : l.iinLabel,
                helperText: l.taxIdHint,
                helperMaxLines: 3,
                errorText: _fieldErrors['iin'] ?? _fieldErrors['bin'],
              ),
              validator: (v) => (v ?? '').length != 12 ? l.fieldRequired : null,
            ),
          ],
          if (!info.rulesAccepted && !_isEdit)
            CheckboxListTile(
              value: _acceptRules,
              onChanged: (v) => setState(() => _acceptRules = v ?? false),
              controlAffinity: ListTileControlAffinity.leading,
              contentPadding: EdgeInsets.zero,
              title: Text(l.acceptListingRules),
            ),
          const SizedBox(height: 12),
          DecoratedBox(
            decoration: BoxDecoration(
              color: theme.colorScheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(12),
            ),
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                const Icon(Icons.info_outline, size: 20),
                const SizedBox(width: 8),
                Expanded(child: Text(l.pickupOnlyNote, style: theme.textTheme.bodySmall)),
              ]),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: TextStyle(color: theme.colorScheme.error), textAlign: TextAlign.center),
          ],
          const SizedBox(height: 20),
          FilledButton(
            onPressed: _busy ? null : () => _submit(info),
            child: _busy
                ? Text(_progress != null && _progress! > 0 && _progress! < 1
                    ? l.uploading((_progress! * 100).round())
                    : '…')
                : Text(_isEdit ? l.save : l.publish),
          ),
        ],
      ),
    );
  }
}

class _PhotoStrip extends StatelessWidget {
  const _PhotoStrip({
    required this.photos,
    required this.cover,
    required this.max,
    required this.onCover,
    required this.onRemove,
    required this.onAddGallery,
    required this.onAddCamera,
  });

  final List<DraftPhoto> photos;
  final int cover;
  final int max;
  final void Function(int) onCover;
  final void Function(int) onRemove;
  final VoidCallback onAddGallery;
  final VoidCallback onAddCamera;

  @override
  Widget build(BuildContext context) {
    final l = AppLocalizations.of(context);
    final theme = Theme.of(context);
    return SizedBox(
      height: 112,
      child: ListView(
        scrollDirection: Axis.horizontal,
        children: [
          for (var i = 0; i < photos.length; i++)
            Padding(
              padding: const EdgeInsets.only(right: 8),
              child: Semantics(
                button: true,
                selected: i == cover,
                label: i == cover ? l.coverBadge : l.photos,
                child: GestureDetector(
                  onTap: () => onCover(i),
                  child: Stack(children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: SizedBox(
                        width: 112,
                        height: 112,
                        child: photos[i].isNew
                            ? Image.file(File(photos[i].localPath!), fit: BoxFit.cover, cacheWidth: 300)
                            : CachedNetworkImage(imageUrl: photos[i].url!, fit: BoxFit.cover, memCacheWidth: 300),
                      ),
                    ),
                    if (i == cover)
                      Positioned(
                        left: 6,
                        bottom: 6,
                        child: DecoratedBox(
                          decoration: BoxDecoration(color: theme.colorScheme.primary, borderRadius: BorderRadius.circular(6)),
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            child: Text(l.coverBadge, style: TextStyle(color: theme.colorScheme.onPrimary, fontSize: 11)),
                          ),
                        ),
                      ),
                    Positioned(
                      right: 0,
                      top: 0,
                      child: IconButton(
                        tooltip: l.removePhoto,
                        visualDensity: VisualDensity.compact,
                        style: IconButton.styleFrom(backgroundColor: Colors.black54, foregroundColor: Colors.white),
                        icon: const Icon(Icons.close, size: 18),
                        onPressed: () => onRemove(i),
                      ),
                    ),
                  ]),
                ),
              ),
            ),
          if (photos.length < max) ...[
            _AddTile(icon: Icons.photo_library_outlined, label: l.addPhotoGallery, onTap: onAddGallery),
            const SizedBox(width: 8),
            _AddTile(icon: Icons.photo_camera_outlined, label: l.addPhotoCamera, onTap: onAddCamera),
          ],
        ],
      ),
    );
  }
}

class _AddTile extends StatelessWidget {
  const _AddTile({required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: scheme.surfaceContainerHighest,
      borderRadius: BorderRadius.circular(12),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: SizedBox(
          width: 112,
          height: 112,
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(icon, color: scheme.primary),
            const SizedBox(height: 6),
            Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12)),
          ]),
        ),
      ),
    );
  }
}

class _CategoryField extends ConsumerWidget {
  const _CategoryField({required this.value, required this.onChanged});
  final String? value;
  final void Function(String?) onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final groups = ref.watch(categoriesProvider).value ?? const [];
    String? label;
    for (final g in groups) {
      for (final c in g.children) {
        if (c.value == value) label = '${g.label} / ${c.label}';
      }
    }
    return InkWell(
      onTap: groups.isEmpty
          ? null
          : () async {
              final picked = await showModalBottomSheet<String>(
                context: context,
                isScrollControlled: true,
                showDragHandle: true,
                builder: (ctx) => DraggableScrollableSheet(
                  expand: false,
                  initialChildSize: 0.7,
                  builder: (ctx, scroll) => ListView(
                    controller: scroll,
                    children: [
                      for (final g in groups)
                        ExpansionTile(
                          title: Text(g.label),
                          initiallyExpanded: g.children.any((c) => c.value == value),
                          children: [
                            for (final c in g.children)
                              ListTile(
                                title: Text(c.label),
                                trailing: c.value == value ? const Icon(Icons.check) : null,
                                onTap: () => Navigator.pop(ctx, c.value),
                              ),
                          ],
                        ),
                    ],
                  ),
                ),
              );
              if (picked != null) onChanged(picked);
            },
      child: InputDecorator(
        decoration: InputDecoration(labelText: l.listingCategory, suffixIcon: const Icon(Icons.expand_more)),
        child: Text(label ?? l.chooseCategory),
      ),
    );
  }
}
