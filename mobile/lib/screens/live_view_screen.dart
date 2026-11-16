import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';

import '../api.dart';
import '../live_models.dart';
import '../main.dart';
import '../nav.dart';
import 'checkout_screen.dart';
import 'live_common.dart';
import 'seller_screen.dart';

/// Просмотр эфира. Видео — WebRTC от ведущего (сайт или приложение), сигналы — через API.
class LiveViewScreen extends StatefulWidget {
  const LiveViewScreen({super.key, required this.streamId});

  final int streamId;

  @override
  State<LiveViewScreen> createState() => _LiveViewScreenState();
}

class _LiveViewScreenState extends State<LiveViewScreen> {
  final _peerId = randomPeerId();
  final _viewerKey = randomPeerId();
  final _renderer = RTCVideoRenderer();
  RTCPeerConnection? _pc;
  MediaStream? _remote;

  LiveState? _s;
  final _comments = <LiveComment>[];
  int _signalAfter = 0;
  int _commentAfter = 0;
  bool _ended = false;
  bool _hasVideo = false;
  bool _connectTimedOut = false;
  bool _muted = false;
  String? _error;

  Timer? _signalTimer;
  Timer? _stateTimer;
  Timer? _commentTimer;
  Timer? _connectTimer;
  bool _pollingSignals = false;

