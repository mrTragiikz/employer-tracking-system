import 'package:flutter/material.dart';
import '../core/theme.dart';
import '../services/outbox.dart';

/// A small strip shown on the action screens whenever the offline outbox has
/// items waiting to sync. Tapping "Sync now" forces a flush.
class OutboxBanner extends StatelessWidget {
  const OutboxBanner({super.key});

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: Outbox.instance,
      builder: (context, _) {
        final n = Outbox.instance.pending;
        if (n == 0) return const SizedBox.shrink();
        final flushing = Outbox.instance.isFlushing;
        return Container(
          margin: const EdgeInsets.only(bottom: 14),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFFFBF3E6),
            borderRadius: BorderRadius.circular(AppRadius.sm),
            border: Border.all(color: const Color(0xFFEBD9B8)),
          ),
          child: Row(
            children: [
              SizedBox(
                width: 16,
                height: 16,
                child: flushing
                    ? const CircularProgressIndicator(strokeWidth: 2, color: AppColors.accent)
                    : const Icon(Icons.cloud_upload, size: 16, color: AppColors.accent),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  flushing
                      ? 'Syncing $n saved ${n == 1 ? 'action' : 'actions'}…'
                      : '$n saved ${n == 1 ? 'action is' : 'actions are'} waiting to sync',
                  style: const TextStyle(fontSize: 11.5, color: AppColors.text2, height: 1.35),
                ),
              ),
              if (!flushing)
                TextButton(
                  onPressed: () => Outbox.instance.flush(),
                  style: TextButton.styleFrom(
                    foregroundColor: AppColors.brown,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    minimumSize: const Size(0, 0),
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                  ),
                  child: const Text('Sync now', style: TextStyle(fontSize: 11.5)),
                ),
            ],
          ),
        );
      },
    );
  }
}
