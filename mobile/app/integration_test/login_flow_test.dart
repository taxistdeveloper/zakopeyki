// Сквозной сценарий на симуляторе против ЛОКАЛЬНОГО backend (не production!):
//
//   flutter test integration_test -d <simulator> \
//     --dart-define=API_BASE_URL=http://127.0.0.1:8137 \
//     --dart-define=TEST_EMAIL=... --dart-define=TEST_PASSWORD=...
//
// Тестовый пользователь создаётся `php bin/seed_mobile_test_user.php`.
import 'dart:io' show Directory, File, Platform;
import 'dart:convert' show base64Decode;
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:integration_test/integration_test.dart';
import 'package:sign_in_with_apple/sign_in_with_apple.dart';
import 'package:image_picker/image_picker.dart';
import 'package:zakopeyki/app.dart';
import 'package:zakopeyki/features/commerce/checkout_screen.dart' show paymentLauncherProvider;
import 'package:zakopeyki/features/listings/listing_form_screen.dart' show imagePickerProvider;
import 'package:zakopeyki/features/catalog/widgets/product_tile.dart';

const _email = String.fromEnvironment('TEST_EMAIL');
const _password = String.fromEnvironment('TEST_PASSWORD');
/// Уникальный email для теста регистрации (удаляется после прогона скриптом-обёрткой)
const _registerEmail = String.fromEnvironment('TEST_REGISTER_EMAIL');
/// Отдельный email для сценария удаления аккаунта
const _deleteEmail = String.fromEnvironment('TEST_DELETE_EMAIL');

