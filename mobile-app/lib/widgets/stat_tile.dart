import 'package:flutter/material.dart';
import '../core/theme.dart';

/// One of the Home dashboard stat tiles (web: .fh-stat).
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.icon,
    required this.iconColor,
    required this.label,
    required this.value,
    this.foot,
    this.showTick = false,
  });

  final IconData icon;
  final Color iconColor;
  final String label;
  final String value;
  final String? foot;
  final bool showTick;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: AppColors.cardBg,
        borderRadius: BorderRadius.circular(AppRadius.card),
        border: Border.all(color: AppColors.borderSoft),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 30, height: 30,
            decoration: BoxDecoration(
              color: iconColor.withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(9),
            ),
            child: Icon(icon, size: 16, color: iconColor),
          ),
          const SizedBox(height: 8),
          Text(label,
              style: const TextStyle(fontSize: 10.5, color: AppColors.textMuted, fontWeight: FontWeight.w600)),
          const SizedBox(height: 2),
          Row(
            children: [
              if (showTick) ...[
                const Icon(Icons.check_circle, size: 14, color: AppColors.ok),
                const SizedBox(width: 4),
              ],
              Flexible(
                child: Text(value,
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: AppColors.text),
                    overflow: TextOverflow.ellipsis),
              ),
            ],
          ),
          if (foot != null) ...[
            const SizedBox(height: 2),
            Text(foot!, style: const TextStyle(fontSize: 10, color: AppColors.textMuted), maxLines: 1, overflow: TextOverflow.ellipsis),
          ],
        ],
      ),
    );
  }
}
