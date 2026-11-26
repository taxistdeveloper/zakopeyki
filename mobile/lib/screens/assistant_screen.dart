import 'dart:async';

import 'package:flutter/material.dart';
import 'package:speech_to_text/speech_recognition_result.dart';
import 'package:speech_to_text/speech_to_text.dart';

import '../api.dart';
import '../assistant_models.dart';
import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Открыть чат с ИИ-помощником ZAK.
Future<void> openAssistant(BuildContext context) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => const AssistantScreen()));

/// ИИ-помощник ZAK: поиск по каталогу, ответы по сделкам и доставке, передача оператору.
///
/// Вопрос можно надиктовать (кнопка микрофона); [listenOnOpen] — сразу начать слушать,
/// так экран открывается по голосовому пробуждению «Зак».
class AssistantScreen extends StatefulWidget {
  const AssistantScreen({super.key, this.listenOnOpen = false});

  final bool listenOnOpen;

  /// Экран ZAK сейчас открыт: «Зак» запускает диктовку здесь, а не открывает новый экран.
  static bool get onScreen => _AssistantScreenState._open > 0;

  @override
  State<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends State<AssistantScreen> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final _messages = <ZakMessage>[];

  ZakInfo? _info;
  String? _error;
  String _status = 'ai_active';
  List<ZakSuggestion> _suggestions = const [];
  bool _thinking = false;
  Timer? _poll;

  static int _open = 0;
  static const _micHold = 'assistant_dictation';
  final _speech = SpeechToText();
  bool _speechReady = false;
  bool _dictating = false;
  StreamSubscription<void>? _wakeSub;
  late final _wake = SessionScope.read(context).wakeWord;
  late final _voice = SessionScope.read(context).zakVoice;

  /// Последний вопрос задан голосом — ответ на него ZAK озвучит.
  bool _askedByVoice = false;

  @override
  void initState() {
    super.initState();
    _open++;
    _wakeSub = _wake.onWake.listen((_) => _dictate(ack: true));
    if (_wake.enabled) _voice.prefetchAck();
    _load().then((_) {
      if (widget.listenOnOpen && mounted) _dictate(ack: true);
    });
  }

