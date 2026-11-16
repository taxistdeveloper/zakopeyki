import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/locale/locale_controller.dart';
import '../../l10n/app_localizations.dart';
import '../catalog/catalog_repository.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  static const _languageNames = {'ru': 'Русский', 'kk': 'Қазақша', 'en': 'English'};

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l = AppLocalizations.of(context);
    final auth = ref.watch(authControllerProvider);
    final locale = ref.watch(localeProvider);
    final config = ref.watch(remoteConfigProvider).value;

    return Scaffold(
      appBar: AppBar(title: Text(l.navProfile)),
      body: ListView(
        children: [
          switch (auth) {
            AuthAuthenticated(:final user) => ListTile(
                contentPadding: const EdgeInsets.all(16),
                leading: CircleAvatar(
                  radius: 28,
                  backgroundImage: user.avatarUrl != null ? CachedNetworkImageProvider(user.avatarUrl!) : null,
                  child: user.avatarUrl == null ? Text(user.name.isNotEmpty ? user.name.characters.first : '?') : null,
                ),
                title: Text(user.name, style: Theme.of(context).textTheme.titleMedium),
                subtitle: Text(user.email),
              ),
            AuthGuest() => Padding(
                padding: const EdgeInsets.all(16),
                child: FilledButton(onPressed: () => context.push('/login'), child: Text(l.loginButton)),
              ),
            AuthUnknown() => const LinearProgressIndicator(),
          },
          if (auth is AuthAuthenticated) ...[
            ListTile(
              leading: const Icon(Icons.add_box_outlined),
              title: Text(l.newListing),
              onTap: () => context.push('/listing/new'),
            ),
            ListTile(
              leading: const Icon(Icons.receipt_long_outlined),
              title: Text(l.myOrders),
              onTap: () => context.push('/orders'),
            ),
            ListTile(
              leading: const Icon(Icons.inventory_2_outlined),
              title: Text(l.myListings),
              onTap: () => context.push('/my-listings'),
            ),
          ],
          const Divider(),
          ListTile(
            leading: const Icon(Icons.language),
            title: Text(l.language),
            trailing: DropdownButton<String>(
              value: locale.languageCode,
              underline: const SizedBox.shrink(),
              items: [
                for (final code in supportedLanguageCodes)
                  DropdownMenuItem(value: code, child: Text(_languageNames[code] ?? code)),
              ],
              onChanged: (code) {
                if (code != null) ref.read(localeProvider.notifier).select(code);
              },
            ),
          ),
          if (config != null && config.offerUrl.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.description_outlined),
              title: Text(l.legalOffer),
              onTap: () => launchUrl(Uri.parse(config.offerUrl), mode: LaunchMode.inAppBrowserView),
            ),
          if (config != null && config.privacyUrl.isNotEmpty)
            ListTile(
              leading: const Icon(Icons.privacy_tip_outlined),
              title: Text(l.legalPrivacy),
              onTap: () => launchUrl(Uri.parse(config.privacyUrl), mode: LaunchMode.inAppBrowserView),
            ),
          if (auth is AuthAuthenticated) ...[
            const Divider(),
            ListTile(
              leading: const Icon(Icons.logout),
              title: Text(l.logout),
              onTap: () => ref.read(authControllerProvider.notifier).logout(),
            ),
            ListTile(
              leading: const Icon(Icons.devices_other),
              title: Text(l.logoutAll),
              onTap: () => ref.read(authControllerProvider.notifier).logout(everywhere: true),
            ),
            const Divider(),
            ListTile(
              leading: Icon(Icons.delete_forever_outlined, color: Theme.of(context).colorScheme.error),
              title: Text(l.deleteAccount, style: TextStyle(color: Theme.of(context).colorScheme.error)),
              onTap: () => context.push('/delete-account'),
            ),
          ],
        ],
      ),
    );
  }
}
