import 'package:flutter/material.dart';

class ArticleListBlock extends StatelessWidget {
  const ArticleListBlock({
    super.key,
    required this.props,
    required this.title,
  });

  final Map<String, dynamic> props;
  final String title;

  @override
  Widget build(BuildContext context) {
    final limit = int.tryParse('${props['query']?['limit'] ?? 6}') ?? 6;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title, style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        ...List.generate(limit.clamp(1, 8), (i) {
          return ListTile(
            contentPadding: EdgeInsets.zero,
            title: Text('$title条目 ${i + 1}'),
            subtitle: const Text('内容将由 DXEP Channel L2 填充'),
            trailing: const Icon(Icons.chevron_right),
            onTap: () {},
          );
        }),
      ],
    );
  }
}
