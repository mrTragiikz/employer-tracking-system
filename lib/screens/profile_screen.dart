import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../services/auth_service.dart';
import '../widgets/common.dart';

/// My Profile - the web field/profile/ page. Read-only bio data (the
/// employee cannot edit their own fields - only an admin can) plus a
/// self-service change-PIN form and Log Out.
class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  Profile? _p;
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
      final res = await Api.instance.get('/profile.php');
      if (!mounted) return;
      final p = Profile.fromJson((res['profile'] as Map<String, dynamic>?) ?? const {});
      // keep the cached employee (name/photo) in step
      AuthService.instance.updateEmployee(Employee(
        id: p.id, name: p.name, phone: p.phone, code: p.code, photoUrl: p.photoUrl,
      ));
      setState(() {
        _p = p;
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

  String _fmtDob(String? d) {
    if (d == null || d.isEmpty) return '—';
    final parsed = DateTime.tryParse(d);
    return parsed != null ? Fmt.shortDate(parsed) : d;
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Loading();
    if (_error != null) {
      return ErrorRetry(
        message: _error is ApiException
            ? (_error as ApiException).message
            : 'Could not load your profile.',
        onRetry: load,
      );
    }

    final p = _p!;
    return RefreshIndicator(
      color: AppColors.brown,
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // identity
          _Card(
            child: Row(
              children: [
                _Avatar(url: p.photoUrl, initials: p.initials),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(p.name, style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          Icon(p.isActive ? Icons.verified : Icons.block,
                              size: 14, color: p.isActive ? AppColors.ok : AppColors.textMuted),
                          const SizedBox(width: 5),
                          Text(p.isActive ? 'Verified Account' : 'Inactive Account',
                              style: TextStyle(
                                  fontSize: 11.5,
                                  fontWeight: FontWeight.w600,
                                  color: p.isActive ? AppColors.ok : AppColors.textMuted)),
                        ],
                      ),
                      if (p.code != null) ...[
                        const SizedBox(height: 3),
                        Text('Employee Code: ${p.code}',
                            style: const TextStyle(fontSize: 11.5, color: AppColors.text2)),
                      ],
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 14),

          _Card(
            title: 'Contact',
            child: Column(children: [
              _row('Phone', p.phone.isEmpty ? '—' : p.phone),
              if (p.email != null) _row('Email', p.email!),
              _row('Area / Region', p.areaRegion ?? '—'),
              if (p.address != null) _row('Address', p.address!),
            ]),
          ),
          const SizedBox(height: 14),

          _Card(
            title: 'Personal Details',
            child: Column(children: [
              _row('Date of Birth', _fmtDob(p.dob)),
              _row('Gender', _cap(p.gender) ?? '—'),
              if (p.documentLabel != null) _row('Document Type', p.documentLabel!),
              _row('ID No.', p.idNumber ?? '—'),
              if (p.vehicleType != null) _row('Vehicle', _cap(p.vehicleType)!),
              if (p.emergencyContact != null || p.emergencyName != null)
                _row('Emergency Contact',
                    '${p.emergencyContact ?? '—'}${p.emergencyName != null ? ' (${p.emergencyName})' : ''}'),
              _row('Joined On', _fmtDob(p.joinedOn)),
            ]),
          ),

          if (p.idPhotoFrontUrl != null || p.idPhotoBackUrl != null) ...[
            const SizedBox(height: 14),
            _Card(
              title: p.documentLabel ?? 'ID Document',
              child: Row(
                children: [
                  if (p.idPhotoFrontUrl != null)
                    Expanded(child: _IdPhoto(url: p.idPhotoFrontUrl!, label: 'Front')),
                  if (p.idPhotoFrontUrl != null && p.idPhotoBackUrl != null)
                    const SizedBox(width: 10),
                  if (p.idPhotoBackUrl != null)
                    Expanded(child: _IdPhoto(url: p.idPhotoBackUrl!, label: 'Back')),
                ],
              ),
            ),
          ],

          const SizedBox(height: 14),
          _Card(
            title: 'Change PIN',
            child: _ChangePinForm(),
          ),

          const SizedBox(height: 18),
          GhostButton(
            label: 'Log Out',
            icon: Icons.logout,
            onPressed: () => AuthService.instance.logout(),
          ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }

  static String? _cap(String? s) =>
      (s == null || s.isEmpty) ? null : s[0].toUpperCase() + s.substring(1);

  Widget _row(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 9),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              flex: 5,
              child: Text(label,
                  style: const TextStyle(fontSize: 12, color: AppColors.text2, height: 1.4)),
            ),
            const SizedBox(width: 12),
            Expanded(
              flex: 6,
              child: Text(value,
                  textAlign: TextAlign.right,
                  style: const TextStyle(
                      fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.text, height: 1.4)),
            ),
          ],
        ),
      );
}

