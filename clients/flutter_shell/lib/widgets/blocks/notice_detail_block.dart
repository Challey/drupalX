import 'package:flutter/material.dart';

/// `notice_detail` (catalog v2) — one L2 notice resource, inline.
///
/// Same field names as article_detail (the server projects both from the
/// same content shape); the notice badge is the only visual difference. The
/// 文号 area waits for an L2 `doc_no` field — see the lane change note.
class NoticeDetailBlock extends StatelessWidget {
  const NoticeDetailBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final title = props['title']?.toString() ?? '';
    final summary = props['summary']?.toString() ?? '';
    final publishedAt = props['published_at']?.toString() ?? '';
    final id = props['id']?.toString() ?? '';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            const Icon(Icons.campaign, size: 18),
            const SizedBox(width: 6),
            Text('公告', style: Theme.of(context).textTheme.labelLarge),
          ],
        ),
        const SizedBox(height: 6),
        Text(title.isEmpty ? '公告详情' : title,
            style: Theme.of(context).textTheme.titleMedium),
        if (publishedAt.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(publishedAt, style: Theme.of(context).textTheme.bodySmall),
        ],
        if (summary.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(summary),
        ],
        if (id.isNotEmpty) ...[
          const SizedBox(height: 8),
          Text(id, style: Theme.of(context).textTheme.labelSmall),
        ],
      ],
    );
  }
}
