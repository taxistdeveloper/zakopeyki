import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'screens/home_shell.dart';
import 'screens/stub_screen.dart';
import 'session.dart';
import 'theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(statusBarColor: Colors.transparent));
  final session = Session()
    ..loadThemeMode()
    ..start();
  runApp(ZakopeykiApp(session: session));
}

/// Доступ к Session из любого виджета: SessionScope.of(context).
class SessionScope extends InheritedNotifier<Session> {
  const SessionScope({super.key, required Session session, required super.child})
      : super(notifier: session);

  static Session of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<SessionScope>()!.notifier!;

  static Session read(BuildContext context) =>
      context.getInheritedWidgetOfExactType<SessionScope>()!.notifier!;
}

class ZakopeykiApp extends StatelessWidget {
  const ZakopeykiApp({super.key, required this.session});

  final Session session;

  static final _light = buildTheme();
  static final _dark = buildTheme(Brightness.dark);

  @override
  Widget build(BuildContext context) {
    return SessionScope(
      session: session,
      child: ListenableBuilder(
        listenable: session,
        builder: (_, _) => MaterialApp(
          title: 'Закопейки',
          debugShowCheckedModeBanner: false,
          theme: _light,
          darkTheme: _dark,
          themeMode: session.themeMode,
          home: const _Root(),
        ),
      ),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final s = SessionScope.of(context);
    if (s.loading && s.config == null) {
      return Scaffold(
        body: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Image.asset(context.pal.dark ? 'assets/img/logo_header_dark.png' : 'assets/img/logo_header.png', width: 220),
            const SizedBox(height: 28),
            const SizedBox(width: 28, height: 28, child: CircularProgressIndicator(strokeWidth: 3)),
          ]),
        ),
      );
    }
    if (s.config == null) {
      return Scaffold(
        body: SafeArea(
          child: Center(
            child: Padding(
              padding: const EdgeInsets.all(32),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.wifi_off_rounded, size: 56, color: context.pal.faint),
                  const SizedBox(height: 16),
                  Text(s.startupError ?? 'Нет соединения',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 20),
                  FilledButton(onPressed: s.start, child: const Text('Повторить')),
                ],
              ),
            ),
          ),
        ),
      );
    }
    if (s.stubLocked) return const StubScreen();
    return const HomeShell();
  }
}
