import 'dart:async';
import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:video_player/video_player.dart';

import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../social_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'seller_screen.dart';

// =============================================================== лента (кружки над каталогом)

class StoriesBar extends StatefulWidget {
  const StoriesBar({super.key});

  @override
  State<StoriesBar> createState() => StoriesBarState();
}

class StoriesBarState extends State<StoriesBar> {
  List<StoryGroup> _groups = const [];
  int? _loadedFor = -1;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final uid = SessionScope.of(context).user?.id;
    if (_loadedFor != uid) {
      _loadedFor = uid;
      reload();
    }
  }

  Future<void> reload() async {
    final api = SessionScope.read(context).api;
    try {
      final g = await api.stories();
      if (mounted) setState(() => _groups = g);
    } catch (_) {
      // лента историй не критична для каталога
    }
  }

  Future<void> _create() async {
    await requireLogin(context, () async {
      final ok = await Navigator.of(context).push<bool>(
        MaterialPageRoute(
          fullscreenDialog: true,
          builder: (_) => const StoryCreateScreen(),
        ),
      );
      if (ok == true) reload();
    });
  }

  Future<void> _open(int index) async {
    await Navigator.of(context).push(
      PageRouteBuilder(
        opaque: true,
        pageBuilder: (_, _, _) =>
            StoryViewerScreen(groups: _groups, initialGroup: index),
        transitionsBuilder: (_, a, _, child) =>
            FadeTransition(opacity: a, child: child),
      ),
    );
    reload();
  }

  @override
  Widget build(BuildContext context) {
    final loggedIn = SessionScope.of(context).loggedIn;
    final hasMine = _groups.isNotEmpty && _groups.first.isMine;
    if (_groups.isEmpty && !loggedIn) return const SizedBox.shrink();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Text(
            'Истории',
            style: display(17, weight: FontWeight.w700, color: context.pal.ink),
          ),
        ),
        SizedBox(
          height: 104,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(8, 8, 8, 4),
            children: [
              if (!hasMine)
                _Bubble(
                  label: 'Ваша история',
                  onTap: _create,
                  child: CircleAvatar(
                    radius: 30,
                    backgroundColor: context.pal.brandSoft,
                    child: Icon(Icons.add_rounded, color: kBrand, size: 30),
                  ),
                ),
              for (var i = 0; i < _groups.length; i++)
                _Bubble(
                  label: _groups[i].isMine ? 'Вы' : _groups[i].userName,
                  ring: true,
                  onTap: () => _open(i),
                  onLongPress: _groups[i].isMine ? _create : null,
                  child: CircleAvatar(
                    radius: 30,
                    backgroundColor: const Color(0xFFDBEAFE),
                    foregroundImage: _groups[i].avatar != null
                        ? NetworkImage(_groups[i].avatar!)
                        : null,
                    child: Text(
                      initialOf(_groups[i].userName),
                      style: const TextStyle(
                        color: kBrand,
                        fontWeight: FontWeight.w700,
                        fontSize: 22,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({
    required this.label,
    required this.child,
    required this.onTap,
    this.onLongPress,
    this.ring = false,
  });

  final String label;
  final Widget child;
  final VoidCallback onTap;
  final VoidCallback? onLongPress;
  final bool ring;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      onLongPress: onLongPress,
      child: SizedBox(
        width: 76,
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.all(2.5),
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                gradient: ring
                    ? const LinearGradient(
                        colors: [Color(0xFF7C3AED), kBrand, Color(0xFF06B6D4)],
                      )
                    : null,
              ),
              child: Stack(
                clipBehavior: Clip.none,
                alignment: Alignment.bottomCenter,
                children: [
                  Container(
                    padding: const EdgeInsets.all(2),
                    decoration: BoxDecoration(
                      color: context.pal.surface,
                      shape: BoxShape.circle,
                    ),
                    child: child,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 4),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}

// =============================================================== просмотр

class StoryViewerScreen extends StatefulWidget {
  const StoryViewerScreen({
    super.key,
    required this.groups,
    required this.initialGroup,
  });

  final List<StoryGroup> groups;
  final int initialGroup;

  @override
  State<StoryViewerScreen> createState() => _StoryViewerScreenState();
}

class _StoryViewerScreenState extends State<StoryViewerScreen>
    with SingleTickerProviderStateMixin {
  static const _photoDuration = Duration(seconds: 6);

  late final PageController _pages = PageController(
    initialPage: widget.initialGroup,
  );
  late final AnimationController _progress =
      AnimationController(vsync: this, duration: _photoDuration)
        ..addStatusListener((s) {
          if (s == AnimationStatus.completed) _next();
        });
  late int _group = widget.initialGroup;
  int _story = 0;
  VideoPlayerController? _video;
  bool _paused = false;
  final _reply = TextEditingController();
  final _replyFocus = FocusNode();

  StoryGroup get _g => widget.groups[_group];
  StoryItem get _s => _g.stories[_story];

  @override
  void initState() {
    super.initState();
    _replyFocus.addListener(() => _replyFocus.hasFocus ? _pause() : _resume());
    _start();
  }

  @override
  void dispose() {
    _progress.dispose();
    _video?.dispose();
    _pages.dispose();
    _reply.dispose();
    _replyFocus.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    _progress.stop();
    _progress.value = 0;
    final old = _video;
    _video = null;
    await old?.dispose();
    final s = _s;
    if (s.isVideo && s.mediaUrl != null) {
      final c = VideoPlayerController.networkUrl(Uri.parse(s.mediaUrl!));
      _video = c;
      try {
        await c.initialize();
      } catch (_) {
        // битое видео — просто показываем подпись и идём дальше по таймеру
      }
      if (!mounted || _video != c) return;
      final d = c.value.duration;
      _progress.duration = d > Duration.zero ? d : _photoDuration;
      c.play();
      setState(() {});
    } else {
      _progress.duration = _photoDuration;
    }
    if (!_paused) _progress.forward();
  }

  void _pause() {
    _paused = true;
    _progress.stop();
    _video?.pause();
  }

  void _resume() {
    if (_replyFocus.hasFocus) return;
    _paused = false;
    _progress.forward();
    _video?.play();
  }

  void _next() {
    if (_story < _g.stories.length - 1) {
      setState(() => _story++);
      _start();
    } else if (_group < widget.groups.length - 1) {
      _pages.nextPage(
        duration: const Duration(milliseconds: 280),
        curve: Curves.easeOut,
      );
    } else {
      Navigator.of(context).maybePop();
    }
  }

  void _prev() {
    if (_story > 0) {
      setState(() => _story--);
      _start();
    } else if (_group > 0) {
      _pages.previousPage(
        duration: const Duration(milliseconds: 280),
        curve: Curves.easeOut,
      );
    } else {
      _start();
    }
  }

  Future<void> _sendReply() async {
    final text = _reply.text.trim();
    if (text.isEmpty) return;
    final api = SessionScope.read(context).api;
    final messenger = ScaffoldMessenger.of(context);
    final nav = Navigator.of(context);
    try {
      final chatId = await api.replyStory(_s.id, text);
      _reply.clear();
      _replyFocus.unfocus();
      messenger.showSnackBar(
        SnackBar(
          behavior: SnackBarBehavior.floating,
          content: const Text('Ответ отправлен в сообщения'),
          action: SnackBarAction(
            label: 'Открыть',
            onPressed: () => nav.push(
              MaterialPageRoute(builder: (_) => ChatScreen(chatId: chatId)),
            ),
          ),
        ),
      );
    } on ApiException catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text(e.message), behavior: SnackBarBehavior.floating),
      );
    }
  }

  Future<void> _delete() async {
    _pause();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Удалить историю?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Отмена'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Удалить'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) {
      _resume();
      return;
    }
    try {
      await SessionScope.read(context).api.deleteStory(_s.id);
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
      _resume();
    }
  }

  Color _bg(String hex) {
    final v = int.tryParse(hex.replaceFirst('#', ''), radix: 16);
    return v == null ? const Color(0xFF7C3AED) : Color(0xFF000000 | v);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      resizeToAvoidBottomInset: true,
      body: PageView.builder(
        controller: _pages,
        itemCount: widget.groups.length,
        onPageChanged: (i) {
          setState(() {
            _group = i;
            _story = 0;
          });
          _start();
        },
        itemBuilder: (_, gi) {
          if (gi != _group) return const ColoredBox(color: Colors.black);
          return _storyPage();
        },
      ),
    );
  }

  Widget _storyPage() {
    final s = _s;
    final g = _g;
    final width = MediaQuery.sizeOf(context).width;
    Widget media;
    if (s.isVideo && _video != null && _video!.value.isInitialized) {
      media = Center(
        child: AspectRatio(
          aspectRatio: _video!.value.aspectRatio,
          child: VideoPlayer(_video!),
        ),
      );
    } else if (!s.isVideo && s.mediaUrl != null) {
      media = CachedNetworkImage(
        imageUrl: s.mediaUrl!,
        fit: BoxFit.contain,
        width: double.infinity,
        height: double.infinity,
        placeholder: (_, _) =>
            const Center(child: CircularProgressIndicator(color: Colors.white)),
        errorWidget: (_, _, _) => ColoredBox(color: _bg(s.bgColor)),
      );
    } else if (s.isVideo) {
      media = const Center(
        child: CircularProgressIndicator(color: Colors.white),
      );
    } else {
      media = ColoredBox(color: _bg(s.bgColor));
    }

    return GestureDetector(
      onTapUp: (d) => d.localPosition.dx < width / 3 ? _prev() : _next(),
      onLongPressStart: (_) => _pause(),
      onLongPressEnd: (_) => _resume(),
      onVerticalDragEnd: (d) {
        if ((d.primaryVelocity ?? 0) > 300) Navigator.of(context).maybePop();
      },
      child: Stack(
        fit: StackFit.expand,
        children: [
          media,
          // затемнение сверху и снизу для читаемости
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [
                  Colors.black54,
                  Colors.transparent,
                  Colors.transparent,
                  Colors.black54,
                ],
                stops: [0, 0.18, 0.7, 1],
              ),
            ),
          ),
          SafeArea(
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 8, 8, 0),
                  child: Row(
                    children: [
                      for (var i = 0; i < g.stories.length; i++)
                        Expanded(
                          child: Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 2),
                            child: AnimatedBuilder(
                              animation: _progress,
                              builder: (_, _) => LinearProgressIndicator(
                                value: i < _story
                                    ? 1
                                    : (i == _story ? _progress.value : 0),
                                minHeight: 3,
                                backgroundColor: Colors.white30,
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(2),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                ListTile(
                  contentPadding: const EdgeInsets.fromLTRB(12, 0, 0, 0),
                  leading: GestureDetector(
                    onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => SellerScreen(id: g.userId),
                      ),
                    ),
                    child: CircleAvatar(
                      backgroundColor: const Color(0xFFDBEAFE),
                      foregroundImage: g.avatar != null
                          ? NetworkImage(g.avatar!)
                          : null,
                      child: Text(
                        initialOf(g.userName),
                        style: const TextStyle(color: kBrand),
                      ),
                    ),
                  ),
                  title: Text(
                    g.isMine ? 'Ваша история' : g.userName,
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                  subtitle: Text(
                    chatTime(s.createdAt),
                    style: const TextStyle(color: Colors.white70),
                  ),
                  trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (g.isMine)
                        IconButton(
                          onPressed: _delete,
                          icon: const Icon(
                            Icons.delete_outline_rounded,
                            color: Colors.white,
                          ),
                        ),
                      IconButton(
                        onPressed: () => Navigator.of(context).maybePop(),
                        icon: const Icon(
                          Icons.close_rounded,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                ),
                const Spacer(),
                if (s.caption != null)
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                    child: Text(
                      s.caption!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 17,
                        height: 1.3,
                        shadows: [Shadow(blurRadius: 8, color: Colors.black54)],
                      ),
                    ),
                  ),
                if (s.product != null) _productSticker(s.product!),
                if (!g.isMine && s.commentsEnabled) _replyBar(),
                const SizedBox(height: 8),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _productSticker(StoryProduct p) {
    return GestureDetector(
      onTap: () async {
        _pause();
        await openProduct(context, p.id);
        _resume();
      },
      child: Container(
        margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(
          children: [
            ProductThumb(p.image, size: 48),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    p.title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w600),
                  ),
                  Text(
                    p.priceText,
                    style: const TextStyle(
                      fontWeight: FontWeight.w800,
                      color: kBrand,
                    ),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right_rounded),
          ],
        ),
      ),
    );
  }

  Widget _replyBar() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 0, 12, 0),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _reply,
              focusNode: _replyFocus,
              style: const TextStyle(color: Colors.white),
              textCapitalization: TextCapitalization.sentences,
              onSubmitted: (_) => _sendReply(),
              decoration: InputDecoration(
                hintText: 'Ответить ${_g.userName}…',
                hintStyle: const TextStyle(color: Colors.white70),
                filled: true,
                fillColor: Colors.white12,
                contentPadding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 10,
                ),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(24),
                  borderSide: const BorderSide(color: Colors.white38),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(24),
                  borderSide: const BorderSide(color: Colors.white38),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(24),
                  borderSide: const BorderSide(color: Colors.white),
                ),
              ),
            ),
          ),
          IconButton(
            onPressed: _sendReply,
            icon: const Icon(Icons.send_rounded, color: Colors.white),
          ),
        ],
      ),
    );
  }
}

