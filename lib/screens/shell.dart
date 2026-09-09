import 'package:flutter/material.dart';
import '../core/theme.dart';
import '../services/auth_service.dart';
import '../widgets/announcement_gate.dart';
import 'attendance_screen.dart';
import 'checkinout_screen.dart';
import 'home_screen.dart';
import 'profile_screen.dart';
import 'routes_screen.dart';
import 'visits_screen.dart';

/// The signed-in shell: a bottom tab bar (Dashboard / Check In-Out / My Visits
/// / Routes / Attendance), matching the web field app's footer.php tabs.
///
/// A top bar carries the page title and a tappable avatar that opens the
/// Profile screen (same as the web header). The whole shell is wrapped in
/// AnnouncementGate, which polls for and shows admin -> employee popups.
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _tab = 0;

  final _homeKey = GlobalKey<HomeScreenState>();
  final _checkKey = GlobalKey<CheckInOutScreenState>();
  final _visitsKey = GlobalKey<VisitsScreenState>();
  final _routesKey = GlobalKey<RoutesScreenState>();
  final _attKey = GlobalKey<AttendanceScreenState>();

  static const _tabs = [
    (label: 'Dashboard', icon: Icons.grid_view_rounded),
    (label: 'Check In/Out', icon: Icons.login_rounded),
    (label: 'My Visits', icon: Icons.storefront_rounded),
    (label: 'Routes', icon: Icons.signpost_rounded),
    (label: 'Attendance', icon: Icons.history_rounded),
  ];

  void _goToTab(int i) => setState(() => _tab = i);

  /// After a check-in/out or a visit change, refresh the sibling screens so
  /// the dashboard KM / routes / attendance stay in step.
  void _refreshSiblings() {
    _homeKey.currentState?.load();
    _visitsKey.currentState?.load();
    _routesKey.currentState?.load();
    _attKey.currentState?.load();
    _checkKey.currentState?.load();
  }

  @override
  Widget build(BuildContext context) {
    return AnnouncementGate(
      child: Scaffold(
        backgroundColor: AppColors.ground,
        appBar: _TopBar(
          title: _tabs[_tab].label,
          onAvatarTap: () => Navigator.of(context).push(
            MaterialPageRoute(builder: (_) => const _ProfilePage()),
          ),
        ),
        body: SafeArea(
          top: false,
          child: IndexedStack(
            index: _tab,
            children: [
              HomeScreen(key: _homeKey, onGoToTab: _goToTab),
              CheckInOutScreen(key: _checkKey, onChanged: _refreshSiblings),
              VisitsScreen(key: _visitsKey, onChanged: _refreshSiblings),
              RoutesScreen(key: _routesKey),
              AttendanceScreen(key: _attKey),
            ],
          ),
        ),
        bottomNavigationBar: NavigationBar(
          height: 64,
          backgroundColor: AppColors.cardBg,
          indicatorColor: AppColors.panelInset,
          selectedIndex: _tab,
          onDestinationSelected: (i) => setState(() => _tab = i),
          destinations: [
            for (final t in _tabs)
              NavigationDestination(icon: Icon(t.icon, size: 22), label: t.label),
          ],
        ),
      ),
    );
  }
}

class _TopBar extends StatelessWidget implements PreferredSizeWidget {
  const _TopBar({required this.title, required this.onAvatarTap});
  final String title;
  final VoidCallback onAvatarTap;

  @override
  Size get preferredSize => const Size.fromHeight(52);

  @override
  Widget build(BuildContext context) {
    final me = AuthService.instance.employee;
    final initials = () {
      final n = me?.name.trim() ?? '';
      if (n.isEmpty) return '?';
      return n.length >= 2 ? n.substring(0, 2).toUpperCase() : n.toUpperCase();
    }();

    return AppBar(
      title: Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
      actions: [
        Padding(
          padding: const EdgeInsets.only(right: 14),
          child: GestureDetector(
            onTap: onAvatarTap,
            child: (me?.photoUrl != null && me!.photoUrl!.isNotEmpty)
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: Image.network(me.photoUrl!, width: 32, height: 32, fit: BoxFit.cover,
                        errorBuilder: (c, e, s) => _initialsAvatar(initials)),
                  )
                : _initialsAvatar(initials),
          ),
        ),
      ],
    );
  }

  Widget _initialsAvatar(String initials) => Container(
        width: 32,
        height: 32,
        alignment: Alignment.center,
        decoration: const BoxDecoration(color: AppColors.panelInset, shape: BoxShape.circle),
        child: Text(initials,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.brown)),
      );
}

/// Profile as a pushed page with its own back-bar.
class _ProfilePage extends StatelessWidget {
  const _ProfilePage();
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ground,
      appBar: AppBar(
        title: const Text('My Profile',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
      ),
      body: const SafeArea(top: false, child: ProfileScreen()),
    );
  }
}
