// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for Russian (`ru`).
class AppLocalizationsRu extends AppLocalizations {
  AppLocalizationsRu([String locale = 'ru']) : super(locale);

  @override
  String get appTitle => 'Zakopeyki.kz';

  @override
  String get navHome => 'Главная';

  @override
  String get navFavorites => 'Избранное';

  @override
  String get navProfile => 'Профиль';

  @override
  String get searchHint => 'Поиск товаров и услуг';

  @override
  String get allTypes => 'Все';

  @override
  String get retry => 'Повторить';

  @override
  String get loadError => 'Не удалось загрузить данные';

  @override
  String get networkError => 'Нет соединения с сервером. Проверьте интернет.';

  @override
  String get emptyFeed => 'Ничего не найдено';

  @override
  String get emptyFavorites => 'В избранном пока пусто';

  @override
  String get loginTitle => 'Вход';

  @override
  String get loginSubtitle =>
      'Войдите, чтобы сохранять избранное и совершать покупки';

  @override
  String get email => 'Email';

  @override
  String get password => 'Пароль';

  @override
  String get loginButton => 'Войти';

  @override
  String get fieldRequired => 'Обязательное поле';

  @override
  String get twoFactorTitle => 'Подтверждение входа';

  @override
  String get twoFactorHint =>
      'Введите 6-значный код из приложения-аутентификатора или резервный код';

  @override
  String get code => 'Код';

  @override
  String get confirm => 'Подтвердить';

  @override
  String get logout => 'Выйти';

  @override
  String get logoutAll => 'Выйти на всех устройствах';

  @override
  String get language => 'Язык';

  @override
  String get loginRequired => 'Войдите в аккаунт';

  @override
  String get loginRequiredFavorites => 'Войдите, чтобы видеть избранное';

  @override
  String get description => 'Описание';

  @override
  String get characteristics => 'Характеристики';

  @override
  String get exchangeFor => 'Обменяю на';

  @override
  String get seller => 'Продавец';

  @override
  String get business => 'Бизнес';

