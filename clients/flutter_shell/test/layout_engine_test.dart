import 'dart:convert';
import 'dart:io';

import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/block_registry.dart';
import 'package:dx_flutter_shell/layout/component_catalog.dart';
import 'package:dx_flutter_shell/layout/layout_engine.dart';
import 'package:flutter_test/flutter_test.dart';

/// Layout-engine unit cases (L3 Phase H2) — pure Dart, `flutter test` only.
void main() {
  Map<String, dynamic> fixture(String name) {
    final file = File('assets/fixtures/$name');
    expect(file.existsSync(), isTrue, reason: 'missing fixture $name');
    return jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  }

  AppLayout layoutFrom(Map<String, dynamic> map) {
    map['spec'] = 'DX-APP-LAYOUT';
    map['revision'] = 1;
    map['min_shell_version'] = '1.0.0';
    return AppLayout.fromJson(map);
  }

  LayoutPage pageOf(List<String> types) => LayoutPage(
        blocks: types
            .map((t) => LayoutBlock(type: t, props: const <String, dynamic>{}))
            .toList(),
      );

  test('parses gov layout fixture and knows catalog types', () {
    final layout = layoutFrom(fixture('app_layout_gov.json'));
    expect(layout.navigation.items.length, greaterThan(0));
    expect(layout.pages.containsKey('page_home'), isTrue);
    for (final block in layout.pages['page_home']!.blocks) {
      expect(BlockRegistry.known.contains(block.type), isTrue,
          reason: 'unknown type ${block.type}');
    }
    expect(layout.isShellCompatible('1.0.0'), isTrue);
    expect(layout.isShellCompatible('0.9.0'), isFalse);
  });

  test('ent layout fixture stays inside the closed catalog', () {
    final layout = layoutFrom(fixture('app_layout_ent.json'));
    for (final page in layout.pages.values) {
      for (final block in page.blocks) {
        expect(ComponentCatalog.isKnown(block.type), isTrue,
            reason: '${block.type} is not catalogued');
      }
    }
  });

  test('shell 1.3.x and 2.x accept a 1.0.0 layout; 0.9 does not', () {
    final layout = layoutFrom(fixture('app_layout_gov.json'));
    expect(layout.isShellCompatible('1.3.0'), isTrue);
    expect(layout.isShellCompatible('1.10.0'), isTrue,
        reason: 'numeric compare, not string compare');
    expect(layout.isShellCompatible('0.9.9'), isFalse);
  });

  test('render plan keeps v1 order and drops unknown types', () {
    final page = pageOf([
      'hero_banner',
      'live_map',
      'article_list',
      'not_a_component',
      'profile_header',
    ]);
    final plan = LayoutEngine.renderPlan(page, const <String>[]);
    expect(plan.map((b) => b.type),
        <String>['hero_banner', 'article_list', 'profile_header']);
  });

  test('v2 blocks render once catalogued, capability gates apply', () {
    final page = pageOf(['search_bar', 'quick_actions', 'nearby_service']);
    expect(
      LayoutEngine.renderPlan(page, const <String>[]).map((b) => b.type),
      <String>['search_bar', 'quick_actions'],
      reason: 'nearby_service needs the location capability',
    );
    expect(
      LayoutEngine.renderPlan(page, const <String>['location']).length,
      3,
    );
  });

  test('a page of nothing renderable yields an empty plan, not a crash', () {
    final plan = LayoutEngine.renderPlan(
      pageOf(['nope', 'nada']),
      const <String>['location'],
    );
    expect(plan, isEmpty);
    expect(LayoutEngine.renderPlan(pageOf(<String>[]), const <String>[]), isEmpty);
  });

  test('v2 catalog is additive for delivered layouts', () {
    // Every type any shipped fixture uses must still be v1-known, so an app
    // that never upgrades its shell keeps rendering the same page.
    final gov = layoutFrom(fixture('app_layout_gov.json'));
    final ent = layoutFrom(fixture('app_layout_ent.json'));
    final shipped = <String>{
      for (final page in [...gov.pages.values, ...ent.pages.values])
        for (final block in page.blocks) block.type,
    };
    expect(shipped.difference(ComponentCatalog.v1Types.toSet()), isEmpty,
        reason: 'fixtures may not pre-use v2-only types');
  });
}
