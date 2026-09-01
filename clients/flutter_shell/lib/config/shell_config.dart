import 'dart:convert';

import 'package:flutter/services.dart';

/// Injected / asset shell configuration.
class ShellConfig {
  ShellConfig({
    required this.apiBase,
    required this.tenantId,
    required this.bearerToken,
    required this.shellVersion,
    required this.useFixtures,
    required this.pollSeconds,
    this.layoutFixture = 'assets/fixtures/app_layout_gov.json',
  });

  final String apiBase;
  final String tenantId;
  final String bearerToken;
  final String shellVersion;
  final bool useFixtures;
  final int pollSeconds;

  /// Which bundled layout fixture the shell reads in fixtures mode. Defaults
  /// to the v1 `app_layout_gov` document (the one shipped to live 1.2.x apps);
  /// point it at `assets/fixtures/app_layout_v2.json` to exercise catalogue
  /// v2 blocks.
  final String layoutFixture;

  static Future<ShellConfig> load() async {
    String raw;
    try {
      raw = await rootBundle.loadString('assets/config/shell.json');
    } catch (_) {
      raw = await rootBundle.loadString('assets/config/shell.example.json');
    }
    final map = jsonDecode(raw) as Map<String, dynamic>;
    return ShellConfig.fromJson(map);
  }

  factory ShellConfig.fromJson(Map<String, dynamic> json) {
    return ShellConfig(
      apiBase: (json['api_base'] as String? ?? '').replaceAll(RegExp(r'/+$'), ''),
      tenantId: json['tenant_id'] as String? ?? 'default',
      bearerToken: json['bearer_token'] as String? ?? '',
      shellVersion: json['shell_version'] as String? ?? '1.0.0',
      useFixtures: json['use_fixtures'] as bool? ?? true,
      pollSeconds: json['poll_seconds'] as int? ?? 60,
      layoutFixture: json['layout_fixture'] as String?
          ?? 'assets/fixtures/app_layout_gov.json',
    );
  }
}
