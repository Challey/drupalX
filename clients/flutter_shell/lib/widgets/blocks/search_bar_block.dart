import 'package:flutter/material.dart';

/// `search_bar` (catalog v2) — read-only search entry that routes to a page of
/// the layout. It never performs a request itself: the target page's own block
/// queries DXEP L2, keeping the shell free of endpoint knowledge (F2-A).
class SearchBarBlock extends StatelessWidget {
  const SearchBarBlock({super.key, required this.props, this.onTap});

  final Map<String, dynamic> props;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final placeholder = props['placeholder']?.toString() ?? '搜索';
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          // Plain grey instead of a Material-3 surface token: the delivered
          // shells pin different Flutter versions and must all compile.
          color: Colors.grey.shade100,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Row(
          children: [
            Icon(Icons.search,
                size: 18, color: Theme.of(context).colorScheme.outline),
            const SizedBox(width: 8),
            Text(placeholder,
                style: TextStyle(color: Theme.of(context).colorScheme.outline)),
          ],
        ),
      ),
    );
  }
}
