import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../services/capture_service.dart';
import '../services/outbox.dart';
import '../services/tracking_service.dart';
import '../widgets/capture_fields.dart';
import '../widgets/common.dart';
import '../widgets/outbox_banner.dart';
import '../widgets/tracking_permission_sheet.dart';

/// Check In / Out - the web field/checkinout/ page. Owns the check-in and
/// check-out ACTIONS (Attendance tab is read-only history).
///
///  - not checked in, window open  -> check-in form (GPS + odometer photo + KM)
///  - not checked in, window closed -> "Too Late to Check In"
///  - checked in, not out           -> summary + Check Out button
///    (blocked while a visit is open)
///  - check-out tapped              -> check-out form (same shape)
///  - checked out                   -> full day summary
class CheckInOutScreen extends StatefulWidget {
  const CheckInOutScreen({super.key, this.onChanged});

  /// Called after a successful check-in or check-out so the shell can
  /// refresh the other tabs.
  final VoidCallback? onChanged;

  @override
  State<CheckInOutScreen> createState() => CheckInOutScreenState();
}

class CheckInOutScreenState extends State<CheckInOutScreen> {
  Map<String, dynamic>? _me;
  Object? _error;
  bool _loading = true;

  // check-out mode toggle (web: ?action=checkout)
  bool _checkoutMode = false;

  @override
  void initState() {
    super.initState();
    Outbox.instance.addListener(_onOutbox);
    load();
  }

  @override
  void dispose() {
    Outbox.instance.removeListener(_onOutbox);
    super.dispose();
  }

  void _onOutbox() {
    if (!mounted) return;
    setState(() {});
    if (Outbox.instance.isEmpty) load();
  }

  Future<void> load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final me = await Api.instance.get('/me.php');
      final visits = await Api.instance.get('/visits.php');
      if (!mounted) return;
      setState(() {
        _me = {...me, 'open_visit_id': visits['open_visit_id']};
        _loading = false;
        _checkoutMode = false;
      });
      // Live tracking follows the check-in state: recording between check-in
      // and check-out, off otherwise. The service itself no-ops if the admin
      // has tracking turned off (the first ping response tells it to stop).
      final att = me['attendance'];
      final checkedIn = att is Map && att['checked_in'] == true;
      final checkedOut = att is Map && att['checked_out'] == true;
      if (checkedIn && !checkedOut) {
        await _ensureTrackingPermissionThenStart();
      } else {
        await TrackingService.instance.stop();
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  Future<void> _refreshFromServer() async {
    await load();
    widget.onChanged?.call();
  }

  bool _trackingPrompted = false;

  /// Ask for "Allow all the time" location the first time after a check-in,
  /// then start the recording service. If the worker declines, check-in still
  /// works - tracking just does not run and a note shows on the checked-in
  /// card. Runs at most once per screen lifetime.
  Future<void> _ensureTrackingPermissionThenStart() async {
    if (await TrackingService.instance.isRunning) return;
    if (_trackingPrompted) {
      await TrackingService.instance.start();
      return;
    }
    _trackingPrompted = true;
    if (!mounted) return;
    final ok = await showTrackingPermissionSheet(context);
    if (ok) {
      await TrackingService.instance.start();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Loading();
    if (_error != null) {
      return ErrorRetry(
        message: _error is ApiException
            ? (_error as ApiException).message
            : 'Could not load your attendance.',
        onRetry: load,
      );
    }

    final me = _me!;
    final att = me['attendance'] is Map<String, dynamic>
        ? Attendance.fromJson(me['attendance'] as Map<String, dynamic>)
        : null;
    final checkedIn = att?.checkedIn ?? false;
    final checkedOut = att?.checkedOut ?? false;
    final blocked = me['checkin_blocked'] == true;
    final openVisitId = me['open_visit_id'] as int?;

    Widget body;
    if (checkedOut) {
      body = _DaySummary(att: att!);
    } else if (_checkoutMode) {
      body = _CheckForm(
        key: const ValueKey('checkout'),
        mode: _CheckMode.checkout,
        checkInKm: att!.checkInOdometerKm,
        onDone: _refreshFromServer,
        onCancel: () => setState(() => _checkoutMode = false),
      );
    } else if (checkedIn) {
      body = _CheckedInCard(
        att: att!,
        openVisitId: openVisitId,
        onCheckOut: () => setState(() => _checkoutMode = true),
      );
    } else if (blocked) {
      body = const _BlockedCard();
    } else {
      body = _CheckForm(
        key: const ValueKey('checkin'),
        mode: _CheckMode.checkin,
        onDone: _refreshFromServer,
      );
    }

    return RefreshIndicator(
      color: AppColors.brown,
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text('Check In/Out', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 4),
          Text(Fmt.longDate(DateTime.now()),
              style: const TextStyle(fontSize: 12, color: AppColors.text2)),
          const SizedBox(height: 16),
          const OutboxBanner(),
          body,
        ],
      ),
    );
  }
}

