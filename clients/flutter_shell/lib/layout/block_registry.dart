import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/component_catalog.dart';
import 'package:dx_flutter_shell/widgets/blocks/article_detail_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/article_list_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/hero_banner_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/nearby_service_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/notice_detail_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/notice_ticker_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/product_detail_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/product_grid_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/profile_header_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/quick_actions_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/rich_html_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/search_bar_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/service_grid_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/status_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/web_link_block.dart';

/// Closed component catalog (F2-A). Unknown types → null (skip).
///
/// v1 shipped 12 types; v2 (L3 Phase H2) adds the detail trio plus
/// `search_bar` / `quick_actions` / `nearby_service`. The type list, widget
/// names and file layout are generated from [ComponentCatalog] — never edit a
/// `case` here without adding the catalogue entry, CI checks both directions.
class BlockRegistry {
  const BlockRegistry._();

  /// Catalogue schema version this registry implements.
  static const int catalogSchemaVersion = ComponentCatalog.schemaVersion;

  /// Kept for existing call sites and the mini-program mirror (`known`).
  static Set<String> get known => ComponentCatalog.types;

  static List<String> get v1Types => ComponentCatalog.v1Types;

  static List<String> get v2Types => ComponentCatalog.v2Types;

  static bool isKnown(String type) => ComponentCatalog.isKnown(type);

  static Widget? build(
    BuildContext context,
    LayoutBlock block, {
    required Map<String, dynamic> site,
    required LayoutTheme theme,
    List<String> capabilities = const <String>[],
  }) {
    if (!ComponentCatalog.isAvailable(block.type, capabilities)) {
      // Unknown type, or a capability the shell/layout did not declare.
      if (ComponentCatalog.isKnown(block.type)) {
        debugPrint('DX shell: skip ${block.type}, capability '
            '${ComponentCatalog.find(block.type)!.capability} unavailable');
      } else {
        debugPrint('DX shell: skip unknown block type ${block.type}');
      }
      return null;
    }
    switch (block.type) {
      case 'hero_banner':
        return HeroBannerBlock(props: block.props, site: site, theme: theme);
      case 'notice_ticker':
        return NoticeTickerBlock(props: block.props);
      case 'article_list':
        return ArticleListBlock(props: block.props, title: '资讯');
      case 'notice_list':
        return ArticleListBlock(props: block.props, title: '通知公告');
      case 'product_grid':
        return ProductGridBlock(props: block.props);
      case 'service_grid':
        return ServiceGridBlock(props: block.props);
      case 'profile_header':
        return ProfileHeaderBlock(site: site, theme: theme);
      case 'rich_html':
      case 'content':
        return RichHtmlBlock(props: block.props);
      case 'web_link':
        return WebLinkBlock(props: block.props);
      case 'empty':
        return StatusBlock(props: block.props);
      case 'error':
        return StatusBlock(props: block.props, isError: true);
      case 'article_detail':
        return ArticleDetailBlock(props: block.props);
      case 'notice_detail':
        return NoticeDetailBlock(props: block.props);
      case 'product_detail':
        return ProductDetailBlock(props: block.props);
      case 'search_bar':
        return SearchBarBlock(props: block.props);
      case 'quick_actions':
        return QuickActionsBlock(props: block.props);
      case 'nearby_service':
        return NearbyServiceBlock(props: block.props);
      default:
        debugPrint('DX shell: skip unknown block type ${block.type}');
        return null;
    }
  }
}
