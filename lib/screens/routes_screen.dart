import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../widgets/common.dart';

/// Routes - the web field/routes/ page: a plain numbered list of the day's
/// stops (Start -> Visit 1 -> Visit 2 -> ... -> End) for a chosen date.
/// No live map (matches the web's deliberate choice).
class RoutesScreen extends StatefulWidget {
  const RoutesScreen({super.key});

  @override
  State<RoutesScreen> createState() => RoutesScreenState();
}

class _RoutePoint {
  _RoutePoint(this.kind, this.no, this.at, this.label, this.sub, this.lat, this.lng);
  final String kind; // start | visit | end
  final int? no;
  final DateTime? at;
  final String label;
  final String? sub;
  final double? lat;
  final double? lng;

  factory _RoutePoint.fromJson(Map<String, dynamic> j) => _RoutePoint(
        (j['kind'] as String?) ?? 'visit',
        (j['no'] as num?)?.toInt(),
        j['at'] is String ? DateTime.tryParse(j['at'] as String) : null,
        (j['label'] as String?) ?? '',
        j['sub'] as String?,
        (j['lat'] as num?)?.toDouble(),
        (j['lng'] as num?)?.toDouble(),
      );
}

class RoutesScreenState extends State<RoutesScreen> {
  DateTime _date = DateTime.now();
  List<_RoutePoint>? _points;
  Object? _error;
  bool _loading = true;

  static String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

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
      final res = await Api.instance.get('/routes.php', query: {'date': _ymd(_date)});
      final list = (res['points'] as List?) ?? const [];
      if (!mounted) return;
      setState(() {
        _points = list
            .whereType<Map<String, dynamic>>()
            .map(_RoutePoint.fromJson)
            .toList();
        // server may clamp a future date back to today
        final d = res['date'] as String?;
        if (d != null) {
          final parsed = DateTime.tryParse(d);
          if (parsed != null) _date = parsed;
        }
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

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _date,
      firstDate: DateTime(2024, 1, 1),
      lastDate: DateTime.now(),
    );
    if (picked != null) {
      setState(() => _date = picked);
      load();
    }
  }

  bool get _isToday {
    final n = DateTime.now();
    return _date.year == n.year && _date.month == n.month && _date.day == n.day;
  }

  @override
  Widget build(BuildContext context) {
    final visitCount = _points?.where((p) => p.kind == 'visit').length ?? 0;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 8),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Routes', style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 4),
              Row(
                children: [
                  const Icon(Icons.calendar_today, size: 12, color: AppColors.text2),
                  const SizedBox(width: 5),
                  Text(Fmt.longDate(_date),
                      style: const TextStyle(fontSize: 12, color: AppColors.text2)),
                ],
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _pickDate,
                  icon: const Icon(Icons.event, size: 16),
                  label: Text(Fmt.shortDate(_date)),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: AppColors.text,
                    side: const BorderSide(color: AppColors.border),
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                ),
              ),
              if (!_isToday) ...[
                const SizedBox(width: 8),
                TextButton.icon(
                  onPressed: () {
                    setState(() => _date = DateTime.now());
                    load();
                  },
                  icon: const Icon(Icons.restart_alt, size: 16),
                  label: const Text('Today'),
                  style: TextButton.styleFrom(foregroundColor: AppColors.brown),
                ),
              ],
            ],
          ),
        ),
        const SizedBox(height: 12),
        Expanded(
          child: RefreshIndicator(
            color: AppColors.brown,
            onRefresh: load,
            child: _buildBody(visitCount),
          ),
        ),
      ],
    );
  }

  Widget _buildBody(int visitCount) {
    if (_loading) return const Loading();
    if (_error != null) {
      return ListView(children: [
        const SizedBox(height: 40),
        ErrorRetry(
          message: _error is ApiException
              ? (_error as ApiException).message
              : 'Could not load your route.',
          onRetry: load,
        ),
      ]);
    }
    final pts = _points ?? const [];

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      children: [
        Container(
          decoration: BoxDecoration(
            color: AppColors.cardBg,
            borderRadius: BorderRadius.circular(AppRadius.card),
            border: Border.all(color: AppColors.borderSoft),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 10),
                child: Text(
                  visitCount > 0 ? 'Visit Points ($visitCount)' : 'Visit Points',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
              ),
              if (pts.isEmpty)
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 4, 16, 20),
                  child: Text(
                    'No check-in recorded on ${Fmt.longDate(_date)}.',
                    style: const TextStyle(color: AppColors.textMuted, fontSize: 12.5),
                  ),
                )
              else
                ...List.generate(pts.length, (i) => _PointRow(pts[i], last: i == pts.length - 1)),
            ],
          ),
        ),
      ],
    );
  }
}

class _PointRow extends StatelessWidget {
  const _PointRow(this.p, {this.last = false});
  final _RoutePoint p;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final badge = p.kind == 'start' ? 'A' : (p.kind == 'end' ? 'B' : '${p.no ?? ''}');
    final Color badgeBg;
    final Color badgeFg;
    switch (p.kind) {
      case 'start':
        badgeBg = AppColors.ok.withValues(alpha: 0.14);
        badgeFg = AppColors.ok;
        break;
      case 'end':
        badgeBg = AppColors.down.withValues(alpha: 0.12);
        badgeFg = AppColors.down;
        break;
      default:
        badgeBg = AppColors.brown.withValues(alpha: 0.12);
        badgeFg = AppColors.brown;
    }
    final tag = p.kind == 'start'
        ? 'Start'
        : (p.kind == 'end' ? 'End' : 'Visit #${p.no ?? ''}');

    return Container(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
      decoration: BoxDecoration(
        border: last
            ? null
            : const Border(bottom: BorderSide(color: AppColors.borderSoft)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 26,
            height: 26,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: badgeBg, borderRadius: BorderRadius.circular(8)),
            child: Text(badge,
                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: badgeFg)),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(Fmt.time(p.at),
                    style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted)),
                const SizedBox(height: 2),
                Text(p.label,
                    style: const TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: AppColors.text)),
                if (p.sub != null && p.sub!.isNotEmpty) ...[
                  const SizedBox(height: 1),
                  Text(p.sub!, style: const TextStyle(fontSize: 11.5, color: AppColors.text2)),
                ],
                if (p.lat != null && p.lng != null) ...[
                  const SizedBox(height: 3),
                  Text(Fmt.coord(p.lat!, p.lng!),
                      style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted)),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
            decoration: BoxDecoration(
              color: AppColors.panelInset,
              borderRadius: BorderRadius.circular(999),
            ),
            child: Text(tag,
                style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: AppColors.text2)),
          ),
        ],
      ),
    );
  }
}
