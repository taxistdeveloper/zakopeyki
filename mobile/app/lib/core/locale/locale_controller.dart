import 'dart:ui';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../storage/secure_store.dart';

const supportedLanguageCodes = ['ru', 'kk', 'en'];

/// Язык интерфейса: выбор пользователя или системный (если поддерживается), иначе русский.
class LocaleController extends Notifier<Locale> {
  @override
  Locale build() {
    final system = PlatformDispatcher.instance.locale.languageCode;
    final initial = Locale(supportedLanguageCodes.contains(system) ? system : 'ru');
    currentLocaleCode = initial.languageCode;
    Future.microtask(_loadSaved);
    return initial;
  }

  Future<void> _loadSaved() async {
    final saved = await ref.read(secureStoreProvider).readLocale();
    if (saved != null && supportedLanguageCodes.contains(saved) && saved != state.languageCode) {
      _apply(saved);
    }
  }

  Future<void> select(String code) async {
    if (!supportedLanguageCodes.contains(code)) return;
    _apply(code);
    await ref.read(secureStoreProvider).writeLocale(code);
  }

  void _apply(String code) {
    currentLocaleCode = code;
    state = Locale(code);
  }
}

final localeProvider = NotifierProvider<LocaleController, Locale>(LocaleController.new);
