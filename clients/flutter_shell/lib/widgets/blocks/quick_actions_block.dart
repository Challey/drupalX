import 'package:flutter/material.dart';

/// `quick_actions` (catalog v2) — tenant-configured shortcut chips.
///
/// Pure layout data (`items: [{label, target}]`), no L2 field needed, which is
/// why it can ship before the search/order endpoints land. `target` is kept as
/// a plain string; resolving it to a route is the host app's job via [onPick]
/// (the shell never executes remote code).
class QuickActionsBlock extends StatelessWidget {
  const QuickActionsBlock({super.key, required this.props, this.onPick});

  final Map<String, dynamic> props;
  final void Function(String target)? onPick;

  @override
  Widget build(BuildContext context) {
    final raw = props['items'] is List ? props['items'] as List : const [];
    final columns = int.tryParse('${props['columns'] ?? 4}') ?? 4;
    final title = props['title']?.toString() ?? '快捷入口';
    if (raw.isEmpty) {
      return const SizedBox.shrink();
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final entry in raw.take(columns.clamp(1, 12)))
              if (entry is Map<String, dynamic>)
                ActionChip(
                  label: Text(entry['label']?.toString() ?? ''),
                  onPressed: onPick == null
                      ? null
                      : () => onPick!(entry['target']?.toString() ?? ''),
                ),
          ],
        ),
      ],
    );
  }
}
