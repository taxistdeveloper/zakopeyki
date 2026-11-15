import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';

// Фирменная палитра zakopeyki.kz (tailwind-конфиг сайта: brand / accent / gold / ink).
const kBrand = Color(0xFF2563EB); // brand-500
const kBrandDark = Color(0xFF1D4ED8); // brand-600
const kBrandDeep = Color(0xFF1E3A8A); // brand-700
const kBrand50 = Color(0xFFEFF6FF);
const kBrand100 = Color(0xFFDBEAFE);
const kBrand300 = Color(0xFF93C5FD);
const kAccent = Color(0xFFF97316); // accent-500 — главная кнопка покупки
const kAccentDark = Color(0xFFEA580C);
const kAccent50 = Color(0xFFFFF7ED);
const kGold = Color(0xFFF59E0B);
const kInk900 = Color(0xFF0F172A);
const kInk800 = Color(0xFF1E293B);
const kInk700 = Color(0xFF334155);
const kInk100 = Color(0xFFF1F5F9);
const kInk50 = Color(0xFFF8FAFC);
const kLine = Color(0xFFE6EBF5);
const kBg = Color(0xFFF4F7FD);

/// Радиусы как на сайте (rounded-2xl / rounded-[24px]).
const kRadius = 20.0;
const kRadiusLg = 24.0;

/// Мягкая голубая тень сайта (shadow-soft).
const kSoftShadow = [
  BoxShadow(color: Color(0x0F2563EB), blurRadius: 2, offset: Offset(0, 1)),
  BoxShadow(color: Color(0x4D93C5FD), blurRadius: 24, offset: Offset(0, 8), spreadRadius: -6),
];

/// Приподнятая тень (shadow-lift) — для плавающих элементов.
const kLiftShadow = [
  BoxShadow(color: Color(0x241E3A8A), blurRadius: 40, offset: Offset(0, 12), spreadRadius: -8),
];

/// Фирменный градиент шапки.
const kBrandGradient = LinearGradient(
  begin: Alignment.topLeft,
  end: Alignment.bottomRight,
  colors: [Color(0xFF3B82F6), kBrand, kBrandDark],
);

/// Заголовки — Sora (font-display на сайте).
TextStyle display(double size, {FontWeight weight = FontWeight.w700, Color? color, double? height}) =>
    GoogleFonts.sora(fontSize: size, fontWeight: weight, color: color, height: height, letterSpacing: -0.2);

/// Цвета, зависящие от светлой/тёмной темы. В светлой — ровно прежние значения.
@immutable
class Palette extends ThemeExtension<Palette> {
  const Palette({
    required this.dark,
    required this.bg,
    required this.surface,
    required this.ink,
    required this.ink2,
    required this.muted,
    required this.muted2,
    required this.faint,
    required this.faint2,
    required this.hairline,
    required this.line,
    required this.subtle,
    required this.brandSoft,
    required this.brandSoft2,
    required this.accentSoft,
  });

  final bool dark;
  final Color bg; // фон экрана
  final Color surface; // карточки, листы, поля
  final Color ink; // основной текст
  final Color ink2; // вторичный тёмный текст
  final Color muted; // black54
  final Color muted2; // black45
  final Color faint; // black38
  final Color faint2; // black26
  final Color hairline; // black12
  final Color line; // рамки
  final Color subtle; // серые плашки
  final Color brandSoft; // голубая подложка (brand-50)
  final Color brandSoft2; // brand-100
  final Color accentSoft; // accent-50

  static const light = Palette(
    dark: false,
    bg: kBg,
    surface: Colors.white,
    ink: kInk900,
    ink2: kInk700,
    muted: Colors.black54,
    muted2: Colors.black45,
    faint: Colors.black38,
    faint2: Colors.black26,
    hairline: Colors.black12,
    line: kLine,
    subtle: kInk100,
    brandSoft: kBrand50,
    brandSoft2: kBrand100,
    accentSoft: kAccent50,
  );

