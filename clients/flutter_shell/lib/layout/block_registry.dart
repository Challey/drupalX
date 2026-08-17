import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/widgets/blocks/article_list_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/hero_banner_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/notice_ticker_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/product_grid_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/profile_header_block.dart';
import 'package:dx_flutter_shell/widgets/blocks/service_grid_block.dart';

/// Closed component catalog (F2-A). Unknown types → null (skip).
class BlockRegistry {
  static const known = <String>{
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
  };

  static Widget? build(
    BuildContext context,
    LayoutBlock block, {
    required Map<String, dynamic> site,
    required LayoutTheme theme,
  }) {
    switch (block.type) {
      case 'hero_banner':
        return HeroBannerBlock(props: block.props, site: site, theme: theme);
      case 'notice_ticker':
        return NoticeTickerBlock(props: block.props);
      case 'article_list':
      case 'notice_list':
        return ArticleListBlock(
          props: block.props,
          title: block.type == 'notice_list' ? '通知公告' : '资讯',
        );
      case 'product_grid':
        return ProductGridBlock(props: block.props);
      case 'service_grid':
        return ServiceGridBlock(props: block.props);
      case 'profile_header':
        return ProfileHeaderBlock(site: site, theme: theme);
      case 'rich_html':
      case 'content':
        return _RichHtmlStub(html: block.props['html']?.toString() ?? block.props['body']?.toString() ?? '');
      case 'web_link':
        return ListTile(
          title: Text(block.props['title']?.toString() ?? '外链'),
          subtitle: Text(block.props['url']?.toString() ?? ''),
          trailing: const Icon(Icons.open_in_new),
        );
      case 'empty':
      case 'error':
        return const SizedBox.shrink();
      default:
        debugPrint('DX shell: skip unknown block type ${block.type}');
        return null;
    }
  }
}

class _RichHtmlStub extends StatelessWidget {
  const _RichHtmlStub({required this.html});
  final String html;

  @override
  Widget build(BuildContext context) {
    // v1: plain text fallback (no remote HTML exec). Sanitize server-side.
    final text = html.replaceAll(RegExp(r'<[^>]*>'), ' ').trim();
    return Text(text.isEmpty ? ' ' : text);
  }
}
