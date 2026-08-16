import 'package:flutter/material.dart';

class NoticeTickerBlock extends StatelessWidget {
  const NoticeTickerBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final limit = int.tryParse('${props['query']?['limit'] ?? 5}') ?? 5;
    // L2 wiring lands with contents API; placeholder rows for MVP shell.
    final items = List.generate(
      limit.clamp(1, 5),
      (i) => '通知公告示例 ${i + 1}',
    );
    return Card(
      elevation: 0,
      color: Theme.of(context).colorScheme.surfaceContainerHighest,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Icon(Icons.campaign, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                items.join('  ·  '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