  static const darkPalette = Palette(
    dark: true,
    bg: Color(0xFF0B1220),
    surface: Color(0xFF141D30),
    ink: Color(0xFFE5EAF3),
    ink2: Color(0xFFCBD5E1),
    muted: Color(0xFF9AA8BD),
    muted2: Color(0xFF8593A8),
    faint: Color(0xFF6B7890),
    faint2: Color(0xFF4A566B),
    hairline: Color(0xFF26324A),
    line: Color(0xFF243049),
    subtle: Color(0xFF1C2740),
    brandSoft: Color(0xFF16244A),
    brandSoft2: Color(0xFF1E3A8A),
    accentSoft: Color(0xFF3A1D0B),
  );

  @override
  Palette copyWith() => this;

  @override
  Palette lerp(Palette? other, double t) => t < 0.5 || other == null ? this : other;
}

extension PaletteX on BuildContext {
  Palette get pal => Theme.of(this).extension<Palette>() ?? Palette.light;
}

ThemeData buildTheme([Brightness brightness = Brightness.light]) {
  final dark = brightness == Brightness.dark;
  final p = dark ? Palette.darkPalette : Palette.light;
  final kInk900 = p.ink;
  final kInk800 = dark ? p.ink2 : const Color(0xFF1E293B);
  final kInk700 = p.ink2;
  final kLine = p.line;
  final kBg = p.bg;
  final kBrand50 = p.brandSoft;
  final kBrand100 = p.brandSoft2;
  final white = p.surface;
  final slate = dark ? p.muted : const Color(0xFF64748B);
  final link = dark ? const Color(0xFF60A5FA) : kBrand; // blue-400 читается на тёмном
  final scheme = ColorScheme.fromSeed(
    seedColor: kBrand,
    brightness: brightness,
    primary: kBrand,
    secondary: kAccent,
    surface: white,
    surfaceTint: Colors.transparent,
  ).copyWith(onSurface: kInk900, outline: kLine, outlineVariant: kLine, onPrimary: Colors.white);

  // Текст — DM Sans, заголовки — Sora
  final base = GoogleFonts.dmSansTextTheme(dark ? ThemeData.dark().textTheme : null)
      .apply(bodyColor: kInk900, displayColor: kInk900);
  final text = base.copyWith(
    displayLarge: display(40, weight: FontWeight.w800, color: kInk900),
    displayMedium: display(34, weight: FontWeight.w800, color: kInk900),
    displaySmall: display(28, weight: FontWeight.w800, color: kInk900),
    headlineLarge: display(26, weight: FontWeight.w800, color: kInk900),
    headlineMedium: display(24, weight: FontWeight.w800, color: kInk900),
    headlineSmall: display(21, weight: FontWeight.w700, color: kInk900),
    titleLarge: display(19, weight: FontWeight.w700, color: kInk900),
    titleMedium: GoogleFonts.dmSans(fontSize: 16, fontWeight: FontWeight.w700, color: kInk900),
    titleSmall: GoogleFonts.dmSans(fontSize: 14, fontWeight: FontWeight.w700, color: kInk900),
  );

  final rounded = RoundedRectangleBorder(borderRadius: BorderRadius.circular(16));
  final buttonText = GoogleFonts.dmSans(fontSize: 16, fontWeight: FontWeight.w700);

  return ThemeData(
    useMaterial3: true,
    brightness: brightness,
    extensions: [p],
    colorScheme: scheme,
    textTheme: text,
    scaffoldBackgroundColor: kBg,
    splashFactory: InkSparkle.splashFactory,
    appBarTheme: AppBarTheme(
      backgroundColor: kBg,
      foregroundColor: kInk900,
      elevation: 0,
      scrolledUnderElevation: 0,
      centerTitle: false,
      titleTextStyle: display(19, weight: FontWeight.w700, color: kInk900),
      systemOverlayStyle: (dark ? SystemUiOverlayStyle.light : SystemUiOverlayStyle.dark).copyWith(statusBarColor: Colors.transparent),
    ),
    cardTheme: CardThemeData(
      color: white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(kRadiusLg)),
    ),
    dividerTheme: DividerThemeData(color: kLine, thickness: 1, space: 1),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: white,
      hintStyle: GoogleFonts.dmSans(color: (dark ? p.faint : const Color(0xFF94A3B8))),
      labelStyle: GoogleFonts.dmSans(color: slate),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: kLine)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: kLine)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: kBrand, width: 1.6)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: const BorderSide(color: Color(0xFFDC2626))),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        minimumSize: const Size(0, 52),
        shape: rounded,
        textStyle: buttonText,
        elevation: 0,
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size(0, 52),
        shape: rounded,
        textStyle: buttonText,
        foregroundColor: link,
        side: BorderSide(color: kBrand100, width: 1.4),
        backgroundColor: white,
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(foregroundColor: link, textStyle: GoogleFonts.dmSans(fontSize: 15, fontWeight: FontWeight.w700)),
    ),
    chipTheme: ChipThemeData(
      backgroundColor: white,
      selectedColor: kBrand,
      secondarySelectedColor: kBrand,
      side: BorderSide(color: kLine),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
      labelStyle: GoogleFonts.dmSans(fontSize: 14, fontWeight: FontWeight.w600, color: kInk800),
      secondaryLabelStyle: GoogleFonts.dmSans(fontSize: 14, fontWeight: FontWeight.w700, color: Colors.white),
      checkmarkColor: Colors.white,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
    ),
    segmentedButtonTheme: SegmentedButtonThemeData(
      style: SegmentedButton.styleFrom(
        selectedBackgroundColor: kBrand,
        selectedForegroundColor: Colors.white,
        backgroundColor: white,
        foregroundColor: kInk800,
        side: BorderSide(color: kLine),
        textStyle: GoogleFonts.dmSans(fontWeight: FontWeight.w700),
      ),
    ),
    tabBarTheme: TabBarThemeData(
      labelColor: kBrand,
      unselectedLabelColor: slate,
      indicatorColor: kBrand,
      dividerColor: kLine,
      labelStyle: GoogleFonts.dmSans(fontSize: 15, fontWeight: FontWeight.w700),
      unselectedLabelStyle: GoogleFonts.dmSans(fontSize: 15, fontWeight: FontWeight.w600),
    ),
    listTileTheme: ListTileThemeData(
      iconColor: kInk700,
      titleTextStyle: GoogleFonts.dmSans(fontSize: 15.5, fontWeight: FontWeight.w600, color: kInk900),
      subtitleTextStyle: GoogleFonts.dmSans(fontSize: 13, color: slate),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(kRadiusLg))),
      showDragHandle: true,
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(kRadiusLg)),
      titleTextStyle: display(19, weight: FontWeight.w700, color: kInk900),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: dark ? const Color(0xFF26324A) : kInk900,
      contentTextStyle: GoogleFonts.dmSans(color: Colors.white, fontWeight: FontWeight.w500),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),
    floatingActionButtonTheme: FloatingActionButtonThemeData(
      backgroundColor: kBrand,
      foregroundColor: Colors.white,
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      extendedTextStyle: GoogleFonts.dmSans(fontWeight: FontWeight.w700, fontSize: 15),
    ),
    badgeTheme: const BadgeThemeData(backgroundColor: kAccent, textColor: Colors.white),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: white,
      surfaceTintColor: Colors.transparent,
      indicatorColor: kBrand50,
      elevation: 0,
      height: 68,
      labelTextStyle: WidgetStateProperty.resolveWith((s) => GoogleFonts.dmSans(
            fontSize: 12,
            fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
            color: s.contains(WidgetState.selected) ? kBrand : slate,
          )),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(
            color: s.contains(WidgetState.selected) ? kBrand : slate,
          )),
    ),
    drawerTheme: DrawerThemeData(
      backgroundColor: white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.horizontal(right: Radius.circular(kRadiusLg))),
    ),
    progressIndicatorTheme: const ProgressIndicatorThemeData(color: kBrand),
  );
}

/// Оранжевая кнопка покупки (bg-accent на сайте).
ButtonStyle accentButton() => FilledButton.styleFrom(backgroundColor: kAccent, foregroundColor: Colors.white);

/// Карточка с мягкой тенью сайта (в тёмной теме — тонкая рамка вместо тени).
BoxDecoration softCard(BuildContext context, {double radius = kRadiusLg, Color? color}) {
  final p = context.pal;
  return BoxDecoration(
    color: color ?? p.surface,
    borderRadius: BorderRadius.circular(radius),
    boxShadow: p.dark ? null : kSoftShadow,
    border: p.dark ? Border.all(color: p.line) : null,
  );
}