class _ChangePinForm extends StatefulWidget {
  @override
  State<_ChangePinForm> createState() => _ChangePinFormState();
}

class _ChangePinFormState extends State<_ChangePinForm> {
  final _form = GlobalKey<FormState>();
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();
  bool _busy = false;
  String? _flashOk;
  String? _flashErr;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() {
      _flashOk = null;
      _flashErr = null;
    });
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await Api.instance.postJson('/change-pin.php', {
        'current_pin': _current.text,
        'new_pin': _next.text,
        'new_pin_confirm': _confirm.text,
      });
      if (!mounted) return;
      setState(() {
        _flashOk = 'Your PIN has been changed.';
        _busy = false;
      });
      _current.clear();
      _next.clear();
      _confirm.clear();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _flashErr = e.message;
        _busy = false;
      });
    }
  }

  String? _pin4(String? v) {
    if (v == null || v.length != 4) return 'Enter a 4-digit PIN.';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    return Form(
      key: _form,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Your 4-digit PIN is used to sign in on this device.',
              style: TextStyle(fontSize: 11.5, color: AppColors.textMuted, height: 1.4)),
          const SizedBox(height: 12),
          if (_flashOk != null) FlashBar(text: _flashOk!),
          if (_flashErr != null) FlashBar(text: _flashErr!, isError: true),
          _pinField('Current PIN', _current),
          const SizedBox(height: 10),
          _pinField('New PIN (4 digits)', _next),
          const SizedBox(height: 10),
          _pinField('Confirm New PIN', _confirm),
          const SizedBox(height: 14),
          PrimaryButton(
            label: 'Update PIN',
            icon: Icons.lock,
            loading: _busy,
            onPressed: _busy ? null : _submit,
          ),
        ],
      ),
    );
  }

  Widget _pinField(String label, TextEditingController c) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.text)),
          const SizedBox(height: 5),
          TextFormField(
            controller: c,
            obscureText: true,
            keyboardType: TextInputType.number,
            maxLength: 4,
            validator: _pin4,
            decoration: const InputDecoration(counterText: '', hintText: '••••'),
          ),
        ],
      );
}

class _Card extends StatelessWidget {
  const _Card({required this.child, this.title});
  final String? title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderSoft),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (title != null) ...[
            Text(title!, style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
          ],
          child,
        ],
      ),
    );
  }
}

class _Avatar extends StatelessWidget {
  const _Avatar({this.url, required this.initials});
  final String? url;
  final String initials;

  @override
  Widget build(BuildContext context) {
    const size = 60.0;
    if (url != null && url!.isNotEmpty) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(999),
        child: Image.network(url!, width: size, height: size, fit: BoxFit.cover,
            errorBuilder: (c, e, s) => _fallback()),
      );
    }
    return _fallback();
  }

  Widget _fallback() => Container(
        width: 60,
        height: 60,
        alignment: Alignment.center,
        decoration: const BoxDecoration(color: AppColors.panelInset, shape: BoxShape.circle),
        child: Text(initials,
            style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: AppColors.brown)),
      );
}

class _IdPhoto extends StatelessWidget {
  const _IdPhoto({required this.url, required this.label});
  final String url;
  final String label;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () => showDialog<void>(
        context: context,
        builder: (_) => Dialog(
          backgroundColor: Colors.black,
          insetPadding: const EdgeInsets.all(12),
          child: Stack(
            children: [
              InteractiveViewer(child: Center(child: Image.network(url, fit: BoxFit.contain))),
              Positioned(
                top: 4,
                right: 4,
                child: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white),
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ),
            ],
          ),
        ),
      ),
      child: Column(
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.sm),
            child: Image.network(url, height: 90, width: double.infinity, fit: BoxFit.cover,
                errorBuilder: (c, e, s) => Container(
                      height: 90,
                      color: AppColors.panelInset,
                      child: const Icon(Icons.broken_image, color: AppColors.textMuted),
                    )),
          ),
          const SizedBox(height: 4),
          Text(label, style: const TextStyle(fontSize: 11, color: AppColors.text2)),
        ],
      ),
    );
  }
}
