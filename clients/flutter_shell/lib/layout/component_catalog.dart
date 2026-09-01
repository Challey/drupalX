/// Dart mirror of `assets/config/component_catalog.json` (DX-COMPONENT-CATALOG).
///
/// L3 Phase H2: the closed component catalogue, v2.
///
/// The JSON file is the contract the server team / CI read; this class is the
/// copy the shell uses at runtime. They must stay identical —
/// `test/component_catalog_test.dart` asserts it with `flutter test`, and
/// `tools/clients/isomorph_check.py catalog` asserts it statically (no SDK),
/// including the one-widget-file-per-component rule.
library;

class ComponentSpec {
  const ComponentSpec({
    required this.type,
    required this.widget,
    required this.file,
    required this.since,
    this.capability,
    this.aliasOf,
  });

  /// DX-APP-LAYOUT block type, as produced by the server.
  final String type;

  /// Widget class rendered for it (must exist in [file]).
  final String widget;

  /// Path inside this package; the layout-driven mini-program mirrors it.
  final String file;

  /// Catalogue version the type entered (1 = frozen v1 set, 2 = Phase H2).
  final int since;

  /// Optional L1 capability gate: the block renders only when
  /// `AppLayout.capabilities` contains this token. Names are shared with the
  /// Android shell 1.3.0 manifest (`location`, `microphone`, `photo_upload`).
  final String? capability;

  /// Set when the type reuses another type's widget (e.g. `content`).
  final String? aliasOf;
}

class ComponentCatalog {
  const ComponentCatalog._();

  static const int schemaVersion = 2;
  static const String spec = 'DX-COMPONENT-CATALOG';

  static const List<ComponentSpec> entries = <ComponentSpec>[
    ComponentSpec(type: 'hero_banner', widget: 'HeroBannerBlock', file: 'lib/widgets/blocks/hero_banner_block.dart', since: 1),
    ComponentSpec(type: 'notice_ticker', widget: 'NoticeTickerBlock', file: 'lib/widgets/blocks/notice_ticker_block.dart', since: 1),
    ComponentSpec(type: 'article_list', widget: 'ArticleListBlock', file: 'lib/widgets/blocks/article_list_block.dart', since: 1),
    ComponentSpec(type: 'notice_list', widget: 'ArticleListBlock', file: 'lib/widgets/blocks/article_list_block.dart', since: 1, aliasOf: 'article_list'),
    ComponentSpec(type: 'product_grid', widget: 'ProductGridBlock', file: 'lib/widgets/blocks/product_grid_block.dart', since: 1),
    ComponentSpec(type: 'service_grid', widget: 'ServiceGridBlock', file: 'lib/widgets/blocks/service_grid_block.dart', since: 1),
    ComponentSpec(type: 'profile_header', widget: 'ProfileHeaderBlock', file: 'lib/widgets/blocks/profile_header_block.dart', since: 1),
    ComponentSpec(type: 'rich_html', widget: 'RichHtmlBlock', file: 'lib/widgets/blocks/rich_html_block.dart', since: 1),
    ComponentSpec(type: 'content', widget: 'RichHtmlBlock', file: 'lib/widgets/blocks/rich_html_block.dart', since: 1, aliasOf: 'rich_html'),
    ComponentSpec(type: 'web_link', widget: 'WebLinkBlock', file: 'lib/widgets/blocks/web_link_block.dart', since: 1),
    ComponentSpec(type: 'empty', widget: 'StatusBlock', file: 'lib/widgets/blocks/status_block.dart', since: 1),
    ComponentSpec(type: 'error', widget: 'StatusBlock', file: 'lib/widgets/blocks/status_block.dart', since: 1, aliasOf: 'empty'),
    ComponentSpec(type: 'article_detail', widget: 'ArticleDetailBlock', file: 'lib/widgets/blocks/article_detail_block.dart', since: 2),
    ComponentSpec(type: 'notice_detail', widget: 'NoticeDetailBlock', file: 'lib/widgets/blocks/notice_detail_block.dart', since: 2),
    ComponentSpec(type: 'product_detail', widget: 'ProductDetailBlock', file: 'lib/widgets/blocks/product_detail_block.dart', since: 2),
    ComponentSpec(type: 'search_bar', widget: 'SearchBarBlock', file: 'lib/widgets/blocks/search_bar_block.dart', since: 2),
    ComponentSpec(type: 'quick_actions', widget: 'QuickActionsBlock', file: 'lib/widgets/blocks/quick_actions_block.dart', since: 2),
    ComponentSpec(type: 'nearby_service', widget: 'NearbyServiceBlock', file: 'lib/widgets/blocks/nearby_service_block.dart', since: 2, capability: 'location'),
  ];

  static Set<String> get types =>
      entries.map((e) => e.type).toSet();

  /// v1 frozen types — must stay a subset of [types] forever (delivered apps).
  static const List<String> v1Types = <String>[
    'hero_banner',
    'notice_ticker',
    'article_list',
    'notice_list',
    'product_grid',
    'service_grid',
    'profile_header',
    'rich_html',
    'content',
    'web_link',
    'empty',
    'error',
  ];

  static List<String> get v2Types => entries
      .where((e) => e.since >= 2)
      .map((e) => e.type)
      .toList(growable: false);

  static ComponentSpec? find(String type) {
    for (final spec in entries) {
      if (spec.type == type) {
        return spec;
      }
    }
    return null;
  }

  static bool isKnown(String type) => find(type) != null;

  /// True when the block may render for a shell/session with [capabilities].
  static bool isAvailable(String type, List<String> capabilities) {
    final spec = find(type);
    if (spec == null) {
      return false;
    }
    return spec.capability == null || capabilities.contains(spec.capability);
  }
}
