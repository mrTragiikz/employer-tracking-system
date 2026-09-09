import 'package:flutter/material.dart';
import '../core/theme.dart';
import '../services/auth_service.dart';

/// The signed-in shell: a bottom tab bar (Dashboard / Check In-Out / My Visits
/// / Routes / Attendance), matching the web field app's footer.php tabs.
///
/// B1: the tab pages are placeholders. Real screens land in B3, one per
/// session. The Dashboard placeholder shows the logged-in employee + a
/// working Logout, so we can prove the whole auth loop end to end.
class AppShell extends StatefulWidget {
  const AppShell({super.key});
  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  int _tab = 0;

  static const _tabs = [
    (label: 'Dashboard', icon: Icons.grid_view_rounded),
    (label: 'Check In/Out', icon: Icons.login_rounded),
    (label: 'My Visits', icon: Icons.storefront_rounded),
    (label: 'Routes', icon: Icons.signpost_rounded),
    (label: 'Attendance', icon: Icons.history_rounded),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ground,
      body: SafeArea(
        child: IndexedStack(
          index: _tab,
          children: [
            const _DashboardPlaceholder(),
            for (var i = 1; i < _tabs.length; i++) _Placeholder(_tabs[i].label),
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

class _Placeholder extends StatelessWidget {
  const _Placeholder(this.name);
  final String name;
  @override
  Widget build(BuildContext context) => Center(
        child: Text('$name\n(coming in B3)',
            textAlign: TextAlign.center,
            style: const TextStyle(color: AppColors.textMuted)),
      );
}

class _DashboardPlaceholder extends StatelessWidget {
  const _DashboardPlaceholder();
  @override
  Widget build(BuildContext context) {
    final me = AuthService.instance.employee;
    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const SizedBox(height: 8),
          Text('Signed in', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 12),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(me?.name ?? '—',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text('${me?.phone ?? ''}   ${me?.code ?? ''}',
                      style: const TextStyle(color: AppColors.textMuted, fontSize: 12)),
                ],
              ),
            ),
          ),
          const Spacer(),
          OutlinedButton.icon(
            onPressed: () => AuthService.instance.logout(),
            icon: const Icon(Icons.logout, size: 18),
            label: const Text('Log out'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.down,
              side: const BorderSide(color: Color(0xFFF0C4C0)),
            ),
          ),
        ],
      ),
    );
  }
}
