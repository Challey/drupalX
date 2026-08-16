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
  });

  final String apiBase;
  final String tenantId;
  final String bearerToken;
  final String shellVersion;
  final bool useFixtures;
  final int pollSeconds;

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
    );
  }
}
