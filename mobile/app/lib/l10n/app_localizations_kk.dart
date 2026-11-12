// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Kazakh (`kk`).
class AppLocalizationsKk extends AppLocalizations {
  AppLocalizationsKk([String locale = 'kk']) : super(locale);

  @override
  String get appTitle => 'Zakopeyki.kz';

  @override
  String get navHome => 'Басты бет';

  @override
  String get navFavorites => 'Таңдаулылар';

  @override
  String get navProfile => 'Профиль';

  @override
  String get searchHint => 'Тауарлар мен қызметтерді іздеу';

  @override
  String get allTypes => 'Барлығы';

  @override
  String get retry => 'Қайталау';

  @override
  String get loadError => 'Деректерді жүктеу мүмкін болмады';

  @override
  String get networkError => 'Сервермен байланыс жоқ. Интернетті тексеріңіз.';

  @override
  String get emptyFeed => 'Ештеңе табылмады';

  @override
  String get emptyFavorites => 'Таңдаулыларда әзірге ештеңе жоқ';

  @override
  String get loginTitle => 'Кіру';

  @override
  String get loginSubtitle =>
      'Таңдаулыларды сақтау және сатып алу үшін кіріңіз';

  @override
  String get email => 'Email';

  @override
  String get password => 'Құпиясөз';

  @override
  String get loginButton => 'Кіру';

  @override
  String get fieldRequired => 'Міндетті өріс';

  @override
  String get twoFactorTitle => 'Кіруді растау';

  @override
  String get twoFactorHint =>
      'Аутентификатор қолданбасындағы 6 таңбалы кодты немесе резервтік кодты енгізіңіз';

  @override
  String get code => 'Код';

  @override
  String get confirm => 'Растау';

  @override
  String get logout => 'Шығу';

  @override
  String get logoutAll => 'Барлық құрылғылардан шығу';

  @override
  String get language => 'Тіл';

  @override
  String get loginRequired => 'Аккаунтқа кіріңіз';

  @override
  String get loginRequiredFavorites => 'Таңдаулыларды көру үшін кіріңіз';

  @override
  String get description => 'Сипаттама';

  @override
  String get characteristics => 'Сипаттамалар';

  @override
  String get exchangeFor => 'Айырбастаймын';

  @override
  String get seller => 'Сатушы';

  @override
  String get business => 'Бизнес';

