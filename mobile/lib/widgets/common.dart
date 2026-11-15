import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';

import '../main.dart';
import '../theme.dart';

/// Полноэкранная ошибка с кнопкой «Повторить».
class ErrorView extends StatelessWidget {
  const ErrorView({super.key, required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.cloud_off_rounded,
              size: 52,
              color: context.pal.faint2,
            ),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center),
            const SizedBox(height: 16),
            FilledButton.tonal(
              onPressed: onRetry,
              child: const Text('Повторить'),
            ),
          ],
        ),
      ),
    );
  }
}

/// Пустой список.
class EmptyView extends StatelessWidget {
  const EmptyView({
    super.key,
    required this.icon,
    required this.title,
    this.text,
    this.action,
  });

  final IconData icon;
  final String title;
  final String? text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [context.pal.brandSoft, context.pal.brandSoft2]),
                shape: BoxShape.circle,
              ),
              child: Icon(icon, size: 40, color: kBrand),
            ),
            const SizedBox(height: 18),
            Text(
              title,
              textAlign: TextAlign.center,
              style: display(18, weight: FontWeight.w700, color: context.pal.ink),
            ),
            if (text != null) ...[
              const SizedBox(height: 6),
              Text(
                text!,
                textAlign: TextAlign.center,
                style: TextStyle(color: context.pal.muted),
              ),
            ],
            if (action != null) ...[const SizedBox(height: 20), action!],
          ],
        ),
      ),
    );
  }
}

/// Белая карточка-секция.
class SectionCard extends StatelessWidget {
  const SectionCard({
    super.key,
    this.title,
    required this.child,
    this.padding = const EdgeInsets.all(16),
  });

  final String? title;
  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.fromLTRB(14, 0, 14, 14),
      padding: padding,
      decoration: softCard(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (title != null) ...[
            Text(
              title!,
              style: display(16.5, weight: FontWeight.w700, color: context.pal.ink),
            ),
            const SizedBox(height: 12),
          ],
          child,
        ],
      ),
    );
  }
}

/// Строка «название — сумма».
class AmountRow extends StatelessWidget {
  const AmountRow(
    this.label,
    this.value, {
    super.key,
    this.bold = false,
    this.color,
  });

  final String label;
  final String value;
  final bool bold;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(
      fontWeight: bold ? FontWeight.w800 : FontWeight.w400,
      fontSize: bold ? 17 : 15,
      color: color,
    );
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: bold ? style : style.copyWith(color: context.pal.muted),
            ),
          ),
          Text(value, style: style),
        ],
      ),
    );
  }
}

/// Квадратная миниатюра товара.
class ProductThumb extends StatelessWidget {
  const ProductThumb(this.url, {super.key, this.size = 64});

  final String? url;
  final double size;

  @override
  Widget build(BuildContext context) {
    final placeholder = Container(
      color: context.pal.brandSoft,
      alignment: Alignment.center,
      child: Icon(
        Icons.inventory_2_outlined,
        color: kBrand.withValues(alpha: 0.5),
      ),
    );
    return ClipRRect(
      borderRadius: BorderRadius.circular(size > 56 ? 16 : 12),
      child: SizedBox(
        width: size,
        height: size,
        child: url == null
            ? placeholder
            : CachedNetworkImage(
                imageUrl: url!,
                fit: BoxFit.cover,
                errorWidget: (_, _, _) => placeholder,
              ),
      ),
    );
  }
}

/// Цветная плашка статуса.
class StatusPill extends StatelessWidget {
  const StatusPill(this.text, {super.key, this.color = kBrand});

  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}

/// Нижняя панель с кнопкой (оформление, сохранение).
class BottomActionBar extends StatelessWidget {
  const BottomActionBar({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: context.pal.surface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(kRadiusLg)),
        boxShadow: [
          BoxShadow(
            color: Color(0x1A1E3A8A),
            blurRadius: 24,
            offset: Offset(0, -6),
            spreadRadius: -6,
          ),
        ],
      ),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      child: SafeArea(top: false, child: child),
    );
  }
}

Widget busyLabel(bool busy, String text) => busy
    ? const SizedBox(
        width: 22,
        height: 22,
        child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white),
      )
    : Text(text);

/// Выбор оформления: как в системе / светлая / тёмная.
Future<void> showThemePicker(BuildContext context) {
  final session = SessionScope.read(context);
  return showModalBottomSheet<void>(
    context: context,
    builder: (ctx) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
          child: Align(
            alignment: Alignment.centerLeft,
            child: Text('Оформление', style: display(18, weight: FontWeight.w700, color: ctx.pal.ink)),
          ),
        ),
        for (final (mode, icon, title) in const [
          (ThemeMode.system, Icons.brightness_auto_rounded, 'Как в системе'),
          (ThemeMode.light, Icons.light_mode_rounded, 'Светлая'),
          (ThemeMode.dark, Icons.dark_mode_rounded, 'Тёмная'),
        ])
          ListTile(
            leading: Icon(icon, color: session.themeMode == mode ? kBrand : null),
            title: Text(title),
            trailing: session.themeMode == mode ? const Icon(Icons.check_rounded, color: kBrand) : null,
            onTap: () {
              session.setThemeMode(mode);
              Navigator.pop(ctx);
            },
          ),
        const SizedBox(height: 8),
      ]),
    ),
  );
}

String themeModeLabel(ThemeMode m) => switch (m) {
      ThemeMode.light => 'Светлая',
      ThemeMode.dark => 'Тёмная',
      ThemeMode.system => 'Как в системе',
    };
