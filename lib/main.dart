import 'package:flutter/material.dart';
import 'core/api.dart';
import 'core/theme.dart';
import 'services/auth_service.dart';
import 'services/outbox.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  Outbox.instance.init();
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
