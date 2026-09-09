import 'dart:io';
import 'package:flutter/material.dart';
import '../core/theme.dart';
import '../services/capture_service.dart';

/// "Your Location" card - the web .fa-locate-btn + .fa-location box.
/// Tapping captures one GPS fix; it cannot be typed or edited.
class LocationField extends StatefulWidget {
  const LocationField({
    super.key,
    required this.fix,
    required this.onFix,
    this.title = 'Your Location',
    this.hint = 'Tap the button to capture your location - this cannot be typed or edited.',
  });

  final GpsFix? fix;
  final ValueChanged<GpsFix?> onFix;
  final String title;
  final String hint;

  @override
  State<LocationField> createState() => _LocationFieldState();
}

class _LocationFieldState extends State<LocationField> {
  bool _busy = false;
  String? _error;

  Future<void> _capture() async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final fix = await CaptureService.instance.getLocation();
      widget.onFix(fix);
    } on CaptureException catch (e) {
      widget.onFix(null);
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final fix = widget.fix;
    return _CaptureCard(
      icon: Icons.place,
      title: widget.title,
      hint: widget.hint,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          OutlinedButton.icon(
            onPressed: _busy ? null : _capture,
            icon: _busy
                ? const SizedBox(
                    width: 15, height: 15, child: CircularProgressIndicator(strokeWidth: 2))
                : Icon(fix == null ? Icons.my_location : Icons.refresh, size: 16),
            label: Text(fix == null ? 'Click Me' : 'Re-capture'),
            style: OutlinedButton.styleFrom(
              foregroundColor: AppColors.brown,
              side: const BorderSide(color: AppColors.border),
              padding: const EdgeInsets.symmetric(vertical: 12),
            ),
          ),
          if (fix != null) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: const Color(0xFFF3F8F2),
                borderRadius: BorderRadius.circular(AppRadius.sm),
                border: Border.all(color: const Color(0xFFCDE6D2)),
              ),
              child: Row(
                children: [
                  const Icon(Icons.check_circle, size: 15, color: AppColors.ok),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Location captured\n${fix.coordText}'
                      '${fix.accuracyM != null ? '  ·  ±${fix.accuracyM!.round()} m' : ''}',
                      style: const TextStyle(fontSize: 11.5, color: AppColors.text2, height: 1.4),
                    ),
                  ),
                ],
              ),
            ),
          ],
          if (_error != null) ...[
            const SizedBox(height: 8),
            Text(_error!, style: const TextStyle(fontSize: 11.5, color: AppColors.down, height: 1.4)),
          ],
        ],
      ),
    );
  }
}

/// "Photo" shot - the web .fa-shot label + camera input + preview.
class PhotoField extends StatefulWidget {
  const PhotoField({
    super.key,
    required this.file,
    required this.onFile,
    this.title = 'Bike current meter photo',
    this.hint = 'Tap to open the camera.',
    this.icon = Icons.photo_camera,
  });

  final File? file;
  final ValueChanged<File?> onFile;
  final String title;
  final String hint;
  final IconData icon;

  @override
  State<PhotoField> createState() => _PhotoFieldState();
}

class _PhotoFieldState extends State<PhotoField> {
  bool _busy = false;

  Future<void> _shoot() async {
    setState(() => _busy = true);
    try {
      final f = await CaptureService.instance.takePhoto();
      if (f != null) widget.onFile(f);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final file = widget.file;
    return _CaptureCard(
      icon: widget.icon,
      title: widget.title,
      hint: widget.hint,
      child: GestureDetector(
        onTap: _busy ? null : _shoot,
        child: Container(
          height: file == null ? 120 : 200,
          width: double.infinity,
          decoration: BoxDecoration(
            color: AppColors.panelInset,
            borderRadius: BorderRadius.circular(AppRadius.sm),
            border: Border.all(color: AppColors.border),
            image: file != null
                ? DecorationImage(image: FileImage(file), fit: BoxFit.cover)
                : null,
          ),
          child: file != null
              ? Align(
                  alignment: Alignment.topRight,
                  child: Container(
                    margin: const EdgeInsets.all(8),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.55),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: const Text('Tap to retake',
                        style: TextStyle(fontSize: 10.5, color: Colors.white)),
                  ),
                )
              : Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    _busy
                        ? const SizedBox(
                            width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.photo_camera, size: 26, color: AppColors.textMuted),
                    const SizedBox(height: 6),
                    const Text('Tap to open camera',
                        style: TextStyle(fontSize: 12, color: AppColors.textMuted)),
                  ],
                ),
        ),
      ),
    );
  }
}

class _CaptureCard extends StatelessWidget {
  const _CaptureCard({
    required this.icon,
    required this.title,
    required this.hint,
    required this.child,
  });
  final IconData icon;
  final String title;
  final String hint;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderSoft),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Container(
                width: 34,
                height: 34,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: AppColors.panelInset,
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Icon(icon, size: 17, color: AppColors.brown),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: Theme.of(context).textTheme.titleLarge),
                    const SizedBox(height: 2),
                    Text(hint,
                        style: const TextStyle(fontSize: 11.5, color: AppColors.text2, height: 1.4)),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    );
  }
}
