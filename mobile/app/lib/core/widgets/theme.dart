import 'package:flutter/material.dart';

/// Цвета бренда — те же, что на сайте (tailwind brand / accent в layouts/main.php).
class ZkColors {
  static const brand = Color(0xFF2563EB);
  static const brandDark = Color(0xFF1D4ED8);
  static const accent = Color(0xFFF97316);
}

ThemeData buildTheme(Brightness brightness) {
  final scheme = ColorScheme.fromSeed(
    seedColor: ZkColors.brand,
    brightness: brightness,
    primary: brightness == Brightness.light ? ZkColors.brand : null,
    secondary: ZkColors.accent,
  );
  return ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    appBarTheme: const AppBarTheme(centerTitle: false),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    ),
    cardTheme: CardThemeData(
      elevation: 0,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size.fromHeight(52),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      ),
    ),
  );
}
