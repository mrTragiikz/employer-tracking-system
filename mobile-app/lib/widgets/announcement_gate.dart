import 'dart:async';
import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/config.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../services/auth_service.dart';
import '../services/outbox.dart';
import 'common.dart';

/// Wraps the signed-in app and shows the admin -> employee announcement as a
/// modal, the same as the web popup (field/components/announcement/). Polls
/// GET /announcement.php every 8s (AppConfig.announcementPoll) plus on app
/// resume. An admin EDIT / "Push again" clears dismissals, so a re-pushed
/// announcement pops again on the next poll.
class AnnouncementGate extends StatefulWidget {
  const AnnouncementGate({super.key, required this.child});
  final Widget child;

  @override
  State<AnnouncementGate> createState() => _AnnouncementGateState();
}

class _AnnouncementGateState extends State<AnnouncementGate> with WidgetsBindingObserver {
  Timer? _timer;
  bool _inFlight = false;
  int? _showingId;
  bool _dialogOpen = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    AuthService.instance.addListener(_onAuthChange);
    _syncPolling();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    AuthService.instance.removeListener(_onAuthChange);
    _timer?.cancel();
    super.dispose();
  }

  void _onAuthChange() => _syncPolling();

  void _syncPolling() {
    final signedIn = AuthService.instance.status == AuthStatus.signedIn;
    if (signedIn && _timer == null) {
      _poll();
      _timer = Timer.periodic(AppConfig.announcementPoll, (_) => _poll());
    } else if (!signedIn && _timer != null) {
      _timer?.cancel();
      _timer = null;
      _showingId = null;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _poll();
      Outbox.instance.flush();
    }
  }

  Future<void> _poll() async {
    if (_inFlight || _dialogOpen) return;
    if (AuthService.instance.status != AuthStatus.signedIn) return;
    _inFlight = true;
    try {
      final res = await Api.instance.get('/announcement.php');
      final raw = res['announcement'];
      if (raw is! Map<String, dynamic>) return;
      final ann = Announcement.fromJson(raw);
      if (ann.id == 0 || ann.id == _showingId) return;
      if (!mounted) return;
      _showingId = ann.id;
      await _show(ann);
    } catch (_) {
      // network hiccup - try again next tick
    } finally {
      _inFlight = false;
    }
  }

  Future<void> _show(Announcement ann) async {
    _dialogOpen = true;
    await showDialog<void>(
      context: context,
      barrierDismissible: false,
      builder: (_) => _AnnouncementDialog(ann: ann),
    );
    _dialogOpen = false;
    // dismissal already recorded by the dialog's button
  }

  @override
  Widget build(BuildContext context) => widget.child;
}

class _AnnouncementDialog extends StatefulWidget {
  const _AnnouncementDialog({required this.ann});
  final Announcement ann;

  @override
  State<_AnnouncementDialog> createState() => _AnnouncementDialogState();
}

class _AnnouncementDialogState extends State<_AnnouncementDialog> {
  bool _busy = false;

  Future<void> _dismiss() async {
    setState(() => _busy = true);
    try {
      await Api.instance.postJson('/announcement-dismiss.php', {'id': widget.ann.id});
    } catch (_) {
      // always close; a failed dismiss just re-pops next poll (harmless)
    }
    if (mounted) Navigator.of(context).pop();
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: AppColors.cardBg,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.card)),
      insetPadding: const EdgeInsets.symmetric(horizontal: 28, vertical: 40),
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxHeight: 480),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.fromLTRB(18, 18, 18, 12),
              decoration: const BoxDecoration(
                gradient: AppColors.buttonGradient,
                borderRadius: BorderRadius.vertical(top: Radius.circular(AppRadius.card)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.campaign, color: Colors.white, size: 20),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Text(
                      widget.ann.title,
                      style: const TextStyle(
                          color: Colors.white, fontSize: 15, fontWeight: FontWeight.w700),
                    ),
                  ),
                ],
              ),
            ),
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(18, 14, 18, 16),
                child: Text(
                  widget.ann.body,
                  style: const TextStyle(fontSize: 13, color: AppColors.text, height: 1.55),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(18, 0, 18, 16),
              child: PrimaryButton(
                label: 'Got it',
                icon: Icons.check,
                loading: _busy,
                onPressed: _busy ? null : _dismiss,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
