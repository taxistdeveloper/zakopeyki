import 'dart:async';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';
import 'package:image_picker/image_picker.dart';

import '../api.dart';
import '../live_models.dart';
import '../main.dart';
import '../nav.dart';
import '../widgets/common.dart';
import 'live_common.dart';

/// Причина паузы голосового пробуждения, пока микрофон нужен эфиру.
const _liveMicHold = 'live';

// =============================================================== настройка эфира

class LiveSetupScreen extends StatefulWidget {
  const LiveSetupScreen({super.key});

  @override
  State<LiveSetupScreen> createState() => _LiveSetupScreenState();
}

class _LiveSetupScreenState extends State<LiveSetupScreen> {
  LiveSetupOptions? _o;
  String? _error;
  final _selected = <int>{};
  int? _featured;
  final _specialPrice = TextEditingController();
  int _duration = 7200;
  bool _followersOnly = false;
  bool _chat = true;
  bool _notify = true;
  bool _giveaway = false;
  final _giveawayTitle = TextEditingController();
  int _giveawayGoal = 500;
  XFile? _cover;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _specialPrice.dispose();
    _giveawayTitle.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final o = await SessionScope.read(context).api.liveSetupOptions();
      if (!mounted) return;
      setState(() {
        _o = o;
        _selected.addAll(o.products.take(5).map((p) => p.id));
        _featured = o.products.isNotEmpty ? o.products.first.id : null;
        if (o.durations.isNotEmpty && !o.durations.contains(_duration)) _duration = o.durations.first;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  static String _durationLabel(int s) => s >= 3600 ? '${s ~/ 3600} ч' : '${s ~/ 60} мин';

  Future<void> _start() async {
    if (_selected.isEmpty) {
      showSnack(context, 'Выберите хотя бы один товар для эфира');
      return;
    }
    // Доступ к камере и микрофону: проверяем до запуска эфира (flutter_webrtc сам покажет системный запрос)
    final wake = SessionScope.read(context).wakeWord;
    await wake.hold(_liveMicHold); // микрофон нужен эфиру, слушатель «Зак» мешал бы
    try {
      final probe = await navigator.mediaDevices.getUserMedia({'audio': true, 'video': true});
      for (final t in probe.getTracks()) {
        await t.stop();
      }
      await probe.dispose();
    } catch (_) {
      if (mounted) showSnack(context, 'Для эфира нужен доступ к камере и микрофону — разрешите его в настройках телефона');
      return;
    } finally {
      wake.release(_liveMicHold);
    }
    if (!mounted) return;
    setState(() => _busy = true);
    final o = _o!;
    final featured = _featured != null && _selected.contains(_featured) ? _featured : _selected.first;
    final setup = <String, dynamic>{
      'product_ids': _selected.toList(),
      'featured_product_id': featured,
      if (_specialPrice.text.trim().isNotEmpty) 'featured_price': int.tryParse(_specialPrice.text.trim()),
      'duration': _duration,
      'visibility': _followersOnly ? 'followers' : 'all',
      'chat_enabled': _chat,
      'notify_subs': _notify,
      if (_giveaway && _giveawayTitle.text.trim().isNotEmpty)
        'giveaway': {'title': _giveawayTitle.text.trim(), 'goal': _giveawayGoal},
    };
    try {
      final id = await SessionScope.read(context).api.startLive(setup, coverPath: _cover?.path);
      if (!mounted) return;
      // ждём конца эфира, затем закрываем настройку — так список эфиров обновится уже без него
      await Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => LiveHostScreen(streamId: id, products: o.products.where((p) => _selected.contains(p.id)).toList()),
      ));
      if (mounted) Navigator.of(context).pop();
      return;
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final o = _o;
    return Scaffold(
      appBar: AppBar(title: const Text('Новый эфир')),
      body: o == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : o.products.isEmpty
              ? const EmptyView(
                  icon: Icons.videocam_outlined,
                  title: 'Нет товаров для эфира',
                  text: 'В эфире показывают товары, которые можно купить. Подайте объявление и возвращайтесь.',
                )
              : ListView(
                  padding: const EdgeInsets.only(top: 12, bottom: 24),
                  children: [
                    SectionCard(
                      title: 'Товары в эфире · ${_selected.length}',
                      child: Column(children: [
                        for (final p in o.products)
                          CheckboxListTile(
                            value: _selected.contains(p.id),
                            contentPadding: EdgeInsets.zero,
                            controlAffinity: ListTileControlAffinity.leading,
                            onChanged: (v) => setState(() => v == true ? _selected.add(p.id) : _selected.remove(p.id)),
                            title: Text(p.title, maxLines: 1, overflow: TextOverflow.ellipsis),
                            subtitle: Text(p.priceText),
                            secondary: IconButton(
                              tooltip: 'Товар дня',
                              onPressed: _selected.contains(p.id) ? () => setState(() => _featured = p.id) : null,
                              icon: Icon(_featured == p.id ? Icons.star_rounded : Icons.star_outline_rounded,
                                  color: _featured == p.id ? const Color(0xFFF59E0B) : Colors.black38),
                            ),
                          ),
                        const Text('★ — товар дня: показывается зрителям поверх видео',
                            style: TextStyle(color: Colors.black54, fontSize: 12.5)),
                        const SizedBox(height: 8),
                        TextField(
                          controller: _specialPrice,
                          keyboardType: TextInputType.number,
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          decoration: const InputDecoration(
                            labelText: 'Цена товара дня в эфире (необязательно)',
                            suffixText: '₸',
                          ),
                        ),
                      ]),
                    ),
                    SectionCard(
                      title: 'Длительность',
                      child: Wrap(spacing: 8, children: [
                        for (final d in o.durations)
                          ChoiceChip(label: Text(_durationLabel(d)), selected: _duration == d, onSelected: (_) => setState(() => _duration = d)),
                      ]),
                    ),
                    SectionCard(
                      padding: EdgeInsets.zero,
                      child: Column(children: [
                        SwitchListTile(value: _followersOnly, onChanged: (v) => setState(() => _followersOnly = v), title: const Text('Только для подписчиков')),
                        SwitchListTile(value: _chat, onChanged: (v) => setState(() => _chat = v), title: const Text('Чат с зрителями')),
                        SwitchListTile(value: _notify, onChanged: (v) => setState(() => _notify = v), title: const Text('Уведомить подписчиков')),
                        if (o.giveawayEnabled)
                          SwitchListTile(value: _giveaway, onChanged: (v) => setState(() => _giveaway = v), title: const Text('Розыгрыш в эфире')),
                      ]),
                    ),
                    if (_giveaway)
                      SectionCard(
                        title: 'Розыгрыш',
                        child: Column(children: [
                          TextField(controller: _giveawayTitle, maxLength: 120, decoration: const InputDecoration(labelText: 'Что разыгрываете')),
                          Row(children: [
                            const Text('Цель участников'),
                            Expanded(
                              child: Slider(
                                value: _giveawayGoal.toDouble(),
                                min: 50,
                                max: 5000,
                                divisions: 99,
                                label: '$_giveawayGoal',
                                onChanged: (v) => setState(() => _giveawayGoal = v.round()),
                              ),
                            ),
                            Text('$_giveawayGoal'),
                          ]),
                        ]),
                      ),
                    SectionCard(
                      title: 'Обложка (необязательно)',
                      child: Row(children: [
                        if (_cover != null)
                          ClipRRect(
                            borderRadius: BorderRadius.circular(10),
                            child: Image.file(File(_cover!.path), width: 64, height: 64, fit: BoxFit.cover),
                          ),
                        if (_cover != null) const SizedBox(width: 12),
                        OutlinedButton.icon(
                          onPressed: () async {
                            final f = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1280, imageQuality: 85);
                            if (f != null) setState(() => _cover = f);
                          },
                          icon: const Icon(Icons.image_outlined),
                          label: Text(_cover == null ? 'Выбрать' : 'Заменить'),
                        ),
                      ]),
                    ),
                    const Padding(
                      padding: EdgeInsets.fromLTRB(20, 4, 20, 0),
                      child: Text('Эфир не записывается. Видео идёт напрямую с вашего телефона зрителям — '
                          'держите приложение открытым и подключение стабильным.',
                          style: TextStyle(color: Colors.black54, fontSize: 12.5)),
                    ),
                  ],
                ),
      bottomNavigationBar: o == null || o.products.isEmpty
          ? null
          : BottomActionBar(
              child: FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
                onPressed: _busy ? null : _start,
                icon: const Icon(Icons.videocam_rounded),
                label: busyLabel(_busy, 'Начать эфир'),
              ),
            ),
    );
  }
}

