import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../services/auth_service.dart';
import '../widgets/common.dart';
import '../widgets/stat_tile.dart';

/// Home / Dashboard - the web field/home/ page: greeting, attendance CTA,
/// and the 4 stat tiles (Status, Today's Visits, Productive Work KM,
/// Check-out KM).
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, this.onGoToTab});

  /// Lets the CTA buttons jump the shell to another tab
  /// (0 dashboard, 1 check-in/out, 2 visits).
  final void Function(int tab)? onGoToTab;

  @override
  State<HomeScreen> createState() => HomeScreenState();
}

class HomeScreenState extends State<HomeScreen> {
  HomeData? _data;
  Object? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    load();
  }

  Future<void> load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await Api.instance.get('/home.php');
      if (!mounted) return;
      setState(() {
        _data = HomeData.fromJson(res);
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Loading();
    if (_error != null) {
      return ErrorRetry(
        message: _error is ApiException
            ? (_error as ApiException).message
            : 'Could not load your dashboard.',
        onRetry: load,
      );
    }

    final d = _data!;
    final att = d.attendance;
    final checkedIn = att?.checkedIn ?? false;
    final checkedOut = att?.checkedOut ?? false;
    final date = Fmt.parseDate(d.date);
    final firstName = AuthService.instance.employee?.firstName ?? 'there';

    String sub;
    if (checkedOut) {
      sub = "You're checked out for today. Nice work!";
    } else if (checkedIn) {
      sub = "You're checked in - keep logging your visits.";
    } else {
      sub = "Let's mark your first attendance and start your day.";
    }

    return RefreshIndicator(
      color: AppColors.brown,
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // greeting
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${d.greeting}, $firstName 👋',
                        style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: 4),
                    Text(sub,
                        style: const TextStyle(
                            color: AppColors.text2, fontSize: 12.5, height: 1.4)),
                  ],
                ),
              ),
              if (date != null) ...[
                const SizedBox(width: 8),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.panelInset,
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.calendar_today,
                          size: 12, color: AppColors.text2),
                      const SizedBox(width: 5),
                      Text(Fmt.shortDate(date),
                          style: const TextStyle(
                              fontSize: 11,
                              color: AppColors.text2,
                              fontWeight: FontWeight.w600)),
                    ],
                  ),
                ),
              ],
            ],
          ),
          const SizedBox(height: 16),

          // attendance CTA
          if (!checkedIn)
            _CtaCard(
              icon: Icons.fingerprint,
              iconOk: false,
              title: 'Mark Your First Attendance',
              body:
                  'Your attendance for today is not marked yet. Tap below to check in with your location.',
              buttonLabel: 'Mark Attendance Now',
              buttonIcon: Icons.place,
              onPressed: () => widget.onGoToTab?.call(1),
            )
          else
            _CtaCard(
              icon: Icons.check_circle,
              iconOk: true,
              title: checkedOut ? 'Day Complete' : 'Checked In',
              body: _checkedInLine(att!),
              buttonLabel: checkedOut ? null : 'Go to My Visits',
              buttonIcon: Icons.storefront,
              ghost: true,
              onPressed:
                  checkedOut ? null : () => widget.onGoToTab?.call(2),
            ),
          const SizedBox(height: 16),

          // stat tiles - 2x2 grid
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 10,
            crossAxisSpacing: 10,
            childAspectRatio: 1.45,
            children: [
              StatTile(
                icon: Icons.check_circle_outline,
                iconColor: AppColors.ok,
                label: 'Status',
                showTick: checkedIn,
                value: checkedOut
                    ? 'Checked Out'
                    : (checkedIn ? 'Checked In' : 'Not Marked'),
                foot: checkedIn
                    ? '${Fmt.time(att!.checkInAt)} · ${(att.checkInOdometerKm ?? 0).toStringAsFixed(1)} km'
                    : "You haven't checked in today",
              ),
              StatTile(
                icon: Icons.place_outlined,
                iconColor: const Color(0xFF3977C9),
                label: "Today's Visits",
                value: '${d.visitsToday}',
                foot: 'Shops visited today',
              ),
              StatTile(
                icon: Icons.map_outlined,
                iconColor: AppColors.accent,
                label: 'Productive Work KM',
                value: Fmt.km(d.kmToday),
                foot: 'travelled so far',
              ),
              StatTile(
                icon: Icons.logout,
                iconColor: AppColors.down,
                label: 'Check-out KM',
                value: checkedOut
                    ? '${(att!.checkOutOdometerKm ?? 0).toStringAsFixed(1)} km'
                    : '—',
                foot: checkedOut
                    ? Fmt.time(att!.checkOutAt)
                    : 'Not checked out yet',
              ),
            ],
          ),
        ],
      ),
    );
  }

  String _checkedInLine(Attendance a) {
    final b = StringBuffer('Checked in at ${Fmt.time(a.checkInAt)}');
    if (a.checkInLat != null && a.checkInLng != null) {
      b.write(' · ${Fmt.coord(a.checkInLat!, a.checkInLng!)}');
    }
    if (a.checkedOut) {
      b.write(' · checked out at ${Fmt.time(a.checkOutAt)}');
    }
    return b.toString();
  }
}

class _CtaCard extends StatelessWidget {
  const _CtaCard({
    required this.icon,
    required this.iconOk,
    required this.title,
    required this.body,
    this.buttonLabel,
    this.buttonIcon,
    this.onPressed,
    this.ghost = false,
  });

  final IconData icon;
  final bool iconOk;
  final String title;
  final String body;
  final String? buttonLabel;
  final IconData? buttonIcon;
  final VoidCallback? onPressed;
  final bool ghost;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: iconOk ? const Color(0xFFF3F8F2) : AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(
            color: iconOk ? const Color(0xFFCDE6D2) : AppColors.borderSoft),
      ),
      child: Column(
        children: [
          Container(
            width: 46,
            height: 46,
            decoration: BoxDecoration(
              color: iconOk ? const Color(0xFFDDEEDD) : AppColors.panelInset,
              shape: BoxShape.circle,
            ),
            child: Icon(icon,
                color: iconOk ? AppColors.ok : AppColors.brown, size: 22),
          ),
          const SizedBox(height: 10),
          Text(title, style: Theme.of(context).textTheme.titleLarge),
          const SizedBox(height: 6),
          Text(body,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  color: AppColors.text2, fontSize: 12, height: 1.5)),
          if (buttonLabel != null) ...[
            const SizedBox(height: 14),
            ghost
                ? GhostButton(
                    label: buttonLabel!,
                    icon: buttonIcon,
                    onPressed: onPressed)
                : PrimaryButton(
                    label: buttonLabel!,
                    icon: buttonIcon,
                    onPressed: onPressed),
          ],
        ],
      ),
    );
  }
}
