/// Where the app talks to. Flip [isProd] to build for the live server.
///
/// Dev points at this PC's LAN IP (a phone on the same WiFi can reach it;
/// "localhost" would mean the phone itself). Keep it in sync with the PHP
/// APP_URL in secure_config.php.
class AppConfig {
  /// false = dev PC over LAN, true = the live cPanel site.
  static const bool isProd = false;

  static const String _devBase = 'https://192.168.1.82/try';
  static const String _prodBase = 'https://prabinsharma.com';

  static String get baseUrl => isProd ? _prodBase : _devBase;

  /// The mobile JSON API root (field/api-v2/).
  static String get apiBase => '$baseUrl/field/api-v2';

  /// The dev server uses a locally-trusted mkcert certificate that a phone
  /// does not have in its trust store. In a dev build we tell Dio to accept
  /// it; a prod build always verifies (Let's Encrypt on the live site).
  static bool get allowBadCertificates => !isProd;

  /// How often the announcement popup checks in (matches the web: ~8s).
  static const Duration announcementPoll = Duration(seconds: 8);
}