  @override
  String ratingCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count пікір',
      zero: 'пікір жоқ',
    );
    return '$_temp0';
  }

  @override
  String inStock(int count) {
    return 'Қоймада: $count';
  }

  @override
  String get buyOnSite => 'Сайтта сатып алу';

  @override
  String get openOnSite => 'Сайтта ашу';

  @override
  String safeDealInfo(int percent) {
    return 'Қауіпсіз мәміле: ақша тауарды алғаныңызды растағанша ұсталады. Сатып алушы алымы $percent%.';
  }

  @override
  String auctionEnds(String date) {
    return 'Аукцион $date дейін';
  }

  @override
  String get siteClosedTitle => 'Жақында ашылады';

  @override
  String get siteClosedText =>
      'Zakopeyki.kz жақында ашылады. Әзірге қолжетімділік тек жабық тестілеуге қатысушыларға ғана ашық.';

  @override
  String get legalOffer => 'Пайдаланушы келісімі';

  @override
  String get legalPrivacy => 'Құпиялылық саясаты';

  @override
  String views(int count) {
    return 'Қаралым: $count';
  }

  @override
  String get addedToFavorites => 'Таңдаулыларға қосылды';

  @override
  String get removedFromFavorites => 'Таңдаулылардан өшірілді';

  @override
  String get sessionExpired => 'Сеанс аяқталды. Қайта кіріңіз.';

  @override
  String get registerTitle => 'Тіркелу';

  @override
  String get registerButton => 'Тіркелу';

  @override
  String get name => 'Аты';

  @override
  String get phoneOptional => 'Телефон (міндетті емес)';

  @override
  String get passwordHint => 'Кемінде 8 таңба';

  @override
  String get acceptOfferPrefix => 'Мен қабылдаймын:';

  @override
  String get offerLink => 'пайдаланушы келісімі';

  @override
  String get noAccount => 'Аккаунтыңыз жоқ па?';

  @override
  String get createAccount => 'Тіркелу';

  @override
  String get haveAccount => 'Аккаунтыңыз бар ма? Кіру';

  @override
  String get forgotPassword => 'Құпиясөзді ұмыттыңыз ба?';

  @override
  String get forgotTitle => 'Құпиясөзді қалпына келтіру';

  @override
  String get forgotHint =>
      'Аккаунт email-ін көрсетіңіз — жаңа құпиясөз жасау сілтемесін жібереміз.';

  @override
  String get sendLink => 'Сілтемені жіберу';

  @override
  String get orDivider => 'немесе';

  @override
  String get appleUnavailable => 'Бұл құрылғыда Apple арқылы кіру қолжетімсіз';

  @override
  String get invalidEmail => 'Дұрыс email енгізіңіз';

  @override
  String get passwordTooShort => 'Құпиясөз кемінде 8 таңбадан тұруы керек';

  @override
  String get offerRequired => 'Пайдаланушы келісімін қабылдаңыз';

  @override
  String registrationBonus(int points) {
    final intl.NumberFormat pointsNumberFormat =
        intl.NumberFormat.decimalPattern(localeName);
    final String pointsString = pointsNumberFormat.format(points);

    return 'Тіркелгеніңіз үшін $pointsString бонус берілді!';
  }

  @override
  String get appleSignIn => 'Apple арқылы кіру';

  @override
  String get typeNew => 'Жаңа тауар';

  @override
  String get typeUsed => 'Қолданылған тауар';

  @override
  String get typeAuction => 'Аукцион';

  @override
  String get typeService => 'Қызметтер';

  @override
  String get typeExchange => 'Бартер';

  @override
  String get typeFree => 'Тегін';

  @override
  String get typeGig => 'Қызметтер биржасы';

  @override
  String get deleteAccount => 'Аккаунтты жою';

  @override
  String get deleteAccountTitle => 'Аккаунтты жою';

  @override
  String get deleteAccountIntro =>
      'Аккаунт қалпына келтіру мүмкіндігінсіз жойылады:';

  @override
  String get deleteAccountPoint1 =>
      'аты, email, телефон, ЖСН/БСН, мекенжайлар, профиль суреті және құпиясөз өшіріледі;';

  @override
  String get deleteAccountPoint2 =>
      'хабарландырулар жариялаудан алынады, таңдаулылар мен жазылымдар жойылады;';

  @override
  String get deleteAccountPoint3 =>
      'аяқталған мәмілелер тарихы иесіз түрде сақталады — ол екінші тарапқа және төлемдер есебіне қажет;';

  @override
  String get deleteAccountPoint4 =>
      'бонус ұпайлары күйеді. Сол email-мен қайта тіркелуге болады.';

  @override
  String get deleteAccountBlocked => 'Қазір аккаунтты жою мүмкін емес:';

  @override
  String get deleteAccountConfirm => 'Жою қайтымсыз екенін түсінемін';

  @override
  String get deleteAccountPasswordHint => 'Растау үшін құпиясөзді енгізіңіз';

  @override
  String get deleteAccountReauthHint =>
      'Растау үшін қайта кіріп (мысалы, Apple арқылы), бірден осы экранға оралыңыз.';

  @override
  String get deleteAccountReauthButton => 'Қайта кіру';

  @override
  String get deleteAccountButton => 'Аккаунтты біржола жою';

  @override
  String get accountDeleted => 'Аккаунт жойылды';

  @override
  String get myListings => 'Менің хабарландыруларым';

  @override
  String get newListing => 'Хабарландыру беру';

  @override
  String get editListing => 'Хабарландыруды өңдеу';

  @override
  String get listingType => 'Хабарландыру түрі';

  @override
  String get listingTitle => 'Атауы';

  @override
  String get listingDescription => 'Сипаттама';

  @override
  String get listingPrice => 'Бағасы, ₸';

  @override
  String get listingCategory => 'Санат';

  @override
  String get chooseCategory => 'Санатты таңдаңыз';

  @override
  String get listingQuantity => 'Саны';

  @override
  String get listingExchangeFor => 'Орнына не алғыңыз келеді';

  @override
  String get listingLocation => 'Қала';

  @override
  String get listingCharacteristics => 'Сипаттамалар (міндетті емес)';

  @override
  String get listingCharacteristicsHint => 'Әр жолға біреуден: «Түсі: қара»';

  @override
  String get photos => 'Фото';

  @override
  String photosHint(int max) {
    return '$max фотоға дейін. Мұқаба ету үшін фотоны басыңыз.';
  }

  @override
  String get addPhotoGallery => 'Галереядан';

  @override
  String get addPhotoCamera => 'Камера';

  @override
  String get coverBadge => 'Мұқаба';

  @override
  String get removePhoto => 'Фотоны өшіру';

  @override
  String get iinLabel => 'ЖСН (12 сан)';

  @override
  String get binLabel => 'БСН (12 сан)';

  @override
  String get taxIdHint =>
      'Сатушыны тексеру үшін бір рет қажет (ҚР ҚМА талабы). Серверде сақталады, сатып алушыларға көрсетілмейді.';

  @override
  String get acceptListingRules =>
      'Хабарландыру орналастыру ережелерін қабылдаймын';

  @override
  String get pickupOnlyNote =>
      'Қолданбадан хабарландыру өзі алып кету шартымен жарияланады. CDEK жеткізуін, аукциондарды және ақылы қызметтерді әзірге сайтта баптауға болады.';

  @override
  String get publish => 'Жариялау';

  @override
  String get save => 'Сақтау';

  @override
  String get listingPublished => 'Хабарландыру жарияланды';

  @override
  String get listingSaved => 'Өзгерістер сақталды';

  @override
  String listingBonus(int points) {
    return 'Хабарландыру үшін +$points бонус';
  }

  @override
  String get deleteListing => 'Хабарландыруды жою';

  @override
  String get deleteListingConfirm =>
      'Бұл хабарландыруды жою керек пе? Бұл әрекетті болдырмау мүмкін емес.';

  @override
  String get cancel => 'Болдырмау';

  @override
  String get delete => 'Жою';

  @override
  String get listingDeleted => 'Хабарландыру жойылды';

  @override
  String get emptyMyListings => 'Сізде әзірге хабарландыру жоқ';

  @override
  String get editOnSite => 'Бұл хабарландыру түрі сайтта өңделеді';

  @override
  String get statusActive => 'Белсенді';

  @override
  String get statusSold => 'Сатылды';

  @override
  String get statusArchived => 'Мұрағатта';

  @override
  String get statusReserved => 'Броньдалған';

  @override
  String get statusOutOfStock => 'Қоймада жоқ';

  @override
  String get needPhoto => 'Кемінде бір фото қосыңыз';

  @override
  String uploading(int percent) {
    return 'Жүктелуде $percent%';
  }

  @override
  String get publishBlocked => 'Жариялау қолжетімсіз';

  @override
  String get listingRulesRequired =>
      'Хабарландыру орналастыру ережелерін қабылдаңыз';

  @override
  String get navCart => 'Себет';

  @override
  String get addToCart => 'Себетке';

  @override
  String get inCart => 'Себетте';

  @override
  String get buyNow => 'Сатып алу';

  @override
  String get addedToCart => 'Себетке қосылды';

  @override
  String get emptyCart => 'Себет бос';

  @override
  String get cartTotal => 'Барлығы';

  @override
  String get checkoutAll => 'Тапсырысты рәсімдеу';

  @override
  String get remove => 'Өшіру';

  @override
  String get checkoutTitle => 'Тапсырысты рәсімдеу';

  @override
  String get dealSafe => 'Қауіпсіз мәміле';

  @override
  String dealSafeHint(int hours) {
    return 'Алғаныңызды растағанша ақша платформада сақталады. Алғаннан кейін тексеруге және дауласуға $hours сағат бар.';
  }

  @override
  String get dealDirect => 'Сатушыға тікелей';

  @override
  String get dealDirectHint =>
      'Мәміле қорғауынсыз және алымсыз. Ақша бірден сатушыға кетеді, оны платформа арқылы қайтару мүмкін емес.';

  @override
  String get dealFee => 'Қауіпсіз мәміле алымы';

  @override
  String get subtotal => 'Тауарлар';

  @override
  String get totalToPay => 'Төлеуге';

  @override
  String get delivery => 'Алу тәсілі';

  @override
  String get deliveryPickup => 'Өзі алып кету';

  @override
  String get deliveryKazpost => 'Қазпошта';

  @override
  String get deliveryCourier => 'Курьер';

  @override
  String get deliveryOther => 'Басқа';

  @override
  String get deliveryCdek => 'CDEK';

  @override
  String get deliveryDigital => 'Цифрлық тауар';

  @override
  String get deliveryCdekOnSite =>
      'CDEK жеткізуін әзірге тек сайтта рәсімдеуге болады';

  @override
  String get payment => 'Төлем';

  @override
  String get payCard => 'Картамен (Halyk ePay)';

  @override
  String get payCardSimulated => 'Картамен (сынақ режимі)';

  @override
  String get payWallet => 'Әмияннан';

  @override
  String walletBalance(String amount) {
    return 'Баланс: $amount';
  }

  @override
  String get walletNotEnough => 'Әмиянда қаражат жеткіліксіз';

  @override
  String payButton(String amount) {
    return '$amount төлеу';
  }

  @override
  String get paymentOpening => 'Төлем беті ашылуда…';

  @override
  String get paymentCheck => 'Төлем тексерілуде…';

  @override
  String get paymentCancelled =>
      'Төлем аяқталмады. Тапсырыс төлемді күтуде — оны «Менің тапсырыстарым» бөлімінде болдырмауға болады.';

  @override
  String get orderPlaced => 'Тапсырыс рәсімделді';

  @override
  String get myOrders => 'Менің тапсырыстарым';

  @override
  String get ordersBuying => 'Сатып алулар';

  @override
  String get ordersSelling => 'Сатулар';

  @override
  String get emptyOrders => 'Әзірге тапсырыс жоқ';

  @override
  String orderTitle(int id) {
    return '№$id тапсырыс';
  }

  @override
  String get orderSeller => 'Сатушы';

  @override
  String get orderBuyer => 'Сатып алушы';

  @override
  String orderQty(int qty) {
    return 'Саны: $qty';
  }

  @override
  String get orderTracking => 'Трек-нөмір';

  @override
  String orderInspectUntil(String date) {
    return '$date дейін тексеру';
  }

  @override
  String get orderPayout => 'Сіз аласыз (10% комиссиясыз)';

  @override
  String get openOnSiteDetails => 'Толығырақ сайтта';

  @override
  String get actionShip => 'Тауарды жібердім';

  @override
  String get actionDelivered => 'Тауарды алдым';

  @override
  String get actionConfirm => 'Растау, бәрі дұрыс';

  @override
  String get actionCancel => 'Тапсырысты болдырмау';

  @override
  String get actionDispute => 'Қайтару немесе дау';

  @override
  String get shipTitle => 'Тауарды жіберу';

  @override
  String get shipTracking => 'Трек-нөмір немесе жүкқұжат нөмірі';

  @override
  String get shipCarrier => 'Жеткізу қызметі (міндетті емес)';

  @override
  String get confirmTitle => 'Алғаныңызды растайсыз ба?';

  @override
  String get confirmText =>
      'Ақша сатушыға аударылады. Мұны болдырмау мүмкін емес.';

  @override
  String get cancelTitle => 'Тапсырысты болдырмау керек пе?';

  @override
  String get cancelText =>
      'Тапсырыс төленген болса, ақша Zakopeyki әмияныңызға қайтарылады.';

  @override
  String get deliveredTitle => 'Тауарды алдыңыз ба?';

  @override
  String get deliveredText =>
      'Тауарды тексеру мерзімі басталады. Бір нәрсе дұрыс болмаса — мерзім біткенге дейін дау ашыңыз.';

  @override
  String get disputeTitle => 'Қайтару немесе дау';

  @override
  String get disputeReason => 'Себебі';

  @override
  String get disputeComment => 'Мәселені сипаттаңыз';

  @override
  String get disputeSend => 'Жіберу';

  @override
  String get reason_not_as_described => 'Сипаттамаға сәйкес емес';

  @override
  String get reason_not_received => 'Тауарды алмадым';

  @override
  String get reason_changed_mind => 'Ойымнан айныдым';

  @override
  String get reason_courier_void => 'Курьер жеткізгенде бас тарту';

  @override
  String get reason_digital_defect => 'Цифрлық тауардағы мәселе';

  @override
  String get confirmAction => 'Растау';

  @override
  String get back => 'Артқа';

  @override
  String get ordersOtherOnSite =>
      'Қайтару бойынша келіссөздер және арбитраж — сайтта.';

  @override
  String get retryPayment => 'Төлемді тексеру';
}
