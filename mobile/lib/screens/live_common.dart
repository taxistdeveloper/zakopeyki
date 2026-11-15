import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';

import '../live_models.dart';
import '../social_models.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Случайный id участника соединения (как liveRandomPeerId на сайте).
String randomPeerId() {
  final r = Random.secure();
  return List.generate(16, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
}

/// Конфигурация RTCPeerConnection из ice_servers сервера.
Map<String, dynamic> rtcConfig(List<Map<String, dynamic>> iceServers) => {
      'iceServers': iceServers.isNotEmpty
          ? iceServers
          : [
              {'urls': 'stun:stun.l.google.com:19302'},
              {'urls': 'stun:stun1.l.google.com:19302'},
            ],
      'sdpSemantics': 'unified-plan',
    };

/// ICE-кандидат из сигнала сайта/приложения: {candidate: {candidate, sdpMid, sdpMLineIndex}}.
RTCIceCandidate? candidateFrom(Map<String, dynamic>? payload) {
  final c = payload?['candidate'];
  if (c is! Map) return null;
  final line = c['candidate']?.toString();
  if (line == null || line.isEmpty) return null;
  return RTCIceCandidate(line, c['sdpMid']?.toString(), (c['sdpMLineIndex'] as num?)?.toInt());
}

/// Красная плашка LIVE.
class LiveBadge extends StatelessWidget {
  const LiveBadge({super.key, this.small = false});

  final bool small;

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.symmetric(horizontal: small ? 5 : 8, vertical: small ? 1 : 3),
        decoration: BoxDecoration(color: const Color(0xFFEF4444), borderRadius: BorderRadius.circular(6)),
        child: Text('LIVE',
            style: TextStyle(color: Colors.white, fontSize: small ? 9 : 11, fontWeight: FontWeight.w800, letterSpacing: 0.5)),
      );
}

/// Счётчик на полупрозрачной подложке (зрители, лайки).
class LivePill extends StatelessWidget {
  const LivePill({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
        decoration: BoxDecoration(color: Colors.black38, borderRadius: BorderRadius.circular(10)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, color: Colors.white, size: 14),
          const SizedBox(width: 4),
          Text(text, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600)),
        ]),
      );
}

/// Шапка эфира: ведущий, LIVE, зрители, лайки, закрыть.
class LiveHeader extends StatelessWidget {
  const LiveHeader({super.key, required this.host, required this.viewers, required this.likes, required this.onClose, this.trailing});

  final LiveHost host;
  final int viewers;
  final int likes;
  final VoidCallback onClose;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 8, 4, 0),
        child: Row(
          children: [
            CircleAvatar(
              radius: 18,
              backgroundColor: const Color(0xFFDBEAFE),
              foregroundImage: host.avatar != null ? NetworkImage(host.avatar!) : null,
              child: Text(initialOf(host.name), style: const TextStyle(color: kBrand)),
            ),
            const SizedBox(width: 8),
            Flexible(
              child: Text(host.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, shadows: [Shadow(blurRadius: 6)])),
            ),
            const SizedBox(width: 8),
            const LiveBadge(),
            const SizedBox(width: 6),
            LivePill(icon: Icons.visibility_rounded, text: '$viewers'),
            const SizedBox(width: 6),
            LivePill(icon: Icons.favorite_rounded, text: '$likes'),
            const Spacer(),
            ?trailing,
            IconButton(onPressed: onClose, icon: const Icon(Icons.close_rounded, color: Colors.white)),
          ],
        ),
      );
}

/// Лента комментариев поверх видео (последние снизу).
class LiveCommentsView extends StatelessWidget {
  const LiveCommentsView({super.key, required this.comments});

  final List<LiveComment> comments;

