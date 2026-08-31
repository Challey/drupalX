import 'package:flutter/material.dart';

/// `product_detail` (catalog v2) — one L2 product resource, inline.
///
/// Reads the projected product shape: `title`, `sku`, `price.amount`,
/// `price.currency` (amount stays a decimal *string* end to end — never a
/// float, see docs/data-exchange.md §7 and clients/field-contract.json).
class ProductDetailBlock extends StatelessWidget {
  const ProductDetailBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final title = props['title']?.toString() ?? '';
    final sku = props['sku']?.toString() ?? '';
    final price = props['price'] is Map<String, dynamic>
        ? props['price'] as Map<String, dynamic>
        : const <String, dynamic>{};
    final amount = price['amount']?.toString() ?? '';
    final currency = price['currency']?.toString() ?? 'CNY';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(title.isEmpty ? '产品详情' : title,
            style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Row(
          children: [
            Text(
              amount.isEmpty ? '—' : '$currency $amount',
              style: Theme.of(context).textTheme.titleLarge?.copyWith(
                    color: Theme.of(context).colorScheme.primary,
                  ),
            ),
            if (sku.isNotEmpty) ...[
              const SizedBox(width: 12),
              Text('SKU $sku', style: Theme.of(context).textTheme.bodySmall),
            ],
          ],
        ),
      ],
    );
  }
}
