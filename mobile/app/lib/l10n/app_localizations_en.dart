// ignore: unused_import
import 'package:intl/intl.dart' as intl;

import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appTitle => 'Zakopeyki.kz';

  @override
  String get navHome => 'Home';

  @override
  String get navFavorites => 'Favorites';

  @override
  String get navProfile => 'Profile';

  @override
  String get searchHint => 'Search goods and services';

  @override
  String get allTypes => 'All';

  @override
  String get retry => 'Retry';

  @override
  String get loadError => 'Couldn\'t load data';

  @override
  String get networkError => 'Can\'t reach the server. Check your connection.';

  @override
  String get emptyFeed => 'Nothing found';

  @override
  String get emptyFavorites => 'No favorites yet';

  @override
  String get loginTitle => 'Sign in';

  @override
  String get loginSubtitle => 'Sign in to save favorites and make purchases';

  @override
  String get email => 'Email';

  @override
  String get password => 'Password';

  @override
  String get loginButton => 'Sign in';

  @override
  String get fieldRequired => 'Required field';

  @override
  String get twoFactorTitle => 'Confirm sign-in';

  @override
  String get twoFactorHint =>
      'Enter the 6-digit code from your authenticator app or a recovery code';

  @override
  String get code => 'Code';

  @override
  String get confirm => 'Confirm';

  @override
  String get logout => 'Sign out';

  @override
  String get logoutAll => 'Sign out on all devices';

  @override
  String get language => 'Language';

  @override
  String get loginRequired => 'Sign in to your account';

  @override
  String get loginRequiredFavorites => 'Sign in to see your favorites';

  @override
  String get description => 'Description';

  @override
  String get characteristics => 'Specifications';

  @override
  String get exchangeFor => 'Will exchange for';

  @override
  String get seller => 'Seller';

  @override
  String get business => 'Business';

  @override
  String ratingCount(int count) {
    String _temp0 = intl.Intl.pluralLogic(
      count,
      locale: localeName,
      other: '$count reviews',
      one: '$count review',
      zero: 'no reviews',
    );
    return '$_temp0';
  }

  @override
  String inStock(int count) {
    return 'In stock: $count';
  }

  @override
  String get buyOnSite => 'Buy on the website';

  @override
  String get openOnSite => 'Open on the website';

  @override
  String safeDealInfo(int percent) {
    return 'Safe Deal: money is held until you confirm receipt. Buyer fee $percent%.';
  }

  @override
  String auctionEnds(String date) {
    return 'Auction ends $date';
  }

  @override
  String get siteClosedTitle => 'Opening soon';

  @override
  String get siteClosedText =>
      'Zakopeyki.kz opens soon. Access is currently limited to closed beta participants.';

  @override
  String get legalOffer => 'Terms of use';

  @override
  String get legalPrivacy => 'Privacy policy';

  @override
  String views(int count) {
    return 'Views: $count';
  }

  @override
  String get addedToFavorites => 'Added to favorites';

  @override
  String get removedFromFavorites => 'Removed from favorites';

  @override
  String get sessionExpired => 'Session ended. Please sign in again.';

  @override
  String get registerTitle => 'Create account';

  @override
  String get registerButton => 'Create account';

  @override
  String get name => 'Name';

  @override
  String get phoneOptional => 'Phone (optional)';

  @override
  String get passwordHint => 'At least 8 characters';

  @override
  String get acceptOfferPrefix => 'I accept the';

  @override
  String get offerLink => 'terms of use';

  @override
  String get noAccount => 'No account yet?';

  @override
  String get createAccount => 'Create account';

  @override
  String get haveAccount => 'Already have an account? Sign in';

  @override
  String get forgotPassword => 'Forgot password?';

  @override
  String get forgotTitle => 'Reset password';

  @override
  String get forgotHint =>
      'Enter your account email and we\'ll send a link to set a new password.';

  @override
  String get sendLink => 'Send link';

  @override
  String get orDivider => 'or';

  @override
  String get appleUnavailable =>
      'Sign in with Apple isn\'t available on this device';

  @override
  String get invalidEmail => 'Enter a valid email';

  @override
  String get passwordTooShort => 'Password must be at least 8 characters';

  @override
  String get offerRequired => 'Please accept the terms of use';

  @override
  String registrationBonus(int points) {
    final intl.NumberFormat pointsNumberFormat =
        intl.NumberFormat.decimalPattern(localeName);
    final String pointsString = pointsNumberFormat.format(points);

    return 'You received $pointsString bonus points for signing up!';
  }

  @override
  String get appleSignIn => 'Sign in with Apple';

  @override
  String get typeNew => 'New';

  @override
  String get typeUsed => 'Used';

  @override
  String get typeAuction => 'Auction';

  @override
  String get typeService => 'Services';

  @override
  String get typeExchange => 'Barter';

  @override
  String get typeFree => 'Free';

  @override
  String get typeGig => 'Gigs';

  @override
  String get deleteAccount => 'Delete account';

  @override
  String get deleteAccountTitle => 'Delete account';

  @override
  String get deleteAccountIntro => 'Your account will be deleted permanently:';

  @override
  String get deleteAccountPoint1 =>
      'your name, email, phone, IIN/BIN, addresses, profile photo and password will be erased;';

  @override
  String get deleteAccountPoint2 =>
      'your listings will be unpublished; favorites and follows will be removed;';

  @override
  String get deleteAccountPoint3 =>
      'completed deals stay in anonymized form — the other party and payment records need them;';

  @override
  String get deleteAccountPoint4 =>
      'bonus points will be lost. You can register again with the same email.';

  @override
  String get deleteAccountBlocked =>
      'Your account can\'t be deleted right now:';

  @override
  String get deleteAccountConfirm => 'I understand this can\'t be undone';

  @override
  String get deleteAccountPasswordHint => 'Enter your password to confirm';

  @override
  String get deleteAccountReauthHint =>
      'To confirm, sign in again (e.g. with Apple) and come straight back to this screen.';

  @override
  String get deleteAccountReauthButton => 'Sign in again';

  @override
  String get deleteAccountButton => 'Delete account permanently';

  @override
  String get accountDeleted => 'Account deleted';

  @override
  String get myListings => 'My listings';

  @override
  String get newListing => 'Post a listing';

  @override
  String get editListing => 'Edit listing';

  @override
  String get listingType => 'Listing type';

  @override
  String get listingTitle => 'Title';

  @override
  String get listingDescription => 'Description';

  @override
  String get listingPrice => 'Price, ₸';

  @override
  String get listingCategory => 'Category';

  @override
  String get chooseCategory => 'Choose a category';

  @override
  String get listingQuantity => 'Quantity';

  @override
  String get listingExchangeFor => 'What do you want in exchange';

  @override
  String get listingLocation => 'City';

  @override
  String get listingCharacteristics => 'Specifications (optional)';

  @override
  String get listingCharacteristicsHint => 'One per line: \"Color: black\"';

  @override
  String get photos => 'Photos';

  @override
  String photosHint(int max) {
    return 'Up to $max photos. Tap a photo to make it the cover.';
  }

  @override
  String get addPhotoGallery => 'Gallery';

  @override
  String get addPhotoCamera => 'Camera';

  @override
  String get coverBadge => 'Cover';

  @override
  String get removePhoto => 'Remove photo';

  @override
  String get iinLabel => 'IIN (12 digits)';

  @override
  String get binLabel => 'BIN (12 digits)';

  @override
  String get taxIdHint =>
      'Needed once to verify sellers (Kazakhstan AFM requirement). Stored on the server, never shown to buyers.';

  @override
  String get acceptListingRules => 'I accept the listing rules';

  @override
  String get pickupOnlyNote =>
      'Listings posted from the app are pickup-only. CDEK delivery, auctions and paid services can be set up on the website for now.';

  @override
  String get publish => 'Publish';

  @override
  String get save => 'Save';

  @override
  String get listingPublished => 'Listing published';

  @override
  String get listingSaved => 'Changes saved';

  @override
  String listingBonus(int points) {
    return '+$points bonus points for the listing';
  }

  @override
  String get deleteListing => 'Delete listing';

  @override
  String get deleteListingConfirm =>
      'Delete this listing? This can\'t be undone.';

  @override
  String get cancel => 'Cancel';

  @override
  String get delete => 'Delete';

  @override
  String get listingDeleted => 'Listing deleted';

  @override
  String get emptyMyListings => 'You have no listings yet';

  @override
  String get editOnSite => 'This listing type is edited on the website';

  @override
  String get statusActive => 'Active';

  @override
  String get statusSold => 'Sold';

  @override
  String get statusArchived => 'Archived';

  @override
  String get statusReserved => 'Reserved';

  @override
  String get statusOutOfStock => 'Out of stock';

  @override
  String get needPhoto => 'Add at least one photo';

  @override
  String uploading(int percent) {
    return 'Uploading $percent%';
  }

  @override
  String get publishBlocked => 'Publishing unavailable';

  @override
  String get listingRulesRequired => 'Please accept the listing rules';

  @override
  String get navCart => 'Cart';

  @override
  String get addToCart => 'Add to cart';

  @override
  String get inCart => 'In cart';

  @override
  String get buyNow => 'Buy now';

  @override
  String get addedToCart => 'Added to cart';

  @override
  String get emptyCart => 'Your cart is empty';

  @override
  String get cartTotal => 'Total';

  @override
  String get checkoutAll => 'Checkout';

  @override
  String get remove => 'Remove';

  @override
  String get checkoutTitle => 'Checkout';

  @override
  String get dealSafe => 'Safe Deal';

  @override
  String dealSafeHint(int hours) {
    return 'The money is held by the platform until you confirm receipt. After receiving you have $hours h to inspect and open a dispute.';
  }

  @override
  String get dealDirect => 'Pay the seller directly';

  @override
  String get dealDirectHint =>
      'No deal protection and no fee. The money goes straight to the seller and can\'t be refunded through the platform.';

  @override
  String get dealFee => 'Safe Deal fee';

  @override
  String get subtotal => 'Items';

  @override
  String get totalToPay => 'To pay';

  @override
  String get delivery => 'Delivery';

  @override
  String get deliveryPickup => 'Pickup';

  @override
  String get deliveryKazpost => 'Kazpost';

  @override
  String get deliveryCourier => 'Courier';

  @override
  String get deliveryOther => 'Other';

  @override
  String get deliveryCdek => 'CDEK';

  @override
  String get deliveryDigital => 'Digital item';

  @override
  String get deliveryCdekOnSite =>
      'CDEK delivery can only be arranged on the website for now';

  @override
  String get payment => 'Payment';

  @override
  String get payCard => 'Card (Halyk ePay)';

  @override
  String get payCardSimulated => 'Card (test mode)';

  @override
  String get payWallet => 'Wallet';

  @override
  String walletBalance(String amount) {
    return 'Balance: $amount';
  }

  @override
  String get walletNotEnough => 'Not enough money in the wallet';

  @override
  String payButton(String amount) {
    return 'Pay $amount';
  }

  @override
  String get paymentOpening => 'Opening the payment page…';

  @override
  String get paymentCheck => 'Checking the payment…';

  @override
  String get paymentCancelled =>
      'Payment not completed. The order is awaiting payment — you can cancel it in My orders.';

  @override
  String get orderPlaced => 'Order placed';

  @override
  String get myOrders => 'My orders';

  @override
  String get ordersBuying => 'Buying';

  @override
  String get ordersSelling => 'Selling';

  @override
  String get emptyOrders => 'No orders yet';

  @override
  String orderTitle(int id) {
    return 'Order #$id';
  }

  @override
  String get orderSeller => 'Seller';

  @override
  String get orderBuyer => 'Buyer';

  @override
  String orderQty(int qty) {
    return 'Quantity: $qty';
  }

  @override
  String get orderTracking => 'Tracking number';

  @override
  String orderInspectUntil(String date) {
    return 'Inspection until $date';
  }

  @override
  String get orderPayout => 'You receive (after 10% commission)';

  @override
  String get openOnSiteDetails => 'Details on the website';

  @override
  String get actionShip => 'I\'ve shipped it';

  @override
  String get actionDelivered => 'I\'ve received it';

  @override
  String get actionConfirm => 'Confirm, all good';

  @override
  String get actionCancel => 'Cancel order';

  @override
  String get actionDispute => 'Return or dispute';

  @override
  String get shipTitle => 'Ship the item';

  @override
  String get shipTracking => 'Tracking or waybill number';

  @override
  String get shipCarrier => 'Carrier (optional)';

  @override
  String get confirmTitle => 'Confirm receipt?';

  @override
  String get confirmText =>
      'The money will be released to the seller. This can\'t be undone.';

  @override
  String get cancelTitle => 'Cancel the order?';

  @override
  String get cancelText =>
      'If it\'s paid, the money returns to your Zakopeyki wallet.';

  @override
  String get deliveredTitle => 'Received the item?';

  @override
  String get deliveredText =>
      'The inspection period starts. If something\'s wrong, open a dispute before it ends.';

  @override
  String get disputeTitle => 'Return or dispute';

  @override
  String get disputeReason => 'Reason';

  @override
  String get disputeComment => 'Describe the problem';

  @override
  String get disputeSend => 'Send';

  @override
  String get reason_not_as_described => 'Not as described';

  @override
  String get reason_not_received => 'Not received';

  @override
  String get reason_changed_mind => 'Changed my mind';

  @override
  String get reason_courier_void => 'Refused on courier delivery';

  @override
  String get reason_digital_defect => 'Problem with a digital item';

  @override
  String get confirmAction => 'Confirm';

  @override
  String get back => 'Back';

  @override
  String get ordersOtherOnSite =>
      'Return negotiations and arbitration are on the website.';

  @override
  String get retryPayment => 'Check payment';
}
