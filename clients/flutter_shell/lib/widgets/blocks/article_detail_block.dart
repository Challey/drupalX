import 'package:flutter/material.dart';

/// `article_detail` (catalog v2) — one L2 article resource, inline.
///
/// Field names mirror the DXEP content projection 1:1 (`id`, `title`,
/// `summary`, `published_at`); `clients/field-contract.json` +
/// `clients-isomorph-smoke.sh` keep the three ends aligned, so renaming a key
/// here without the server fails CI with the offending end and file printed.
class ArticleDetailBlock extends StatelessWidget {
  const ArticleDetailBlock({super.key, required this.props});

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
        Text(title.isEmpty ? '资讯详情' : title,
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
