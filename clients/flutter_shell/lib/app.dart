import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/config/shell_config.dart';
import 'package:dx_flutter_shell/dxep/channel_client.dart';
import 'package:dx_flutter_shell/screens/shell_bootstrap.dart';
import 'package:dx_flutter_shell/theme/dx_theme.dart';

class DxShellApp extends StatelessWidget {
  const DxShellApp({super.key, required this.config});

  final ShellConfig config;

  @override
  Widget build(BuildContext context) {
    final client = ChannelClient(config);
    return MaterialApp(
      title: 'DrupalX',
      debugShowCheckedModeBanner: false,
      theme: DxTheme.light(primaryHex: '#1A365D'),
      home: ShellBootstrap(config: config, client: client),
    );
  }
}
