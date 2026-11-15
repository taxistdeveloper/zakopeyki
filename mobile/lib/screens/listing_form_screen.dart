import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../main.dart';
import '../models.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'info_page_screen.dart';

/// Подача / редактирование объявления. Типы и правила — из GET /listings/form.
/// Возвращает id сохранённого объявления.
class ListingFormScreen extends StatefulWidget {
  const ListingFormScreen({super.key, this.editId});

  final int? editId;

  @override
  State<ListingFormScreen> createState() => _ListingFormScreenState();
}

class _Photo {
  _Photo.existing(this.file, this.url) : path = null;
  _Photo.local(this.path)
      : file = null,
        url = null;

  final String? file;
  final String? url;
  final String? path;
}

class _Spec {
  _Spec([String name = '', String value = ''])
      : name = TextEditingController(text: name),
        value = TextEditingController(text: value);

  final TextEditingController name;
  final TextEditingController value;

  void dispose() {
    name.dispose();
    value.dispose();
  }
}

class _ListingFormScreenState extends State<ListingFormScreen> {
  ListingForm? _form;
  String? _loadError;

  String _type = 'used';
  final _photos = <_Photo>[];
  final _title = TextEditingController();
  final _price = TextEditingController();
  final _quantity = TextEditingController(text: '1');
  final _exchangeFor = TextEditingController();
  final _description = TextEditingController();
  final _location = TextEditingController();
  final _whatsapp = TextEditingController();
  final _taxId = TextEditingController();
  final _specs = <_Spec>[];
  String? _categoryValue;
  bool _rulesAck = false;

  bool _busy = false;
  String? _error;
  Map<String, String> _fields = const {};

  bool get _editing => widget.editId != null;

