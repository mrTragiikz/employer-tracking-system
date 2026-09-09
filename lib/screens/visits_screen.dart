import 'dart:io';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/models.dart';
import '../services/capture_service.dart';
import '../widgets/capture_fields.dart';
import '../widgets/common.dart';

/// My Visits - the web field/visit/ page. Log a visit, mark it Done, view it.
///
///  - not checked in    -> note pointing to Check In/Out
///  - a visit still open -> "You're at X" banner + Visit Done
///  - otherwise          -> "Log a Visit" button + the day's list
class VisitsScreen extends StatefulWidget {
  const VisitsScreen({super.key, this.onChanged});
  final VoidCallback? onChanged;

  @override
  State<VisitsScreen> createState() => VisitsScreenState();
}

class VisitsScreenState extends State<VisitsScreen> {
  Map<String, dynamic>? _data;
  Object? _error;
  bool _loading = true;
  bool _completing = false;

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
      final res = await Api.instance.get('/visits.php');
      if (!mounted) return;
      setState(() {
        _data = res;
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

  Future<void> _refresh() async {
    await load();
    widget.onChanged?.call();
  }

  Future<void> _completeVisit(int visitId) async {
    setState(() => _completing = true);
    try {
      await Api.instance.postJson('/visit-complete.php', {'visit_id': visitId});
      await _refresh();
    } on ApiException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => _completing = false);
    }
  }

  Future<void> _openLogSheet(String nextLabel) async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.ground,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (_) => _LogVisitSheet(title: nextLabel),
    );
    if (saved == true) await _refresh();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Loading();
    if (_error != null) {
      return ErrorRetry(
        message: _error is ApiException
            ? (_error as ApiException).message
            : 'Could not load your visits.',
        onRetry: load,
      );
    }

    final d = _data!;
    final checkedIn = d['checked_in'] == true;
    final checkedOut = d['checked_out'] == true;
    final openId = d['open_visit_id'] as int?;
    final nextLabel = (d['next_label'] as String?) ?? 'Log a Visit';
    final visits = ((d['visits'] as List?) ?? const [])
        .whereType<Map<String, dynamic>>()
        .map(Visit.fromJson)
        .toList();
    final openVisit = openId != null
        ? visits.where((v) => v.id == openId).cast<Visit?>().firstWhere((v) => true, orElse: () => null)
        : null;

    return RefreshIndicator(
      color: AppColors.brown,
      onRefresh: load,
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text('My Visits', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 4),
          Text(Fmt.longDate(DateTime.now()),
              style: const TextStyle(fontSize: 12, color: AppColors.text2)),
          const SizedBox(height: 16),

          if (!checkedIn)
            _note(Icons.info, 'Check in first to log a visit.')
          else if (checkedOut)
            _note(Icons.check_circle,
                "You're checked out for today - no more visits can be logged.")
          else if (openVisit != null)
            _OpenVisitBanner(
              visit: openVisit,
              busy: _completing,
              onDone: () => _completeVisit(openVisit.id),
            )
          else
            PrimaryButton(
              label: nextLabel,
              icon: Icons.add_circle,
              onPressed: () => _openLogSheet(nextLabel),
            ),

          const SizedBox(height: 16),

          if (visits.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 12),
              child: Text('No visits logged yet today.',
                  style: TextStyle(color: AppColors.textMuted, fontSize: 12.5)),
            )
          else
            ...visits.map((v) => _VisitCard(v)),
        ],
      ),
    );
  }

  Widget _note(IconData icon, String text) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.panelInset,
          borderRadius: BorderRadius.circular(AppRadius.sm),
        ),
        child: Row(
          children: [
            Icon(icon, size: 16, color: AppColors.text2),
            const SizedBox(width: 10),
            Expanded(
              child: Text(text,
                  style: const TextStyle(fontSize: 12, color: AppColors.text2, height: 1.4)),
            ),
          ],
        ),
      );
}

class _OpenVisitBanner extends StatelessWidget {
  const _OpenVisitBanner({required this.visit, required this.busy, required this.onDone});
  final Visit visit;
  final bool busy;
  final VoidCallback onDone;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFFBF3E6),
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: const Color(0xFFEBD9B8)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              const Icon(Icons.storefront, size: 18, color: AppColors.accent),
              const SizedBox(width: 8),
              Expanded(
                child: Text("You're at ${visit.shopName}",
                    style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: AppColors.text)),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text('Since ${Fmt.time(visit.arrivedAt)} · mark it done when you leave.',
              style: const TextStyle(fontSize: 11.5, color: AppColors.text2)),
          const SizedBox(height: 12),
          PrimaryButton(
            label: 'Visit Done',
            icon: Icons.check_circle,
            loading: busy,
            onPressed: busy ? null : onDone,
          ),
        ],
      ),
    );
  }
}

