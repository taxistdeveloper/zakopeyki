import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_en.dart';
import 'app_localizations_kk.dart';
import 'app_localizations_ru.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations)!;
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('en'),
    Locale('kk'),
    Locale('ru'),
  ];

  /// No description provided for @appTitle.
  ///
  /// In ru, this message translates to:
  /// **'Zakopeyki.kz'**
  String get appTitle;

  /// No description provided for @navHome.
  ///
  /// In ru, this message translates to:
  /// **'Главная'**
  String get navHome;

  /// No description provided for @navFavorites.
  ///
  /// In ru, this message translates to:
  /// **'Избранное'**
  String get navFavorites;

  /// No description provided for @navProfile.
  ///
  /// In ru, this message translates to:
  /// **'Профиль'**
  String get navProfile;

  /// No description provided for @searchHint.
  ///
  /// In ru, this message translates to:
  /// **'Поиск товаров и услуг'**
  String get searchHint;

  /// No description provided for @allTypes.
  ///
  /// In ru, this message translates to:
  /// **'Все'**
  String get allTypes;

  /// No description provided for @retry.
  ///
  /// In ru, this message translates to:
  /// **'Повторить'**
  String get retry;

  /// No description provided for @loadError.
  ///
  /// In ru, this message translates to:
  /// **'Не удалось загрузить данные'**
  String get loadError;

  /// No description provided for @networkError.
  ///
  /// In ru, this message translates to:
  /// **'Нет соединения с сервером. Проверьте интернет.'**
  String get networkError;

  /// No description provided for @emptyFeed.
  ///
  /// In ru, this message translates to:
  /// **'Ничего не найдено'**
  String get emptyFeed;

  /// No description provided for @emptyFavorites.
  ///
  /// In ru, this message translates to:
  /// **'В избранном пока пусто'**
  String get emptyFavorites;

  /// No description provided for @loginTitle.
  ///
  /// In ru, this message translates to:
  /// **'Вход'**
  String get loginTitle;

  /// No description provided for @loginSubtitle.
  ///
  /// In ru, this message translates to:
  /// **'Войдите, чтобы сохранять избранное и совершать покупки'**
  String get loginSubtitle;

  /// No description provided for @email.
  ///
  /// In ru, this message translates to:
  /// **'Email'**
  String get email;

  /// No description provided for @password.
  ///
  /// In ru, this message translates to:
  /// **'Пароль'**
  String get password;

  /// No description provided for @loginButton.
  ///
  /// In ru, this message translates to:
  /// **'Войти'**
  String get loginButton;

  /// No description provided for @fieldRequired.
  ///
  /// In ru, this message translates to:
  /// **'Обязательное поле'**
  String get fieldRequired;

  /// No description provided for @twoFactorTitle.
  ///
  /// In ru, this message translates to:
  /// **'Подтверждение входа'**
  String get twoFactorTitle;

  /// No description provided for @twoFactorHint.
  ///
  /// In ru, this message translates to:
  /// **'Введите 6-значный код из приложения-аутентификатора или резервный код'**
  String get twoFactorHint;

  /// No description provided for @code.
  ///
  /// In ru, this message translates to:
  /// **'Код'**
  String get code;

  /// No description provided for @confirm.
  ///
  /// In ru, this message translates to:
  /// **'Подтвердить'**
  String get confirm;

  /// No description provided for @logout.
  ///
  /// In ru, this message translates to:
  /// **'Выйти'**
  String get logout;

  /// No description provided for @logoutAll.
  ///
  /// In ru, this message translates to:
  /// **'Выйти на всех устройствах'**
  String get logoutAll;

  /// No description provided for @language.
  ///
  /// In ru, this message translates to:
  /// **'Язык'**
  String get language;

  /// No description provided for @loginRequired.
  ///
  /// In ru, this message translates to:
  /// **'Войдите в аккаунт'**
  String get loginRequired;

  /// No description provided for @loginRequiredFavorites.
  ///
  /// In ru, this message translates to:
  /// **'Войдите, чтобы видеть избранное'**
  String get loginRequiredFavorites;

  /// No description provided for @description.
  ///
  /// In ru, this message translates to:
  /// **'Описание'**
  String get description;

  /// No description provided for @characteristics.
  ///
  /// In ru, this message translates to:
  /// **'Характеристики'**
  String get characteristics;

  /// No description provided for @exchangeFor.
  ///
  /// In ru, this message translates to:
  /// **'Обменяю на'**
  String get exchangeFor;

  /// No description provided for @seller.
  ///
  /// In ru, this message translates to:
  /// **'Продавец'**
  String get seller;

  /// No description provided for @business.
  ///
  /// In ru, this message translates to:
  /// **'Бизнес'**
  String get business;

  /// No description provided for @ratingCount.
  ///
  /// In ru, this message translates to:
  /// **'{count, plural, =0{нет отзывов} one{{count} отзыв} few{{count} отзыва} other{{count} отзывов}}'**
  String ratingCount(int count);

  /// No description provided for @inStock.
  ///
  /// In ru, this message translates to:
  /// **'В наличии: {count}'**
  String inStock(int count);

  /// No description provided for @buyOnSite.
  ///
  /// In ru, this message translates to:
  /// **'Купить на сайте'**
  String get buyOnSite;

  /// No description provided for @openOnSite.
  ///
  /// In ru, this message translates to:
  /// **'Открыть на сайте'**
  String get openOnSite;

  /// No description provided for @safeDealInfo.
  ///
  /// In ru, this message translates to:
  /// **'Безопасная сделка: деньги удерживаются до подтверждения получения. Сбор покупателя {percent}%.'**
  String safeDealInfo(int percent);

  /// No description provided for @auctionEnds.
  ///
  /// In ru, this message translates to:
  /// **'Аукцион до {date}'**
  String auctionEnds(String date);

  /// No description provided for @siteClosedTitle.
  ///
  /// In ru, this message translates to:
  /// **'Скоро открытие'**
  String get siteClosedTitle;

  /// No description provided for @siteClosedText.
  ///
  /// In ru, this message translates to:
  /// **'Zakopeyki.kz скоро откроется. Доступ пока открыт только участникам закрытого тестирования.'**
  String get siteClosedText;

  /// No description provided for @legalOffer.
  ///
  /// In ru, this message translates to:
  /// **'Пользовательское соглашение'**
  String get legalOffer;

  /// No description provided for @legalPrivacy.
  ///
  /// In ru, this message translates to:
  /// **'Политика конфиденциальности'**
  String get legalPrivacy;

  /// No description provided for @views.
  ///
  /// In ru, this message translates to:
  /// **'Просмотры: {count}'**
  String views(int count);

  /// No description provided for @addedToFavorites.
  ///
  /// In ru, this message translates to:
  /// **'Добавлено в избранное'**
  String get addedToFavorites;

  /// No description provided for @removedFromFavorites.
  ///
  /// In ru, this message translates to:
  /// **'Удалено из избранного'**
  String get removedFromFavorites;

  /// No description provided for @sessionExpired.
  ///
  /// In ru, this message translates to:
  /// **'Сессия завершена. Войдите заново.'**
  String get sessionExpired;

  /// No description provided for @registerTitle.
  ///
  /// In ru, this message translates to:
  /// **'Регистрация'**
  String get registerTitle;

  /// No description provided for @registerButton.
  ///
  /// In ru, this message translates to:
  /// **'Зарегистрироваться'**
  String get registerButton;

  /// No description provided for @name.
  ///
  /// In ru, this message translates to:
  /// **'Имя'**
  String get name;

  /// No description provided for @phoneOptional.
  ///
  /// In ru, this message translates to:
  /// **'Телефон (необязательно)'**
  String get phoneOptional;

  /// No description provided for @passwordHint.
  ///
  /// In ru, this message translates to:
  /// **'Не короче 8 символов'**
  String get passwordHint;

  /// No description provided for @acceptOfferPrefix.
  ///
  /// In ru, this message translates to:
  /// **'Я принимаю'**
  String get acceptOfferPrefix;

  /// No description provided for @offerLink.
  ///
  /// In ru, this message translates to:
  /// **'пользовательское соглашение'**
  String get offerLink;

  /// No description provided for @noAccount.
  ///
  /// In ru, this message translates to:
  /// **'Нет аккаунта?'**
  String get noAccount;

  /// No description provided for @createAccount.
  ///
  /// In ru, this message translates to:
  /// **'Зарегистрироваться'**
  String get createAccount;

  /// No description provided for @haveAccount.
  ///
  /// In ru, this message translates to:
  /// **'Уже есть аккаунт? Войти'**
  String get haveAccount;

  /// No description provided for @forgotPassword.
  ///
  /// In ru, this message translates to:
  /// **'Забыли пароль?'**
  String get forgotPassword;

  /// No description provided for @forgotTitle.
  ///
  /// In ru, this message translates to:
  /// **'Восстановление пароля'**
  String get forgotTitle;

  /// No description provided for @forgotHint.
  ///
  /// In ru, this message translates to:
  /// **'Укажите email аккаунта — мы отправим ссылку для создания нового пароля.'**
  String get forgotHint;

  /// No description provided for @sendLink.
  ///
  /// In ru, this message translates to:
  /// **'Отправить ссылку'**
  String get sendLink;

  /// No description provided for @orDivider.
  ///
  /// In ru, this message translates to:
  /// **'или'**
  String get orDivider;

  /// No description provided for @appleUnavailable.
  ///
  /// In ru, this message translates to:
  /// **'Вход через Apple недоступен на этом устройстве'**
  String get appleUnavailable;

  /// No description provided for @invalidEmail.
  ///
  /// In ru, this message translates to:
  /// **'Введите корректный email'**
  String get invalidEmail;

  /// No description provided for @passwordTooShort.
  ///
  /// In ru, this message translates to:
  /// **'Пароль должен быть не короче 8 символов'**
  String get passwordTooShort;

  /// No description provided for @offerRequired.
  ///
  /// In ru, this message translates to:
  /// **'Примите пользовательское соглашение'**
  String get offerRequired;

  /// No description provided for @registrationBonus.
  ///
  /// In ru, this message translates to:
  /// **'Вам начислено {points} бонусов за регистрацию!'**
  String registrationBonus(int points);

  /// No description provided for @appleSignIn.
  ///
  /// In ru, this message translates to:
  /// **'Вход с Apple'**
  String get appleSignIn;

  /// No description provided for @typeNew.
  ///
  /// In ru, this message translates to:
  /// **'Новый товар'**
  String get typeNew;

  /// No description provided for @typeUsed.
  ///
  /// In ru, this message translates to:
  /// **'Товар Б/У'**
  String get typeUsed;

  /// No description provided for @typeAuction.
  ///
  /// In ru, this message translates to:
  /// **'Аукцион'**
  String get typeAuction;

  /// No description provided for @typeService.
  ///
  /// In ru, this message translates to:
  /// **'Услуги'**
  String get typeService;

  /// No description provided for @typeExchange.
  ///
  /// In ru, this message translates to:
  /// **'Бартер'**
  String get typeExchange;

  /// No description provided for @typeFree.
  ///
  /// In ru, this message translates to:
  /// **'Даром'**
  String get typeFree;

  /// No description provided for @typeGig.
  ///
  /// In ru, this message translates to:
  /// **'Биржа услуг'**
  String get typeGig;

  /// No description provided for @deleteAccount.
  ///
  /// In ru, this message translates to:
  /// **'Удалить аккаунт'**
  String get deleteAccount;

  /// No description provided for @deleteAccountTitle.
  ///
  /// In ru, this message translates to:
  /// **'Удаление аккаунта'**
  String get deleteAccountTitle;

  /// No description provided for @deleteAccountIntro.
  ///
  /// In ru, this message translates to:
  /// **'Аккаунт будет удалён без возможности восстановления:'**
  String get deleteAccountIntro;

  /// No description provided for @deleteAccountPoint1.
  ///
  /// In ru, this message translates to:
  /// **'имя, email, телефон, ИИН/БИН, адреса, фото профиля и пароль будут стёрты;'**
  String get deleteAccountPoint1;

  /// No description provided for @deleteAccountPoint2.
  ///
  /// In ru, this message translates to:
  /// **'объявления снимутся с публикации, избранное и подписки удалятся;'**
  String get deleteAccountPoint2;

  /// No description provided for @deleteAccountPoint3.
  ///
  /// In ru, this message translates to:
  /// **'история завершённых сделок сохранится в обезличенном виде — она нужна второй стороне и для учёта платежей;'**
  String get deleteAccountPoint3;

  /// No description provided for @deleteAccountPoint4.
  ///
  /// In ru, this message translates to:
  /// **'бонусные баллы сгорят. Тот же email можно будет зарегистрировать снова.'**
  String get deleteAccountPoint4;

  /// No description provided for @deleteAccountBlocked.
  ///
  /// In ru, this message translates to:
  /// **'Сейчас удалить аккаунт нельзя:'**
  String get deleteAccountBlocked;

  /// No description provided for @deleteAccountConfirm.
  ///
  /// In ru, this message translates to:
  /// **'Я понимаю, что удаление необратимо'**
  String get deleteAccountConfirm;

  /// No description provided for @deleteAccountPasswordHint.
  ///
  /// In ru, this message translates to:
  /// **'Введите пароль для подтверждения'**
  String get deleteAccountPasswordHint;

  /// No description provided for @deleteAccountReauthHint.
  ///
  /// In ru, this message translates to:
  /// **'Для подтверждения войдите заново (например, через Apple) и сразу вернитесь на этот экран.'**
  String get deleteAccountReauthHint;

  /// No description provided for @deleteAccountReauthButton.
  ///
  /// In ru, this message translates to:
  /// **'Войти заново'**
  String get deleteAccountReauthButton;

  /// No description provided for @deleteAccountButton.
  ///
  /// In ru, this message translates to:
  /// **'Удалить аккаунт навсегда'**
  String get deleteAccountButton;

  /// No description provided for @accountDeleted.
  ///
  /// In ru, this message translates to:
  /// **'Аккаунт удалён'**
  String get accountDeleted;

  /// No description provided for @myListings.
  ///
  /// In ru, this message translates to:
  /// **'Мои объявления'**
  String get myListings;

  /// No description provided for @newListing.
  ///
  /// In ru, this message translates to:
  /// **'Подать объявление'**
  String get newListing;

  /// No description provided for @editListing.
  ///
  /// In ru, this message translates to:
  /// **'Редактировать объявление'**
  String get editListing;

  /// No description provided for @listingType.
  ///
  /// In ru, this message translates to:
  /// **'Тип объявления'**
  String get listingType;

  /// No description provided for @listingTitle.
  ///
  /// In ru, this message translates to:
  /// **'Название'**
  String get listingTitle;

  /// No description provided for @listingDescription.
  ///
  /// In ru, this message translates to:
  /// **'Описание'**
  String get listingDescription;

  /// No description provided for @listingPrice.
  ///
  /// In ru, this message translates to:
  /// **'Цена, ₸'**
  String get listingPrice;

  /// No description provided for @listingCategory.
  ///
  /// In ru, this message translates to:
  /// **'Категория'**
  String get listingCategory;

  /// No description provided for @chooseCategory.
  ///
  /// In ru, this message translates to:
  /// **'Выберите категорию'**
  String get chooseCategory;

  /// No description provided for @listingQuantity.
  ///
  /// In ru, this message translates to:
  /// **'Количество'**
  String get listingQuantity;

  /// No description provided for @listingExchangeFor.
  ///
  /// In ru, this message translates to:
  /// **'Что хотите получить взамен'**
  String get listingExchangeFor;

  /// No description provided for @listingLocation.
  ///
  /// In ru, this message translates to:
  /// **'Город'**
  String get listingLocation;

  /// No description provided for @listingCharacteristics.
  ///
  /// In ru, this message translates to:
  /// **'Характеристики (необязательно)'**
  String get listingCharacteristics;

  /// No description provided for @listingCharacteristicsHint.
  ///
  /// In ru, this message translates to:
  /// **'По одной на строку: «Цвет: чёрный»'**
  String get listingCharacteristicsHint;

  /// No description provided for @photos.
  ///
  /// In ru, this message translates to:
  /// **'Фото'**
  String get photos;

  /// No description provided for @photosHint.
  ///
  /// In ru, this message translates to:
  /// **'До {max} фото. Нажмите на фото, чтобы сделать его обложкой.'**
  String photosHint(int max);

  /// No description provided for @addPhotoGallery.
  ///
  /// In ru, this message translates to:
  /// **'Из галереи'**
  String get addPhotoGallery;

  /// No description provided for @addPhotoCamera.
  ///
  /// In ru, this message translates to:
  /// **'Камера'**
  String get addPhotoCamera;

  /// No description provided for @coverBadge.
  ///
  /// In ru, this message translates to:
  /// **'Обложка'**
  String get coverBadge;

  /// No description provided for @removePhoto.
  ///
  /// In ru, this message translates to:
  /// **'Удалить фото'**
  String get removePhoto;

  /// No description provided for @iinLabel.
  ///
  /// In ru, this message translates to:
  /// **'ИИН (12 цифр)'**
  String get iinLabel;

  /// No description provided for @binLabel.
  ///
  /// In ru, this message translates to:
  /// **'БИН (12 цифр)'**
  String get binLabel;

  /// No description provided for @taxIdHint.
  ///
  /// In ru, this message translates to:
  /// **'Нужен один раз для проверки продавца (требование АФМ РК). Хранится на сервере и не показывается покупателям.'**
  String get taxIdHint;

  /// No description provided for @acceptListingRules.
  ///
  /// In ru, this message translates to:
  /// **'Я принимаю правила размещения объявлений'**
  String get acceptListingRules;

  /// No description provided for @pickupOnlyNote.
  ///
  /// In ru, this message translates to:
  /// **'Из приложения объявление публикуется с самовывозом. Доставку CDEK, аукционы и платные услуги пока можно настроить на сайте.'**
  String get pickupOnlyNote;

  /// No description provided for @publish.
  ///
  /// In ru, this message translates to:
  /// **'Опубликовать'**
  String get publish;

  /// No description provided for @save.
  ///
  /// In ru, this message translates to:
  /// **'Сохранить'**
  String get save;

  /// No description provided for @listingPublished.
  ///
  /// In ru, this message translates to:
  /// **'Объявление опубликовано'**
  String get listingPublished;

  /// No description provided for @listingSaved.
  ///
  /// In ru, this message translates to:
  /// **'Изменения сохранены'**
  String get listingSaved;

  /// No description provided for @listingBonus.
  ///
  /// In ru, this message translates to:
  /// **'+{points} бонусов за объявление'**
  String listingBonus(int points);

  /// No description provided for @deleteListing.
  ///
  /// In ru, this message translates to:
  /// **'Удалить объявление'**
  String get deleteListing;

  /// No description provided for @deleteListingConfirm.
  ///
  /// In ru, this message translates to:
  /// **'Удалить это объявление? Это действие нельзя отменить.'**
  String get deleteListingConfirm;

  /// No description provided for @cancel.
  ///
  /// In ru, this message translates to:
  /// **'Отмена'**
  String get cancel;

  /// No description provided for @delete.
  ///
  /// In ru, this message translates to:
  /// **'Удалить'**
  String get delete;

  /// No description provided for @listingDeleted.
  ///
  /// In ru, this message translates to:
  /// **'Объявление удалено'**
  String get listingDeleted;

  /// No description provided for @emptyMyListings.
  ///
  /// In ru, this message translates to:
  /// **'У вас пока нет объявлений'**
  String get emptyMyListings;

  /// No description provided for @editOnSite.
  ///
  /// In ru, this message translates to:
  /// **'Этот тип объявления редактируется на сайте'**
  String get editOnSite;

  /// No description provided for @statusActive.
  ///
  /// In ru, this message translates to:
  /// **'Активно'**
  String get statusActive;

  /// No description provided for @statusSold.
  ///
  /// In ru, this message translates to:
  /// **'Продано'**
  String get statusSold;

  /// No description provided for @statusArchived.
  ///
  /// In ru, this message translates to:
  /// **'В архиве'**
  String get statusArchived;

  /// No description provided for @statusReserved.
  ///
  /// In ru, this message translates to:
  /// **'Забронировано'**
  String get statusReserved;

  /// No description provided for @statusOutOfStock.
  ///
  /// In ru, this message translates to:
  /// **'Нет в наличии'**
  String get statusOutOfStock;

  /// No description provided for @needPhoto.
  ///
  /// In ru, this message translates to:
  /// **'Добавьте хотя бы одно фото'**
  String get needPhoto;

  /// No description provided for @uploading.
  ///
  /// In ru, this message translates to:
  /// **'Загрузка {percent}%'**
  String uploading(int percent);

  /// No description provided for @publishBlocked.
  ///
  /// In ru, this message translates to:
  /// **'Публикация недоступна'**
  String get publishBlocked;

  /// No description provided for @listingRulesRequired.
  ///
  /// In ru, this message translates to:
  /// **'Примите правила размещения объявлений'**
  String get listingRulesRequired;

  /// No description provided for @navCart.
  ///
  /// In ru, this message translates to:
  /// **'Корзина'**
  String get navCart;

  /// No description provided for @addToCart.
  ///
  /// In ru, this message translates to:
  /// **'В корзину'**
  String get addToCart;

  /// No description provided for @inCart.
  ///
  /// In ru, this message translates to:
  /// **'В корзине'**
  String get inCart;

  /// No description provided for @buyNow.
  ///
  /// In ru, this message translates to:
  /// **'Купить'**
  String get buyNow;

  /// No description provided for @addedToCart.
  ///
  /// In ru, this message translates to:
  /// **'Добавлено в корзину'**
  String get addedToCart;

  /// No description provided for @emptyCart.
  ///
  /// In ru, this message translates to:
  /// **'Корзина пуста'**
  String get emptyCart;

  /// No description provided for @cartTotal.
  ///
  /// In ru, this message translates to:
  /// **'Итого'**
  String get cartTotal;

  /// No description provided for @checkoutAll.
  ///
  /// In ru, this message translates to:
  /// **'Оформить заказ'**
  String get checkoutAll;

  /// No description provided for @remove.
  ///
  /// In ru, this message translates to:
  /// **'Удалить'**
  String get remove;

  /// No description provided for @checkoutTitle.
  ///
  /// In ru, this message translates to:
  /// **'Оформление заказа'**
  String get checkoutTitle;

  /// No description provided for @dealSafe.
  ///
  /// In ru, this message translates to:
  /// **'Безопасная сделка'**
  String get dealSafe;

  /// No description provided for @dealSafeHint.
  ///
  /// In ru, this message translates to:
  /// **'Деньги хранятся на платформе, пока вы не подтвердите получение. После получения есть {hours} ч на проверку и спор.'**
  String dealSafeHint(int hours);

  /// No description provided for @dealDirect.
  ///
  /// In ru, this message translates to:
  /// **'Напрямую продавцу'**
  String get dealDirect;

  /// No description provided for @dealDirectHint.
  ///
  /// In ru, this message translates to:
  /// **'Без защиты сделки и сбора. Деньги сразу уходят продавцу, вернуть их через платформу нельзя.'**
  String get dealDirectHint;

  /// No description provided for @dealFee.
  ///
  /// In ru, this message translates to:
  /// **'Сбор за безопасную сделку'**
  String get dealFee;

  /// No description provided for @subtotal.
  ///
  /// In ru, this message translates to:
  /// **'Товары'**
  String get subtotal;

  /// No description provided for @totalToPay.
  ///
  /// In ru, this message translates to:
  /// **'К оплате'**
  String get totalToPay;

  /// No description provided for @delivery.
  ///
  /// In ru, this message translates to:
  /// **'Получение'**
  String get delivery;

  /// No description provided for @deliveryPickup.
  ///
  /// In ru, this message translates to:
  /// **'Самовывоз'**
  String get deliveryPickup;

  /// No description provided for @deliveryKazpost.
  ///
  /// In ru, this message translates to:
  /// **'Казпочта'**
  String get deliveryKazpost;

  /// No description provided for @deliveryCourier.
  ///
  /// In ru, this message translates to:
  /// **'Курьер'**
  String get deliveryCourier;

  /// No description provided for @deliveryOther.
  ///
  /// In ru, this message translates to:
  /// **'Другое'**
  String get deliveryOther;

  /// No description provided for @deliveryCdek.
  ///
  /// In ru, this message translates to:
  /// **'CDEK'**
  String get deliveryCdek;

  /// No description provided for @deliveryDigital.
  ///
  /// In ru, this message translates to:
  /// **'Цифровой товар'**
  String get deliveryDigital;

  /// No description provided for @deliveryCdekOnSite.
  ///
  /// In ru, this message translates to:
  /// **'Доставку CDEK пока можно оформить только на сайте'**
  String get deliveryCdekOnSite;

  /// No description provided for @payment.
  ///
  /// In ru, this message translates to:
  /// **'Оплата'**
  String get payment;

  /// No description provided for @payCard.
  ///
  /// In ru, this message translates to:
  /// **'Картой (Halyk ePay)'**
  String get payCard;

  /// No description provided for @payCardSimulated.
  ///
  /// In ru, this message translates to:
  /// **'Картой (тестовый режим)'**
  String get payCardSimulated;

  /// No description provided for @payWallet.
  ///
  /// In ru, this message translates to:
  /// **'С кошелька'**
  String get payWallet;

  /// No description provided for @walletBalance.
  ///
  /// In ru, this message translates to:
  /// **'Баланс: {amount}'**
  String walletBalance(String amount);

  /// No description provided for @walletNotEnough.
  ///
  /// In ru, this message translates to:
  /// **'Недостаточно средств на кошельке'**
  String get walletNotEnough;

  /// No description provided for @payButton.
  ///
  /// In ru, this message translates to:
  /// **'Оплатить {amount}'**
  String payButton(String amount);

  /// No description provided for @paymentOpening.
  ///
  /// In ru, this message translates to:
  /// **'Открываем страницу оплаты…'**
  String get paymentOpening;

  /// No description provided for @paymentCheck.
  ///
  /// In ru, this message translates to:
  /// **'Проверяем оплату…'**
  String get paymentCheck;

  /// No description provided for @paymentCancelled.
  ///
  /// In ru, this message translates to:
  /// **'Оплата не завершена. Заказ ждёт оплаты — его можно отменить в «Мои заказы».'**
  String get paymentCancelled;

  /// No description provided for @orderPlaced.
  ///
  /// In ru, this message translates to:
  /// **'Заказ оформлен'**
  String get orderPlaced;

  /// No description provided for @myOrders.
  ///
  /// In ru, this message translates to:
  /// **'Мои заказы'**
  String get myOrders;

  /// No description provided for @ordersBuying.
  ///
  /// In ru, this message translates to:
  /// **'Покупки'**
  String get ordersBuying;

  /// No description provided for @ordersSelling.
  ///
  /// In ru, this message translates to:
  /// **'Продажи'**
  String get ordersSelling;

  /// No description provided for @emptyOrders.
  ///
  /// In ru, this message translates to:
  /// **'Заказов пока нет'**
  String get emptyOrders;

  /// No description provided for @orderTitle.
  ///
  /// In ru, this message translates to:
  /// **'Заказ №{id}'**
  String orderTitle(int id);

  /// No description provided for @orderSeller.
  ///
  /// In ru, this message translates to:
  /// **'Продавец'**
  String get orderSeller;

  /// No description provided for @orderBuyer.
  ///
  /// In ru, this message translates to:
  /// **'Покупатель'**
  String get orderBuyer;

  /// No description provided for @orderQty.
  ///
  /// In ru, this message translates to:
  /// **'Количество: {qty}'**
  String orderQty(int qty);

  /// No description provided for @orderTracking.
  ///
  /// In ru, this message translates to:
  /// **'Трек-номер'**
  String get orderTracking;

  /// No description provided for @orderInspectUntil.
  ///
  /// In ru, this message translates to:
  /// **'Проверка до {date}'**
  String orderInspectUntil(String date);

  /// No description provided for @orderPayout.
  ///
  /// In ru, this message translates to:
  /// **'Вы получите (за вычетом комиссии 10%)'**
  String get orderPayout;

  /// No description provided for @openOnSiteDetails.
  ///
  /// In ru, this message translates to:
  /// **'Подробнее на сайте'**
  String get openOnSiteDetails;

  /// No description provided for @actionShip.
  ///
  /// In ru, this message translates to:
  /// **'Отправил товар'**
  String get actionShip;

  /// No description provided for @actionDelivered.
  ///
  /// In ru, this message translates to:
  /// **'Товар получен'**
  String get actionDelivered;

  /// No description provided for @actionConfirm.
  ///
  /// In ru, this message translates to:
  /// **'Подтвердить, всё в порядке'**
  String get actionConfirm;

  /// No description provided for @actionCancel.
  ///
  /// In ru, this message translates to:
  /// **'Отменить заказ'**
  String get actionCancel;

  /// No description provided for @actionDispute.
  ///
  /// In ru, this message translates to:
  /// **'Возврат или спор'**
  String get actionDispute;

  /// No description provided for @shipTitle.
  ///
  /// In ru, this message translates to:
  /// **'Отправка товара'**
  String get shipTitle;

  /// No description provided for @shipTracking.
  ///
  /// In ru, this message translates to:
  /// **'Трек-номер или номер накладной'**
  String get shipTracking;

  /// No description provided for @shipCarrier.
  ///
  /// In ru, this message translates to:
  /// **'Служба доставки (необязательно)'**
  String get shipCarrier;

  /// No description provided for @confirmTitle.
  ///
  /// In ru, this message translates to:
  /// **'Подтвердить получение?'**
  String get confirmTitle;

  /// No description provided for @confirmText.
  ///
  /// In ru, this message translates to:
  /// **'Деньги будут переведены продавцу. Отменить это нельзя.'**
  String get confirmText;

  /// No description provided for @cancelTitle.
  ///
  /// In ru, this message translates to:
  /// **'Отменить заказ?'**
  String get cancelTitle;

  /// No description provided for @cancelText.
  ///
  /// In ru, this message translates to:
  /// **'Если заказ оплачен, деньги вернутся на ваш кошелёк Zakopeyki.'**
  String get cancelText;

  /// No description provided for @deliveredTitle.
  ///
  /// In ru, this message translates to:
  /// **'Товар получен?'**
  String get deliveredTitle;

  /// No description provided for @deliveredText.
  ///
  /// In ru, this message translates to:
  /// **'Начнётся срок на проверку товара. Если что-то не так — откройте спор до его окончания.'**
  String get deliveredText;

  /// No description provided for @disputeTitle.
  ///
  /// In ru, this message translates to:
  /// **'Возврат или спор'**
  String get disputeTitle;

  /// No description provided for @disputeReason.
  ///
  /// In ru, this message translates to:
  /// **'Причина'**
  String get disputeReason;

  /// No description provided for @disputeComment.
  ///
  /// In ru, this message translates to:
  /// **'Опишите проблему'**
  String get disputeComment;

  /// No description provided for @disputeSend.
  ///
  /// In ru, this message translates to:
  /// **'Отправить'**
  String get disputeSend;

  /// No description provided for @reason_not_as_described.
  ///
  /// In ru, this message translates to:
  /// **'Не соответствует описанию'**
  String get reason_not_as_described;

  /// No description provided for @reason_not_received.
  ///
  /// In ru, this message translates to:
  /// **'Не получил товар'**
  String get reason_not_received;

  /// No description provided for @reason_changed_mind.
  ///
  /// In ru, this message translates to:
  /// **'Передумал'**
  String get reason_changed_mind;

  /// No description provided for @reason_courier_void.
  ///
  /// In ru, this message translates to:
  /// **'Отказ при доставке курьером'**
  String get reason_courier_void;

  /// No description provided for @reason_digital_defect.
  ///
  /// In ru, this message translates to:
  /// **'Проблема с цифровым товаром'**
  String get reason_digital_defect;

  /// No description provided for @confirmAction.
  ///
  /// In ru, this message translates to:
  /// **'Подтвердить'**
  String get confirmAction;

  /// No description provided for @back.
  ///
  /// In ru, this message translates to:
  /// **'Назад'**
  String get back;

  /// No description provided for @ordersOtherOnSite.
  ///
  /// In ru, this message translates to:
  /// **'Переговоры по возврату и арбитраж — на сайте.'**
  String get ordersOtherOnSite;

  /// No description provided for @retryPayment.
  ///
  /// In ru, this message translates to:
  /// **'Проверить оплату'**
  String get retryPayment;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['en', 'kk', 'ru'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'en':
      return AppLocalizationsEn();
    case 'kk':
      return AppLocalizationsKk();
    case 'ru':
      return AppLocalizationsRu();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
