import 'package:flutter/material.dart';

import '../main.dart';
import '../nav.dart';
import '../theme.dart';

/// Сайт закрыт до открытия (stub_mode), у пользователя нет раннего доступа.
class StubScreen extends StatelessWidget {
  const StubScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final opensAt = DateTime.tryParse(session.config?.opensAt ?? '');

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Icon(Icons.storefront_rounded, size: 72, color: kBrand),
              const SizedBox(height: 20),
              Text(
                'Закопейки скоро откроются',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 10),
              Text(
                opensAt != null
                    ? 'Открытие ${opensAt.day.toString().padLeft(2, '0')}.${opensAt.month.toString().padLeft(2, '0')}.${opensAt.year}'
                    : 'Совсем скоро',
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.black54, fontSize: 16),
              ),
              const SizedBox(height: 32),
              if (session.loggedIn) ...[
                Text(
                  'Аккаунт ${session.user!.email} пока без раннего доступа.',
                  textAlign: TextAlign.center,
                ),
                const SizedBox(height: 12),
                OutlinedButton(onPressed: session.start, child: const Text('Проверить снова')),
                TextButton(onPressed: session.logout, child: const Text('Выйти')),
              ] else ...[
                FilledButton(
                  onPressed: () => openLogin(context),
                  child: const Text('Войти (ранний доступ)'),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