// ============================ check-in / check-out form ======================

enum _CheckMode { checkin, checkout }

class _CheckForm extends StatefulWidget {
  const _CheckForm({
    super.key,
    required this.mode,
    required this.onDone,
    this.checkInKm,
    this.onCancel,
  });

  final _CheckMode mode;
  final Future<void> Function() onDone;
  final double? checkInKm;
  final VoidCallback? onCancel;

  @override
  State<_CheckForm> createState() => _CheckFormState();
}

class _CheckFormState extends State<_CheckForm> {
  final _km = TextEditingController();
  GpsFix? _fix;
  File? _photo;
  bool _submitting = false;
  String? _error;
  Map<String, dynamic>? _fieldErrors;

  bool get _isCheckout => widget.mode == _CheckMode.checkout;

  @override
  void dispose() {
    _km.dispose();
    super.dispose();
  }

  bool get _ready => _fix != null && _photo != null && _km.text.trim().isNotEmpty;

  Future<void> _submit() async {
    setState(() {
      _error = null;
      _fieldErrors = null;
    });

    final kmText = _km.text.trim();
    final kmVal = double.tryParse(kmText);
    if (kmVal == null || kmVal < 0) {
      setState(() => _fieldErrors = {'odometer_km': 'Enter a valid bike KM reading.'});
      return;
    }
    if (_isCheckout && widget.checkInKm != null && kmVal < widget.checkInKm!) {
      setState(() => _fieldErrors = {
            'odometer_km':
                'Check-out KM cannot be less than your check-in KM (${widget.checkInKm!.toStringAsFixed(1)} km).'
          });
      return;
    }
    if (_fix == null || _photo == null) {
      setState(() => _error = 'Capture your location and take the odometer photo first.');
      return;
    }

    setState(() => _submitting = true);

    final fields = <String, String>{
      'lat': _fix!.lat.toString(),
      'lng': _fix!.lng.toString(),
      if (_fix!.accuracyM != null) 'accuracy': _fix!.accuracyM!.toString(),
      'location_denied': '0',
      'odometer_km': kmText,
    };

    try {
      final form = FormData.fromMap({
        ...fields,
        'odometer_photo':
            await MultipartFile.fromFile(_photo!.path, filename: 'odometer.jpg'),
      });
      await Api.instance.postForm(
        _isCheckout ? '/checkout.php' : '/checkin.php',
        form,
      );
      if (!mounted) return;
      await widget.onDone();
    } on ApiException catch (e) {
      if (!mounted) return;
      if (e.statusCode == 0) {
        // offline - queue it and move on; the outbox replays when back online
        await Outbox.instance.enqueue(
          _isCheckout ? OutboxKind.checkout : OutboxKind.checkin,
          fields: fields,
          photo: _photo,
        );
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('No signal - saved. It will sync automatically.'),
        ));
        await widget.onDone();
        return;
      }
      setState(() {
        _submitting = false;
        _error = e.message;
        _fieldErrors = e.fields;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final title = _isCheckout ? 'Check Out' : 'Check In';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (widget.onCancel != null)
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(
              onPressed: _submitting ? null : widget.onCancel,
              icon: const Icon(Icons.arrow_back, size: 16),
              label: const Text('Back'),
              style: TextButton.styleFrom(foregroundColor: AppColors.text2),
            ),
          ),
        if (_error != null) FlashBar(text: _error!, isError: true),
        LocationField(fix: _fix, onFix: (f) => setState(() => _fix = f)),
        PhotoField(
          file: _photo,
          onFile: (f) => setState(() => _photo = f),
          title: 'Bike current meter photo',
          hint: 'Take a clear photo of your bike / scooter odometer reading.',
        ),
        // KM field
        Container(
          margin: const EdgeInsets.only(bottom: 14),
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: AppColors.cardBg,
            borderRadius: BorderRadius.circular(AppRadius.card),
            border: Border.all(color: AppColors.borderSoft),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Bike KM (from the odometer)',
                  style: TextStyle(fontSize: 13, fontWeight: FontWeight.w600, color: AppColors.text)),
              const SizedBox(height: 6),
              TextField(
                controller: _km,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
                onChanged: (_) => setState(() {}),
                decoration: const InputDecoration(hintText: 'e.g. 24583.5'),
              ),
              if (_fieldErrors?['odometer_km'] != null) ...[
                const SizedBox(height: 5),
                Text('${_fieldErrors!['odometer_km']}',
                    style: const TextStyle(fontSize: 11.5, color: AppColors.down)),
              ],
            ],
          ),
        ),
        PrimaryButton(
          label: title,
          icon: Icons.check_circle,
          loading: _submitting,
          onPressed: _ready && !_submitting ? _submit : null,
        ),
        const SizedBox(height: 6),
        if (!_ready)
          const Text('Capture location, take the photo, and enter the KM to continue.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 11, color: AppColors.textMuted)),
      ],
    );
  }
}

