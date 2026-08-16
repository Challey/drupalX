/// Parsed DX-APP-LAYOUT document.
class AppLayout {
  AppLayout({
    required this.spec,
    required this.revision,
    required this.minShellVersion,
    required this.theme,
    required this.capabilities,
    required this.navigation,
    required this.pages,
    required this.routes,
    this.layoutId,
    this.tenantId,
    this.checksum,
  });

  final String spec;
  final int revision;
  final String minShellVersion;
  final LayoutTheme theme;
  final List<String> capabilities;
  final LayoutNavigation navigation;
  final Map<String, LayoutPage> pages;
  final Map<String, dynamic> routes;
  final String? layoutId;
  final String? tenantId;
  final String? checksum;

  factory AppLayout.fromJson(Map<String, dynamic> json) {
    final pagesRaw = json['pages'] as Map<String, dynamic>? ?? {};
    final pages = <String, LayoutPage>{};
    pagesRaw.forEach((key, value) {
      if (value is Map<String, dynamic>) {
        pages[key] = LayoutPage.fromJson(value);
      }
    });
    return AppLayout(
      spec: json['spec']?.toString() ?? 'DX-APP-LAYOUT',
      revision: int.tryParse('${json['revision'] ?? 1}') ?? 1,
      minShellVersion: json['min_shell_version']?.toString() ?? '1.0.0',
      theme: LayoutTheme.fromJson(
        json['theme'] is Map<String, dynamic>
            ? json['theme'] as Map<String, dynamic>
            : {},
      ),
      capabilities: (json['capabilities'] as List<dynamic>? ?? [])
          .map((e) => e.toString())
          .toList(),
      navigation: LayoutNavigation.fromJson(
        json['navigation'] is Map<String, dynamic>
            ? json['navigation'] as Map<String, dynamic>
            : {},
      ),
      pages: pages,
      routes: json['routes'] is Map<String, dynamic>
          ? json['routes'] as Map<String, dynamic>
          : {},
      layoutId: json['layout_id']?.toString(),
      tenantId: json['tenant_id']?.toString(),
      checksum: json['checksum']?.toString(),
    );
  }

  /// Semver-ish: shell must be >= min (major.minor.patch numeric compare).
  bool isShellCompatible(String shellVersion) {
    return _cmp(shellVersion, minShellVersion) >= 0;
  }

  static int _cmp(String a, String b) {
    List<int> parts(String v) => v
        .split('.')
        .map((p) => int.tryParse(p.replaceAll(RegExp(r'[^0-9]'), '')) ?? 0)
        .toList();
    final pa = parts(a);
    final pb = parts(b);
    final n = pa.length > pb.length ? pa.length : pb.length;
    for (var i = 0; i < n; i++) {
      final x = i < pa.length ? pa[i] : 0;
      final y = i < pb.length ? pb[i] : 0;
      if (x != y) return x.compareTo(y);
    }
    return 0;
  }
}

class LayoutTheme {
  LayoutTheme({
    required this.pack,
    required this.primary,
    required this.displayName,
    this.logoUrl,
  });

  final String pack;
  final String primary;
  final String displayName;
  final String? logoUrl;

  factory LayoutTheme.fromJson(Map<String, dynamic> json) {
    return LayoutTheme(
      pack: json['pack']?.toString() ?? 'portal',
      primary: json['primary']?.toString() ?? '#1A365D',
      displayName: json['display_name']?.toString() ?? 'DrupalX',
      logoUrl: json['logo_url']?.toString(),
    );
  }
}

class LayoutNavigation {
  LayoutNavigation({required this.type, required this.items});

  final String type;
  final List<NavItem> items;

  factory LayoutNavigation.fromJson(Map<String, dynamic> json) {
    final items = <NavItem>[];
    for (final raw in json['items'] as List<dynamic>? ?? []) {
      if (raw is Map<String, dynamic>) {
        items.add(NavItem.fromJson(raw));
      }
    }
    return LayoutNavigation(
      type: json['type']?.toString() ?? 'tab',
      items: items,
    );
  }
}

class NavItem {
  NavItem({
    required this.id,
    required this.label,
    required this.icon,
    required this.page,
  });

  final String id;
  final String label;
  final String icon;
  final String page;

  factory NavItem.fromJson(Map<String, dynamic> json) {
    return NavItem(
      id: json['id']?.toString() ?? '',
      label: json['label']?.toString() ?? '',
      icon: json['icon']?.toString() ?? 'circle',
      page: json['page']?.toString() ?? '',
    );
  }
}

class LayoutPage {
  LayoutPage({required this.blocks});

  final List<LayoutBlock> blocks;

  factory LayoutPage.fromJson(Map<String, dynamic> json) {
    final blocks = <LayoutBlock>[];
    for (final raw in json['blocks'] as List<dynamic>? ?? []) {
      if (raw is Map<String, dynamic>) {
        blocks.add(LayoutBlock.fromJson(raw));
      }
    }
    return LayoutPage(blocks: blocks);
  }
}

class LayoutBlock {
  LayoutBlock({required this.type, required this.props});

  final String type;
  final Map<String, dynamic> props;

  factory LayoutBlock.fromJson(Map<String, dynamic> json) {
    return LayoutBlock(
      type: json['type']?.toString() ?? 'empty',
      props: json['props'] is Map<String, dynamic>
          ? json['props'] as Map<String, dynamic>
          : {},
    );
  }
}