  @override
  void dispose() {
    _open--;
    _wakeSub?.cancel();
    _voice.stop();
    if (_dictating) {
      _speech.cancel();
      _wake.release(_micHold);
    }
    _poll?.cancel();
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  int get _lastId => _messages.fold(0, (m, e) => (e.id ?? 0) > m ? e.id! : m);

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final info = await SessionScope.read(context).api.assistant();
      if (!mounted) return;
      setState(() {
        _info = info;
        _status = info.conversationStatus;
        _messages
          ..clear()
          ..addAll(info.messages);
        final lastAi = info.messages.lastWhere((m) => !m.mine, orElse: () => ZakMessage(from: 'ai', text: ''));
        _suggestions = lastAi.suggestions.isNotEmpty ? lastAi.suggestions : info.suggestions;
      });
      _syncPolling();
      _toBottom();
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  void _syncPolling() {
    final need = _status == 'human_escalated';
    if (need && _poll == null) {
      _poll = Timer.periodic(const Duration(seconds: 4), (_) => _pollNew());
    } else if (!need) {
      _poll?.cancel();
      _poll = null;
    }
  }

  Future<void> _pollNew() async {
    try {
      final (status, list) = await SessionScope.read(context).api.assistantPoll(_lastId);
      if (!mounted) return;
      final fresh = list.where((m) => !m.mine && !_messages.any((x) => x.id == m.id)).toList();
      final wasThinking = _thinking;
      setState(() {
        _status = status;
        _messages.addAll(fresh);
        if (fresh.isNotEmpty) _thinking = false;
      });
      // асинхронный ответ ИИ (или оператора) пришёл опросом
      if (wasThinking && fresh.isNotEmpty) _maybeSpeak(fresh.last.text);
      if (fresh.isNotEmpty) _toBottom();
      _syncPolling();
    } catch (_) {
      // сеть может моргнуть — следующий тик повторит
    }
  }

  void _toBottom() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(_scroll.position.maxScrollExtent + 200,
            duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      }
    });
  }

  /// Голосовой ввод вопроса: системное распознавание речи Android, по паузе отправляем текст.
  /// [ack] — сначала ответить голосом «Да, слушаю вас» (после слова «Зак»).
  Future<void> _dictate({bool ack = false}) async {
    if (_dictating || _thinking || _info == null) return;
    setState(() => _dictating = true);
    await _voice.stop();
    if (ack) {
      await _voice.sayAck();
      if (!mounted || !_dictating) return;
    }
    // освобождаем микрофон от слушателя «Зак», иначе распознаватели помешают друг другу
    await _wake.hold(_micHold);
    try {
      _speechReady = _speechReady ||
          await _speech.initialize(
            onStatus: (status) {
              if (status == SpeechToText.doneStatus || status == SpeechToText.notListeningStatus) _finishDictation();
            },
            onError: (e) {
              if (mounted && e.permanent && e.errorMsg != 'error_no_match' && e.errorMsg != 'error_speech_timeout') {
                showSnack(context, 'Не удалось распознать речь');
              }
              _finishDictation();
            },
          );
      if (!_speechReady) {
        if (mounted) showSnack(context, 'Голосовой ввод недоступен: нужен доступ к микрофону и сервис распознавания речи');
        _finishDictation();
        return;
      }
      if (!mounted || !_dictating) return;
      _input.clear();
      await _speech.listen(
        onResult: _onSpeech,
        listenOptions: SpeechListenOptions(
          localeId: 'ru_RU',
          partialResults: true,
          cancelOnError: true,
          listenFor: const Duration(seconds: 30),
          pauseFor: const Duration(seconds: 3),
        ),
      );
    } catch (_) {
      if (mounted) showSnack(context, 'Голосовой ввод недоступен');
      _finishDictation();
    }
  }

  void _onSpeech(SpeechRecognitionResult r) {
    if (!mounted) return;
    _input.text = r.recognizedWords;
    _input.selection = TextSelection.collapsed(offset: _input.text.length);
    if (r.finalResult) {
      _finishDictation();
      if (r.recognizedWords.trim().isNotEmpty) _send(null, true);
    }
  }

  void _stopDictation() => _speech.stop();

  void _finishDictation() {
    if (!_dictating || !mounted) return;
    setState(() => _dictating = false);
    _wake.release(_micHold);
  }

  /// Озвучить ответ ZAK, если вопрос был голосом или включена озвучка всех ответов.
  void _maybeSpeak(String reply) {
    if (reply.trim().isEmpty || !mounted) return;
    if (_askedByVoice || _voice.autoSpeak) _voice.speak(reply);
    _askedByVoice = false;
  }

  Future<void> _send([String? preset, bool byVoice = false]) async {
    final text = (preset ?? _input.text).trim();
    if (text.isEmpty || _thinking) return;
    _voice.stop();
    _askedByVoice = byVoice;
    _input.clear();
    final mine = ZakMessage(from: 'user', text: text);
    setState(() {
      _messages.add(mine);
      _thinking = true;
      _suggestions = const [];
    });
    _toBottom();
    try {
      final r = await SessionScope.read(context).api.assistantSend(text);
      if (!mounted) return;
      setState(() {
        _status = r.status;
        final i = _messages.indexOf(mine);
        if (i >= 0 && r.messageId != null) {
          _messages[i] = ZakMessage(id: r.messageId, from: 'user', text: text);
        }
        if (r.reply.isNotEmpty) {
          _messages.add(ZakMessage(
            id: r.aiMessageId,
            from: r.status == 'human_escalated' && r.aiMessageId == null ? 'system' : 'ai',
            text: r.reply,
            products: r.products,
            suggestions: r.suggestions,
          ));
        }
        _suggestions = r.suggestions;
        _thinking = r.pending;
      });
      if (!r.pending) _maybeSpeak(r.reply);
      if (r.pending) {
        // асинхронный режим: ждём ответ опросом
        _poll ??= Timer.periodic(const Duration(seconds: 2), (_) => _pollNew());
      } else {
        _syncPolling();
      }
      _toBottom();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _thinking = false;
        _messages.add(ZakMessage(from: 'system', text: e.message));
      });
      _toBottom();
    }
  }

  Future<void> _clear() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Начать новый диалог?'),
        content: const Text('История переписки с ZAK будет закрыта.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Очистить')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await SessionScope.read(context).api.assistantClear();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
      return;
    }
    _poll?.cancel();
    _poll = null;
    if (!mounted) return;
    setState(() {
      _messages.clear();
      _status = 'ai_active';
      _suggestions = _info?.suggestions ?? const [];
      _thinking = false;
    });
  }

  Future<void> _rate(ZakMessage m, int rating) async {
    if (m.id == null || m.rating != null) return;
    setState(() => m.rating = rating);
    try {
      await SessionScope.read(context).api.assistantFeedback(m.id!, rating);
      if (mounted) showSnack(context, 'Спасибо за оценку!');
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => m.rating = null);
      showSnack(context, e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final p = context.pal;
    final info = _info;
    final operator = _status == 'human_escalated';
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Row(children: [
          const _ZakAvatar(size: 40),
          const SizedBox(width: 10),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('ZAK', style: display(18, weight: FontWeight.w800, color: p.ink)),
              Text(
                operator ? 'Подключён оператор' : (info?.status ?? 'ИИ-помощник'),
                style: TextStyle(fontSize: 12.5, color: operator ? kAccent : const Color(0xFF16A34A)),
              ),
            ]),
          ),
        ]),
        actions: [
          ListenableBuilder(
            listenable: _voice,
            builder: (context, _) => IconButton(
              tooltip: _voice.speaking
                  ? 'Остановить озвучку'
                  : (_voice.autoSpeak ? 'Не озвучивать ответы' : 'Озвучивать все ответы'),
              onPressed: () => _voice.speaking ? _voice.stop() : _voice.setAutoSpeak(!_voice.autoSpeak),
              icon: Icon(
                _voice.speaking
                    ? Icons.stop_circle_outlined
                    : (_voice.autoSpeak ? Icons.volume_up_rounded : Icons.volume_off_outlined),
                color: _voice.autoSpeak || _voice.speaking ? kBrand : null,
              ),
            ),
          ),
          IconButton(
            tooltip: 'Новый диалог',
            onPressed: _messages.isEmpty ? null : _clear,
            icon: const Icon(Icons.delete_sweep_outlined),
          ),
        ],
      ),
      body: info == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : Column(children: [
              Expanded(
                child: ListView(
                  controller: _scroll,
                  padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
                  children: [
                    _Bubble(message: ZakMessage(from: 'ai', text: info.welcome)),
                    for (final m in _messages)
                      _Bubble(message: m, onRate: m.from == 'ai' && m.id != null ? (r) => _rate(m, r) : null),
                    if (_thinking) const _Typing(),
                  ],
                ),
              ),
              if (_suggestions.isNotEmpty && !_thinking)
                SizedBox(
                  height: 48,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(12, 4, 12, 4),
                    children: [
                      for (final s in _suggestions)
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ActionChip(
                            label: Text(s.label),
                            onPressed: () => _send(s.message),
                          ),
                        ),
                    ],
                  ),
                ),
              _InputBar(
                controller: _input,
                hint: _dictating ? 'Говорите…' : info.placeholder,
                busy: _thinking,
                onSend: () => _send(),
                dictating: _dictating,
                onMic: _dictating ? _stopDictation : _dictate,
              ),
            ]),
    );
  }
}

