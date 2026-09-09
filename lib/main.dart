import 'package:flutter/material.dart';
import 'package:flutter_foreground_task/flutter_foreground_task.dart';
import 'core/api.dart';
import 'core/theme.dart';
import 'services/auth_service.dart';
import 'services/outbox.dart';
import 'services/tracking_service.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Required before any foreground-task use (isolate <-> UI channel).
  FlutterForegroundTask.initCommunicationPort();
  Outbox.instance.init();
  TrackingService.instance.initForegroundTask();
  runApp(const RajdootApp());
}

class RajdootApp extends StatefulWidget {
  const RajdootApp({super.key});
  @override
  State<RajdootApp> createState() => _RajdootAppState();
}

class _RajdootAppState extends State<RajdootApp> {
  final _auth = AuthService.instance;

  @override
  void initState() {
    super.initState();
    // Any 401 from the API wipes the session and sends us to login.
    Api.onUnauthorized = () => _auth.forceSignOut();
    _auth.bootstrap();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Rajdoot',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      builder: (context, child) => WithForegroundTask(child: child ?? const SizedBox()),
      home: AnimatedBuilder(
        animation: _auth,
        builder: (context, _) {
          switch (_auth.status) {
            case AuthStatus.unknown:
              return const Scaffold(body: _Boot());
            case AuthStatus.signedOut:
              return const LoginScreen();
            case AuthStatus.signedIn:
              return const AppShell();
          }
        },
      ),
    );
  }
}

class _Boot extends StatelessWidget {
  const _Boot();
  @override
  Widget build(BuildContext context) => const Center(
        child: CircularProgressIndicator(color: AppColors.brown, strokeWidth: 2.5),
      );
}
