import 'dart:convert';

import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import 'package:dx_flutter_shell/config/shell_config.dart';
import 'package:dx_flutter_shell/dxep/envelope.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';

/// DXEP Channel client (Bearer required).
class ChannelClient {
  ChannelClient(this.config, {http.Client? httpClient})
      : _http = httpClient ?? http.Client();

  final ShellConfig config;
  final http.Client _http;

  Future<Map<String, dynamic>> fetchSite() async {
    if (config.useFixtures) {
      final raw = await rootBundle.loadString('assets/fixtures/site.json');
      final env = DxEnvelope.decode(raw);
      if (!env.ok || env.data is! Map<String, dynamic>) {
        throw StateError('fixture site invalid');
      }
      return env.data as Map<String, dynamic>;
    }
    final env = await _get('/api/dx/v1/channel/site');
    if (!env.ok || env.data is! Map<String, dynamic>) {
      throw StateError(env.errorMessage ?? 'site failed');
    }
    return env.data as Map<String, dynamic>;
  }

  /// Returns layout, or null when 304 Not Modified.
  Future<AppLayout?> fetchAppLayout({int? sinceRevision}) async {
    if (config.useFixtures) {
      final path = 'assets/fixtures/app_layout_gov.json';
      final raw = await rootBundle.loadString(path);
      final map = jsonDecode(raw) as Map<String, dynamic>;
      // Fixture file is raw layout (not envelope).
      map.putIfAbsent('spec', () => 'DX-APP-LAYOUT');
      map.putIfAbsent('spec_version', () => '1.0');
      map.putIfAbsent('revision', () => 1);
      map.putIfAbsent('min_shell_version', () => '1.0.0');
      map.putIfAbsent('tenant_id', () => config.tenantId);
      return AppLayout.fromJson(map);
    }

    final q = sinceRevision == null ? '' : '?since_revision=$sinceRevision';
    final uri = Uri.parse('${config.apiBase}/api/dx/v1/channel/app-layout$q');
    final res = await _http.get(uri, headers: _headers());
    if (res.statusCode == 304) {
      return null;
    }
    if (res.statusCode == 401 || res.statusCode == 403) {
      throw StateError('Channel auth failed (${res.statusCode})');
    }
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw StateError('app-layout HTTP ${res.statusCode}');
    }
    final env = DxEnvelope.decode(res.body);
    if (!env.ok || env.data is! Map<String, dynamic>) {
      throw StateError(env.errorMessage ?? 'app-layout failed');
    }
    return AppLayout.fromJson(env.data as Map<String, dynamic>);
  }

  Future<DxEnvelope> _get(String path) async {
    final uri = Uri.parse('${config.apiBase}$path');
    final res = await _http.get(uri, headers: _headers());
    if (res.statusCode == 401 || res.statusCode == 403) {
      throw StateError('Channel auth failed (${res.statusCode})');
    }
    if (res.statusCode < 200 || res.statusCode >= 300) {
      throw StateError('HTTP ${res.statusCode} for $path');
    }
    return DxEnvelope.decode(res.body);
  }

  Map<String, String> _headers() {
    final headers = <String, String>{
      'Accept': 'application/json',
    };
    if (config.bearerToken.isNotEmpty) {
      headers['Authorization'] = 'Bearer ${config.bearerToken}';
    }
    return headers;
  }
}
