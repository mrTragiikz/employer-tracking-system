import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'package:geolocator/geolocator.dart';
import '../core/theme.dart';
import 'common.dart';

/// Shown once, right after a check-in, to get the permissions live tracking
/// needs: "Allow location all the time" and (best-effort) a battery
/// optimisation exemption so the OS does not kill the recording service on
/// OEM skins (Xiaomi / Oppo / Vivo).
///
/// Returns true if location-always was granted (the service can start).
/// Declining is fine - check-in already succeeded; tracking just won't run.
Future<bool> showTrackingPermissionSheet(BuildContext context) async {
  final result = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    isDismissible: false,
    backgroundColor: AppColors.ground,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
    ),
    builder: (_) => const _Sheet(),
  );
  return result ?? false;
}

class _Sheet extends StatefulWidget {
  const _Sheet();
  @override
  State<_Sheet> createState() => _SheetState();
}

class _SheetState extends State<_Sheet> {
  bool _busy = false;
  String? _note;

  Future<void> _grant() async {
    setState(() {
      _busy = true;
      _note = null;
    });

    // 1. location - ask up to "always". On Android this often needs two steps:
    //    whileInUse first, then a second request bumps it to always (or sends
    //    the user to settings).
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
    }
    if (perm == LocationPermission.whileInUse) {
      perm = await Geolocator.requestPermission();
    }

    final okLocation =
        perm == LocationPermission.always || perm == LocationPermission.whileInUse;

    if (perm == LocationPermission.deniedForever) {
      setState(() {
        _busy = false;
        _note =
            'Location is blocked for this app. Open Settings > Apps > Rajdoot > '
            'Permissions > Location and choose "Allow all the time", then check in again.';
      });
      return;
    }

    // 2. battery optimisation exemption - best effort, no hard dependency.
    try {
      if (Platform.isAndroid &&
          !await FlutterForegroundTask.isIgnoringBatteryOptimizations) {
        await FlutterForegroundTask.requestIgnoreBatteryOptimization();
      }
    } catch (_) {
      // not fatal - the service still runs, just more likely to be killed
    }

    // 3. notification permission (Android 13+) - the service notification.
    try {
      final np = await FlutterForegroundTask.checkNotificationPermission();
      if (np != NotificationPermission.granted) {
        await FlutterForegroundTask.requestNotificationPermission();
      }
    } catch (_) {}

    if (!mounted) return;
    Navigator.of(context).pop(okLocation);
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.fromLTRB(
          20, 16, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 38,
              height: 4,
              margin: const EdgeInsets.only(bottom: 16),
              decoration: BoxDecoration(
                color: AppColors.border,
                borderRadius: BorderRadius.circular(999),
              ),
            ),
          ),
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppColors.panelInset,
                  borderRadius: BorderRadius.circular(11),
                ),
                child: const Icon(Icons.route, color: AppColors.brown, size: 20),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Text('Record your route today',
                    style: Theme.of(context).textTheme.titleLarge),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Text(
            'Your route will be recorded from now until you check out, so your '
            'office can see where you travelled. A notification stays in your '
            'status bar while it records.',
            style: TextStyle(fontSize: 13, color: AppColors.text2, height: 1.6),
          ),
          const SizedBox(height: 10),
          const Text(
            'The next screens ask for "Allow all the time" location and to keep '
            'the app running in the background - please allow both.',
            style: TextStyle(fontSize: 12, color: AppColors.textMuted, height: 1.6),
          ),
          if (_note != null) ...[
            const SizedBox(height: 12),
            FlashBar(text: _note!, isError: true),
          ],
          const SizedBox(height: 16),
          PrimaryButton(
            label: 'Allow and continue',
            icon: Icons.check,
            loading: _busy,
            onPressed: _busy ? null : _grant,
          ),
          const SizedBox(height: 8),
          Center(
            child: TextButton(
              onPressed: _busy ? null : () => Navigator.of(context).pop(false),
              style: TextButton.styleFrom(foregroundColor: AppColors.text2),
              child: const Text('Not now'),
            ),
          ),
        ],
      ),
    );
  }
}