  late Api _api;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _api = SessionScope.read(context).api;
  }

  @override
  void initState() {
    super.initState();
    _renderer.initialize().then((_) => _init());
  }

  @override
  void dispose() {
    _signalTimer?.cancel();
    _stateTimer?.cancel();
    _commentTimer?.cancel();
    _connectTimer?.cancel();
    if (_pc != null && !_ended) {
      _api.liveSignal(widget.streamId, role: 'viewer', peerId: _peerId, type: 'leave').catchError((_) {});
    }
    _pc?.close();
    _remote?.dispose();
    _renderer.dispose();
    super.dispose();
  }

  Future<void> _init() async {
    try {
      final s = await _api.stream(widget.streamId, viewerKey: _viewerKey);
      if (!mounted) return;
      setState(() => _s = s);
      _stateTimer = Timer.periodic(const Duration(seconds: 5), (_) => _refreshState());
      _commentTimer = Timer.periodic(const Duration(seconds: 2), (_) => _pollComments());
      _pollComments();
      if (!s.isDemo && !s.isHost) await _connect(s);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.code == 'stream_ended') {
          _ended = true;
        } else {
          _error = e.message;
        }
      });
    }
  }

  // --- WebRTC ------------------------------------------------------------------

  Future<void> _connect(LiveState s) async {
    _connectTimer?.cancel();
    _signalTimer?.cancel();
    await _pc?.close();
    setState(() {
      _connectTimedOut = false;
      _hasVideo = false;
    });

    final pc = await createPeerConnection(rtcConfig(s.iceServers));
    _pc = pc;
    pc.onTrack = (event) {
      final stream = event.streams.isNotEmpty ? event.streams.first : null;
      if (stream == null) return;
      _remote = stream;
      _renderer.srcObject = stream;
      for (final t in stream.getAudioTracks()) {
        t.enabled = !_muted;
      }
      if (event.track.kind == 'video' && mounted) setState(() => _hasVideo = true);
    };
    pc.onIceCandidate = (c) {
      if (c.candidate == null) return;
      _api
          .liveSignal(widget.streamId, role: 'viewer', peerId: _peerId, type: 'ice', payload: {'candidate': c.toMap()})
          .catchError((_) {});
    };

    _signalAfter = 0;
    await _api.liveSignal(widget.streamId, role: 'viewer', peerId: _peerId, type: 'join');
    _signalTimer = Timer.periodic(const Duration(seconds: 1), (_) => _pollSignals());
    // ведущий мог закрыть вкладку или сидеть за строгим NAT — даём 20 с
    _connectTimer = Timer(const Duration(seconds: 20), () {
      if (mounted && !_hasVideo) setState(() => _connectTimedOut = true);
    });
  }

  Future<void> _pollSignals() async {
    final pc = _pc;
    if (pc == null || _pollingSignals || _ended) return;
    _pollingSignals = true;
    try {
      final signals = await _api.liveSignals(widget.streamId, role: 'viewer', peerId: _peerId, after: _signalAfter);
      for (final sig in signals) {
        if (sig.id > _signalAfter) _signalAfter = sig.id;
        if (sig.type == 'offer' && sig.payload?['sdp'] != null) {
          await pc.setRemoteDescription(RTCSessionDescription(sig.payload!['sdp'] as String, 'offer'));
          final answer = await pc.createAnswer();
          await pc.setLocalDescription(answer);
          await _api.liveSignal(widget.streamId,
              role: 'viewer', peerId: _peerId, type: 'answer', payload: {'sdp': answer.sdp});
        } else if (sig.type == 'ice') {
          final c = candidateFrom(sig.payload);
          if (c != null) await pc.addCandidate(c);
        }
      }
    } on ApiException catch (e) {
      if (e.code == 'stream_ended') _onEnded();
    } catch (_) {
      // сбой одного шага согласования — следующий опрос продолжит
    } finally {
      _pollingSignals = false;
    }
  }

  // --- состояние, чат, лайки --------------------------------------------------

  Future<void> _refreshState() async {
    try {
      final s = await _api.stream(widget.streamId, viewerKey: _viewerKey);
      if (mounted) setState(() => _s = s);
    } on ApiException catch (e) {
      if (e.code == 'stream_ended') _onEnded();
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

  void _onEnded() {
    if (_ended || !mounted) return;
    _signalTimer?.cancel();
    _stateTimer?.cancel();
    _commentTimer?.cancel();
    _connectTimer?.cancel();
    _pc?.close();
    _pc = null;
    setState(() => _ended = true);
  }

  Future<bool> _sendComment(String text) async {
    var ok = false;
    await requireLogin(context, () async {
      try {
        final c = await _api.liveComment(widget.streamId, text);
        if (!mounted) return;
        setState(() {
          if (!_comments.any((x) => x.id == c.id)) _comments.add(c);
          if (c.id > _commentAfter) _commentAfter = c.id;
        });
        ok = true;
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      }
    });
    return ok;
  }

  int _likesBump = 0;

  Future<void> _like() async {
    setState(() => _likesBump++);
    try {
      await _api.liveLike(widget.streamId);
    } on ApiException catch (e) {
      if (e.code == 'too_many_attempts' && mounted) showSnack(context, 'Не так быстро 🙂');
    }
  }

  void _toggleMute() {
    setState(() => _muted = !_muted);
    for (final t in _remote?.getAudioTracks() ?? <MediaStreamTrack>[]) {
      t.enabled = !_muted;
    }
  }

  Future<void> _buy(LiveProduct p) async {
    await requireLogin(context, () async {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => CheckoutScreen(productId: p.id)));
    });
  }

  Future<void> _joinGiveaway() async {
    await requireLogin(context, () async {
      try {
        final n = await _api.liveJoinGiveaway(widget.streamId);
        if (mounted) showSnack(context, 'Вы участвуете! Участников: $n');
        _refreshState();
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      }
    });
  }

  // --- UI ----------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return Scaffold(
      backgroundColor: Colors.black,
      resizeToAvoidBottomInset: true,
      body: Stack(
        fit: StackFit.expand,
        children: [
          _video(s),
          if (s != null && !_ended)
            SafeArea(
              child: Column(
                children: [
                  LiveHeader(
                    host: s.host,
                    viewers: s.viewers,
                    likes: s.likes + _likesBump,
                    onClose: () => Navigator.of(context).maybePop(),
                    trailing: IconButton(
                      onPressed: _toggleMute,
                      icon: Icon(_muted ? Icons.volume_off_rounded : Icons.volume_up_rounded, color: Colors.white),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
                    child: Row(children: [
                      Expanded(
                        child: Text(s.title,
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(color: Colors.white70, shadows: [Shadow(blurRadius: 6)])),
                      ),
                      if (!s.isHost)
                        TextButton(
                          onPressed: () => Navigator.of(context)
                              .push(MaterialPageRoute(builder: (_) => SellerScreen(id: s.host.id))),
                          style: TextButton.styleFrom(foregroundColor: Colors.white),
                          child: Text(s.isFollowing ? 'Профиль' : 'Подписаться'),
                        ),
                    ]),
                  ),
                  if (s.giveaway != null) _giveaway(s.giveaway!),
                  const Spacer(),
                  SizedBox(height: 220, child: LiveCommentsView(comments: _comments)),
                  if (s.featured != null)
                    Padding(
                      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                      child: LiveProductCard(product: s.featured!, onTap: () => _buy(s.featured!)),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
                    child: Row(
                      children: [
                        Expanded(child: LiveCommentInput(onSend: _sendComment, enabled: s.chatEnabled)),
                        if (s.products.length > 1)
                          IconButton(
                            tooltip: 'Товары эфира',
                            onPressed: () async {
                              final p = await showLiveShelf(context, s.products);
                              if (p != null && mounted) _buy(p);
                            },
                            icon: Badge(
                              label: Text('${s.products.length}'),
                              child: const Icon(Icons.shopping_bag_outlined, color: Colors.white, size: 28),
                            ),
                          ),
                        IconButton(
                          onPressed: _like,
                          icon: const Icon(Icons.favorite_rounded, color: Color(0xFFEF4444), size: 30),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          if (s == null && _error == null && !_ended) const Center(child: CircularProgressIndicator(color: Colors.white)),
          if (_error != null)
            LiveMessage(
              icon: Icons.cloud_off_rounded,
              text: _error!,
              action: FilledButton(onPressed: () => Navigator.of(context).maybePop(), child: const Text('Закрыть')),
            ),
          if (_ended)
            LiveMessage(
              icon: Icons.videocam_off_rounded,
              text: 'Эфир завершён.\nЗаписи эфиров не сохраняются.',
              action: FilledButton(onPressed: () => Navigator.of(context).maybePop(), child: const Text('Закрыть')),
            ),
        ],
      ),
    );
  }

  Widget _video(LiveState? s) {
    final cover = s?.cover != null
        ? CachedNetworkImage(imageUrl: s!.cover!, fit: BoxFit.cover, errorWidget: (_, _, _) => const ColoredBox(color: Colors.black))
        : const ColoredBox(color: Color(0xFF111827));
    if (s == null || _ended) return const ColoredBox(color: Colors.black);
    if (_hasVideo) {
      return RTCVideoView(_renderer, objectFit: RTCVideoViewObjectFit.RTCVideoViewObjectFitCover);
    }
    String? hint;
    if (s.isDemo) {
      hint = 'Демонстрационный эфир — видео нет';
    } else if (s.isHost) {
      hint = 'Это ваш эфир';
    } else if (_connectTimedOut) {
      hint = 'Не удаётся получить видео от ведущего';
    }
    return Stack(fit: StackFit.expand, children: [
      cover,
      Container(color: Colors.black45),
      Center(
        child: hint == null
            ? const Column(mainAxisSize: MainAxisSize.min, children: [
                CircularProgressIndicator(color: Colors.white),
                SizedBox(height: 12),
                Text('Подключаемся к эфиру…', style: TextStyle(color: Colors.white)),
              ])
            : Column(mainAxisSize: MainAxisSize.min, children: [
                Text(hint, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 15)),
                if (_connectTimedOut) ...[
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: () => _connect(s),
                    style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                    child: const Text('Переподключиться'),
                  ),
                ],
              ]),
      ),
    ]);
  }

  Widget _giveaway(LiveGiveaway g) => Container(
        margin: const EdgeInsets.fromLTRB(12, 8, 12, 0),
        padding: const EdgeInsets.fromLTRB(12, 8, 8, 8),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [Color(0xFFF59E0B), Color(0xFFEA580C)]),
          borderRadius: BorderRadius.circular(14),
        ),
        child: Row(children: [
          const Icon(Icons.card_giftcard_rounded, color: Colors.white),
          const SizedBox(width: 8),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(g.title, maxLines: 1, overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
              Text(g.winner != null ? 'Победитель: ${g.winner}' : 'Участников: ${g.participants} из ${g.goal}',
                  style: const TextStyle(color: Colors.white, fontSize: 12.5)),
            ]),
          ),
          if (g.winner == null)
            FilledButton(
              onPressed: _joinGiveaway,
              style: FilledButton.styleFrom(
                  backgroundColor: Colors.white, foregroundColor: const Color(0xFFEA580C), minimumSize: const Size(0, 36)),
              child: const Text('Участвовать'),
            ),
        ]),
      );
}
