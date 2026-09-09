import 'package:flutter/material.dart';
import '../core/api.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../widgets/common.dart';

/// Attendance / My Overview - the web field/attendance/ page.
/// Day / Month / All time segments, a "statement" summary card, and
/// (day view) a location timeline.
class AttendanceScreen extends StatefulWidget {
  const AttendanceScreen({super.key});

  @override
  State<AttendanceScreen> createState() => AttendanceScreenState();
}

enum _View { day, month, alltime }

class AttendanceScreenState extends State<AttendanceScreen> {
  _View _view = _View.day;
  DateTime _day = DateTime.now();
  DateTime _month = DateTime.now();

  Map<String, dynamic>? _data;
  Object? _error;
  bool _loading = true;

  static String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
  static String _ym(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}';

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
      final q = <String, dynamic>{'view': _view.name};
      if (_view == _View.day) q['on'] = _ymd(_day);
      if (_view == _View.month) q['month'] = _ym(_month);
      final res = await Api.instance.get('/attendance.php', query: q);
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

  void _setView(_View v) {
    if (v == _view) return;
    setState(() => _view = v);
    load();
  }

  bool get _dayIsToday {
    final n = DateTime.now();
    return _day.year == n.year && _day.month == n.month && _day.day == n.day;
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 18, 20, 10),
          child: Text('Attendance', style: Theme.of(context).textTheme.headlineSmall),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20),
          child: _Segments(
            value: _view,
            onChanged: _setView,
          ),
        ),
        const SizedBox(height: 10),
        if (_view == _View.day)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: _day,
                        firstDate: DateTime(2024, 1, 1),
                        lastDate: DateTime.now(),
                      );
                      if (picked != null) {
                        setState(() => _day = picked);
                        load();
                      }
                    },
                    icon: const Icon(Icons.event, size: 16),
                    label: Text(Fmt.shortDate(_day)),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.text,
                      side: const BorderSide(color: AppColors.border),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                    ),
                  ),
                ),
                if (!_dayIsToday) ...[
                  const SizedBox(width: 8),
                  TextButton.icon(
                    onPressed: () {
                      setState(() => _day = DateTime.now());
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
        if (_view == _View.month)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Align(
              alignment: Alignment.centerLeft,
              child: OutlinedButton.icon(
                onPressed: _pickMonth,
                icon: const Icon(Icons.calendar_month, size: 16),
                label: Text(_monthLabel(_month)),
                style: OutlinedButton.styleFrom(
                  foregroundColor: AppColors.text,
                  side: const BorderSide(color: AppColors.border),
                  padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
                ),
              ),
            ),
          ),
        const SizedBox(height: 12),
        Expanded(
          child: RefreshIndicator(
            color: AppColors.brown,
            onRefresh: load,
            child: _buildBody(),
          ),
        ),
      ],
    );
  }

  static String _monthLabel(DateTime d) {
    const months = [
      'January', 'February', 'March', 'April', 'May', 'June',
      'July', 'August', 'September', 'October', 'November', 'December'
    ];
    return '${months[d.month - 1]} ${d.year}';
  }

  Future<void> _pickMonth() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _month,
      firstDate: DateTime(2024, 1, 1),
      lastDate: DateTime.now(),
      helpText: 'Select a month',
    );
    if (picked != null) {
      setState(() => _month = DateTime(picked.year, picked.month));
      load();
    }
  }

  Widget _buildBody() {
    if (_loading) return const Loading();
    if (_error != null) {
      return ListView(children: [
        const SizedBox(height: 40),
        ErrorRetry(
          message: _error is ApiException
              ? (_error as ApiException).message
              : 'Could not load your overview.',
          onRetry: load,
        ),
      ]);
    }

    final d = _data ?? const {};
    final label = (d['period_label'] as String?) ?? '';

    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
      children: [
        if (_view == _View.day)
          ..._dayContent(d, label)
        else
          ..._periodContent(d, label),
      ],
    );
  }

  // ---- day view ----
  List<Widget> _dayContent(Map<String, dynamic> d, String label) {
    final s = (d['statement'] as Map<String, dynamic>?) ?? const {};
    final has = s['has_attendance'] == true;
    final tl = (d['timeline'] as List?) ?? const [];

    final rows = <Widget>[
      _Card(
        title: '$label statement',
        child: !has
            ? _empty('You did not check in on ${Fmt.longDate(_day)}.')
            : Column(
                children: [
                  _row('Attendance time (check-in)', _timeWithKm(s['check_in_at'], s['check_in_km'])),
                  _row('Total visits', '${(s['visits'] as num?)?.toInt() ?? 0}'),
                  _row('Productive KM travelled (estimate)', Fmt.km((s['km'] as num?)?.toDouble() ?? 0)),
                  _row('Productive working time (at shops)', Fmt.hm((s['shop_seconds'] as num?)?.toInt() ?? 0)),
                  _row('Full day time (check-in to check-out)',
                      s['active_seconds'] != null ? Fmt.hm((s['active_seconds'] as num).toInt()) : '—',
                      warn: s['check_out_at'] == null && s['check_in_at'] != null ? 'still open' : null),
                  _row('Last check-out', _timeWithKm(s['check_out_at'], s['check_out_km'])),
                ],
              ),
      ),
    ];

    rows.add(const SizedBox(height: 14));
    rows.add(_Card(
      title: _dayIsToday ? "Today's Attendance & Location Timeline" : "Day's Attendance & Location Timeline",
      child: tl.isEmpty
          ? _empty('No check-in recorded for this day.')
          : Column(
              children: [
                for (var i = 0; i < tl.length; i++)
                  _TimelineRow(
                    (tl[i] as Map<String, dynamic>),
                    last: i == tl.length - 1,
                    onViewPhoto: _openPhoto,
                  ),
              ],
            ),
    ));
    return rows;
  }

  String _timeWithKm(dynamic iso, dynamic km) {
    final t = iso is String ? Fmt.time(DateTime.tryParse(iso)) : '—';
    if (km is num) return '$t  ·  ${km.toStringAsFixed(1)} km';
    return t;
  }

  // ---- month / alltime ----
  List<Widget> _periodContent(Map<String, dynamic> d, String label) {
    final t = (d['totals'] as Map<String, dynamic>?) ?? const {};
    final days = (t['days'] as num?)?.toInt() ?? 0;
    final monthDays = (d['days'] as List?) ?? const [];

    int? ti(String k) => (t[k] as num?)?.toInt();
    double? td(String k) => (t[k] as num?)?.toDouble();

    final rows = <Widget>[
      _Card(
        title: '$label statement',
        child: days == 0
            ? _empty(_view == _View.month
                ? 'No working days recorded in $label.'
                : 'No recorded activity yet.')
            : Column(
                children: [
                  _row('Visits', '${ti('visits') ?? 0}'),
                  _row('Shops visited', '${ti('shops') ?? 0}'),
                  _row('Productive KM (estimate)', Fmt.km(td('productive_km') ?? 0)),
                  _row('Productive time (at shops)', Fmt.hm(ti('shop_secs') ?? 0)),
                  _row('Attendance (days worked)', '$days',
                      warn: (ti('incomplete') ?? 0) > 0 ? '${ti('incomplete')} incomplete' : null),
                  _row('Travel time (between shops)', Fmt.hm(ti('road_secs') ?? 0)),
                  _row('Avg. visit duration',
                      t['avg_dwell_secs'] != null ? Fmt.hm((t['avg_dwell_secs'] as num).toInt()) : '—'),
                  _row('Total time (check-in to check-out)', Fmt.hm(ti('total_secs') ?? 0)),
                ],
              ),
      ),
    ];

    if (_view == _View.month) {
      rows.add(const SizedBox(height: 14));
      final attended = monthDays.where((r) => (r as Map)['present'] == true).length;
      rows.add(_Card(
        title: 'Days in $label',
        subtitle: '$attended attended of ${monthDays.length} days',
        child: monthDays.isEmpty
            ? _empty('Nothing to show for $label.')
            : Column(
                children: [
                  for (var i = 0; i < monthDays.length; i++)
                    _MonthDayRow(monthDays[i] as Map<String, dynamic>,
                        last: i == monthDays.length - 1),
                ],
              ),
      ));
    } else {
      rows.add(const SizedBox(height: 14));
      rows.add(_Card(
        title: 'Day-by-day',
        child: _empty('See Month view for a day-by-day breakdown.'),
      ));
    }
    return rows;
  }

  void _openPhoto(String url) {
    showDialog<void>(
      context: context,
      builder: (_) => Dialog(
        backgroundColor: Colors.black,
        insetPadding: const EdgeInsets.all(12),
        child: Stack(
          children: [
            InteractiveViewer(
              child: Center(child: Image.network(url, fit: BoxFit.contain)),
            ),
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

  // ---- shared bits ----
  Widget _row(String label, String value, {String? warn}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 9),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(label,
                  style: const TextStyle(fontSize: 12, color: AppColors.text2, height: 1.4)),
            ),
            const SizedBox(width: 12),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(value,
                    style: const TextStyle(
                        fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.text)),
                if (warn != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: Text(warn,
                        style: const TextStyle(
                            fontSize: 10, color: AppColors.down, fontWeight: FontWeight.w600)),
                  ),
              ],
            ),
          ],
        ),
      );

  Widget _empty(String text) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Text(text, style: const TextStyle(color: AppColors.textMuted, fontSize: 12.5)),
      );
}