Future<void> pumpUntil(WidgetTester tester, Finder finder, {Duration timeout = const Duration(seconds: 20)}) async {
  final end = DateTime.now().add(timeout);
  while (DateTime.now().isBefore(end)) {
    await tester.pump(const Duration(milliseconds: 250));
    if (finder.evaluate().isNotEmpty) return;
  }
  // Диагностика: что было на экране в момент таймаута
  final texts = find.byType(Text).evaluate().map((e) => (e.widget as Text).data).whereType<String>().where((t) => t.trim().isNotEmpty);
  throw TestFailure('Timed out waiting for $finder\nOn screen: ${texts.take(40).join(' | ')}');
}

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('guest → login → feed → product card', (tester) async {
    expect(_email, isNotEmpty, reason: 'pass --dart-define=TEST_EMAIL');
    // Начинаем гостем: сессия из прошлых запусков хранится в Keychain симулятора
    await const FlutterSecureStorage().deleteAll();
    // Сценарий проверяет русские тексты — фиксируем язык независимо от языка устройства
    await const FlutterSecureStorage().write(key: 'zk_locale', value: 'ru');
    // Битые/отсутствующие картинки в локальных данных: приложение показывает заглушку,
    // но движок логирует ошибку декодирования — в этом сценарии она не является провалом.
    final defaultOnError = FlutterError.onError;
    FlutterError.onError = (details) {
      if (details.library == 'image resource service') return;
      defaultOnError?.call(details);
    };
    addTearDown(() => FlutterError.onError = defaultOnError);
    await tester.pumpWidget(const ProviderScope(child: ZakopeykiApp()));

    // Сайт в режиме заглушки: гость видит «Скоро открытие» с кнопкой входа
    final loginButton = find.widgetWithText(FilledButton, 'Войти');
    await pumpUntil(tester, loginButton);
    await tester.tap(loginButton);
    await pumpUntil(tester, find.byType(TextFormField));
    await tester.pumpAndSettle();

    await tester.enterText(find.byType(TextFormField).at(0), _email);
    await tester.enterText(find.byType(TextFormField).at(1), _password);
    await tester.tap(find.widgetWithText(FilledButton, 'Войти').last);

    // После входа лента загружается с товарами
    await pumpUntil(tester, find.byType(ProductTile));
    expect(find.byType(ProductTile), findsWidgets);

    // Открываем первую карточку
    await tester.tap(find.byType(ProductTile).first);
    // Нижняя кнопка карточки видна всегда; блок «Продавец» — ниже, проверяем после прокрутки
    await pumpUntil(tester, find.byIcon(Icons.open_in_new));
    await tester.scrollUntilVisible(find.text('Продавец'), 300, scrollable: find.byType(Scrollable).first);
    await tester.pumpAndSettle();
    expect(find.text('Продавец'), findsOneWidget);
  });

  testWidgets('guest → registration → signed in (site closed for non-beta users)', (tester) async {
    expect(_registerEmail, isNotEmpty, reason: 'pass --dart-define=TEST_REGISTER_EMAIL');
    await const FlutterSecureStorage().deleteAll();
    // Сценарий проверяет русские тексты — фиксируем язык независимо от языка устройства
    await const FlutterSecureStorage().write(key: 'zk_locale', value: 'ru');
    final defaultOnError = FlutterError.onError;
    FlutterError.onError = (details) {
      if (details.library == 'image resource service') return;
      defaultOnError?.call(details);
    };
    addTearDown(() => FlutterError.onError = defaultOnError);
    await tester.pumpWidget(const ProviderScope(child: ZakopeykiApp()));

    final loginButton = find.widgetWithText(FilledButton, 'Войти');
    await pumpUntil(tester, loginButton);
    await tester.tap(loginButton);
    await pumpUntil(tester, find.byType(TextFormField));
    await tester.pumpAndSettle();

    // Sign in with Apple — только на iOS
    expect(find.byType(SignInWithAppleButton), Platform.isIOS ? findsOneWidget : findsNothing);

    final toRegister = find.widgetWithText(TextButton, 'Зарегистрироваться');
    await tester.ensureVisible(toRegister);
    await tester.tap(toRegister);
    await pumpUntil(tester, find.text('Регистрация'));

    final fields = find.byType(TextFormField);
    await tester.enterText(fields.at(0), 'Интеграционный Тест');
    await tester.enterText(fields.at(1), _registerEmail);
    await tester.enterText(fields.at(2), 'IntegrationPass123');
    // Форма длиннее экрана телефона (особенно с клавиатурой) — прокручиваем к элементам
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final scrollable = find.byType(Scrollable).last;
    await tester.scrollUntilVisible(find.byType(Checkbox), 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Checkbox));
    final submit = find.widgetWithText(FilledButton, 'Зарегистрироваться');
    await tester.scrollUntilVisible(submit, 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.tap(submit);

    // Вошли сразу после регистрации; у нового пользователя нет доступа к закрытому бета-сайту
    await pumpUntil(tester, find.text('Скоро открытие'));
    expect(find.widgetWithText(FilledButton, 'Войти'), findsNothing);
  });

  testWidgets('registration → profile → delete account → signed out', (tester) async {
    expect(_deleteEmail, isNotEmpty, reason: 'pass --dart-define=TEST_DELETE_EMAIL');
    await const FlutterSecureStorage().deleteAll();
    await const FlutterSecureStorage().write(key: 'zk_locale', value: 'ru');
    await tester.pumpWidget(const ProviderScope(child: ZakopeykiApp()));

    // Регистрация (без прокрутки ко всем деталям — проверена в предыдущем сценарии)
    await pumpUntil(tester, find.widgetWithText(FilledButton, 'Войти'));
    await tester.tap(find.widgetWithText(FilledButton, 'Войти'));
    await pumpUntil(tester, find.byType(TextFormField));
    await tester.pumpAndSettle();
    final toRegister = find.widgetWithText(TextButton, 'Зарегистрироваться');
    await tester.ensureVisible(toRegister);
    await tester.tap(toRegister);
    await pumpUntil(tester, find.text('Регистрация'));
    final fields = find.byType(TextFormField);
    await tester.enterText(fields.at(0), 'Удаляемый Тест');
    await tester.enterText(fields.at(1), _deleteEmail);
    await tester.enterText(fields.at(2), 'DeleteMe12345');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final scrollable = find.byType(Scrollable).last;
    await tester.scrollUntilVisible(find.byType(Checkbox), 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Checkbox));
    final submit = find.widgetWithText(FilledButton, 'Зарегистрироваться');
    await tester.scrollUntilVisible(submit, 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.tap(submit);
    await pumpUntil(tester, find.text('Скоро открытие'));

    // Профиль → Удалить аккаунт
    await tester.tap(find.text('Профиль'));
    await pumpUntil(tester, find.text('Удалить аккаунт'));
    await tester.ensureVisible(find.text('Удалить аккаунт'));
    await tester.tap(find.text('Удалить аккаунт'));
    await pumpUntil(tester, find.text('Удаление аккаунта'));
    await pumpUntil(tester, find.byType(TextField));

    await tester.enterText(find.byType(TextField), 'DeleteMe12345');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final list = find.byType(Scrollable).last;
    await tester.scrollUntilVisible(find.byType(Checkbox), 200, scrollable: list);
    await tester.pumpAndSettle();
    await tester.tap(find.byType(Checkbox));
    final deleteButton = find.widgetWithText(FilledButton, 'Удалить аккаунт навсегда');
    await tester.scrollUntilVisible(deleteButton, 200, scrollable: list);
    await tester.pumpAndSettle();
    await tester.tap(deleteButton);

    // Возврат на главную гостем: снова видна кнопка входа
    await pumpUntil(tester, find.widgetWithText(FilledButton, 'Войти'));
    // Сообщение показывается на кадре после перехода — ждём его (таймаут = провал)
    await pumpUntil(tester, find.text('Аккаунт удалён'), timeout: const Duration(seconds: 5));
  });

  testWidgets('beta user: post a listing with a photo → my listings → delete', (tester) async {
    await const FlutterSecureStorage().deleteAll();
    await const FlutterSecureStorage().write(key: 'zk_locale', value: 'ru');
    final defaultOnError = FlutterError.onError;
    FlutterError.onError = (details) {
      if (details.library == 'image resource service') return;
      defaultOnError?.call(details);
    };
    addTearDown(() => FlutterError.onError = defaultOnError);

    // Настоящая загрузка с устройства на сервер; подменён только системный выбор фото
    final photo = File('${Directory.systemTemp.path}/zk_e2e_photo.png')..writeAsBytesSync(_tinyPng);
    await tester.pumpWidget(ProviderScope(
      overrides: [imagePickerProvider.overrideWithValue(_StubPicker(photo.path))],
      child: const ZakopeykiApp(),
    ));

    await pumpUntil(tester, find.widgetWithText(FilledButton, 'Войти'));
    await tester.tap(find.widgetWithText(FilledButton, 'Войти'));
    await pumpUntil(tester, find.byType(TextFormField));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), _email);
    await tester.enterText(find.byType(TextFormField).at(1), _password);
    await tester.tap(find.widgetWithText(FilledButton, 'Войти').last);
    await pumpUntil(tester, find.byType(FloatingActionButton));
    await tester.pumpAndSettle(); // дождаться закрытия экрана входа

    await tester.tap(find.byType(FloatingActionButton));
    await pumpUntil(tester, find.text('Из галереи'));
    await tester.pumpAndSettle(); // дождаться окончания перехода на экран формы
    await tester.tap(find.text('Из галереи'));
    await tester.pumpAndSettle();
    expect(find.text('Обложка'), findsOneWidget, reason: 'photo added');

    Finder field(String label) => find.widgetWithText(TextFormField, label);
    final scrollable = find.byType(Scrollable).first;
    await tester.enterText(field('Название'), 'E2E велосипед');
    await tester.enterText(field('Описание'), 'Создано интеграционным тестом');
    await tester.scrollUntilVisible(field('Цена, ₸'), 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.enterText(field('Цена, ₸'), '12345');
    FocusManager.instance.primaryFocus?.unfocus();
    await tester.pumpAndSettle();
    final publish = find.widgetWithText(FilledButton, 'Опубликовать');
    await tester.scrollUntilVisible(publish, 200, scrollable: scrollable);
    await tester.pumpAndSettle();
    await tester.tap(publish);
    await pumpUntil(tester, find.text('Мои объявления'), timeout: const Duration(seconds: 40));
    await pumpUntil(tester, find.text('E2E велосипед'));
    expect(find.textContaining('12 345'), findsWidgets);

    // Удаление через меню
    final tile = find.ancestor(of: find.text('E2E велосипед'), matching: find.byType(Card));
    await tester.tap(find.descendant(of: tile, matching: find.byType(PopupMenuButton<String>)));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Удалить объявление'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(TextButton, 'Удалить'));
    await pumpUntil(tester, find.text('Объявление удалено'));
    expect(find.text('E2E велосипед'), findsNothing);
  });

  testWidgets('beta user: cart → checkout by card → payment closed → order awaiting payment → cancel', (tester) async {
    await const FlutterSecureStorage().deleteAll();
    await const FlutterSecureStorage().write(key: 'zk_locale', value: 'ru');
    final defaultOnError = FlutterError.onError;
    FlutterError.onError = (details) {
      if (details.library == 'image resource service') return;
      defaultOnError?.call(details);
    };
    addTearDown(() => FlutterError.onError = defaultOnError);

    String? paymentUrl;
    await tester.pumpWidget(ProviderScope(
      overrides: [
        // Страница банка вне приложения — имитируем закрытие окна оплаты пользователем
        paymentLauncherProvider.overrideWithValue((url) async {
          paymentUrl = url;
          return null;
        }),
      ],
      child: const ZakopeykiApp(),
    ));

    await pumpUntil(tester, find.widgetWithText(FilledButton, 'Войти'));
    await tester.tap(find.widgetWithText(FilledButton, 'Войти'));
    await pumpUntil(tester, find.byType(TextFormField));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField).at(0), _email);
    await tester.enterText(find.byType(TextFormField).at(1), _password);
    await tester.tap(find.widgetWithText(FilledButton, 'Войти').last);
    await pumpUntil(tester, find.byType(ProductTile));
    await tester.pumpAndSettle();

    // Товар другого продавца → в корзину
    await tester.tap(find.byType(ProductTile).first);
    await pumpUntil(tester, find.text('В корзину'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('В корзину'));
    await pumpUntil(tester, find.text('Добавлено в корзину'));
    // назад к ленте (как системная кнопка «Назад»)
    tester.state<NavigatorState>(find.byType(Navigator).first).pop();
    await tester.pumpAndSettle();

    // Вкладка «Корзина» → оформить
    await tester.tap(find.text('Корзина').last);
    await pumpUntil(tester, find.text('Оформить заказ'));
    // Сообщение «Добавлено в корзину» внизу экрана перекрывает кнопку — ждём, пока оно исчезнет
    for (var i = 0; i < 60 && find.byType(SnackBar).evaluate().isNotEmpty; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }
    await tester.pumpAndSettle();
    await tester.tap(find.text('Оформить заказ'));
    await pumpUntil(tester, find.text('Безопасная сделка'));
    await tester.pumpAndSettle();

    final pay = find.byWidgetPredicate((w) => w is FilledButton && w.child is Text && ((w.child as Text).data ?? '').startsWith('Оплатить'));
    await tester.scrollUntilVisible(pay, 200, scrollable: find.byType(Scrollable).first);
    await tester.pumpAndSettle();
    await tester.tap(pay);

    await pumpUntil(tester, find.text('Ожидает оплаты'), timeout: const Duration(seconds: 30));
    expect(paymentUrl, contains('/payments/halyk/app/'));
    expect(find.text('Отменить заказ'), findsOneWidget);

    // На экране «ожидает оплаты» крутится индикатор — pumpAndSettle никогда не завершится
    await tester.tap(find.text('Отменить заказ'));
    await pumpUntil(tester, find.widgetWithText(FilledButton, 'Подтвердить'));
    await tester.tap(find.widgetWithText(FilledButton, 'Подтвердить'));
    await pumpUntil(tester, find.text('Отменено'), timeout: const Duration(seconds: 20));
    await tester.pumpWidget(const SizedBox());
  });
}

class _StubPicker extends ImagePicker {
  _StubPicker(this.path);
  final String path;

  @override
  Future<List<XFile>> pickMultiImage({double? maxWidth, double? maxHeight, int? imageQuality, int? limit, bool requestFullMetadata = true}) async =>
      [XFile(path)];
}

/// Настоящий PNG 64×64 (сгенерирован GD): проходит серверную проверку и декодируется на устройстве.
final Uint8List _tinyPng = base64Decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAiElEQVRoge3ZsQ3CMBBAUUAMkCVSwVasTKZIkxHS0VsoerL0X21L93WdfV8/+21mDz3AvwrQCtAK0ArQCtAK0ArQnqMXvsvrijl+3sc2dH76DRSgFaAVoBWgFaAVoBWgFaAVoBWgFaAVoBWgFaAVoA3/D4y+319t+g0UoBWgFaAVoBWgFaAVoJ3fNQbolHiAUwAAAABJRU5ErkJggg==');