  @override
  Widget build(BuildContext context) {
    final items = comments.length > 40 ? comments.sublist(comments.length - 40) : comments;
    return ShaderMask(
      shaderCallback: (r) => const LinearGradient(
        begin: Alignment.topCenter,
        end: Alignment.bottomCenter,
        colors: [Colors.transparent, Colors.black],
        stops: [0, 0.25],
      ).createShader(r),
      blendMode: BlendMode.dstIn,
      child: ListView.builder(
        reverse: true,
        padding: const EdgeInsets.symmetric(horizontal: 12),
        itemCount: items.length,
        itemBuilder: (_, i) {
          final c = items[items.length - 1 - i];
          return Padding(
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(color: Colors.black38, borderRadius: BorderRadius.circular(12)),
                child: Text.rich(TextSpan(children: [
                  TextSpan(
                    text: c.isHost ? '${c.user} · ведущий  ' : '${c.user}  ',
                    style: TextStyle(color: c.isHost ? const Color(0xFFFDE68A) : Colors.white70, fontWeight: FontWeight.w700, fontSize: 13),
                  ),
                  TextSpan(text: c.body, style: const TextStyle(color: Colors.white, fontSize: 13.5)),
                ])),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Поле ввода комментария.
class LiveCommentInput extends StatefulWidget {
  const LiveCommentInput({super.key, required this.onSend, this.enabled = true});

  final Future<bool> Function(String text) onSend;
  final bool enabled;

  @override
  State<LiveCommentInput> createState() => _LiveCommentInputState();
}

class _LiveCommentInputState extends State<LiveCommentInput> {
  final _c = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final t = _c.text.trim();
    if (t.isEmpty || _busy) return;
    setState(() => _busy = true);
    final ok = await widget.onSend(t);
    if (ok) _c.clear();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return TextField(
      controller: _c,
      enabled: widget.enabled,
      maxLength: 280,
      style: const TextStyle(color: Colors.white),
      textCapitalization: TextCapitalization.sentences,
      onSubmitted: (_) => _send(),
      decoration: InputDecoration(
        counterText: '',
        hintText: widget.enabled ? 'Комментарий…' : 'Чат выключен ведущим',
        hintStyle: const TextStyle(color: Colors.white60),
        filled: true,
        fillColor: Colors.black38,
        isDense: true,
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: BorderSide.none),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(22), borderSide: const BorderSide(color: Colors.white54)),
        suffixIcon: IconButton(
          onPressed: widget.enabled ? _send : null,
          icon: _busy
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.send_rounded, color: Colors.white),
        ),
      ),
    );
  }
}

/// Карточка «товар дня» поверх видео.
class LiveProductCard extends StatelessWidget {
  const LiveProductCard({super.key, required this.product, required this.onTap, this.actionLabel = 'Купить'});

  final LiveProduct product;
  final VoidCallback onTap;
  final String actionLabel;

  @override
  Widget build(BuildContext context) {
    final p = product;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(14)),
        child: Row(
          children: [
            ProductThumb(p.image, size: 48),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                  Row(children: [
                    Text(p.priceText, style: const TextStyle(fontWeight: FontWeight.w800, color: kBrand)),
                    if (p.oldPriceText != null) ...[
                      const SizedBox(width: 6),
                      Text(p.oldPriceText!,
                          style: const TextStyle(
                              color: Colors.black38, decoration: TextDecoration.lineThrough, fontSize: 12.5)),
                    ],
                  ]),
                ],
              ),
            ),
            FilledButton(
              onPressed: onTap,
              style: FilledButton.styleFrom(minimumSize: const Size(0, 38), padding: const EdgeInsets.symmetric(horizontal: 14)),
              child: Text(actionLabel),
            ),
          ],
        ),
      ),
    );
  }
}

/// Полка товаров эфира (нижний лист).
Future<LiveProduct?> showLiveShelf(BuildContext context, List<LiveProduct> products, {String title = 'Товары эфира', int? selectedId}) {
  return showModalBottomSheet<LiveProduct>(
    context: context,
    showDragHandle: true,
    isScrollControlled: true,
    builder: (ctx) => DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.55,
      builder: (ctx, scroll) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Text(title, style: Theme.of(ctx).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
          ),
          Expanded(
            child: ListView(
              controller: scroll,
              children: [
                for (final p in products)
                  ListTile(
                    selected: p.id == selectedId,
                    leading: ProductThumb(p.image, size: 48),
                    title: Text(p.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text(p.priceText, style: const TextStyle(fontWeight: FontWeight.w700, color: kBrand)),
                    trailing: const Icon(Icons.chevron_right_rounded),
                    onTap: () => Navigator.pop(ctx, p),
                  ),
              ],
            ),
          ),
        ],
      ),
    ),
  );
}

/// Сообщение на чёрном фоне (эфир завершён, нет связи).
class LiveMessage extends StatelessWidget {
  const LiveMessage({super.key, required this.icon, required this.text, this.action});

  final IconData icon;
  final String text;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, color: Colors.white54, size: 56),
            const SizedBox(height: 12),
            Text(text, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 16)),
            if (action != null) ...[const SizedBox(height: 16), action!],
          ]),
        ),
      );
}
