import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:uuid/uuid.dart';

/// Small wrapper over flutter_secure_storage for the few things we persist:
/// the bearer token (the "forever logged in" story), the per-install
/// device_id (the one-device rule 10), and the cached employee display bits.
class Storage {
  Storage._();
  static final Storage instance = Storage._();

  static const _s = FlutterSecureStorage(
    aOptions: AndroidOptions(),
  );

  static const _kToken = 'auth_token';
  static const _kDeviceId = 'device_id';
  static const _kName = 'emp_name';
  static const _kPhone = 'emp_phone';
  static const _kCode = 'emp_code';
  static const _kPhoto = 'emp_photo';

  Future<String?> get token => _s.read(key: _kToken);
  Future<void> setToken(String t) => _s.write(key: _kToken, value: t);
  Future<void> clearToken() => _s.delete(key: _kToken);

  /// A stable id for this install. Created once, kept forever - it is what
  /// binds the employee's account to this phone (server-side rule 10).
  Future<String> deviceId() async {
    final existing = await _s.read(key: _kDeviceId);
    if (existing != null && existing.isNotEmpty) return existing;
    final fresh = const Uuid().v4();
    await _s.write(key: _kDeviceId, value: fresh);
    return fresh;
  }

  Future<void> saveEmployee({
    required String name,
    required String phone,
    String? code,
    String? photoUrl,
  }) async {
    await _s.write(key: _kName, value: name);
    await _s.write(key: _kPhone, value: phone);
    await _s.write(key: _kCode, value: code ?? '');
    await _s.write(key: _kPhoto, value: photoUrl ?? '');
  }

  Future<Map<String, String?>> readEmployee() async => {
        'name': await _s.read(key: _kName),
        'phone': await _s.read(key: _kPhone),
        'code': await _s.read(key: _kCode),
        'photo_url': await _s.read(key: _kPhoto),
      };

  /// Full wipe - called on an explicit Logout or a 401 from the server.
  Future<void> clearAll() async {
    await _s.delete(key: _kToken);
    await _s.delete(key: _kName);
    await _s.delete(key: _kPhone);
    await _s.delete(key: _kCode);
    await _s.delete(key: _kPhoto);
    // NOT the device_id - that stays for the life of the install.
  }
}
