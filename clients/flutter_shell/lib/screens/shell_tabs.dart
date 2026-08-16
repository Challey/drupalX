import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/config/shell_config.dart';
import 'package:dx_flutter_shell/dxep/channel_client.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/layout_engine.dart';

class ShellTabs extends StatefulWidget {
  const ShellTabs({
    super.key,
    required this.config,
    required this.client,
    required this.site,
    required this.layout,
  });

  final ShellConfig config;
  final ChannelClient client;
  final Map<String, dynamic> site;
  final AppLayout layout;

  @override
  State<ShellTabs> createState() => _ShellTabsState();
}

class _ShellTabsState extends State<ShellTabs> {
  int _index = 0;
  late AppLayout _layout;
  late Map<String, dynamic> _site;

  @override
  void initState() {
    super.initState();
    _layout = widget.layout;
    _site = widget.site;
    _schedulePoll();
  }

  void _schedulePoll() {
    final seconds = widget.config.pollSeconds;
    if (seconds <= 0) return;
    Future<void>.delayed(Duration(seconds: seconds), () async {
      if (!mounted) return;
      try {
        final next = await widget.client.fetchAppLayout(
          sinceRevision: _layout.revision,
        );
        if (next != null && mounted) {
          setState(() => _layout = next);
        }
      } catch (_) {
        // Keep prior layout on poll errors.
      }
      if (mounted) _schedulePoll();
    });
  }

  IconData _icon(String name) {
    switch (name) {
      case 'home':
        return Icons.home_outlined;
      case 'article':
        return Icons.article_outlined;
      case 'campaign':
        return Icons.campaign_outlined;
      case 'grid':
        return Icons.apps_outlined;
      case 'inventory':
        return Icons.inventory_2_outlined;
      case 'person':
        return Icons.person_outline;
      default:
        return Icons.circle_outlined;
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _layout.navigation.items;
    if (items.isEmpty) {
      return const Scaffold(body: Center(child: Text('无导航配置')));
    }
    final safeIndex = _index.clamp(0, items.length - 1);
    final pageId = items[safeIndex].page;
    final page = _layout.pages[pageId] ?? LayoutPage(blocks: const []);

    return Scaffold(
      appBar: AppBar(
        title: Text(_layout.theme.displayName),
      ),
      body: LayoutEngine(
        page: page,
        site: _site,
        theme: _layout.theme,
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: safeIndex,
        onDestinationSelected: (i) => setState(() => _index = i),
        destinations: [
          for (final item in items)
            NavigationDestination(
              icon: Icon(_icon(item.icon)),
              label: item.label,
            ),
        ],
      ),
    );
  }
}
