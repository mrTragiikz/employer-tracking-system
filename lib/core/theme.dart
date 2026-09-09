import 'package:flutter/material.dart';

/// The Rajdoot cream/brown palette, ported from the web field app's CSS
/// tokens (field/components/header/css/header.css :root { ... }).
class AppColors {
  static const cardBg = Color(0xFFFFFFFF);
  static const panelBg = Color(0xFFFFFDF8);
  static const panelInset = Color(0xFFF3EDE4);
  static const ground = Color(0xFFFAF6EE); // page background
  static const border = Color(0xFFE7DDD0);
  static const borderSoft = Color(0xFFEFE8DE);

  static const text = Color(0xFF2A2420);
  static const text2 = Color(0xFF6B6156);
  static const textMuted = Color(0xFF948A7D);

  static const brown = Color(0xFF6B4423);
  static const brownStrong = Color(0xFF4F3018);
  static const accent = Color(0xFF8A5A2E);
  static const down = Color(0xFFC1362E); // errors
  static const ok = Color(0xFF1F7A43);

  /// The brown gradient used on primary buttons (offline.html, the
  /// announcement modal's "Got it" button).
  static const buttonGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF8A5A2E), Color(0xFF6B4423), Color(0xFF4F3018)],
    stops: [0.0, 0.55, 1.0],
  );
}

class AppRadius {
  static const card = 16.0;
  static const sm = 10.0;
  static const pill = 999.0;
}

ThemeData buildTheme() {
  const base = TextStyle(color: AppColors.text, fontFamilyFallback: [
    'Inter',
    'system-ui',
    'Segoe UI',
    'Roboto',
  ]);

  final scheme = ColorScheme.fromSeed(
    seedColor: AppColors.brown,
    primary: AppColors.brown,
    surface: AppColors.cardBg,
    error: AppColors.down,
    brightness: Brightness.light,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: AppColors.ground,
    fontFamily: 'Inter',
    textTheme: TextTheme(
      headlineSmall: base.copyWith(fontSize: 20, fontWeight: FontWeight.w700),
      titleLarge: base.copyWith(fontSize: 16, fontWeight: FontWeight.w700),
      titleMedium: base.copyWith(fontSize: 14, fontWeight: FontWeight.w600),
      bodyMedium: base.copyWith(fontSize: 13.5, height: 1.5),
      bodySmall: base.copyWith(fontSize: 11.5, color: AppColors.text2, height: 1.5),
      labelLarge: base.copyWith(fontSize: 14, fontWeight: FontWeight.w600),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: AppColors.cardBg,
      foregroundColor: AppColors.text,
      elevation: 0,
      surfaceTintColor: Colors.transparent,
    ),
    cardTheme: CardThemeData(
      color: AppColors.cardBg,
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(AppRadius.card),
        side: const BorderSide(color: AppColors.borderSoft),
      ),
      margin: EdgeInsets.zero,
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: AppColors.cardBg,
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        borderSide: const BorderSide(color: AppColors.border),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        borderSide: const BorderSide(color: AppColors.border),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        borderSide: const BorderSide(color: AppColors.brown, width: 1.6),
      ),
      errorBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(AppRadius.sm),
        borderSide: const BorderSide(color: AppColors.down),
      ),
    ),
    dividerColor: AppColors.borderSoft,
  );
}
