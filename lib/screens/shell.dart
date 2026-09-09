import 'package:flutter/material.dart';
import '../core/theme.dart';
import '../services/auth_service.dart';
import 'attendance_screen.dart';
import 'home_screen.dart';
import 'profile_screen.dart';
import 'routes_screen.dart';

/// The signed-in shell: a bottom tab bar (Dashboard / Check In-Out / My Visits
/// / Routes / Attendance), matching the web field app's footer.php tabs.
///
/// A top bar carries the page title and a tappable avatar that opens the
/// Profile screen (same as the web header). Check In/Out and My Visits are
/// still placeholders - they land in B3 Session 2 and 3 (GPS + camera).
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _tab = 0;
  final _homeKey = GlobalKey<HomeScreenState>();

  static const _tabs = [
    (label: 'Dashboard', icon: Icons.grid_view_rounded),
    (label: 'Check In/Out', icon: Icons.login_rounded),
    (label: 'My Visits', icon: Icons.storefront_rounded),
    (label: 'Routes', icon: Icons.signpost_rounded),
    (label: 'Attendance', icon: Icons.history_rounded),
  ];

  void _goToTab(int i) {
    setState(() => _tab = i);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
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
            const _Placeholder('Check In/Out', note: 'GPS + camera - coming next (B3 Session 2)'),
            const _Placeholder('My Visits', note: 'GPS + camera - coming next (B3 Session 3)'),
            const RoutesScreen(),
            const AttendanceScreen(),
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

class _Placeholder extends StatelessWidget {
  const _Placeholder(this.name, {this.note});
  final String name;
  final String? note;
  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.construction, size: 40, color: AppColors.textMuted),
              const SizedBox(height: 12),
              Text(name,
                  style: const TextStyle(
                      fontSize: 15, fontWeight: FontWeight.w700, color: AppColors.text)),
              if (note != null) ...[
                const SizedBox(height: 6),
                Text(note!,
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: AppColors.textMuted, fontSize: 12, height: 1.5)),
              ],
            ],
          ),
        ),
      );
}