// =============================================================== эфир ведущего

class LiveHostScreen extends StatefulWidget {
  const LiveHostScreen({super.key, required this.streamId, required this.products});

  final int streamId;
  final List<LiveProduct> products;

  @override
  State<LiveHostScreen> createState() => _LiveHostScreenState();
}

class _LiveHostScreenState extends State<LiveHostScreen> with WidgetsBindingObserver {
  final _local = RTCVideoRenderer();
  MediaStream? _stream;
  final _peers = <String, RTCPeerConnection>{};
  final _pending = <String>[];
  late Api _api;

  LiveState? _s;
  final _comments = <LiveComment>[];
  int _signalAfter = 0;
  int _commentAfter = 0;
  bool _micOn = true;
  bool _frontCamera = true;
  bool _ended = false;
  String? _cameraError;
  final _started = DateTime.now();

  Timer? _signalTimer;
  Timer? _heartbeatTimer;
  Timer? _stateTimer;
  Timer? _commentTimer;
  Timer? _clock;
  bool _pollingSignals = false;
  late final _wake = SessionScope.read(context).wakeWord;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // на время эфира микрофон занят трансляцией — голосовое пробуждение на паузе
    _wake.hold(_liveMicHold).then((_) => _local.initialize()).then((_) => _startMedia());
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _api = SessionScope.read(context).api;
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    for (final t in [_signalTimer, _heartbeatTimer, _stateTimer, _commentTimer, _clock]) {
      t?.cancel();
    }
    if (!_ended) _api.endLive(widget.streamId).catchError((_) {});
    for (final pc in _peers.values) {
      pc.close();
    }
    _stream?.getTracks().forEach((t) => t.stop());
    _stream?.dispose();
    _local.dispose();
    _wake.release(_liveMicHold);
    super.dispose();
  }

  Future<void> _startMedia() async {
    try {
      final stream = await navigator.mediaDevices.getUserMedia({
        'audio': true,
        'video': {
          'facingMode': 'user',
          'width': {'ideal': 720},
          'height': {'ideal': 1280},
          'frameRate': {'ideal': 24},
        },
      });
      _stream = stream;
      _local.srcObject = stream;
      if (mounted) setState(() {});
    } catch (e) {
      if (mounted) setState(() => _cameraError = 'Нет доступа к камере или микрофону');
    }
    _signalTimer = Timer.periodic(const Duration(seconds: 1), (_) => _pollSignals());
    _heartbeatTimer = Timer.periodic(const Duration(seconds: 15), (_) => _heartbeat());
    _stateTimer = Timer.periodic(const Duration(seconds: 5), (_) => _refreshState());
    _commentTimer = Timer.periodic(const Duration(seconds: 2), (_) => _pollComments());
    _clock = Timer.periodic(const Duration(seconds: 1), (_) => mounted ? setState(() {}) : null);
    _heartbeat();
    _refreshState();
    // зрители, пришедшие до камеры
    for (final p in List<String>.from(_pending)) {
      _pending.remove(p);
      _offerTo(p);
    }
  }

  // --- WebRTC: по соединению на каждого зрителя ------------------------------------

  Future<void> _pollSignals() async {
    if (_pollingSignals || _ended) return;
    _pollingSignals = true;
    try {
      final signals = await _api.liveSignals(widget.streamId, role: 'host', after: _signalAfter);
      for (final sig in signals) {
        if (sig.id > _signalAfter) _signalAfter = sig.id;
        switch (sig.type) {
          case 'join':
            await _offerTo(sig.peerId);
          case 'answer':
            final sdp = sig.payload?['sdp'];
            final pc = _peers[sig.peerId];
            if (pc != null && sdp is String) {
              await pc.setRemoteDescription(RTCSessionDescription(sdp, 'answer')).catchError((_) {});
            }
          case 'ice':
            final c = candidateFrom(sig.payload);
            final pc = _peers[sig.peerId];
            if (pc != null && c != null) await pc.addCandidate(c).catchError((_) {});
          case 'leave':
            await _peers.remove(sig.peerId)?.close();
        }
      }
    } on ApiException catch (e) {
      if (e.code == 'stream_ended' || e.code == 'forbidden') _markEnded();
    } catch (_) {
    } finally {
      _pollingSignals = false;
    }
  }

  Future<void> _offerTo(String peerId) async {
    final stream = _stream;
    if (stream == null) {
      if (!_pending.contains(peerId)) _pending.add(peerId);
      return;
    }
    await _peers.remove(peerId)?.close();
    final pc = await createPeerConnection(rtcConfig(_s?.iceServers ?? const []));
    _peers[peerId] = pc;
    for (final t in stream.getTracks()) {
      await pc.addTrack(t, stream);
    }
    pc.onIceCandidate = (c) {
      if (c.candidate == null || _ended) return;
      _api
          .liveSignal(widget.streamId, role: 'host', peerId: peerId, type: 'ice', payload: {'candidate': c.toMap()})
          .catchError((_) {});
    };
    pc.onConnectionState = (state) {
      if (state == RTCPeerConnectionState.RTCPeerConnectionStateFailed ||
          state == RTCPeerConnectionState.RTCPeerConnectionStateClosed) {
        _peers.remove(peerId)?.close();
      }
    };
    try {
      final offer = await pc.createOffer();
      await pc.setLocalDescription(offer);
      await _api.liveSignal(widget.streamId, role: 'host', peerId: peerId, type: 'offer', payload: {'sdp': offer.sdp});
    } catch (_) {
      await _peers.remove(peerId)?.close();
    }
  }

  // --- служебное -------------------------------------------------------------------

  Future<void> _heartbeat() async {
    try {
      final live = await _api.liveHeartbeat(widget.streamId);
      if (!live) _markEnded();
    } catch (_) {}
  }

  Future<void> _refreshState() async {
    try {
      final s = await _api.stream(widget.streamId);
      if (mounted) setState(() => _s = s);
    } on ApiException catch (e) {
      if (e.code == 'stream_ended') _markEnded();
    } catch (_) {}
  }

  Future<void> _pollComments() async {
    try {
      final list = await _api.liveComments(widget.streamId, after: _commentAfter);
      if (list.isEmpty || !mounted) return;
      setState(() {
        _comments.addAll(list);
        _commentAfter = list.last.id;
      });
    } catch (_) {}
  }

  void _markEnded() {
    if (_ended || !mounted) return;
    setState(() => _ended = true);
    for (final t in [_signalTimer, _heartbeatTimer, _stateTimer, _commentTimer, _clock]) {
      t?.cancel();
    }
  }

  Future<bool> _sendComment(String text) async {
    try {
      final c = await _api.liveComment(widget.streamId, text);
      if (!mounted) return true;
      setState(() {
        if (!_comments.any((x) => x.id == c.id)) _comments.add(c);
        if (c.id > _commentAfter) _commentAfter = c.id;
      });
      return true;
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
      return false;
    }
  }

  void _toggleMic() {
    setState(() => _micOn = !_micOn);
    for (final t in _stream?.getAudioTracks() ?? <MediaStreamTrack>[]) {
      t.enabled = _micOn;
    }
  }

  Future<void> _switchCamera() async {
    final v = _stream?.getVideoTracks();
    if (v == null || v.isEmpty) return;
    await Helper.switchCamera(v.first);
    setState(() => _frontCamera = !_frontCamera);
  }

  Future<void> _pickFeatured() async {
    final p = await showLiveShelf(context, widget.products, title: 'Товар дня', selectedId: _s?.featured?.id);
    if (p == null || !mounted) return;
    try {
      await _api.liveFeature(widget.streamId, p.id);
      _refreshState();
      if (mounted) showSnack(context, 'Товар дня: ${p.title}');
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<void> _draw() async {
    try {
      final w = await _api.liveDrawGiveaway(widget.streamId);
      if (mounted) showSnack(context, w != null ? 'Победитель: $w' : 'Розыгрыш проведён');
      _refreshState();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<void> _end() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Завершить эфир?'),
        content: const Text('Зрители увидят, что эфир закончился. Запись не сохраняется.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Продолжить')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Завершить'),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    await _api.endLive(widget.streamId).catchError((_) {});
    _ended = true;
    if (mounted) Navigator.of(context).pop();
  }

  String get _elapsed {
    final d = DateTime.now().difference(_started);
    String two(int v) => v.toString().padLeft(2, '0');
    return d.inHours > 0
        ? '${d.inHours}:${two(d.inMinutes % 60)}:${two(d.inSeconds % 60)}'
        : '${two(d.inMinutes)}:${two(d.inSeconds % 60)}';
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return PopScope(
      canPop: _ended,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _end();
      },
      child: Scaffold(
        backgroundColor: Colors.black,
        body: Stack(
          fit: StackFit.expand,
          children: [
            if (_stream != null)
              RTCVideoView(_local, mirror: _frontCamera, objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover)
            else
              LiveMessage(icon: Icons.videocam_off_rounded, text: _cameraError ?? 'Включаем камеру…'),
            if (_ended)
              LiveMessage(
                icon: Icons.videocam_off_rounded,
                text: 'Эфир завершён',
                action: FilledButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Закрыть')),
              )
            else
              SafeArea(
                child: Column(children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
                    child: Row(children: [
                      const LiveBadge(),
                      const SizedBox(width: 8),
                      LivePill(icon: Icons.timer_outlined, text: _elapsed),
                      const SizedBox(width: 6),
                      LivePill(icon: Icons.visibility_rounded, text: '${s?.viewers ?? 0}'),
                      const SizedBox(width: 6),
                      LivePill(icon: Icons.favorite_rounded, text: '${s?.likes ?? 0}'),
                      const Spacer(),
                      TextButton(
                        onPressed: _end,
                        style: TextButton.styleFrom(backgroundColor: const Color(0xFFEF4444), foregroundColor: Colors.white),
                        child: const Text('Завершить'),
                      ),
                    ]),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 6, 12, 0),
                    child: Row(children: [
                      LivePill(icon: Icons.link_rounded, text: 'Подключено: ${_peers.length}'),
                    ]),
                  ),
                  if (s?.giveaway != null)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                      child: Row(children: [
                        Expanded(
                          child: LivePill(
                            icon: Icons.card_giftcard_rounded,
                            text: s!.giveaway!.winner != null
                                ? 'Победитель: ${s.giveaway!.winner}'
                                : '${s.giveaway!.title}: ${s.giveaway!.participants}/${s.giveaway!.goal}',
                          ),
                        ),
                        if (s.giveaway!.winner == null)
                          TextButton(
                            onPressed: _draw,
                            style: TextButton.styleFrom(foregroundColor: Colors.white),
                            child: const Text('Разыграть'),
                          ),
                      ]),
                    ),
                  const Spacer(),
                  SizedBox(height: 200, child: LiveCommentsView(comments: _comments)),
                  if (s?.featured != null)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                      child: LiveProductCard(product: s!.featured!, actionLabel: 'Сменить', onTap: _pickFeatured),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(8, 8, 8, 8),
                    child: Row(children: [
                      Expanded(child: LiveCommentInput(onSend: _sendComment, enabled: s?.chatEnabled ?? true)),
                      IconButton(
                        onPressed: _toggleMic,
                        icon: Icon(_micOn ? Icons.mic_rounded : Icons.mic_off_rounded, color: _micOn ? Colors.white : const Color(0xFFEF4444)),
                      ),
                      IconButton(onPressed: _switchCamera, icon: const Icon(Icons.cameraswitch_rounded, color: Colors.white)),
                      IconButton(
                        onPressed: _pickFeatured,
                        icon: const Icon(Icons.shopping_bag_outlined, color: Colors.white),
                      ),
                    ]),
                  ),
                ]),
              ),
          ],
        ),
      ),
    );
  }
}

/// Кнопка/переход «Выйти в эфир» (с проверкой входа).
Future<void> openGoLive(BuildContext context) =>
    requireLogin(context, () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LiveSetupScreen())));