  ListingTypeSpec? get _spec => _form?.types.where((t) => t.code == _type).firstOrNull;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_title, _price, _quantity, _exchangeFor, _description, _location, _whatsapp, _taxId]) {
      c.dispose();
    }
    for (final s in _specs) {
      s.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _loadError = null);
    final api = SessionScope.read(context).api;
    try {
      final form = await api.listingForm();
      EditableListing? e;
      if (_editing) e = await api.editableListing(widget.editId!);
      if (!mounted) return;
      setState(() {
        _form = form;
        _location.text = form.defaultLocation;
        if (form.types.isNotEmpty && !form.types.any((t) => t.code == _type)) _type = form.types.first.code;
        if (e != null) _fill(e);
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _loadError = e.message);
    }
  }

  void _fill(EditableListing e) {
    _type = e.type;
    _title.text = e.title;
    _price.text = e.price > 0 ? '${e.price}' : '';
    _quantity.text = '${e.quantity}';
    _exchangeFor.text = e.exchangeFor ?? '';
    _description.text = e.description;
    _location.text = e.location;
    _whatsapp.text = e.whatsapp ?? '';
    _categoryValue = e.category;
    final imgs = [...e.images]..sort((a, b) => (b.isCover ? 1 : 0) - (a.isCover ? 1 : 0));
    _photos.addAll(imgs.map((i) => _Photo.existing(i.file, i.url)));
    for (final line in e.characteristics.split('\n')) {
      final i = line.indexOf(':');
      if (i > 0) _specs.add(_Spec(line.substring(0, i).trim(), line.substring(i + 1).trim()));
    }
  }

  // --- фото ----------------------------------------------------------------

  Future<void> _addPhotos() async {
    final max = _form?.maxPhotos ?? 10;
    final left = max - _photos.length;
    if (left <= 0) {
      showSnack(context, 'Можно добавить не больше $max фото');
      return;
    }
    final source = await showModalBottomSheet<ImageSource>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Сделать фото'),
              onTap: () => Navigator.pop(ctx, ImageSource.camera),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Выбрать из галереи'),
              onTap: () => Navigator.pop(ctx, ImageSource.gallery),
            ),
          ],
        ),
      ),
    );
    if (source == null) return;
    final picker = ImagePicker();
    final files = source == ImageSource.camera
        ? [?await picker.pickImage(source: source, maxWidth: 2000, imageQuality: 85)]
        : await picker.pickMultiImage(maxWidth: 2000, imageQuality: 85, limit: left > 1 ? left : null);
    if (!mounted || files.isEmpty) return;
    setState(() => _photos.addAll(files.take(left).map((f) => _Photo.local(f.path))));
  }

  // --- категория -------------------------------------------------------------

  Future<void> _pickCategory() async {
    final cats = SessionScope.read(context).config?.categories ?? [];
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
            for (final c in cats)
              ExpansionTile(
                title: Text(c.label, style: const TextStyle(fontWeight: FontWeight.w600)),
                children: [
                  for (final sub in c.children)
                    ListTile(
                      contentPadding: const EdgeInsets.only(left: 32, right: 16),
                      title: Text(sub.label),
                      onTap: () => Navigator.pop(ctx, sub.name),
                    ),
                  if (c.children.isEmpty)
                    ListTile(
                      contentPadding: const EdgeInsets.only(left: 32, right: 16),
                      title: Text(c.label),
                      onTap: () => Navigator.pop(ctx, c.name),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
    if (picked != null) setState(() => _categoryValue = picked);
  }

  String? get _categoryLabel {
    final v = _categoryValue;
    if (v == null || v.isEmpty) return null;
    for (final c in SessionScope.read(context).config?.categories ?? <Category>[]) {
      if (c.name == v) return c.label;
      for (final s in c.children) {
        if (s.name == v) return '${c.label} / ${s.label}';
      }
    }
    return v;
  }

  // --- отправка ----------------------------------------------------------------

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    final form = _form!;
    final spec = _spec;
    String? local;
    if (_photos.isEmpty) local = 'Добавьте хотя бы одно фото';
    if (_title.text.trim().isEmpty) local ??= 'Укажите название';
    if (spec?.hasPrice == true && (int.tryParse(_price.text) ?? 0) <= 0) local ??= 'Укажите цену';
    if (spec?.hasCategory == true && (_categoryValue ?? '').isEmpty) local ??= 'Выберите категорию';
    if (spec?.requiresExchangeFor == true && _exchangeFor.text.trim().isEmpty) local ??= 'Напишите, на что хотите обменять';
    if (_description.text.trim().isEmpty) local ??= 'Добавьте описание';
    if (!form.rulesAccepted && !_rulesAck) local ??= 'Подтвердите правила размещения';
    if (local != null) {
      setState(() => _error = local);
      return;
    }

    final keep = _photos.where((p) => p.file != null).map((p) => p.file!).toList();
    final newPaths = _photos.where((p) => p.path != null).map((p) => p.path!).toList();
    final first = _photos.first;
    final cover = first.file ?? '__new__${newPaths.indexOf(first.path!)}';

    final specs = _specs
        .where((s) => s.name.text.trim().isNotEmpty && s.value.text.trim().isNotEmpty)
        .map((s) => '${s.name.text.trim()}: ${s.value.text.trim()}')
        .join('\n');

    final fields = <String, String>{
      'type': _type,
      'title': _title.text.trim(),
      'description': _description.text.trim(),
      'characteristics': specs,
      'location': _location.text.trim(),
      'whatsapp': _whatsapp.text.trim(),
      'cover': cover,
      if (spec?.hasPrice == true) 'price': _price.text.trim(),
      if (spec?.hasCategory == true && _categoryValue != null) 'category': _categoryValue!,
      if (spec?.hasQuantity == true) 'quantity': _quantity.text.trim().isEmpty ? '1' : _quantity.text.trim(),
      if (spec?.requiresExchangeFor == true) 'exchange_for': _exchangeFor.text.trim(),
      if (!form.rulesAccepted) 'listing_rules_ack': _rulesAck ? '1' : '0',
      if (!form.taxIdOnFile && _taxId.text.trim().isNotEmpty) form.taxIdKind: _taxId.text.trim(),
    };

    setState(() {
      _busy = true;
      _error = null;
      _fields = const {};
    });
    try {
      final id = await SessionScope.read(context).api.saveListing(
            id: widget.editId,
            fields: fields,
            keepImages: keep,
            files: newPaths,
          );
      if (!mounted) return;
      showSnack(context, _editing ? 'Изменения сохранены' : 'Объявление опубликовано');
      Navigator.of(context).pop(id);
    } on ApiException catch (e) {
      if (mounted) {
        setState(() {
          _error = e.message;
          _fields = e.fields;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  // --- UI ----------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final form = _form;
    return Scaffold(
      appBar: AppBar(title: Text(_editing ? 'Редактирование' : 'Новое объявление')),
      body: form == null
          ? (_loadError != null
              ? ErrorView(message: _loadError!, onRetry: _load)
              : const Center(child: CircularProgressIndicator()))
          : _body(form),
      bottomNavigationBar: form == null || (!form.canPublish && !_editing)
          ? null
          : BottomActionBar(
              child: FilledButton(
                onPressed: _busy ? null : _submit,
                child: busyLabel(_busy, _editing ? 'Сохранить' : 'Опубликовать'),
              ),
            ),
    );
  }

  Widget _body(ListingForm form) {
    if (!form.canPublish && !_editing) {
      return EmptyView(
        icon: Icons.block_rounded,
        title: 'Сейчас нельзя подать объявление',
        text: form.publishBlockReason,
      );
    }
    final spec = _spec;
    return ListView(
      padding: const EdgeInsets.only(top: 12, bottom: 12),
      children: [
        SectionCard(
          title: 'Что размещаете',
          child: Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final t in form.types)
                ChoiceChip(
                  label: Text(t.label),
                  selected: _type == t.code,
                  onSelected: (_) => setState(() => _type = t.code),
                ),
            ],
          ),
        ),
        SectionCard(
          title: 'Фото',
          child: _photoGrid(form.maxPhotos),
        ),
        SectionCard(
          title: 'Основное',
          child: Column(
            children: [
              TextField(
                controller: _title,
                maxLength: 120,
                textCapitalization: TextCapitalization.sentences,
                decoration: InputDecoration(labelText: 'Название', errorText: _fields['title']),
              ),
              if (spec?.hasPrice == true) ...[
                const SizedBox(height: 4),
                TextField(
                  controller: _price,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(labelText: 'Цена', suffixText: '₸', errorText: _fields['price']),
                ),
              ],
              if (spec?.hasQuantity == true) ...[
                const SizedBox(height: 12),
                TextField(
                  controller: _quantity,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(labelText: 'Количество', suffixText: 'шт.', errorText: _fields['quantity']),
                ),
              ],
              if (spec?.requiresExchangeFor == true) ...[
                const SizedBox(height: 12),
                TextField(
                  controller: _exchangeFor,
                  decoration: InputDecoration(labelText: 'На что обменяете', errorText: _fields['exchange_for']),
                ),
              ],
              if (spec?.hasCategory == true) ...[
                const SizedBox(height: 12),
                InkWell(
                  onTap: _pickCategory,
                  borderRadius: BorderRadius.circular(12),
                  child: InputDecorator(
                    decoration: InputDecoration(
                      labelText: 'Категория',
                      errorText: _fields['category'],
                      suffixIcon: const Icon(Icons.expand_more_rounded),
                    ),
                    child: Text(_categoryLabel ?? 'Выберите',
                        style: TextStyle(color: _categoryLabel == null ? context.pal.muted2 : null)),
                  ),
                ),
              ],
            ],
          ),
        ),
        SectionCard(
          title: 'Описание',
          child: TextField(
            controller: _description,
            minLines: 4,
            maxLines: 12,
            textCapitalization: TextCapitalization.sentences,
            decoration: InputDecoration(
              hintText: 'Состояние, комплектация, причина продажи…',
              errorText: _fields['description'],
            ),
          ),
        ),
        SectionCard(
          title: 'Характеристики',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              for (final s in _specs)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: Row(
                    children: [
                      Expanded(child: TextField(controller: s.name, decoration: const InputDecoration(hintText: 'Параметр'))),
                      const SizedBox(width: 8),
                      Expanded(child: TextField(controller: s.value, decoration: const InputDecoration(hintText: 'Значение'))),
                      IconButton(
                        onPressed: () => setState(() {
                          _specs.remove(s);
                          s.dispose();
                        }),
                        icon: Icon(Icons.close_rounded, color: context.pal.faint),
                      ),
                    ],
                  ),
                ),
              if (_fields['characteristics'] != null)
                Text(_fields['characteristics']!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              if (_specs.length < 12)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    onPressed: () => setState(() => _specs.add(_Spec())),
                    icon: const Icon(Icons.add_rounded),
                    label: Text(_specs.isEmpty ? 'Добавить (например, «Размер: M»)' : 'Ещё параметр'),
                  ),
                ),
            ],
          ),
        ),
        SectionCard(
          title: 'Контакты и место',
          child: Column(
            children: [
              TextField(
                controller: _location,
                decoration: InputDecoration(labelText: 'Город', errorText: _fields['location']),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _whatsapp,
                keyboardType: TextInputType.phone,
                decoration: InputDecoration(
                  labelText: 'WhatsApp (необязательно)',
                  hintText: '+7 7__ ___ __ __',
                  errorText: _fields['whatsapp'],
                ),
              ),
              const SizedBox(height: 8),
              Row(
                children: [
                  Icon(Icons.storefront_rounded, size: 18, color: context.pal.muted2),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text('Передача: самовывоз. Доставку СДЭК можно настроить на сайте.',
                        style: TextStyle(color: context.pal.muted, fontSize: 13)),
                  ),
                ],
              ),
            ],
          ),
        ),
        if (!form.taxIdOnFile || !form.rulesAccepted)
          SectionCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (!form.taxIdOnFile)
                  TextField(
                    controller: _taxId,
                    keyboardType: TextInputType.number,
                    maxLength: 12,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      labelText: form.taxIdKind == 'bin' ? 'БИН организации' : 'ИИН',
                      helperText: 'Нужен один раз — требование закона для продавцов',
                      errorText: _fields['iin'] ?? _fields['bin'],
                    ),
                  ),
                if (!form.rulesAccepted)
                  CheckboxListTile(
                    value: _rulesAck,
                    onChanged: (v) => setState(() => _rulesAck = v ?? false),
                    controlAffinity: ListTileControlAffinity.leading,
                    contentPadding: EdgeInsets.zero,
                    title: GestureDetector(
                      onTap: () => openInfoPage(context, 'listing-rules', title: 'Правила размещения'),
                      child: const Text.rich(TextSpan(children: [
                        TextSpan(text: 'Согласен с '),
                        TextSpan(text: 'правилами размещения', style: TextStyle(color: kBrand, fontWeight: FontWeight.w600)),
                      ])),
                    ),
                  ),
              ],
            ),
          ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text(_error!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ),
      ],
    );
  }

  Widget _photoGrid(int max) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (var i = 0; i < _photos.length; i++) _photoTile(i),
            if (_photos.length < max)
              InkWell(
                onTap: _addPhotos,
                borderRadius: BorderRadius.circular(12),
                child: Container(
                  width: 92,
                  height: 92,
                  decoration: BoxDecoration(
                    color: context.pal.brandSoft,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: kBrand.withValues(alpha: 0.4)),
                  ),
                  child: const Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Icon(Icons.add_a_photo_outlined, color: kBrand),
                      SizedBox(height: 4),
                      Text('Добавить', style: TextStyle(color: kBrand, fontSize: 12)),
                    ],
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        Text('До $max фото. Первое — обложка; нажмите на фото, чтобы сделать его обложкой.',
            style: TextStyle(color: context.pal.muted, fontSize: 12.5)),
      ],
    );
  }

  Widget _photoTile(int i) {
    final p = _photos[i];
    final image = p.path != null
        ? Image.file(File(p.path!), fit: BoxFit.cover)
        : CachedNetworkImage(imageUrl: p.url ?? '', fit: BoxFit.cover);
    return SizedBox(
      width: 92,
      height: 92,
      child: Stack(
        fit: StackFit.expand,
        children: [
          GestureDetector(
            onTap: () => setState(() {
              _photos.removeAt(i);
              _photos.insert(0, p);
            }),
            child: ClipRRect(borderRadius: BorderRadius.circular(12), child: image),
          ),
          if (i == 0)
            Positioned(
              left: 4,
              bottom: 4,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                decoration: BoxDecoration(color: kBrand, borderRadius: BorderRadius.circular(6)),
                child: const Text('Обложка', style: TextStyle(color: Colors.white, fontSize: 10)),
              ),
            ),
          Positioned(
            right: 2,
            top: 2,
            child: InkWell(
              onTap: () => setState(() => _photos.removeAt(i)),
              child: Container(
                padding: const EdgeInsets.all(3),
                decoration: BoxDecoration(color: Colors.black54, shape: BoxShape.circle),
                child: const Icon(Icons.close_rounded, size: 14, color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
