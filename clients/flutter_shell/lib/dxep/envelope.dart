import 'dart:convert';

/// DXEP response envelope.
class DxEnvelope {
  DxEnvelope({
    required this.ok,
    required this.apiVersion,
    required this.requestId,
    required this.tenantId,
    this.data,
    this.meta,
    this.errorCode,
    this.errorMessage,
  });

  final bool ok;
  final String apiVersion;
  final String requestId;
  final String tenantId;
  final dynamic data;
  final Map<String, dynamic>? meta;
  final String? errorCode;
  final String? errorMessage;

  factory DxEnvelope.fromJson(Map<String, dynamic> json) {
    final error = json['error'] as Map<String, dynamic>?;
    return DxEnvelope(
      ok: json['ok'] == true,
      apiVersion: json['api_version']?.toString() ?? '1.0',
      requestId: json['request_id']?.toString() ?? '',
      tenantId: json['tenant_id']?.toString() ?? '',
      data: json['data'],
      meta: json['meta'] is Map<String, dynamic>
          ? json['meta'] as Map<String, dynamic>
          : null,
      errorCode: error?['code']?.toString(),
      errorMessage: error?['message']?.toString(),
    );
  }

  static DxEnvelope decode(String raw) =>
      DxEnvelope.fromJson(jsonDecode(raw) as Map<String, dynamic>);
}
