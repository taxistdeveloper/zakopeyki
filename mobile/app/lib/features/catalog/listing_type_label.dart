import 'package:flutter/widgets.dart';

import '../../l10n/app_localizations.dart';

/// Название типа объявления на языке интерфейса. Набор типов фиксирован на сервере
/// (ProductHelper::TYPES); для неизвестного кода — подпись, пришедшая с сервера.
String listingTypeLabel(BuildContext context, String code, String serverLabel) {
  final l = AppLocalizations.of(context);
  return switch (code) {
    'new' => l.typeNew,
    'used' => l.typeUsed,
    'auction' => l.typeAuction,
    'service' => l.typeService,
    'exchange' => l.typeExchange,
    'free' => l.typeFree,
    'gig' => l.typeGig,
    _ => serverLabel,
  };
}
