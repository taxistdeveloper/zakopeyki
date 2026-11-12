import 'package:intl/intl.dart';

/// 249690 → «249 690 ₸» (разделитель групп — пробел, как на сайте).
String formatTenge(int amount) {
  final digits = NumberFormat('#,##0', 'en').format(amount).replaceAll(',', ' ');
  return '$digits ₸';
}