  @override
  String ratingCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count отзывов',
      few: '$count отзыва',
      one: '$count отзыв',
      zero: 'нет отзывов',
    );
    return '$_temp0';
  }

  @override
  String inStock(int count) {
    return 'В наличии: $count';
  }

  @override
  String get buyOnSite => 'Купить на сайте';

  @override
  String get openOnSite => 'Открыть на сайте';

  @override
  String safeDealInfo(int percent) {
    return 'Безопасная сделка: деньги удерживаются до подтверждения получения. Сбор покупателя $percent%.';
  }

  @override
  String auctionEnds(String date) {
    return 'Аукцион до $date';
  }

  @override
  String get siteClosedTitle => 'Скоро открытие';

  @override
  String get siteClosedText =>
      'Zakopeyki.kz скоро откроется. Доступ пока открыт только участникам закрытого тестирования.';

  @override
  String get legalOffer => 'Пользовательское соглашение';

  @override
  String get legalPrivacy => 'Политика конфиденциальности';

  @override
  String views(int count) {
    return 'Просмотры: $count';
  }

  @override
  String get addedToFavorites => 'Добавлено в избранное';

  @override
  String get removedFromFavorites => 'Удалено из избранного';

  @override
  String get sessionExpired => 'Сессия завершена. Войдите заново.';

  @override
  String get registerTitle => 'Регистрация';

  @override
  String get registerButton => 'Зарегистрироваться';

  @override
  String get name => 'Имя';

  @override
  String get phoneOptional => 'Телефон (необязательно)';

  @override
  String get passwordHint => 'Не короче 8 символов';

  @override
  String get acceptOfferPrefix => 'Я принимаю';

  @override
  String get offerLink => 'пользовательское соглашение';

  @override
  String get noAccount => 'Нет аккаунта?';

  @override
  String get createAccount => 'Зарегистрироваться';

  @override
  String get haveAccount => 'Уже есть аккаунт? Войти';

  @override
  String get forgotPassword => 'Забыли пароль?';

  @override
  String get forgotTitle => 'Восстановление пароля';

  @override
  String get forgotHint =>
      'Укажите email аккаунта — мы отправим ссылку для создания нового пароля.';

  @override
  String get sendLink => 'Отправить ссылку';

  @override
  String get orDivider => 'или';

  @override
  String get appleUnavailable =>
      'Вход через Apple недоступен на этом устройстве';

  @override
  String get invalidEmail => 'Введите корректный email';

  @override
  String get passwordTooShort => 'Пароль должен быть не короче 8 символов';

  @override
  String get offerRequired => 'Примите пользовательское соглашение';

  @override
  String registrationBonus(int points) {
    final intl.NumberFormat pointsNumberFormat =
        intl.NumberFormat.decimalPattern(localeName);
    final String pointsString = pointsNumberFormat.format(points);

    return 'Вам начислено $pointsString бонусов за регистрацию!';
  }

  @override
  String get appleSignIn => 'Вход с Apple';

  @override
  String get typeNew => 'Новый товар';

  @override
  String get typeUsed => 'Товар Б/У';

  @override
  String get typeAuction => 'Аукцион';

  @override
  String get typeService => 'Услуги';

  @override
  String get typeExchange => 'Бартер';

  @override
  String get typeFree => 'Даром';

  @override
  String get typeGig => 'Биржа услуг';

  @override
  String get deleteAccount => 'Удалить аккаунт';

  @override
  String get deleteAccountTitle => 'Удаление аккаунта';

  @override
  String get deleteAccountIntro =>
      'Аккаунт будет удалён без возможности восстановления:';

  @override
  String get deleteAccountPoint1 =>
      'имя, email, телефон, ИИН/БИН, адреса, фото профиля и пароль будут стёрты;';

  @override
  String get deleteAccountPoint2 =>
      'объявления снимутся с публикации, избранное и подписки удалятся;';

  @override
  String get deleteAccountPoint3 =>
      'история завершённых сделок сохранится в обезличенном виде — она нужна второй стороне и для учёта платежей;';

  @override
  String get deleteAccountPoint4 =>
      'бонусные баллы сгорят. Тот же email можно будет зарегистрировать снова.';

  @override
  String get deleteAccountBlocked => 'Сейчас удалить аккаунт нельзя:';

  @override
  String get deleteAccountConfirm => 'Я понимаю, что удаление необратимо';

  @override
  String get deleteAccountPasswordHint => 'Введите пароль для подтверждения';

  @override
  String get deleteAccountReauthHint =>
      'Для подтверждения войдите заново (например, через Apple) и сразу вернитесь на этот экран.';

  @override
  String get deleteAccountReauthButton => 'Войти заново';

  @override
  String get deleteAccountButton => 'Удалить аккаунт навсегда';

  @override
  String get accountDeleted => 'Аккаунт удалён';

  @override
  String get myListings => 'Мои объявления';

  @override
  String get newListing => 'Подать объявление';

  @override
  String get editListing => 'Редактировать объявление';

  @override
  String get listingType => 'Тип объявления';

  @override
  String get listingTitle => 'Название';

  @override
  String get listingDescription => 'Описание';

  @override
  String get listingPrice => 'Цена, ₸';

  @override
  String get listingCategory => 'Категория';

  @override
  String get chooseCategory => 'Выберите категорию';

  @override
  String get listingQuantity => 'Количество';

  @override
  String get listingExchangeFor => 'Что хотите получить взамен';

  @override
  String get listingLocation => 'Город';

  @override
  String get listingCharacteristics => 'Характеристики (необязательно)';

  @override
  String get listingCharacteristicsHint => 'По одной на строку: «Цвет: чёрный»';

  @override
  String get photos => 'Фото';

  @override
  String photosHint(int max) {
    return 'До $max фото. Нажмите на фото, чтобы сделать его обложкой.';
  }

  @override
  String get addPhotoGallery => 'Из галереи';

  @override
  String get addPhotoCamera => 'Камера';

  @override
  String get coverBadge => 'Обложка';

  @override
  String get removePhoto => 'Удалить фото';

  @override
  String get iinLabel => 'ИИН (12 цифр)';

  @override
  String get binLabel => 'БИН (12 цифр)';

  @override
  String get taxIdHint =>
      'Нужен один раз для проверки продавца (требование АФМ РК). Хранится на сервере и не показывается покупателям.';

  @override
  String get acceptListingRules => 'Я принимаю правила размещения объявлений';

  @override
  String get pickupOnlyNote =>
      'Из приложения объявление публикуется с самовывозом. Доставку CDEK, аукционы и платные услуги пока можно настроить на сайте.';

  @override
  String get publish => 'Опубликовать';

  @override
  String get save => 'Сохранить';

  @override
  String get listingPublished => 'Объявление опубликовано';

  @override
  String get listingSaved => 'Изменения сохранены';

  @override
  String listingBonus(int points) {
    return '+$points бонусов за объявление';
  }

  @override
  String get deleteListing => 'Удалить объявление';

  @override
  String get deleteListingConfirm =>
      'Удалить это объявление? Это действие нельзя отменить.';

  @override
  String get cancel => 'Отмена';

  @override
  String get delete => 'Удалить';

  @override
  String get listingDeleted => 'Объявление удалено';

  @override
  String get emptyMyListings => 'У вас пока нет объявлений';

  @override
  String get editOnSite => 'Этот тип объявления редактируется на сайте';

  @override
  String get statusActive => 'Активно';

  @override
  String get statusSold => 'Продано';

  @override
  String get statusArchived => 'В архиве';

  @override
  String get statusReserved => 'Забронировано';

  @override
  String get statusOutOfStock => 'Нет в наличии';

  @override
  String get needPhoto => 'Добавьте хотя бы одно фото';

  @override
  String uploading(int percent) {
    return 'Загрузка $percent%';
  }

  @override
  String get publishBlocked => 'Публикация недоступна';

  @override
  String get listingRulesRequired => 'Примите правила размещения объявлений';

  @override
  String get navCart => 'Корзина';

  @override
  String get addToCart => 'В корзину';

  @override
  String get inCart => 'В корзине';

  @override
  String get buyNow => 'Купить';

  @override
  String get addedToCart => 'Добавлено в корзину';

  @override
  String get emptyCart => 'Корзина пуста';

  @override
  String get cartTotal => 'Итого';

  @override
  String get checkoutAll => 'Оформить заказ';

  @override
  String get remove => 'Удалить';

  @override
  String get checkoutTitle => 'Оформление заказа';

  @override
  String get dealSafe => 'Безопасная сделка';

  @override
  String dealSafeHint(int hours) {
    return 'Деньги хранятся на платформе, пока вы не подтвердите получение. После получения есть $hours ч на проверку и спор.';
  }

  @override
  String get dealDirect => 'Напрямую продавцу';

  @override
  String get dealDirectHint =>
      'Без защиты сделки и сбора. Деньги сразу уходят продавцу, вернуть их через платформу нельзя.';

  @override
  String get dealFee => 'Сбор за безопасную сделку';

  @override
  String get subtotal => 'Товары';

  @override
  String get totalToPay => 'К оплате';

  @override
  String get delivery => 'Получение';

  @override
  String get deliveryPickup => 'Самовывоз';

  @override
  String get deliveryKazpost => 'Казпочта';

  @override
  String get deliveryCourier => 'Курьер';

  @override
  String get deliveryOther => 'Другое';

  @override
  String get deliveryCdek => 'CDEK';

  @override
  String get deliveryDigital => 'Цифровой товар';

  @override
  String get deliveryCdekOnSite =>
      'Доставку CDEK пока можно оформить только на сайте';

  @override
  String get payment => 'Оплата';

  @override
  String get payCard => 'Картой (Halyk ePay)';

  @override
  String get payCardSimulated => 'Картой (тестовый режим)';

  @override
  String get payWallet => 'С кошелька';

  @override
  String walletBalance(String amount) {
    return 'Баланс: $amount';
  }

  @override
  String get walletNotEnough => 'Недостаточно средств на кошельке';

  @override
  String payButton(String amount) {
    return 'Оплатить $amount';
  }

  @override
  String get paymentOpening => 'Открываем страницу оплаты…';

  @override
  String get paymentCheck => 'Проверяем оплату…';

  @override
  String get paymentCancelled =>
      'Оплата не завершена. Заказ ждёт оплаты — его можно отменить в «Мои заказы».';

  @override
  String get orderPlaced => 'Заказ оформлен';

  @override
  String get myOrders => 'Мои заказы';

  @override
  String get ordersBuying => 'Покупки';

  @override
  String get ordersSelling => 'Продажи';

  @override
  String get emptyOrders => 'Заказов пока нет';

  @override
  String orderTitle(int id) {
    return 'Заказ №$id';
  }

  @override
  String get orderSeller => 'Продавец';

  @override
  String get orderBuyer => 'Покупатель';

  @override
  String orderQty(int qty) {
    return 'Количество: $qty';
  }

  @override
  String get orderTracking => 'Трек-номер';

  @override
  String orderInspectUntil(String date) {
    return 'Проверка до $date';
  }

  @override
  String get orderPayout => 'Вы получите (за вычетом комиссии 10%)';

  @override
  String get openOnSiteDetails => 'Подробнее на сайте';

  @override
  String get actionShip => 'Отправил товар';

  @override
  String get actionDelivered => 'Товар получен';

  @override
  String get actionConfirm => 'Подтвердить, всё в порядке';

  @override
  String get actionCancel => 'Отменить заказ';

  @override
  String get actionDispute => 'Возврат или спор';

  @override
  String get shipTitle => 'Отправка товара';

  @override
  String get shipTracking => 'Трек-номер или номер накладной';

  @override
  String get shipCarrier => 'Служба доставки (необязательно)';

  @override
  String get confirmTitle => 'Подтвердить получение?';

  @override
  String get confirmText =>
      'Деньги будут переведены продавцу. Отменить это нельзя.';

  @override
  String get cancelTitle => 'Отменить заказ?';

  @override
  String get cancelText =>
      'Если заказ оплачен, деньги вернутся на ваш кошелёк Zakopeyki.';

  @override
  String get deliveredTitle => 'Товар получен?';

  @override
  String get deliveredText =>
      'Начнётся срок на проверку товара. Если что-то не так — откройте спор до его окончания.';

  @override
  String get disputeTitle => 'Возврат или спор';

  @override
  String get disputeReason => 'Причина';

  @override
  String get disputeComment => 'Опишите проблему';

  @override
  String get disputeSend => 'Отправить';

  @override
  String get reason_not_as_described => 'Не соответствует описанию';

  @override
  String get reason_not_received => 'Не получил товар';

  @override
  String get reason_changed_mind => 'Передумал';

  @override
  String get reason_courier_void => 'Отказ при доставке курьером';

  @override
  String get reason_digital_defect => 'Проблема с цифровым товаром';

  @override
  String get confirmAction => 'Подтвердить';

  @override
  String get back => 'Назад';

  @override
  String get ordersOtherOnSite =>
      'Переговоры по возврату и арбитраж — на сайте.';

  @override
  String get retryPayment => 'Проверить оплату';
}
