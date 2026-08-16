import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/app.dart';
import 'package:dx_flutter_shell/config/shell_config.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final config = await ShellConfig.load();
  runApp(DxShellApp(config: config));
}
