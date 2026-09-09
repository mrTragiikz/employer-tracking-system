import 'package:flutter/foundation.dart';
import '../core/api.dart';
import '../core/storage.dart';
import '../models/models.dart';

/// Holds the "are we signed in" state for the whole app. Listens do not need
/// to know how the token is stored - they just read [employee] / [status].
enum AuthStatus { unknown, signedOut, signedIn }

class AuthService extends ChangeNotifier {
  AuthService._();
  static final AuthService instance = AuthService._();

  AuthStatus status = AuthStatus.unknown;
  Employee? employee;

  /// On app launch: if we have a token, trust it optimistically (so the app
  /// opens straight to Home) and let the first /me call confirm or bounce.
  Future<void> bootstrap() async {
    final t = await Storage.instance.token;
    if (t == null || t.isEmpty) {
      status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }
    final cached = await Storage.instance.readEmployee();
    if (cached['name'] != null) {
      employee = Employee(
        id: 0,
        name: cached['name']!,
        phone: cached['phone'] ?? '',
        code: (cached['code']?.isEmpty ?? true) ? null : cached['code'],
        photoUrl: (cached['photo_url']?.isEmpty ?? true) ? null : cached['photo_url'],
      );
    }
    status = AuthStatus.signedIn;
    notifyListeners();
  }

  /// POST /auth/login. Throws [ApiException] with a friendly message + a
  /// `reason` (bad|locked|throttled|suspended|left|device) on failure.
  Future<void> login({required String phone, required String pin}) async {
    final deviceId = await Storage.instance.deviceId();
    final res = await Api.instance.postJson('/auth/login.php', {
      'phone': phone,
      'pin': pin,
      'device_id': deviceId,
    });

    final token = res['token'] as String?;
    if (token == null || token.isEmpty) {
      throw ApiException(500, 'Login did not return a token.');
    }
    await Storage.instance.setToken(token);

    final emp = Employee.fromJson((res['employee'] as Map<String, dynamic>?) ?? const {});
    await Storage.instance.saveEmployee(
      name: emp.name, phone: emp.phone, code: emp.code, photoUrl: emp.photoUrl,
    );
    employee = emp;
    status = AuthStatus.signedIn;
    notifyListeners();
  }

  /// POST /auth/logout - best effort, then wipe locally regardless.
  Future<void> logout() async {
    try {
      await Api.instance.postJson('/auth/logout.php', const {});
    } catch (_) {
      // even if the server call fails, sign out locally
    }
    await Storage.instance.clearAll();
    employee = null;
    status = AuthStatus.signedOut;
    notifyListeners();
  }

  /// Called by Api on a 401 from anywhere.
  Future<void> forceSignOut() async {
    await Storage.instance.clearAll();
    employee = null;
    status = AuthStatus.signedOut;
    notifyListeners();
  }

  void updateEmployee(Employee e) {
    employee = e;
    Storage.instance.saveEmployee(
      name: e.name, phone: e.phone, code: e.code, photoUrl: e.photoUrl,
    );
    notifyListeners();
  }
}
