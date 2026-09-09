import 'dart:io';
import 'package:dio/dio.dart';
import 'package:dio/io.dart';
import 'config.dart';
import 'storage.dart';

/// Thrown for any non-2xx from the API. [message] is the server's own
/// "error" string when there is one, so it is safe to show to the user.
/// [fields] carries per-field validation messages from the write endpoints.
class ApiException implements Exception {
  ApiException(this.statusCode, this.message, {this.fields, this.reason});
  final int statusCode;
  final String message;
  final Map<String, dynamic>? fields;
  final String? reason; // login reason: bad|locked|throttled|suspended|left|device

  bool get isAuth => statusCode == 401;
  @override
  String toString() => 'ApiException($statusCode): $message';
}

/// The one HTTP client. Attaches the bearer token to every request, and on a
/// 401 clears the stored session so the app falls back to the login screen.
class Api {
  Api._() {
    _dio = Dio(BaseOptions(
      baseUrl: AppConfig.apiBase,
      connectTimeout: const Duration(seconds: 12),
      receiveTimeout: const Duration(seconds: 20),
      sendTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
      // never throw on a non-2xx here - we translate it to ApiException below
      validateStatus: (_) => true,
    ));

    if (AppConfig.allowBadCertificates) {
      (_dio.httpClientAdapter as IOHttpClientAdapter).createHttpClient = () {
        final c = HttpClient();
        c.badCertificateCallback = (cert, host, port) => true;
        return c;
      };
    }

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final t = await Storage.instance.token;
        if (t != null && t.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $t';
        }
        handler.next(options);
      },
    ));
  }

  static final Api instance = Api._();
  late final Dio _dio;

  /// Set by main() so a 401 anywhere can bounce the whole app to /login.
  static void Function()? onUnauthorized;

  Future<Map<String, dynamic>> get(String path, {Map<String, dynamic>? query}) =>
      _send(() => _dio.get(path, queryParameters: query));

  Future<Map<String, dynamic>> postJson(String path, Map<String, dynamic> body) =>
      _send(() => _dio.post(path, data: body));

  Future<Map<String, dynamic>> postForm(String path, FormData form) =>
      _send(() => _dio.post(path, data: form));

  Future<Map<String, dynamic>> _send(Future<Response> Function() run) async {
    late Response res;
    try {
      res = await run();
    } on DioException catch (e) {
      throw ApiException(0, _friendlyNetworkError(e));
    }

    final data = res.data;
    final map = data is Map<String, dynamic> ? data : <String, dynamic>{};

    if (res.statusCode != null && res.statusCode! >= 200 && res.statusCode! < 300) {
      return map;
    }

    // error
    if (res.statusCode == 401) {
      await Storage.instance.clearAll();
      onUnauthorized?.call();
    }
    throw ApiException(
      res.statusCode ?? 0,
      (map['error'] as String?) ?? 'Something went wrong. Please try again.',
      fields: map['fields'] as Map<String, dynamic>?,
      reason: map['reason'] as String?,
    );
  }

  String _friendlyNetworkError(DioException e) {
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return 'The server took too long to respond. Check your signal and try again.';
      case DioExceptionType.connectionError:
        return 'No connection to the server. Check your internet and try again.';
      default:
        return 'Could not reach the server. Please try again.';
    }
  }
}