class _ZakAvatar extends StatelessWidget {
  const _ZakAvatar({this.size = 32});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(color: context.pal.brandSoft, shape: BoxShape.circle),
      clipBehavior: Clip.antiAlias,
      child: Image.asset('assets/img/zak.png', fit: BoxFit.cover),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({required this.message, this.onRate});

  final ZakMessage message;
  final ValueChanged<int>? onRate;

  @override
  Widget build(BuildContext context) {
    final p = context.pal;
    final m = message;
    if (m.from == 'system') {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Center(
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            decoration: BoxDecoration(color: p.subtle, borderRadius: BorderRadius.circular(999)),
            child: Text(m.text, textAlign: TextAlign.center, style: TextStyle(fontSize: 12.5, color: p.muted)),
          ),
        ),
      );
    }
    final mine = m.mine;
    final bubble = Container(
      constraints: BoxConstraints(maxWidth: MediaQuery.sizeOf(context).width * 0.78),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: mine
          ? const BoxDecoration(
              color: kBrand,
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(18),
                topRight: Radius.circular(18),
                bottomLeft: Radius.circular(18),
                bottomRight: Radius.circular(6),
              ),
            )
          : softCard(context, radius: 18).copyWith(
              borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(6),
                topRight: Radius.circular(18),
                bottomLeft: Radius.circular(18),
                bottomRight: Radius.circular(18),
              ),
            ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        if (m.byOperator)
          const Padding(
            padding: EdgeInsets.only(bottom: 4),
            child: Text('Оператор', style: TextStyle(fontSize: 12, color: kAccent, fontWeight: FontWeight.w700)),
          ),
        SelectableText(m.text, style: TextStyle(color: mine ? Colors.white : p.ink, height: 1.35, fontSize: 15)),
      ]),
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Column(
        crossAxisAlignment: mine ? CrossAxisAlignment.end : CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: mine ? MainAxisAlignment.end : MainAxisAlignment.start,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (!mine) ...[const _ZakAvatar(size: 30), const SizedBox(width: 8)],
              Flexible(child: bubble),
            ],
          ),
          if (m.products.isNotEmpty)
            SizedBox(
              height: 214,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.fromLTRB(38, 8, 0, 4),
                itemCount: m.products.length,
                separatorBuilder: (_, _) => const SizedBox(width: 10),
                itemBuilder: (_, i) => _ProductCard(product: m.products[i]),
              ),
            ),
          if (onRate != null)
            Padding(
              padding: const EdgeInsets.only(left: 38, top: 2),
              child: Row(mainAxisSize: MainAxisSize.min, children: [
                _RateButton(
                  icon: Icons.thumb_up_alt_outlined,
                  active: m.rating == 5,
                  onTap: m.rating == null ? () => onRate!(5) : null,
                ),
                _RateButton(
                  icon: Icons.thumb_down_alt_outlined,
                  active: m.rating == 1,
                  onTap: m.rating == null ? () => onRate!(1) : null,
                ),
              ]),
            ),
        ],
      ),
    );
  }
}

