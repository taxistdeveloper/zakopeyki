import 'dart:async';

import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import '../screens/assistant_screen.dart';
import '../theme.dart';
import '../wake_word.dart';

/// Обёртка над всем приложением (MaterialApp.builder): по слову «Зак» открывает ZAK
/// с голосовым вводом и показывает индикатор, пока микрофон слушает.
class WakeWordHost extends StatefulWidget {
  const WakeWordHost({super.key, required this.navigatorKey, required this.child});

  final GlobalKey<NavigatorState> navigatorKey;
  final Widget child;

  @override
  State<WakeWordHost> createState() => _WakeWordHostState();
}

class _WakeWordHostState extends State<WakeWordHost> {
  StreamSubscription<void>? _sub;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    _sub ??= SessionScope.read(context).wakeWord.onWake.listen((_) => _onWake());
  }

  @override
  void dispose() {
    _sub?.cancel();
    super.dispose();
  }

  void _onWake() {
    final session = SessionScope.read(context);
    // Экран ZAK уже открыт — он сам начнёт диктовку; без конфига/в режиме заглушки ZAK недоступен.
    if (AssistantScreen.onScreen || session.config == null || session.stubLocked) return;
    widget.navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => const AssistantScreen(listenOnOpen: true)));
  }

  @override
  Widget build(BuildContext context) {
    final wake = SessionScope.of(context).wakeWord;
    return Stack(children: [
      widget.child,
      ListenableBuilder(
        listenable: wake,
        builder: (context, _) => wake.listening
            ? Positioned(
                left: 12,
                bottom: MediaQuery.paddingOf(context).bottom + 92,
                child: _ListeningBadge(onTap: () {
                  final ctx = widget.navigatorKey.currentContext;
                  if (ctx != null) showWakeWordSettings(ctx);
                }),
              )
            : const SizedBox.shrink(),
      ),
    ]);
  }
}

/// Пульсирующий значок микрофона: «приложение слушает слово Зак».
class _ListeningBadge extends StatefulWidget {
  const _ListeningBadge({required this.onTap});

  final VoidCallback onTap;

  @override
  State<_ListeningBadge> createState() => _ListeningBadgeState();
}

class _ListeningBadgeState extends State<_ListeningBadge> with SingleTickerProviderStateMixin {
  late final _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400))..repeat(reverse: true);

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      button: true,
      label: 'Голосовое пробуждение включено: скажите «Зак»',
      child: GestureDetector(
        onTap: widget.onTap,
        child: AnimatedBuilder(
          animation: _pulse,
          builder: (context, child) => Container(
            width: 36,
            height: 36,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: kBrand.withValues(alpha: 0.9),
              boxShadow: [
                BoxShadow(color: kBrand.withValues(alpha: 0.35), blurRadius: 4 + 8 * _pulse.value, spreadRadius: 2 * _pulse.value),
              ],
            ),
            child: child,
          ),
          child: const Icon(Icons.mic_rounded, color: Colors.white, size: 20),
        ),
      ),
    );
  }
}

/// Настройка «Голосовое пробуждение»: переключатель, пояснение и загрузка модели.
Future<void> showWakeWordSettings(BuildContext context) {
  final wake = SessionScope.read(context).wakeWord;
  return showModalBottomSheet<void>(
    context: context,
    showDragHandle: true,
    builder: (ctx) => SafeArea(
      child: ListenableBuilder(
        listenable: wake,
        builder: (ctx, _) => Padding(
          padding: const EdgeInsets.fromLTRB(4, 0, 4, 12),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 4),
              child: Text('Голосовое пробуждение', style: display(18, weight: FontWeight.w700, color: ctx.pal.ink)),
            ),
            SwitchListTile(
              value: wake.enabled,
              onChanged: wake.downloading ? null : (v) => _toggle(ctx, wake, v),
              secondary: Icon(Icons.record_voice_over_rounded, color: wake.enabled ? kBrand : null),
              title: const Text('Откликаться на «Зак»'),
              subtitle: Text(_status(wake)),
            ),
            if (wake.downloading)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                child: LinearProgressIndicator(value: wake.progress == 0 ? null : wake.progress),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
              child: Text(
                'Скажите «Зак» — откроется помощник ZAK и сразу начнёт слушать вопрос. '
                'Приложение слушает, только пока открыто на экране; слово распознаётся на телефоне, '
                'звук никуда не отправляется. Для работы один раз загружается голосовая модель (~45 МБ).',
                style: TextStyle(fontSize: 13, color: ctx.pal.muted, height: 1.35),
              ),
            ),
          ]),
        ),
      ),
    ),
  );
}

String _status(WakeWord wake) {
  if (wake.downloading) return 'Загружаем голосовую модель… ${((wake.progress ?? 0) * 100).round()}%';
  if (wake.error != null && wake.enabled) return wake.error!;
  if (!wake.enabled) return 'Выключено';
  return wake.listening ? 'Слушаю — скажите «Зак»' : 'Включено, микрофон на паузе';
}

Future<void> _toggle(BuildContext context, WakeWord wake, bool on) async {
  final err = await wake.setEnabled(on);
  if (err != null && context.mounted) showSnack(context, err);
}
