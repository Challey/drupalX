import 'dart:convert';
import 'dart:io';

import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/block_registry.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('parses gov layout fixture and knows catalog types', () {
    final file = File('assets/fixtures/app_layout_gov.json');
    final map = jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
    map['spec'] = 'DX-APP-LAYOUT';
    map['revision'] = 1;
    map['min_shell_version'] = '1.0.0';
    final layout = AppLayout.fromJson(map);
    expect(layout.navigation.items.length, greaterThan(0));
    expect(layout.pages.containsKey('page_home'), isTrue);
    for (final block in layout.pages['page_home']!.blocks) {
      expect(BlockRegistry.known.contains(block.type), isTrue,
          reason: 'unknown type ${block.type}');
    }
    expect(layout.isShellCompatible('1.0.0'), isTrue);
    expect(layout.isShellCompatible('0.9.0'), isFalse);
  });
}
