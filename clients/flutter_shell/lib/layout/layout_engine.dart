import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/block_registry.dart';
import 'package:dx_flutter_shell/layout/component_catalog.dart';

/// Renders a page of layout blocks; unknown types are skipped.
///
/// Catalog v2 adds the capability gate: a block whose catalogue entry declares
/// `capability: <name>` only renders when the L1 layout declares it, which is
/// the same token the Android shell manifest uses (`location`, …).
class LayoutEngine extends StatelessWidget {
  const LayoutEngine({
    super.key,
    required this.page,
    required this.site,
    required this.theme,
    this.capabilities = const <String>[],
  });

  final LayoutPage page;
  final Map<String, dynamic> site;
  final LayoutTheme theme;
  final List<String> capabilities;

  /// Pure selection step of [build] — no widget tree, so the offline unit
  /// tests (and any host app) can assert the render plan directly.
  static List<LayoutBlock> renderPlan(
    LayoutPage page,
    List<String> capabilities,
  ) {
    return page.blocks
        .where((block) => ComponentCatalog.isAvailable(block.type, capabilities))
        .toList(growable: false);
  }

  @override
  Widget build(BuildContext context) {
    final children = <Widget>[];
    for (final block in renderPlan(page, capabilities)) {
      final widget = BlockRegistry.build(
        context,
        block,
        site: site,
        theme: theme,
        capabilities: capabilities,
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
