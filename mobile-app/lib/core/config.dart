/// Where the app talks to.
///
/// Default is the dev PC over LAN (a phone on the same WiFi can reach it;
/// "localhost" would be the phone itself). A release build passes
/// `--dart-define=RAJDOOT_ENV=prod` to point at the live site instead - so
/// the same source builds both, and no one has to remember to flip a bool.
///
/// Keep the URLs in sync with APP_URL in the PHP secure_config.php.
class AppConfig {
  static const String _env = String.fromEnvironment('RAJDOOT_ENV', defaultValue: 'dev');

  /// true = the live site, false = the dev PC.
  static const bool isProd = _env == 'prod';

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

  /// Live-tracking: how often the foreground service takes a GPS fix, before
  /// the server pushes back its own interval. Matches the PHP default (90s).
  static const Duration trackingDefaultInterval = Duration(seconds: 90);
}