// =============================================================== публикация

class StoryCreateScreen extends StatefulWidget {
  const StoryCreateScreen({super.key});

  @override
  State<StoryCreateScreen> createState() => _StoryCreateScreenState();
}

class _StoryCreateScreenState extends State<StoryCreateScreen> {
  XFile? _media;
  bool _isVideo = false;
  final _caption = TextEditingController();
  bool _followersOnly = false;
  bool _comments = true;
  bool _notify = true;
  String _productMode = 'auto';
  MyListing? _product;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _pick());
  }

  @override
  void dispose() {
    _caption.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final choice = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ListTile(
              leading: const Icon(Icons.photo_camera_outlined),
              title: const Text('Сделать фото'),
              onTap: () => Navigator.pop(ctx, 'camera'),
            ),
            ListTile(
              leading: const Icon(Icons.videocam_outlined),
              title: const Text('Снять видео'),
              onTap: () => Navigator.pop(ctx, 'video_camera'),
            ),
            ListTile(
              leading: const Icon(Icons.photo_library_outlined),
              title: const Text('Фото из галереи'),
              onTap: () => Navigator.pop(ctx, 'gallery'),
            ),
            ListTile(
              leading: const Icon(Icons.video_library_outlined),
              title: const Text('Видео из галереи'),
              onTap: () => Navigator.pop(ctx, 'video_gallery'),
            ),
          ],
        ),
      ),
    );
    if (choice == null) {
      if (_media == null && mounted) Navigator.of(context).pop();
      return;
    }
    final picker = ImagePicker();
    XFile? f;
    var video = false;
    switch (choice) {
      case 'camera':
        f = await picker.pickImage(
          source: ImageSource.camera,
          maxWidth: 1600,
          imageQuality: 85,
        );
      case 'gallery':
        f = await picker.pickImage(
          source: ImageSource.gallery,
          maxWidth: 1600,
          imageQuality: 85,
        );
      case 'video_camera':
        f = await picker.pickVideo(
          source: ImageSource.camera,
          maxDuration: const Duration(seconds: 60),
        );
        video = true;
      case 'video_gallery':
        f = await picker.pickVideo(source: ImageSource.gallery);
        video = true;
    }
    if (!mounted) return;
    if (f == null) {
      if (_media == null) Navigator.of(context).pop();
      return;
    }
    setState(() {
      _media = f;
      _isVideo = video;
    });
  }

  Future<void> _pickProduct() async {
    final api = SessionScope.read(context).api;
    final items = (await api.myListings())
        .where((l) => l.status == 'active')
        .toList();
    if (!mounted) return;
    if (items.isEmpty) {
      showSnack(context, 'Нет активных объявлений');
      return;
    }
    final picked = await showModalBottomSheet<MyListing>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.6,
        builder: (ctx, scroll) => ListView(
          controller: scroll,
          children: [
            for (final l in items)
              ListTile(
                leading: ProductThumb(l.card.image, size: 44),
                title: Text(
                  l.card.title,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                subtitle: Text(l.card.priceText),
                onTap: () => Navigator.pop(ctx, l),
              ),
          ],
        ),
      ),
    );
    if (picked != null) {
      setState(() {
        _product = picked;
        _productMode = 'pick';
      });
    }
  }

  Future<void> _publish() async {
    final m = _media;
    if (m == null) return;
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).api.createStory(
        mediaPath: m.path,
        caption: _caption.text.trim(),
        followersOnly: _followersOnly,
        commentsEnabled: _comments,
        productMode: _productMode,
        productId: _productMode == 'pick' ? _product?.card.id : null,
        notifySubs: _notify,
      );
      if (!mounted) return;
      showSnack(context, 'История опубликована на 24 часа');
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final m = _media;
    return Scaffold(
      appBar: AppBar(title: const Text('Новая история')),
      body: m == null
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.only(top: 12, bottom: 24),
              children: [
                GestureDetector(
                  onTap: _pick,
                  child: Container(
                    height: 320,
                    margin: const EdgeInsets.symmetric(horizontal: 12),
                    decoration: BoxDecoration(
                      color: Colors.black,
                      borderRadius: BorderRadius.circular(16),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: _isVideo
                        ? const Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  Icons.play_circle_outline_rounded,
                                  color: Colors.white,
                                  size: 64,
                                ),
                                SizedBox(height: 8),
                                Text(
                                  'Видео выбрано · нажмите, чтобы заменить',
                                  style: TextStyle(color: Colors.white70),
                                ),
                              ],
                            ),
                          )
                        : Image.file(
                            File(m.path),
                            fit: BoxFit.cover,
                            width: double.infinity,
                          ),
                  ),
                ),
                const SizedBox(height: 12),
                SectionCard(
                  child: TextField(
                    controller: _caption,
                    maxLength: 280,
                    minLines: 1,
                    maxLines: 4,
                    textCapitalization: TextCapitalization.sentences,
                    decoration: const InputDecoration(
                      hintText: 'Подпись (необязательно)',
                    ),
                  ),
                ),
                SectionCard(
                  title: 'Товар в истории',
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      SegmentedButton<String>(
                        segments: const [
                          ButtonSegment(
                            value: 'auto',
                            label: Text('Последний'),
                          ),
                          ButtonSegment(value: 'pick', label: Text('Выбрать')),
                          ButtonSegment(
                            value: 'none',
                            label: Text('Без товара'),
                          ),
                        ],
                        selected: {_productMode},
                        showSelectedIcon: false,
                        onSelectionChanged: (s) {
                          if (s.first == 'pick') {
                            _pickProduct();
                          } else {
                            setState(() => _productMode = s.first);
                          }
                        },
                      ),
                      if (_productMode == 'pick' && _product != null)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: ProductThumb(_product!.card.image, size: 40),
                          title: Text(
                            _product!.card.title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          trailing: TextButton(
                            onPressed: _pickProduct,
                            child: const Text('Изменить'),
                          ),
                        ),
                    ],
                  ),
                ),
                SectionCard(
                  padding: EdgeInsets.zero,
                  child: Column(
                    children: [
                      SwitchListTile(
                        value: _followersOnly,
                        onChanged: (v) => setState(() => _followersOnly = v),
                        title: const Text('Только для подписчиков'),
                      ),
                      SwitchListTile(
                        value: _comments,
                        onChanged: (v) => setState(() => _comments = v),
                        title: const Text('Разрешить ответы'),
                      ),
                      SwitchListTile(
                        value: _notify,
                        onChanged: (v) => setState(() => _notify = v),
                        title: const Text('Уведомить подписчиков'),
                      ),
                    ],
                  ),
                ),
              ],
            ),
      bottomNavigationBar: m == null
          ? null
          : BottomActionBar(
              child: FilledButton(
                onPressed: _busy ? null : _publish,
                child: busyLabel(_busy, 'Опубликовать на 24 часа'),
              ),
            ),
    );
  }
}
