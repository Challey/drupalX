import 'package:flutter/material.dart';

class ProductGridBlock extends StatelessWidget {
  const ProductGridBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final limit = int.tryParse('${props['query']?['limit'] ?? 6}') ?? 6;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('产品', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        GridView.count(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          crossAxisCount: 2,
          mainAxisSpacing: 8,
          crossAxisSpacing: 8,
          childAspectRatio: 1.2,
          children: List.generate(limit.clamp(1, 6), (i) {
            return Container(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(12),
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
              ),
              padding: const EdgeInsets.all(12),
              child: Align(
                alignment: Alignment.bottomLeft,
                child: Text('产品 ${i + 1}'),
              ),
            );
          }),
        ),
      ],
    );
  }
}
