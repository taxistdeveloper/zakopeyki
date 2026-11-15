import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../live_models.dart';
import '../main.dart';
import '../social_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'live_common.dart';
import 'live_host_screen.dart';
import 'live_view_screen.dart';

/// Идущие сейчас эфиры + «Выйти в эфир».
class LiveListScreen extends StatefulWidget {
  const LiveListScreen({super.key});

  @override
  State<LiveListScreen> createState() => _LiveListScreenState();
}

class _LiveListScreenState extends State<LiveListScreen> {
  List<LiveSummary>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final items = await SessionScope.read(context).api.streams();
      if (mounted) setState(() => _items = items);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(LiveSummary s) async {
    await openLive(context, s.id);
    if (mounted) _load();
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    return Scaffold(
      appBar: AppBar(title: const Text('Прямые эфиры')),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFFEF4444),
        foregroundColor: Colors.white,
        onPressed: () async {
          await openGoLive(context);
          if (mounted) _load();
        },
        icon: const Icon(Icons.videocam_rounded),
        label: const Text('Выйти в эфир'),
      ),
      body: items == null
          ? (_error != null
                ? ErrorView(message: _error!, onRetry: _load)
                : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: items.isEmpty
                  ? ListView(
                      children: const [
                        SizedBox(height: 120),
                        EmptyView(
                          icon: Icons.live_tv_rounded,
                          title: 'Сейчас нет эфиров',
                          text:
                              'Продавцы показывают товары вживую и отвечают на вопросы. Начните свой!',
                        ),
                      ],
                    )
                  : GridView.builder(
                      padding: const EdgeInsets.fromLTRB(12, 12, 12, 96),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: 2,
                            mainAxisSpacing: 10,
                            crossAxisSpacing: 10,
                            childAspectRatio: 0.66,
                          ),
                      itemCount: items.length,
                      itemBuilder: (_, i) =>
                          LiveTile(s: items[i], onTap: () => _open(items[i])),
                    ),
            ),
    );
  }
}

/// Карточка идущего эфира (обложка, LIVE, зрители, ведущий).
class LiveTile extends StatelessWidget {
  const LiveTile({super.key, required this.s, required this.onTap});

  final LiveSummary s;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: Stack(
          fit: StackFit.expand,
          children: [
            s.cover != null
                ? CachedNetworkImage(
                    imageUrl: s.cover!,
                    fit: BoxFit.cover,
                    errorWidget: (_, _, _) =>
                        const ColoredBox(color: Color(0xFF1E293B)),
                  )
                : const ColoredBox(
                    color: Color(0xFF1E293B),
                    child: Icon(
                      Icons.live_tv_rounded,
                      color: Colors.white24,
                      size: 56,
                    ),
                  ),
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [Colors.black45, Colors.transparent, Colors.black87],
                  stops: [0, 0.4, 1],
                ),
              ),
            ),
            Positioned(
              left: 8,
              top: 8,
              child: Row(
                children: [
                  const LiveBadge(small: true),
                  const SizedBox(width: 6),
                  LivePill(
                    icon: Icons.visibility_rounded,
                    text: '${s.viewers}',
                  ),
                ],
              ),
            ),
            Positioned(
              left: 10,
              right: 10,
              bottom: 10,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    s.title,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      CircleAvatar(
                        radius: 11,
                        backgroundColor: context.pal.brandSoft2,
                        foregroundImage: s.host.avatar != null
                            ? NetworkImage(s.host.avatar!)
                            : null,
                        child: Text(
                          initialOf(s.host.name),
                          style: const TextStyle(color: kBrand, fontSize: 10),
                        ),
                      ),
                      const SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          s.isMine ? 'Ваш эфир' : s.host.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 12.5,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Отдельная лента «Прямые эфиры» на главной. Скрыта, когда эфиров нет.
class LiveStrip extends StatefulWidget {
  const LiveStrip({super.key});

  @override
  State<LiveStrip> createState() => LiveStripState();
}

class LiveStripState extends State<LiveStrip> {
  List<LiveSummary> _items = const [];

  @override
  void initState() {
    super.initState();
    reload();
  }

  Future<void> reload() async {
    try {
      final l = await SessionScope.read(context).api.streams();
      if (mounted) setState(() => _items = l);
    } catch (_) {
      // эфиры не критичны для каталога
    }
  }

  Future<void> _all() async {
    await Navigator.of(
      context,
    ).push(MaterialPageRoute(builder: (_) => const LiveListScreen()));
    reload();
  }

  @override
  Widget build(BuildContext context) {
    if (_items.isEmpty) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 10, 6, 2),
          child: Row(
            children: [
              const LiveBadge(small: true),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  'Прямые эфиры',
                  style: display(17, weight: FontWeight.w700, color: context.pal.ink),
                ),
              ),
              TextButton(
                onPressed: _all,
                child: Text('Все · ${_items.length}'),
              ),
            ],
          ),
        ),
        SizedBox(
          height: 200,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(14, 6, 14, 6),
            itemCount: _items.length,
            separatorBuilder: (_, _) => const SizedBox(width: 10),
            itemBuilder: (_, i) => SizedBox(
              width: 132,
              child: LiveTile(
                s: _items[i],
                onTap: () async {
                  await openLive(context, _items[i].id);
                  reload();
                },
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// Открыть эфир: чужой — просмотр, свой — экран ведущего недоступен после закрытия, показываем как зритель.
Future<void> openLive(BuildContext context, int streamId) =>
    Navigator.of(context).push(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => LiveViewScreen(streamId: streamId),
      ),
    );
