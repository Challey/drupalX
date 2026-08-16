import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/block_registry.dart';

/// Renders a page of layout blocks; unknown types are skipped.
class LayoutEngine extends StatelessWidget {
  const LayoutEngine({
    super.key,
    required this.page,
    required this.site,
    required this.theme,
  });

  final LayoutPage page;
  final Map<String, dynamic> site;
  final LayoutTheme theme;

  @override
  Widget build(BuildContext context) {
    final children = <Widget>[];
    for (final block in page.blocks) {
      final widget = BlockRegistry.build(
        context,
        block,
        site: site,
        theme: theme,
      );
      if (widget != null) {
        children.add(Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: widget,
        ));
      }
    }
    if (children.isEmpty) {
      return const Center(child: Text('暂无内容模块'));
    }
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
      children: children,
    );
  }
}
