import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import '../models.dart';
import '../nav.dart';
import '../widgets/product_tile.dart';

class CatalogScreen extends StatefulWidget {
  const CatalogScreen({super.key});

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  final _items = <ProductCard>[];
  final _scroll = ScrollController();
  final _search = TextEditingController();
  Timer? _debounce;

  String? _type;
  Category? _category;
  int _page = 0;
  int _total = 0;
  bool _hasMore = true;
  bool _loading = false;
  String? _error;
  int _requestId = 0;
  int? _loadedForUser;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.extentAfter < 600) _loadMore();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Перезагружаем при первом показе и при смене пользователя (избранное в карточках)
    final uid = SessionScope.of(context).user?.id ?? 0;
    if (_loadedForUser != uid) {
      _loadedForUser = uid;
      _reload();
    }
  }

  @override
  void dispose() {
    _scroll.dispose();
    _search.dispose();
    _debounce?.cancel();
    super.dispose();
  }

  Future<void> _reload() async {
    _requestId++;
    setState(() {
      _items.clear();
      _page = 0;
      _hasMore = true;
      _error = null;
      _loading = false;
    });
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    final req = _requestId;
    setState(() => _loading = true);
    try {
      final page = await SessionScope.read(context).api.products(
            type: _type,
            query: _search.text.trim(),
            category: _category?.name,
            page: _page + 1,
          );
      if (!mounted || req != _requestId) return;
      setState(() {
        _items.addAll(page.items);
        _page = page.page;
        _total = page.total;
        _hasMore = page.hasMore;
      });
    } on ApiException catch (e) {
      if (!mounted || req != _requestId) return;
      if (e.isStub) {
        // Доступ к сайту отозвали — пересобираем корень
        SessionScope.read(context).start();
        return;
      }
      setState(() => _error = e.message);
    } finally {
      if (mounted && req == _requestId) setState(() => _loading = false);
    }
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), _reload);
  }

  Future<void> _toggleFavorite(ProductCard item) async {
    await requireLogin(context, () async {
      try {
        final fav = await SessionScope.read(context).api.toggleFavorite(item.id);
        if (mounted) setState(() => item.favorited = fav);
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      }
    });
  }

  Future<void> _pickCategory() async {
    final categories = SessionScope.read(context).config?.categories ?? [];
    final picked = await showModalBottomSheet<Category?>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.7,
        maxChildSize: 0.95,
        builder: (ctx, controller) => ListView(
          controller: controller,
          children: [
            ListTile(
              leading: const Icon(Icons.apps_rounded),
              title: const Text('Все категории'),
              onTap: () => Navigator.pop(ctx, Category(name: '', label: '')),
            ),
            for (final parent in categories)
              ExpansionTile(
                title: Text(parent.label, style: const TextStyle(fontWeight: FontWeight.w600)),
                children: [
                  ListTile(
                    contentPadding: const EdgeInsets.only(left: 32, right: 16),
                    title: Text('Все в «${parent.label}»'),
                    onTap: () => Navigator.pop(ctx, parent),
                  ),
                  for (final child in parent.children)
                    ListTile(
                      contentPadding: const EdgeInsets.only(left: 32, right: 16),
                      title: Text(child.label),
                      onTap: () => Navigator.pop(ctx, child),
                    ),
                ],
              ),
          ],
        ),
      ),
    );
    if (picked == null) return;
    setState(() => _category = picked.name.isEmpty ? null : picked);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    final types = SessionScope.of(context).config?.types ?? [];
    final categoriesApply = _type == null || _type == 'new' || _type == 'used';

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: _reload,
          child: CustomScrollView(
            controller: _scroll,
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 12, 12, 4),
                  child: TextField(
                    controller: _search,
                    onChanged: _onSearchChanged,
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => _reload(),
                    decoration: InputDecoration(
                      hintText: 'Поиск по объявлениям',
                      prefixIcon: const Icon(Icons.search_rounded),
                      suffixIcon: _search.text.isEmpty
                          ? null
                          : IconButton(
                              icon: const Icon(Icons.close_rounded),
                              onPressed: () {
                                _search.clear();
                                _reload();
                              },
                            ),
                    ),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 52,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                    children: [
                      _chip('Все', _type == null, () => _setType(null)),
                      for (final t in types) _chip(t.label, _type == t.key, () => _setType(t.key)),
                    ],
                  ),
                ),
              ),
              if (categoriesApply)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    child: Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _pickCategory,
                            style: OutlinedButton.styleFrom(
                              minimumSize: const Size(0, 40),
                              alignment: Alignment.centerLeft,
                              backgroundColor: Colors.white,
                            ),
                            icon: const Icon(Icons.tune_rounded, size: 18),
                            label: Text(
                              _category?.label ?? 'Все категории',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                        if (_category != null)
                          IconButton(
                            onPressed: () {
                              setState(() => _category = null);
                              _reload();
                            },
                            icon: const Icon(Icons.close_rounded),
                          ),
                      ],
                    ),
                  ),
                ),
              if (_page > 0)
                SliverToBoxAdapter(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(14, 10, 14, 4),
                    child: Text('Найдено: $_total',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(color: Colors.black54)),
                  ),
                ),
              SliverPadding(
                padding: const EdgeInsets.all(12),
                sliver: SliverGrid.builder(
                  gridDelegate: productGridDelegate(context),
                  itemCount: _items.length,
                  itemBuilder: (_, i) {
                    final item = _items[i];
                    return ProductTile(
                      item: item,
                      onTap: () async {
                        await openProduct(context, item.id);
                        if (mounted) setState(() {});
                      },
                      onFavorite: () => _toggleFavorite(item),
                    );
                  },
                ),
              ),
              SliverToBoxAdapter(child: _footer()),
            ],
          ),
        ),
      ),
    );
  }

  void _setType(String? type) {
    if (_type == type) return;
    setState(() {
      _type = type;
      if (!(type == null || type == 'new' || type == 'used')) _category = null;
    });
    _reload();
  }

  Widget _chip(String label, bool selected, VoidCallback onTap) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        showCheckmark: false,
      ),
    );
  }

  Widget _footer() {
    if (_loading) {
      return const Padding(
        padding: EdgeInsets.all(24),
        child: Center(child: CircularProgressIndicator()),
      );
    }
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            Text(_error!, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            OutlinedButton(onPressed: _loadMore, child: const Text('Повторить')),
          ],
        ),
      );
    }
    if (_items.isEmpty && !_hasMore) {
      return const Padding(
        padding: EdgeInsets.all(40),
        child: Center(child: Text('Ничего не найдено', style: TextStyle(color: Colors.black54))),
      );
    }
    return const SizedBox(height: 24);
  }
}
