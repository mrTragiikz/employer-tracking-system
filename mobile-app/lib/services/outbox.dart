import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:path_provider/path_provider.dart';
import 'package:uuid/uuid.dart';
import '../core/api.dart';

/// The offline outbox - the whole point of going native.
///
/// A field worker in a shop basement with no signal can still check in, log a
/// visit, mark it done, and check out. Each action is written to a local
/// queue (a JSON file + any photo copied into app storage) and replayed, in
/// order, the moment the network is back (on app resume, on a timer, and
/// right after the action is queued in case it was just a blip).
///
/// Ordering matters: check-in must land before its visits, a visit-save
/// before its complete, and check-out last. The queue is strictly FIFO and
/// stops on the first failure so nothing runs out of order.
enum OutboxKind { checkin, visitSave, visitComplete, checkout }

class OutboxItem {
  OutboxItem({
    required this.id,
    required this.kind,
    required this.fields,
    required this.photoPath,
    required this.createdAt,
    this.attempts = 0,
    this.lastError,
  });

  final String id;
  final OutboxKind kind;
  final Map<String, String> fields;
  final String? photoPath; // local copy inside app storage
  final DateTime createdAt;
  int attempts;
  String? lastError;

  String get endpoint => switch (kind) {
        OutboxKind.checkin => '/checkin.php',
        OutboxKind.visitSave => '/visit-save.php',
        OutboxKind.visitComplete => '/visit-complete.php',
        OutboxKind.checkout => '/checkout.php',
      };

  String get label => switch (kind) {
        OutboxKind.checkin => 'Check-in',
        OutboxKind.visitSave => 'Visit "${fields['shop_name'] ?? ''}"',
        OutboxKind.visitComplete => 'Visit marked done',
        OutboxKind.checkout => 'Check-out',
      };

  String get photoField => kind == OutboxKind.visitSave ? 'shop_photo' : 'odometer_photo';
  bool get isJson => kind == OutboxKind.visitComplete;

  Map<String, dynamic> toJson() => {
        'id': id,
        'kind': kind.name,
        'fields': fields,
        'photoPath': photoPath,
        'createdAt': createdAt.toIso8601String(),
        'attempts': attempts,
        'lastError': lastError,
      };

  factory OutboxItem.fromJson(Map<String, dynamic> j) => OutboxItem(
        id: j['id'] as String,
        kind: OutboxKind.values.byName(j['kind'] as String),
        fields: Map<String, String>.from(j['fields'] as Map),
        photoPath: j['photoPath'] as String?,
        createdAt: DateTime.parse(j['createdAt'] as String),
        attempts: (j['attempts'] as num?)?.toInt() ?? 0,
        lastError: j['lastError'] as String?,
      );
}

class Outbox extends ChangeNotifier {
  Outbox._();
  static final Outbox instance = Outbox._();

  final List<OutboxItem> _items = [];
  bool _loaded = false;
  bool _flushing = false;
  Timer? _timer;

  List<OutboxItem> get items => List.unmodifiable(_items);
  int get pending => _items.length;
  bool get isFlushing => _flushing;
  bool get isEmpty => _items.isEmpty;

  Directory? _dir;
  Future<Directory> get _storeDir async {
    if (_dir != null) return _dir!;
    final base = await getApplicationDocumentsDirectory();
    final d = Directory('${base.path}/outbox');
    if (!await d.exists()) await d.create(recursive: true);
    return _dir = d;
  }

  Future<File> get _queueFile async => File('${(await _storeDir).path}/queue.json');

  /// Call once at startup.
  Future<void> init() async {
    if (_loaded) return;
    _loaded = true;
    try {
      final f = await _queueFile;
      if (await f.exists()) {
        final raw = jsonDecode(await f.readAsString()) as List;
        _items
          ..clear()
          ..addAll(raw.map((e) => OutboxItem.fromJson(e as Map<String, dynamic>)));
      }
    } catch (_) {
      // corrupt queue - start clean rather than crash
      _items.clear();
    }
    _timer ??= Timer.periodic(const Duration(seconds: 25), (_) => flush());
    notifyListeners();
    unawaited(flush());
  }

  Future<void> _persist() async {
    final f = await _queueFile;
    await f.writeAsString(jsonEncode(_items.map((e) => e.toJson()).toList()));
  }

  /// Add an action. If a [photo] is given it is COPIED into app storage so it
  /// survives even if the camera temp file is cleaned up. Returns the item.
  Future<OutboxItem> enqueue(
    OutboxKind kind, {
    required Map<String, String> fields,
    File? photo,
  }) async {
    final id = const Uuid().v4();
    String? storedPhoto;
    if (photo != null) {
      final dir = await _storeDir;
      final dest = File('${dir.path}/$id.jpg');
      await photo.copy(dest.path);
      storedPhoto = dest.path;
    }
    final item = OutboxItem(
      id: id,
      kind: kind,
      fields: fields,
      photoPath: storedPhoto,
      createdAt: DateTime.now(),
    );
    _items.add(item);
    await _persist();
    notifyListeners();
    // try straight away - the "offline" may have been a one-second blip
    unawaited(flush());
    return item;
  }

  /// Replay the queue in order. Stops on the first item that fails so nothing
  /// is applied out of sequence. Safe to call often; it no-ops if already
  /// running or empty.
  Future<void> flush() async {
    if (_flushing || _items.isEmpty) return;
    _flushing = true;
    notifyListeners();

    try {
      while (_items.isNotEmpty) {
        final item = _items.first;
        try {
          await _send(item);
          // success - drop it and its photo
          _items.removeAt(0);
          if (item.photoPath != null) {
            try {
              await File(item.photoPath!).delete();
            } catch (_) {}
          }
          await _persist();
          notifyListeners();
        } on ApiException catch (e) {
          if (e.statusCode == 0) {
            // network - stop, keep the queue, retry later
            item.lastError = e.message;
            break;
          }
          // a real 4xx/5xx: the server rejected it (e.g. already checked in,
          // window closed, duplicate shop). Retrying will never help - drop
          // it so the queue is not stuck forever, but record why.
          item.attempts++;
          item.lastError = e.message;
          _items.removeAt(0);
          if (item.photoPath != null) {
            try {
              await File(item.photoPath!).delete();
            } catch (_) {}
          }
          _rejected.add(item);
          await _persist();
          notifyListeners();
        }
      }
    } finally {
      _flushing = false;
      notifyListeners();
    }
  }

  Future<void> _send(OutboxItem item) async {
    if (item.isJson) {
      await Api.instance.postJson(item.endpoint, item.fields);
      return;
    }
    final map = <String, dynamic>{...item.fields};
    if (item.photoPath != null) {
      map[item.photoField] = await MultipartFile.fromFile(
        item.photoPath!,
        filename: '${item.photoField}.jpg',
      );
    }
    await Api.instance.postForm(item.endpoint, FormData.fromMap(map));
  }

  /// Server-rejected items (shown once, then cleared by the UI).
  final List<OutboxItem> _rejected = [];
  List<OutboxItem> takeRejected() {
    final out = List<OutboxItem>.from(_rejected);
    _rejected.clear();
    return out;
  }

  /// Wipe everything - used on logout.
  Future<void> clear() async {
    for (final it in _items) {
      if (it.photoPath != null) {
        try {
          await File(it.photoPath!).delete();
        } catch (_) {}
      }
    }
    _items.clear();
    _rejected.clear();
    try {
      await (await _queueFile).delete();
    } catch (_) {}
    notifyListeners();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }
}
