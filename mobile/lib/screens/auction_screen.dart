import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Торги по лоту: текущая цена (обновляется каждые 5 с), ставка, «купить сейчас», история ставок.
class AuctionScreen extends StatefulWidget {
  const AuctionScreen({super.key, required this.productId, required this.title});

  final int productId;
  final String title;

  @override
  State<AuctionScreen> createState() => _AuctionScreenState();
}

class _AuctionScreenState extends State<AuctionScreen> {
  AuctionLive? _a;
  String? _error;
  Timer? _timer;
  bool _busy = false;
  final _amount = TextEditingController();
  bool _amountTouched = false;

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _load(silent: true));
  }

  @override
  void dispose() {
    _timer?.cancel();
    _amount.dispose();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    if (!silent) setState(() => _error = null);
    try {
      final a = await SessionScope.read(context).api.auction(widget.productId);
      if (!mounted) return;
      setState(() {
        _a = a;
        if (!_amountTouched || (int.tryParse(_amount.text) ?? 0) < a.minBid) {
          _amount.text = '${a.minBid}';
          _amountTouched = false;
        }
      });
    } on ApiException catch (e) {
      if (mounted && !silent) setState(() => _error = e.message);
    }
  }

  Future<void> _bid() async {
    final amount = int.tryParse(_amount.text) ?? 0;
    await _run(() => SessionScope.read(context).api.placeBid(widget.productId, amount));
  }

  Future<void> _buyNow() async {
    final a = _a!;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Купить сейчас?'),
        content: Text('Вы выкупите лот за ${formatMoney(a.buyNow!)}. Сумма будет зарезервирована с кошелька.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Отмена')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Купить')),
        ],
      ),
    );
    if (ok == true && mounted) {
      await _run(() => SessionScope.read(context).api.auctionBuyNow(widget.productId));
    }
  }

  Future<void> _run(Future<(AuctionLive, String)> Function() call) async {
    await requireLogin(context, () async {
      setState(() => _busy = true);
      try {
        final (a, msg) = await call();
        if (!mounted) return;
        setState(() {
          _a = a;
          _amountTouched = false;
          _amount.text = '${a.minBid}';
        });
        showSnack(context, msg);
      } on ApiException catch (e) {
        if (mounted) showSnack(context, e.message);
      } finally {
        if (mounted) setState(() => _busy = false);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final a = _a;
    return Scaffold(
      appBar: AppBar(title: Text(widget.title, maxLines: 1, overflow: TextOverflow.ellipsis)),
      body: a == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12, bottom: 24),
              children: [
                Container(
                  margin: const EdgeInsets.fromLTRB(12, 0, 12, 12),
                  padding: const EdgeInsets.all(20),
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: [Color(0xFFEF4444), Color(0xFFDC2626)]),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.gavel_rounded, color: Colors.white70, size: 18),
                          const SizedBox(width: 6),
                          Text(a.active ? 'Идут торги' : 'Торги завершены',
                              style: const TextStyle(color: Colors.white70)),
                          const Spacer(),
                          if (a.active && a.endsAt != null) _Countdown(endsAt: a.endsAt!),
                        ],
                      ),
                      const SizedBox(height: 8),
                      const Text('Текущая цена', style: TextStyle(color: Colors.white70, fontSize: 13)),
                      Text(formatMoney(a.currentPrice),
                          style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800)),
                      if (a.bidStep > 0)
                        Text('Шаг ставки ${formatMoney(a.bidStep)}', style: const TextStyle(color: Colors.white70)),
                      if (a.isWinner)
                        Container(
                          margin: const EdgeInsets.only(top: 10),
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(10)),
                          child: const Text('Ваша ставка лидирует', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
                        ),
                    ],
                  ),
                ),
                if (a.active)
                  SectionCard(
                    title: 'Ваша ставка',
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        TextField(
                          controller: _amount,
                          keyboardType: TextInputType.number,
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          onChanged: (_) => _amountTouched = true,
                          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                          decoration: InputDecoration(
                            suffixText: '₸',
                            helperText: 'Минимум ${formatMoney(a.minBid)}',
                          ),
                        ),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          children: [
                            for (final k in [1, 2, 5])
                              ActionChip(
                                label: Text('+${formatMoney(a.bidStep * k)}'),
                                onPressed: () => setState(() {
                                  _amountTouched = true;
                                  _amount.text = '${a.currentPrice + a.bidStep * k}';
                                }),
                              ),
                          ],
                        ),
                        const SizedBox(height: 12),
                        FilledButton(onPressed: _busy ? null : _bid, child: busyLabel(_busy, 'Сделать ставку')),
                        if (a.buyNow != null) ...[
                          const SizedBox(height: 8),
                          OutlinedButton(
                            onPressed: _busy ? null : _buyNow,
                            child: Text('Купить сейчас за ${formatMoney(a.buyNow!)}'),
                          ),
                        ],
                        const SizedBox(height: 8),
                        Text(
                          'Сумма ставки резервируется на кошельке и возвращается, если вашу ставку перебьют.',
                          style: TextStyle(color: context.pal.muted, fontSize: 12.5),
                        ),
                      ],
                    ),
                  ),
                SectionCard(
                  title: 'Ставки',
                  child: a.bids.isEmpty
                      ? Text('Ставок пока нет — станьте первым', style: TextStyle(color: context.pal.muted))
                      : Column(
                          children: [
                            for (var i = 0; i < a.bids.length; i++)
                              Padding(
                                padding: const EdgeInsets.symmetric(vertical: 6),
                                child: Row(
                                  children: [
                                    Icon(i == 0 ? Icons.emoji_events_rounded : Icons.person_outline_rounded,
                                        size: 18, color: i == 0 ? const Color(0xFFF59E0B) : context.pal.faint),
                                    const SizedBox(width: 8),
                                    Expanded(child: Text(a.bids[i].user)),
                                    Text(chatTime(a.bids[i].createdAt),
                                        style: TextStyle(color: context.pal.muted2, fontSize: 12)),
                                    const SizedBox(width: 10),
                                    Text(formatMoney(a.bids[i].amount),
                                        style: TextStyle(fontWeight: FontWeight.w700, color: i == 0 ? kBrand : null)),
                                  ],
                                ),
                              ),
                          ],
                        ),
                ),
              ],
            ),
    );
  }
}

class _Countdown extends StatefulWidget {
  const _Countdown({required this.endsAt});

  final String endsAt;

  @override
  State<_Countdown> createState() => _CountdownState();
}

class _CountdownState extends State<_Countdown> {
  Timer? _t;

  @override
  void initState() {
    super.initState();
    _t = Timer.periodic(const Duration(seconds: 1), (_) => setState(() {}));
  }

  @override
  void dispose() {
    _t?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final end = DateTime.tryParse(widget.endsAt);
    if (end == null) return const SizedBox.shrink();
    var left = end.difference(DateTime.now());
    if (left.isNegative) left = Duration.zero;
    String two(int v) => v.toString().padLeft(2, '0');
    final text = left.inDays > 0
        ? '${left.inDays} д ${two(left.inHours % 24)}:${two(left.inMinutes % 60)}'
        : '${two(left.inHours)}:${two(left.inMinutes % 60)}:${two(left.inSeconds % 60)}';
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(8)),
      child: Text(text, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontFeatures: [FontFeature.tabularFigures()])),
    );
  }
}
