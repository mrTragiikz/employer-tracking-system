import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../core/api.dart';
import '../core/theme.dart';
import '../services/auth_service.dart';
import '../widgets/common.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _pin = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _showPin = false;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _pin.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _error = null);
    if (!_form.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _busy = true);
    try {
      await AuthService.instance.login(
        phone: _phone.text.trim(),
        pin: _pin.text.trim(),
      );
      // On success the app root swaps to AppShell automatically.
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } catch (_) {
      setState(() => _error = 'Could not sign in. Please try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.ground,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 380),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // brand
                  Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: AppColors.cardBg,
                      borderRadius: BorderRadius.circular(AppRadius.card),
                      border: Border.all(color: AppColors.borderSoft),
                    ),
                    child: Row(
                      children: [
                        Container(
                          width: 44, height: 44,
                          decoration: BoxDecoration(
                            gradient: AppColors.buttonGradient,
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: const Icon(Icons.pin_drop, color: Colors.white),
                        ),
                        const SizedBox(width: 12),
                        const Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Rajdoot',
                                style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.text)),
                            Text('Field attendance',
                                style: TextStyle(fontSize: 11.5, color: AppColors.textMuted)),
                          ],
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 28),
                  Text('Sign in', style: Theme.of(context).textTheme.headlineSmall),
                  const SizedBox(height: 4),
                  const Text('Use your phone number and 4-digit PIN.',
                      style: TextStyle(color: AppColors.text2, fontSize: 13)),
                  const SizedBox(height: 20),

                  if (_error != null) FlashBar(text: _error!, isError: true),

                  Form(
                    key: _form,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        TextFormField(
                          controller: _phone,
                          keyboardType: TextInputType.phone,
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          decoration: const InputDecoration(
                            labelText: 'Phone number',
                            prefixIcon: Icon(Icons.phone_outlined, size: 20),
                            hintText: 'e.g. 9841000001',
                          ),
                          validator: (v) =>
                              (v == null || v.trim().length < 7) ? 'Enter your phone number' : null,
                        ),
                        const SizedBox(height: 14),
                        TextFormField(
                          controller: _pin,
                          keyboardType: TextInputType.number,
                          obscureText: !_showPin,
                          maxLength: 4,
                          inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                          decoration: InputDecoration(
                            labelText: '4-digit PIN',
                            counterText: '',
                            prefixIcon: const Icon(Icons.lock_outline, size: 20),
                            suffixIcon: IconButton(
                              icon: Icon(_showPin ? Icons.visibility_off : Icons.visibility, size: 20),
                              onPressed: () => setState(() => _showPin = !_showPin),
                            ),
                          ),
                          validator: (v) =>
                              (v == null || v.trim().length != 4) ? 'Your PIN is 4 digits' : null,
                          onFieldSubmitted: (_) => _submit(),
                        ),
                        const SizedBox(height: 20),
                        PrimaryButton(
                          label: 'Sign in',
                          loading: _busy,
                          onPressed: _busy ? null : _submit,
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'This account stays signed in on this phone until you log out.',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: AppColors.textMuted, fontSize: 11, height: 1.5),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