class _RateButton extends StatelessWidget {
  const _RateButton({required this.icon, required this.active, this.onTap});

  final IconData icon;
  final bool active;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      visualDensity: VisualDensity.compact,
      iconSize: 17,
      onPressed: onTap,
      icon: Icon(icon, color: active ? kBrand : context.pal.faint),
    );
  }
}

class _ProductCard extends StatelessWidget {
  const _ProductCard({required this.product});

  final ZakProduct product;

  @override
  Widget build(BuildContext context) {
    final p = context.pal;
    return GestureDetector(
      onTap: () => openProduct(context, product.id),
      child: Container(
        width: 150,
        decoration: softCard(context, radius: 16),
        clipBehavior: Clip.antiAlias,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(width: 150, height: 120, child: ProductThumb(product.image, size: 150)),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 8, 10, 0),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(product.priceText, maxLines: 1, style: display(14.5, weight: FontWeight.w800, color: p.ink)),
              const SizedBox(height: 2),
              Text(product.title,
                  maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 12.5, color: p.ink2)),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _Typing extends StatelessWidget {
  const _Typing();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(children: [
        const _ZakAvatar(size: 30),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: softCard(context, radius: 18),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2)),
            const SizedBox(width: 10),
            Text('ZAK думает…', style: TextStyle(color: context.pal.muted, fontSize: 13.5)),
          ]),
        ),
      ]),
    );
  }
}

class _InputBar extends StatelessWidget {
  const _InputBar({
    required this.controller,
    required this.hint,
    required this.busy,
    required this.onSend,
    required this.dictating,
    required this.onMic,
  });

  final TextEditingController controller;
  final String hint;
  final bool busy;
  final VoidCallback onSend;
  final bool dictating;
  final VoidCallback onMic;

  @override
  Widget build(BuildContext context) {
    final p = context.pal;
    return Container(
      decoration: BoxDecoration(
        color: p.surface,
        border: Border(top: BorderSide(color: p.line)),
      ),
      padding: EdgeInsets.fromLTRB(12, 8, 8, 8 + MediaQuery.paddingOf(context).bottom),
      child: Row(children: [
        Expanded(
          child: TextField(
            controller: controller,
            maxLength: 1000,
            keyboardType: TextInputType.text,
            textInputAction: TextInputAction.send,
            onSubmitted: (_) => onSend(),
            decoration: InputDecoration(hintText: hint, counterText: '', fillColor: p.subtle, isDense: true),
          ),
        ),
        const SizedBox(width: 4),
        IconButton(
          tooltip: dictating ? 'Остановить' : 'Сказать голосом',
          onPressed: busy ? null : onMic,
          style: dictating ? IconButton.styleFrom(backgroundColor: kBrand.withValues(alpha: 0.12)) : null,
          icon: Icon(dictating ? Icons.mic_rounded : Icons.mic_none_rounded, color: dictating ? kBrand : p.muted),
        ),
        const SizedBox(width: 4),
        IconButton.filled(
          style: IconButton.styleFrom(backgroundColor: kAccent, foregroundColor: Colors.white, fixedSize: const Size(48, 48)),
          onPressed: busy ? null : onSend,
          icon: const Icon(Icons.send_rounded),
        ),
      ]),
    );
  }
}

/// Плавающая кнопка ZAK на главной (как на сайте).
class ZakFab extends StatelessWidget {
  const ZakFab({super.key});

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => openAssistant(context),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: context.pal.surface,
            boxShadow: kLiftShadow,
            border: Border.all(color: kBrand, width: 2),
          ),
          clipBehavior: Clip.antiAlias,
          child: Image.asset('assets/img/zak.png', fit: BoxFit.cover),
        ),
        const SizedBox(height: 4),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 3),
          decoration: BoxDecoration(color: kBrand, borderRadius: BorderRadius.circular(999), boxShadow: kLiftShadow),
          child: ListenableBuilder(
            listenable: SessionScope.of(context).wakeWord,
            builder: (context, _) => Text(
              SessionScope.of(context).wakeWord.listening ? 'Скажите «Зак»' : 'Спросить ZAK',
              style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700),
            ),
          ),
        ),
      ]),
    );
  }
}