class _Segments extends StatelessWidget {
  const _Segments({required this.value, required this.onChanged});
  final _View value;
  final ValueChanged<_View> onChanged;

  @override
  Widget build(BuildContext context) {
    Widget seg(_View v, String label) {
      final active = v == value;
      return Expanded(
        child: GestureDetector(
          onTap: () => onChanged(v),
          child: Container(
            margin: const EdgeInsets.all(3),
            padding: const EdgeInsets.symmetric(vertical: 8),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: active ? AppColors.cardBg : Colors.transparent,
              borderRadius: BorderRadius.circular(8),
              boxShadow: active
                  ? [BoxShadow(color: Colors.black.withValues(alpha: 0.06), blurRadius: 4, offset: const Offset(0, 1))]
                  : null,
            ),
            child: Text(label,
                style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: active ? AppColors.brown : AppColors.text2)),
          ),
        ),
      );
    }

    return Container(
      decoration: BoxDecoration(
        color: AppColors.panelInset,
        borderRadius: BorderRadius.circular(11),
      ),
      child: Row(children: [
        seg(_View.day, 'Day'),
        seg(_View.month, 'Month'),
        seg(_View.alltime, 'All time'),
      ]),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.title, required this.child, this.subtitle});
  final String title;
  final String? subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderSoft),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 4),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title, style: Theme.of(context).textTheme.titleLarge),
                if (subtitle != null) ...[
                  const SizedBox(height: 2),
                  Text(subtitle!,
                      style: const TextStyle(fontSize: 11, color: AppColors.textMuted)),
                ],
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 14),
            child: child,
          ),
        ],
      ),
    );
  }
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow(this.r, {required this.last, required this.onViewPhoto});
  final Map<String, dynamic> r;
  final bool last;
  final void Function(String url) onViewPhoto;

  @override
  Widget build(BuildContext context) {
    final kind = (r['kind'] as String?) ?? 'visit';
    final isVisit = kind == 'visit';
    final at = r['at'] is String ? DateTime.tryParse(r['at'] as String) : null;
    final leftAt = r['left_at'] is String ? DateTime.tryParse(r['left_at'] as String) : null;
    final lat = (r['lat'] as num?)?.toDouble();
    final lng = (r['lng'] as num?)?.toDouble();
    final photo = r['photo_url'] as String?;
    final dwell = (r['dwell_seconds'] as num?)?.toInt();

    final IconData icon = kind == 'checkin'
        ? Icons.login
        : (kind == 'checkout' ? Icons.logout : Icons.storefront);

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 11),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: AppColors.borderSoft)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 30,
            height: 30,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: AppColors.panelInset,
              borderRadius: BorderRadius.circular(9),
            ),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Icon(icon, size: 15, color: AppColors.brown),
                if (isVisit && r['visit_no'] != null)
                  Positioned(
                    right: -1,
                    bottom: -1,
                    child: Container(
                      padding: const EdgeInsets.all(2),
                      decoration: const BoxDecoration(color: AppColors.brown, shape: BoxShape.circle),
                      constraints: const BoxConstraints(minWidth: 13, minHeight: 13),
                      child: Text('${r['visit_no']}',
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 7.5, color: Colors.white, fontWeight: FontWeight.w700)),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Text((r['label'] as String?) ?? '',
                          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w700, color: AppColors.text)),
                    ),
                    const SizedBox(width: 8),
                    Text(
                      isVisit && leftAt != null
                          ? '${Fmt.time(at)} – ${Fmt.time(leftAt)}'
                          : Fmt.time(at),
                      style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted),
                    ),
                  ],
                ),
                if (lat != null && lng != null) ...[
                  const SizedBox(height: 3),
                  Text('📍 ${Fmt.coord(lat, lng)}',
                      style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted)),
                ],
                if (isVisit) ...[
                  const SizedBox(height: 2),
                  Text(
                    '${(r['area'] as String?) ?? ''} · ${dwell != null ? Fmt.hm(dwell) : 'still there'}',
                    style: const TextStyle(fontSize: 10.5, color: AppColors.text2),
                  ),
                  if (photo != null && photo.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    GestureDetector(
                      onTap: () => onViewPhoto(photo),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: const [
                          Icon(Icons.image, size: 13, color: AppColors.brown),
                          SizedBox(width: 5),
                          Text('View Photo',
                              style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.brown)),
                        ],
                      ),
                    ),
                  ],
                ] else if ((r['remark'] as String?)?.isNotEmpty ?? false) ...[
                  const SizedBox(height: 2),
                  Text(r['remark'] as String,
                      style: const TextStyle(fontSize: 10.5, color: AppColors.text2)),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MonthDayRow extends StatelessWidget {
  const _MonthDayRow(this.r, {required this.last});
  final Map<String, dynamic> r;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final present = r['present'] == true;
    final date = DateTime.tryParse((r['date'] as String?) ?? '');
    final dateLabel = date != null ? Fmt.weekdayShort(date) : (r['date'] as String? ?? '');
    final km = (r['km'] as num?)?.toDouble();
    final visits = (r['visits'] as num?)?.toInt();

    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10),
      decoration: BoxDecoration(
        border: last ? null : const Border(bottom: BorderSide(color: AppColors.borderSoft)),
      ),
      child: Row(
        children: [
          Icon(present ? Icons.check_circle : Icons.cancel,
              size: 18, color: present ? AppColors.ok : AppColors.textMuted),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(dateLabel,
                    style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.text)),
                if (present)
                  Text('${visits ?? 0} visits · ${km != null ? km.toStringAsFixed(1) : '0.0'} km',
                      style: const TextStyle(fontSize: 10.5, color: AppColors.text2))
                else
                  const Text('Not attended',
                      style: TextStyle(fontSize: 10.5, color: AppColors.textMuted)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