class _VisitCard extends StatelessWidget {
  const _VisitCard(this.v);
  final Visit v;

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderSoft),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(AppRadius.sm),
            child: v.photoUrl != null && v.photoUrl!.isNotEmpty
                ? GestureDetector(
                    onTap: () => _viewPhoto(context, v.photoUrl!),
                    child: Image.network(v.photoUrl!, width: 54, height: 54, fit: BoxFit.cover,
                        errorBuilder: (c, e, s) => _photoFallback()),
                  )
                : _photoFallback(),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(v.shopName,
                    style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: AppColors.text)),
                const SizedBox(height: 2),
                Text(v.areaName, style: const TextStyle(fontSize: 11.5, color: AppColors.text2)),
                const SizedBox(height: 3),
                Text(
                  v.leftAt != null
                      ? '${Fmt.time(v.arrivedAt)} – ${Fmt.time(v.leftAt)}'
                          '${v.dwellSeconds != null ? '  ·  ${Fmt.hm(v.dwellSeconds!)}' : ''}'
                      : 'Arrived ${Fmt.time(v.arrivedAt)}',
                  style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          v.isOpen
              ? Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(
                    color: const Color(0xFFFBF3E6),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: const Text('In Shop',
                      style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: AppColors.accent)),
                )
              : const Icon(Icons.check_circle, size: 20, color: AppColors.ok),
        ],
      ),
    );
  }

  Widget _photoFallback() => Container(
        width: 54,
        height: 54,
        color: AppColors.panelInset,
        child: const Icon(Icons.storefront, color: AppColors.textMuted, size: 20),
      );

  void _viewPhoto(BuildContext context, String url) {
    showDialog<void>(
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
    );
  }
}

// ============================ log a visit sheet ============================

class _LogVisitSheet extends StatefulWidget {
  const _LogVisitSheet({required this.title});
  final String title;

  @override
  State<_LogVisitSheet> createState() => _LogVisitSheetState();
}

class _LogVisitSheetState extends State<_LogVisitSheet> {
  final _shop = TextEditingController();
  final _area = TextEditingController();
  GpsFix? _fix;
  File? _photo;
  bool _submitting = false;
  String? _error;
  Map<String, dynamic>? _fieldErrors;

  @override
  void dispose() {
    _shop.dispose();
    _area.dispose();
    super.dispose();
  }

  bool get _ready =>
      _shop.text.trim().isNotEmpty &&
      _area.text.trim().isNotEmpty &&
      _fix != null &&
      _photo != null;

  Future<void> _submit() async {
    setState(() {
      _error = null;
      _fieldErrors = null;
      _submitting = true;
    });
    try {
      final form = FormData.fromMap({
        'shop_name': _shop.text.trim(),
        'area_name': _area.text.trim(),
        'lat': _fix!.lat.toString(),
        'lng': _fix!.lng.toString(),
        if (_fix!.accuracyM != null) 'accuracy': _fix!.accuracyM!.toString(),
        'shop_photo': await MultipartFile.fromFile(_photo!.path, filename: 'shop.jpg'),
      });
      await Api.instance.postForm('/visit-save.php', form);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = e.message;
        _fieldErrors = e.fields;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(16, 10, 16, 16 + bottomInset),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 38,
                height: 4,
                margin: const EdgeInsets.only(bottom: 12),
                decoration: BoxDecoration(
                  color: AppColors.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
            ),
            Row(
              children: [
                Expanded(
                  child: Text(widget.title, style: Theme.of(context).textTheme.headlineSmall),
                ),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: _submitting ? null : () => Navigator.of(context).pop(false),
                ),
              ],
            ),
            const SizedBox(height: 6),
            if (_error != null) FlashBar(text: _error!, isError: true),
            _textField('Shop Name', _shop, 'e.g. Bhatbhateni Mini Mart',
                err: _fieldErrors?['shop_name']?.toString()),
            const SizedBox(height: 10),
            _textField('Area Name', _area, 'e.g. Koteshwor',
                err: _fieldErrors?['area_name']?.toString()),
            const SizedBox(height: 14),
            LocationField(
              fix: _fix,
              onFix: (f) => setState(() => _fix = f),
              hint: 'Tap to capture where this shop is - grabbed once, cannot be edited.',
            ),
            PhotoField(
              file: _photo,
              onFile: (f) => setState(() => _photo = f),
              title: 'Shop evidence photo',
              hint: 'Take a photo of the shopfront.',
              icon: Icons.storefront,
            ),
            PrimaryButton(
              label: 'Confirm',
              icon: Icons.check_circle,
              loading: _submitting,
              onPressed: _ready && !_submitting ? _submit : null,
            ),
            const SizedBox(height: 4),
            if (!_ready)
              const Padding(
                padding: EdgeInsets.only(top: 4),
                child: Text('Fill the shop + area, capture location, and take the photo.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: AppColors.textMuted)),
              ),
          ],
        ),
      ),
    );
  }

  Widget _textField(String label, TextEditingController c, String hint, {String? err}) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label,
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.text)),
          const SizedBox(height: 5),
          TextField(
            controller: c,
            onChanged: (_) => setState(() {}),
            decoration: InputDecoration(hintText: hint),
          ),
          if (err != null) ...[
            const SizedBox(height: 4),
            Text(err, style: const TextStyle(fontSize: 11.5, color: AppColors.down)),
          ],
        ],
      );
}
