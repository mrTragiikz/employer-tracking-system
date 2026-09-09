import 'dart:async';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../core/config.dart';

/// Live location tracking - a foreground service that records the worker's
/// route between check-in and check-out and uploads it to field/api-v2/ping.php.
///
/// Only runs when the admin has turned tracking ON server-side: the very first
/// ping response tells the service whether to keep going. If tracking is off,
/// not-checked-in, or checked-out, the service stops itself.
///
/// The TaskHandler runs in its OWN isolate, so it cannot touch the app's
/// Api / AuthService singletons. It reads the bearer token straight from
/// SharedPreferences (same key Storage uses) and posts with its own Dio.
class TrackingService {
  TrackingService._();
  static final TrackingService instance = TrackingService._();

  static const _serviceId = 42;

  bool _started = false;

  /// Call once, early in app start.
  Future<void> initForegroundTask() async {
    FlutterForegroundTask.init(
      androidNotificationOptions: AndroidNotificationOptions(
        channelId: 'rajdoot_tracking',
        channelName: 'Route recording',
        channelDescription:
            'Shown while your route is being recorded, between check-in and check-out.',
        onlyAlertOnce: true,
      ),
      iosNotificationOptions: const IOSNotificationOptions(
        showNotification: false,
        playSound: false,
      ),
      foregroundTaskOptions: ForegroundTaskOptions(
        // The handler pings on this cadence. The server can push a different
        // interval back; the handler reads it and calls updateService.
        eventAction: ForegroundTaskEventAction.repeat(
          AppConfig.trackingDefaultInterval.inMilliseconds,
        ),
        autoRunOnBoot: false,
        autoRunOnMyPackageReplaced: true,
        allowWakeLock: true,
        allowWifiLock: true,
      ),
    );
  }

  /// Start recording. Called after a successful check-in (and on app launch if
  /// already checked in). Safe to call when already running.
  Future<void> start() async {
    try {
      if (await FlutterForegroundTask.isRunningService) {
        _started = true;
        return;
      }
      await FlutterForegroundTask.startService(
        serviceId: _serviceId,
        notificationTitle: 'Recording your route',
        notificationText: 'Your route is recorded until you check out.',
        callback: trackingCallback,
      );
      _started = true;
    } catch (e) {
      if (kDebugMode) debugPrint('TrackingService.start failed: $e');
    }
  }

  /// Stop recording. Called on check-out, logout, or a 401.
  Future<void> stop() async {
    _started = false;
    try {
      if (await FlutterForegroundTask.isRunningService) {
        await FlutterForegroundTask.stopService();
      }
    } catch (e) {
      if (kDebugMode) debugPrint('TrackingService.stop failed: $e');
    }
  }

  Future<bool> get isRunning => FlutterForegroundTask.isRunningService;

  bool get wasStarted => _started;
}

// ============================================================================
// The isolate side - runs inside the foreground service.
// ============================================================================

@pragma('vm:entry-point')
void trackingCallback() {
  FlutterForegroundTask.setTaskHandler(_TrackingHandler());
}

class _TrackingHandler extends TaskHandler {
  static const _tokenKey = 'auth_token'; // must match Storage._kToken

  final List<Map<String, dynamic>> _buffer = [];
  DateTime _lastFlush = DateTime.fromMillisecondsSinceEpoch(0);
  Dio? _dio;
  int _currentIntervalMs = 90000;

  @override
  Future<void> onStart(DateTime timestamp, TaskStarter starter) async {
    _dio = Dio(BaseOptions(
      baseUrl: AppConfig.apiBase,
      connectTimeout: const Duration(seconds: 12),
      receiveTimeout: const Duration(seconds: 20),
      sendTimeout: const Duration(seconds: 20),
      headers: {'Accept': 'application/json'},
      validateStatus: (_) => true,
    ));
    if (AppConfig.allowBadCertificates) {
      (_dio!.httpClientAdapter as IOHttpClientAdapter).createHttpClient = () {
        final c = HttpClient();
        c.badCertificateCallback = (cert, host, port) => true;
        return c;
      };
    }
  }

  @override
  void onRepeatEvent(DateTime timestamp) {
    _tick();
  }

  Future<void> _tick() async {
    // 1. one GPS fix
    try {
      final serviceOn = await Geolocator.isLocationServiceEnabled();
      if (serviceOn) {
        final pos = await Geolocator.getCurrentPosition(
          locationSettings: const LocationSettings(
            accuracy: LocationAccuracy.high,
            timeLimit: Duration(seconds: 15),
          ),
        );
        _buffer.add({
          'lat': pos.latitude,
          'lng': pos.longitude,
          'accuracy_m': pos.accuracy,
          'speed_kmh': pos.speed.isFinite && pos.speed >= 0 ? pos.speed * 3.6 : null,
          'recorded_at': DateTime.now().toUtc().toIso8601String(),
        });
      }
    } catch (_) {
      // a missed fix is fine - try again next tick
    }

    // 2. flush if we have enough, or it has been a while
    final due = DateTime.now().difference(_lastFlush) >= const Duration(minutes: 4);
    if (_buffer.length >= 5 || (due && _buffer.isNotEmpty)) {
      await _flush();
    }
  }

  Future<void> _flush() async {
    final dio = _dio;
    if (dio == null || _buffer.isEmpty) return;

    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey);
    if (token == null || token.isEmpty) {
      // signed out - nothing we can do; stop the service
      await FlutterForegroundTask.stopService();
      return;
    }

    final batch = List<Map<String, dynamic>>.from(_buffer);
    try {
      final res = await dio.post(
        '/ping.php',
        data: {'points': batch},
        options: Options(headers: {'Authorization': 'Bearer $token'}),
      );

      final code = res.statusCode ?? 0;
      if (code == 401) {
        await FlutterForegroundTask.stopService();
        return;
      }
      if (code >= 200 && code < 300 && res.data is Map) {
        final data = res.data as Map;
        _buffer.clear();
        _lastFlush = DateTime.now();

        final tracking = data['tracking'];
        if (tracking != null && tracking != 'on') {
          // server says stop (tracking off / checked out / not checked in)
          await FlutterForegroundTask.stopService();
          return;
        }

        // adapt the interval if the server changed it
        final nextS = (data['next_interval_s'] as num?)?.toInt();
        if (nextS != null) {
          final nextMs = (nextS.clamp(15, 600)) * 1000;
          if (nextMs != _currentIntervalMs) {
            _currentIntervalMs = nextMs;
            FlutterForegroundTask.updateService(
              foregroundTaskOptions: ForegroundTaskOptions(
                eventAction: ForegroundTaskEventAction.repeat(nextMs),
              ),
            );
          }
        }
      }
      // any other non-2xx (5xx, network): keep the buffer, retry next tick.
      // Cap the buffer so a long outage does not grow it forever.
      if (_buffer.length > 300) {
        _buffer.removeRange(0, _buffer.length - 300);
      }
    } catch (_) {
      // network down - keep the buffer, retry next tick
      if (_buffer.length > 300) {
        _buffer.removeRange(0, _buffer.length - 300);
      }
    }
  }

  @override
  Future<void> onDestroy(DateTime timestamp, bool isTimeout) async {
    // best-effort final flush
    await _flush();
  }

  @override
  void onReceiveData(Object data) {}

  @override
  void onNotificationPressed() {
    FlutterForegroundTask.launchApp('/');
  }
}
