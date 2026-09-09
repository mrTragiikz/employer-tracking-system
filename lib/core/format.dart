import 'package:intl/intl.dart';

/// Display formatters, matching the web field app's PHP formatting.
class Fmt {
  /// "9:24 AM" from a DateTime, or a dash.
  static String time(DateTime? d) => d == null ? '—' : DateFormat('h:mm a').format(d.toLocal());

  /// "9 September 2026"
  static String longDate(DateTime d) => DateFormat('d MMMM yyyy').format(d);

  /// "9 Sep 2026"
  static String shortDate(DateTime d) => DateFormat('d MMM yyyy').format(d);

  /// "Monday, 9 September 2026"
  static String weekdayDate(DateTime d) => DateFormat('EEEE, d MMMM yyyy').format(d);

  /// "Tue, 9 Sep"
  static String weekdayShort(DateTime d) => DateFormat('EEE, d MMM').format(d);

  static DateTime? parseDate(String yyyyMmDd) => DateTime.tryParse(yyyyMmDd);

  /// "21.4 km"
  static String km(num v) => '${v.toStringAsFixed(1)} km';

  /// "6h 57m" from seconds.
  static String hm(int seconds) {
    final h = seconds ~/ 3600;
    final m = (seconds % 3600) ~/ 60;
    return '${h}h ${m.toString().padLeft(2, '0')}m';
  }

  /// "27.67058, 84.29399" for a coordinate line.
  static String coord(num lat, num lng) =>
      '${lat.toStringAsFixed(5)}, ${lng.toStringAsFixed(5)}';
}
