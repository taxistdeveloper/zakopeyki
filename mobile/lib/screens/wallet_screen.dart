import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../account_models.dart';
import '../api.dart';
import '../main.dart';
import '../nav.dart';
import '../shop_models.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'order_screen.dart';
import 'payment_screen.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  WalletData? _w;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final w = await SessionScope.read(context).api.wallet();
      if (mounted) setState(() => _w = w);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _deposit() async {
    final w = _w!;
    final amount = await _askAmount(
      title: 'Пополнить кошелёк',
      hint: 'Минимум ${formatMoney(w.minDeposit)}',
      button: 'Перейти к оплате',
      min: w.minDeposit,
      presets: const [5000, 10000, 20000, 50000],
    );
    if (amount == null || !mounted) return;
    try {
      final url = await SessionScope.read(context).api.walletDeposit(amount);
      if (!mounted) return;
      if (url == null) {
        showSnack(context, 'Кошелёк пополнен на ${formatMoney(amount)}');
      } else {
        final status = await Navigator.of(context).push<String>(
          MaterialPageRoute(fullscreenDialog: true, builder: (_) => PaymentScreen(url: url)),
        );
        if (!mounted) return;
        showSnack(context, status == 'success' ? 'Оплата прошла — баланс скоро обновится' : 'Оплата не завершена');
      }
      _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<void> _withdraw() async {
    final w = _w!;
    final amount = await _askAmount(
      title: 'Вывести средства',
      hint: 'Доступно ${formatMoney(w.balance)}',
      button: 'Вывести на карту',
      min: 1,
      max: w.balance,
    );
    if (amount == null || !mounted) return;
    try {
      await SessionScope.read(context).api.walletWithdraw(amount, 'card');
      if (!mounted) return;
      showSnack(context, 'Заявка на вывод ${formatMoney(amount)} принята');
      _load();
    } on ApiException catch (e) {
      if (mounted) showSnack(context, e.message);
    }
  }

  Future<int?> _askAmount({
    required String title,
    required String hint,
    required String button,
    required int min,
    int? max,
    List<int> presets = const [],
  }) {
    final ctrl = TextEditingController();
    return showModalBottomSheet<int>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) {
          final value = int.tryParse(ctrl.text) ?? 0;
          final valid = value >= min && (max == null || value <= max);
          return Padding(
            padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(ctx).bottom + 20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(title, style: Theme.of(ctx).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 16),
                TextField(
                  controller: ctrl,
                  autofocus: true,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  onChanged: (_) => setLocal(() {}),
                  style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w700),
                  decoration: InputDecoration(labelText: 'Сумма', suffixText: '₸', helperText: hint),
                ),
                if (presets.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Wrap(
                    spacing: 8,
                    children: [
                      for (final p in presets)
                        ActionChip(
                          label: Text(formatMoney(p)),
                          onPressed: () => setLocal(() => ctrl.text = '$p'),
                        ),
                    ],
                  ),
                ],
                const SizedBox(height: 20),
                FilledButton(onPressed: valid ? () => Navigator.pop(ctx, value) : null, child: Text(button)),
              ],
            ),
          );
        },
      ),
    ).whenComplete(ctrl.dispose);
  }

  @override
  Widget build(BuildContext context) {
    final w = _w;
    return Scaffold(
      appBar: AppBar(title: const Text('Кошелёк')),
      body: w == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.only(bottom: 24),
                children: [
                  Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.all(20),
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(colors: [kBrand, kBrandDark]),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Баланс', style: TextStyle(color: Colors.white70)),
                        const SizedBox(height: 4),
                        Text(formatMoney(w.balance),
                            style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w800)),
                        if (w.held > 0) ...[
                          const SizedBox(height: 4),
                          Text('В сделках: ${formatMoney(w.held)}', style: const TextStyle(color: Colors.white70)),
                        ],
                        const SizedBox(height: 18),
                        Row(
                          children: [
                            Expanded(
                              child: FilledButton.icon(
                                onPressed: w.canDeposit ? _deposit : null,
                                style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: kBrand),
                                icon: const Icon(Icons.add_rounded),
                                label: const Text('Пополнить'),
                              ),
                            ),
                            if (w.canWithdraw) ...[
                              const SizedBox(width: 10),
                              Expanded(
                                child: OutlinedButton.icon(
                                  onPressed: w.balance > 0 ? _withdraw : null,
                                  style: OutlinedButton.styleFrom(
                                    foregroundColor: Colors.white,
                                    side: const BorderSide(color: Colors.white70),
                                  ),
                                  icon: const Icon(Icons.arrow_outward_rounded),
                                  label: const Text('Вывести'),
                                ),
                              ),
                            ],
                          ],
                        ),
                        if (!w.canDeposit)
                          const Padding(
                            padding: EdgeInsets.only(top: 10),
                            child: Text('Пополнение картой временно недоступно',
                                style: TextStyle(color: Colors.white70, fontSize: 12.5)),
                          ),
                      ],
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsets.fromLTRB(16, 8, 16, 8),
                    child: Text('История операций', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  ),
                  if (w.transactions.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(32),
                      child: Text('Операций пока нет', textAlign: TextAlign.center, style: TextStyle(color: Colors.black45)),
                    )
                  else
                    Container(
                      margin: const EdgeInsets.symmetric(horizontal: 12),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: Column(
                        children: [
                          for (var i = 0; i < w.transactions.length; i++) ...[
                            if (i > 0) const Divider(height: 1, indent: 64),
                            _txTile(w.transactions[i]),
                          ],
                        ],
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  Widget _txTile(WalletTx t) {
    final incoming = t.amount >= 0;
    final color = incoming ? const Color(0xFF16A34A) : const Color(0xFF0F172A);
    return ListTile(
      onTap: t.orderId == null
          ? null
          : () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderScreen(id: t.orderId!))),
      leading: CircleAvatar(
        backgroundColor: incoming ? const Color(0xFFDCFCE7) : const Color(0xFFF1F5F9),
        child: Icon(incoming ? Icons.south_west_rounded : Icons.north_east_rounded,
            color: incoming ? const Color(0xFF16A34A) : Colors.black54, size: 20),
      ),
      title: Text(t.label, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Text(
        '${formatDate(t.createdAt, withTime: true)}${t.orderId != null ? ' · заказ №${t.orderId}' : ''}',
        style: const TextStyle(fontSize: 12, color: Colors.black45),
      ),
      trailing: Text('${incoming ? '+' : ''}${formatMoney(t.amount)}',
          style: TextStyle(fontWeight: FontWeight.w700, color: color)),
    );
  }
}
