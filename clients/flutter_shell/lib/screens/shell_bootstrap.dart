import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/config/shell_config.dart';
import 'package:dx_flutter_shell/dxep/channel_client.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/screens/shell_tabs.dart';
import 'package:dx_flutter_shell/theme/dx_theme.dart';

class ShellBootstrap extends StatefulWidget {
  const ShellBootstrap({
    super.key,
    required this.config,
    required this.client,
  });

  final ShellConfig config;
  final ChannelClient client;

  @override
  State<ShellBootstrap> createState() => _ShellBootstrapState();
}

class _ShellBootstrapState extends State<ShellBootstrap> {
  late Future<_BootData> _future;

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<_BootData> _load() async {
    final site = await widget.client.fetchSite();
    final layout = await widget.client.fetchAppLayout();
    if (layout == null) {
      throw StateError('No layout');
    }
    if (!layout.isShellCompatible(widget.config.shellVersion)) {
      throw StateError(
        '请升级 App：当前 ${widget.config.shellVersion} < 要求 ${layout.minShellVersion}',
      );
    }
    return _BootData(site: site, layout: layout);
  }

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<_BootData>(
      future: _future,
      builder: (context, snap) {
        if (snap.connectionState != ConnectionState.done) {
          return const Scaffold(
            body: Center(child: CircularProgressIndicator()),
          );
        }
        if (snap.hasError) {
          return Scaffold(
            body: Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Text('加载失败：${snap.error}'),
              ),
            ),
          );
        }
        final data = snap.data!;
        return Theme(
          data: DxTheme.light(primaryHex: data.layout.theme.primary),
          child: ShellTabs(
            config: widget.config,
            client: widget.client,
            site: data.site,
            layout: data.layout,
          ),
        );
      },
    );
  }
}

class _BootData {
  _BootData({required this.site, required this.layout});
  final Map<String, dynamic> site;
  final AppLayout layout;
}