// ============================ status cards ==================================

class _CheckedInCard extends StatelessWidget {
  const _CheckedInCard({required this.att, required this.openVisitId, required this.onCheckOut});
  final Attendance att;
  final int? openVisitId;
  final VoidCallback onCheckOut;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFF3F8F2),
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: const Color(0xFFCDE6D2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.check_circle, color: AppColors.ok, size: 22),
              const SizedBox(width: 10),
              Text('Checked In', style: Theme.of(context).textTheme.titleLarge),
            ],
          ),
          const SizedBox(height: 4),
          const Text("You're marked present for today.",
              style: TextStyle(fontSize: 12, color: AppColors.text2)),
          const SizedBox(height: 14),
          _kv('Check-in', Fmt.time(att.checkInAt)),
          if (att.checkInLat != null && att.checkInLng != null)
            _kv('Location', Fmt.coord(att.checkInLat!, att.checkInLng!)),
          if (att.checkInOdometerKm != null)
            _kv('Check-in KM', '${att.checkInOdometerKm!.toStringAsFixed(1)} km'),
          const SizedBox(height: 16),
          if (openVisitId != null)
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFFFBF3E6),
                borderRadius: BorderRadius.circular(AppRadius.sm),
                border: Border.all(color: const Color(0xFFEBD9B8)),
              ),
              child: const Row(
                children: [
                  Icon(Icons.info, size: 16, color: AppColors.accent),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Mark your open visit Done (on My Visits) before you can check out.',
                      style: TextStyle(fontSize: 11.5, color: AppColors.text2, height: 1.4),
                    ),
                  ),
                ],
              ),
            )
          else
            PrimaryButton(
              label: 'Check Out',
              icon: Icons.logout,
              onPressed: onCheckOut,
            ),
        ],
      ),
    );
  }

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(child: Text(k, style: const TextStyle(fontSize: 12, color: AppColors.text2))),
            Text(v,
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.text)),
          ],
        ),
      );
}

class _DaySummary extends StatelessWidget {
  const _DaySummary({required this.att});
  final Attendance att;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFF3F8F2),
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: const Color(0xFFCDE6D2)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.verified, color: AppColors.ok, size: 22),
              const SizedBox(width: 10),
              Text('Day Complete', style: Theme.of(context).textTheme.titleLarge),
            ],
          ),
          const SizedBox(height: 4),
          const Text("You're checked out for today.",
              style: TextStyle(fontSize: 12, color: AppColors.text2)),
          const SizedBox(height: 14),
          _kv('Check-in', Fmt.time(att.checkInAt)),
          _kv('Check-out', Fmt.time(att.checkOutAt)),
          if (att.checkInOdometerKm != null)
            _kv('Check-in KM', '${att.checkInOdometerKm!.toStringAsFixed(1)} km'),
          if (att.checkOutOdometerKm != null)
            _kv('Check-out KM', '${att.checkOutOdometerKm!.toStringAsFixed(1)} km'),
          _kv('Productive KM', Fmt.km(att.roadKm)),
          _kv('Time at shops', Fmt.hm(att.shopSeconds)),
        ],
      ),
    );
  }

  Widget _kv(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          children: [
            Expanded(child: Text(k, style: const TextStyle(fontSize: 12, color: AppColors.text2))),
            Text(v,
                style: const TextStyle(
                    fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.text)),
          ],
        ),
      );
}

class _BlockedCard extends StatelessWidget {
  const _BlockedCard();

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: const Color(0xFFFBE7E5),
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: const Color(0xFFF0C4C0)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.cancel, color: AppColors.down, size: 22),
              const SizedBox(width: 10),
              Text('Too Late to Check In', style: Theme.of(context).textTheme.titleLarge),
            ],
          ),
          const SizedBox(height: 6),
          const Text(
            'You are too late for today\'s attendance. Sorry, you have been marked absent.',
            style: TextStyle(fontSize: 12, color: AppColors.text2, height: 1.5),
          ),
        ],
      ),
    );
  }
}
