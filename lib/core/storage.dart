import 'package:shared_preferences/shared_preferences.dart';
import 'package:uuid/uuid.dart';

/// Small wrapper over SharedPreferences for the few things we persist: the
/// bearer token (the "forever logged in" story), the per-install device_id
/// (the one-device rule 10), and the cached employee display bits.
///
/// On Android, SharedPreferences lives in the app's private data directory -
/// no other app can read it, and on a lost/wiped phone the admin's
/// Reset Device kills the token server-side anyway. Good enough for a
/// 4-digit-PIN field app; keeps the dependency tree light.
class Storage {
  Storage._();
  static final Storage instance = Storage._();

  SharedPreferences? _p;
  Future<SharedPreferences> get _prefs async => _p ??= await SharedPreferences.getInstance();

  static const _kToken = 'auth_token';
  static const _kDeviceId = 'device_id';
  static const _kName = 'emp_name';
  static const _kPhone = 'emp_phone';
  static const _kCode = 'emp_code';
  static const _kPhoto = 'emp_photo';

  Future<String?> get token async => (await _prefs).getString(_kToken);
  Future<void> setToken(String t) async => (await _prefs).setString(_kToken, t);
  Future<void> clearToken() async => (await _prefs).remove(_kToken);

  /// A stable id for this install. Created once, kept forever - it is what
  /// binds the employee's account to this phone (server-side rule 10).
  Future<String> deviceId() async {
    final p = await _prefs;
    final existing = p.getString(_kDeviceId);
    if (existing != null && existing.isNotEmpty) return existing;
    final fresh = const Uuid().v4();
    await p.setString(_kDeviceId, fresh);
    return fresh;
  }

  Future<void> saveEmployee({
    required String name,
    required String phone,
    String? code,
    String? photoUrl,
  }) async {
    final p = await _prefs;
    await p.setString(_kName, name);
    await p.setString(_kPhone, phone);
    await p.setString(_kCode, code ?? '');
    await p.setString(_kPhoto, photoUrl ?? '');
  }

  Future<Map<String, String?>> readEmployee() async {
    final p = await _prefs;
    return {
      'name': p.getString(_kName),
      'phone': p.getString(_kPhone),
      'code': p.getString(_kCode),
      'photo_url': p.getString(_kPhoto),
    };
  }

  /// Full wipe - called on an explicit Logout or a 401 from the server.
  /// The device_id is deliberately kept for the life of the install.
  Future<void> clearAll() async {
    final p = await _prefs;
    await p.remove(_kToken);
    await p.remove(_kName);
    await p.remove(_kPhone);
    await p.remove(_kCode);
    await p.remove(_kPhoto);
  }
}
