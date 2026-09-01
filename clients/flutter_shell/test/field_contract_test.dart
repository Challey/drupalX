import 'dart:convert';
import 'dart:io';

import 'package:dx_flutter_shell/dxep/field_contract.dart';
import 'package:flutter_test/flutter_test.dart';

/// Field-contract tests (L3 Phase H3).
///
/// Offline only — reads `clients/field-contract.json` plus the bundled
/// fixtures, no server, no device:
///
///   cd clients/flutter_shell && flutter test test/field_contract_test.dart
///
/// The same rules are enforced statically (without a SDK) by
/// `python3 tools/clients/isomorph_check.py fields fixtures`, so a missing
/// Flutter SDK never hides a contract break.
void main() {
  /// The contract lives one level up in the repo; a packed shell (x-pack
  /// Flutter) ships only this package, hence the conditional `skip`.
  final contractFile = File('../field-contract.json');
  final shipped = contractFile.existsSync();

  Map<String, dynamic> contract() =>
      jsonDecode(contractFile.readAsStringSync()) as Map<String, dynamic>;

  List<Map<String, dynamic>> fields() => (contract()['fields'] as List<dynamic>)
      .cast<Map<String, dynamic>>();

  Map<String, dynamic> fixture(String name) => jsonDecode(
        File('assets/fixtures/$name').readAsStringSync(),
      ) as Map<String, dynamic>;

  /// Walks `price.amount` style suffixes inside one L2 item. Null-valued but
  /// present keys count as delivered (the server emits `external_id: null`),
  /// so this checks containment, not truthiness.
  bool hasPath(Map<String, dynamic> item, String suffix) {
    Object? node = item;
    for (final part in suffix.split('.')) {
      if (node is! Map<String, dynamic> || !node.containsKey(part)) {
        return false;
      }
      node = node[part];
    }
    return true;
  }

  test('Dart mirror equals the cross-end contract 1:1', () {
    final entries = fields();
    final want = <String, String>{
      for (final f in entries) f['path'] as String: f['type'] as String,
    };
    expect(dxepFieldContract, want,
        reason: 'run: python3 tools/clients/isomorph_check.py mirror --write');
    expect(dxepFieldContractSchemaVersion, contract()['schema_version']);
    expect(dxepFieldContractSpec, contract()['spec']);
  }, skip: shipped ? false : 'clients/field-contract.json not in this pack');

  test('L2 fixtures carry every required content field', () {
    final required = fields()
        .where((f) =>
            f['resource'] == 'content' &&
            f['required'] == true &&
            (f['presence'] ?? 'always') == 'always')
        .toList();
    final items = <Map<String, dynamic>>[
      for (final raw in (fixture('contents_list.json')['data'] as List<dynamic>))
        raw as Map<String, dynamic>,
      fixture('content_article.json')['data'] as Map<String, dynamic>,
      fixture('content_product.json')['data'] as Map<String, dynamic>,
    ];
    expect(items, isNotEmpty);
    for (final field in required) {
      final scope = field['scope'] as String?;
      final suffix = (field['path'] as String).substring('content.'.length);
      for (final item in items) {
        final isProduct = item['type'] == 'product';
        if (scope == 'product' && !isProduct) {
          continue;
        }
        expect(hasPath(item, suffix), isTrue,
            reason: '${item['id']} is missing ${field['path']}');
      }
    }
  }, skip: shipped ? false : 'L2 fixtures are not in this pack');

  test('L2 timestamps are RFC3339 and prices stay decimal strings', () {
    final rfc3339 = RegExp(
        r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$');
    final decimal = RegExp(r'^-?\d+(\.\d+)?$');
    for (final raw in (fixture('contents_list.json')['data'] as List<dynamic>)) {
      final item = raw as Map<String, dynamic>;
      for (final key in ['published_at', 'updated_at', 'created_at']) {
        expect(rfc3339.hasMatch(item[key] as String), isTrue,
            reason: '${item['id']}.$key is not RFC3339');
      }
    }
    final product =
        fixture('content_product.json')['data'] as Map<String, dynamic>;
    final price = product['price'] as Map<String, dynamic>;
    expect(price['amount'], isA<String>(),
        reason: 'money must never travel as a float');
    expect(decimal.hasMatch(price['amount'] as String), isTrue);
    expect(dxepFieldType('content.price.currency'), 'string');
    expect(price['currency'], isA<String>());
  }, skip: shipped ? false : 'L2 fixtures are not in this pack');

  test('envelope keys the shell depends on are contracted', () {
    for (final path in [
      'envelope.ok',
      'envelope.api_version',
      'envelope.request_id',
      'envelope.tenant_id',
      'envelope.data',
      'envelope.meta.page',
      'envelope.meta.page_size',
      'envelope.meta.total',
      'envelope.error.code',
    ]) {
      expect(dxepFieldExists(path), isTrue, reason: '$path must be declared');
    }
    final env = fixture('contents_list.json');
    expect(env.containsKey('ok'), isTrue);
    expect(env.containsKey('api_version'), isTrue);
    expect((env['meta'] as Map<String, dynamic>).containsKey('page_size'),
        isTrue);
  }, skip: shipped ? false : 'fixtures are not in this pack');
}
