import 'dart:convert';
import 'dart:io';

import 'package:dx_flutter_shell/layout/app_layout.dart';
import 'package:dx_flutter_shell/layout/block_registry.dart';
import 'package:dx_flutter_shell/layout/component_catalog.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Catalog v2 contract tests (L3 Phase H2).
///
/// Everything here runs offline with `flutter test` only — no network, no
/// Drupal, no device:
///
///   cd clients/flutter_shell && flutter test test/component_catalog_test.dart
void main() {
  Map<String, dynamic> loadCatalog() {
    final file = File('assets/config/component_catalog.json');
    expect(file.existsSync(), isTrue,
        reason: 'the catalogue JSON is the single source of truth');
    return jsonDecode(file.readAsStringSync()) as Map<String, dynamic>;
  }

  List<Map<String, dynamic>> jsonComponents(Map<String, dynamic> catalog) {
    return (catalog['components'] as List<dynamic>)
        .cast<Map<String, dynamic>>();
  }

  test('catalogue schema version is 2 and mirrored by the registry', () {
    final catalog = loadCatalog();
    expect(catalog['spec'], 'DX-COMPONENT-CATALOG');
    expect(catalog['schema_version'], 2);
    expect(ComponentCatalog.schemaVersion, catalog['schema_version']);
    expect(ComponentCatalog.spec, catalog['spec']);
    expect(BlockRegistry.catalogSchemaVersion, 2);
  });

  test('Dart mirror and catalogue JSON describe exactly the same components', () {
    final entries = jsonComponents(loadCatalog());
    final fromJson = <String, String>{};
    for (final entry in entries) {
      final type = entry['type'] as String;
      expect(fromJson.containsKey(type), isFalse, reason: 'duplicate $type');
      fromJson[type] = '${entry['widget']}@${entry['file']}';
    }
    final fromDart = <String, String>{
      for (final spec in ComponentCatalog.entries)
        spec.type: '${spec.widget}@${spec.file}',
    };
    expect(fromDart, fromJson);
  });

  test('v1 frozen types are additive only — never removed or re-versioned', () {
    final entries = jsonComponents(loadCatalog());
    final sinceByType = <String, int>{
      for (final entry in entries)
        entry['type'] as String: entry['since'] as int,
    };
    for (final type in ComponentCatalog.v1Types) {
      expect(sinceByType[type], 1, reason: '$type shipped in v1 apps');
      expect(ComponentCatalog.isKnown(type), isTrue);
    }
    expect(BlockRegistry.known, containsAll(ComponentCatalog.v1Types));
    expect(ComponentCatalog.v2Types.length, 6);
  });

  test('every component file exists and declares its widget class', () {
    for (final spec in ComponentCatalog.entries) {
      final file = File(spec.file);
      expect(file.existsSync(), isTrue,
          reason: 'catalogue references ${spec.file}');
      final source = file.readAsStringSync();
      expect(source, contains('class ${spec.widget} extends'),
          reason: '${spec.type} must map to ${spec.widget}');
    }
  });

  test('every widget file under lib/widgets/blocks is catalogued', () {
    final catalogued = ComponentCatalog.entries.map((e) => e.file).toSet();
    final present = Directory('lib/widgets/blocks')
        .listSync()
        .whereType<File>()
        .map((f) => f.path.replaceAll(r'\', '/'))
        .toSet();
    expect(present.difference(catalogued), isEmpty,
        reason: 'orphan widget file — add it to the catalogue or delete it');
    for (final missing in catalogued.difference(present)) {
      expect(File(missing).existsSync(), isTrue, reason: '$missing is listed');
    }
  });

  test('widgets only read props their catalogue entry declares', () {
    final entries = jsonComponents(loadCatalog());
    // A widget file can serve several types (content/rich_html, empty/error):
    // the allowed set is the union over the file.
    final allowedByFile = <String, Set<String>>{};
    for (final entry in entries) {
      final file = entry['file'] as String;
      final props = (entry['props'] as List<dynamic>? ?? [])
          .cast<Map<String, dynamic>>()
          .map((p) => p['name'] as String);
      allowedByFile.putIfAbsent(file, () => <String>{}).addAll(props);
    }
    for (final entry in entries) {
      final file = File(entry['file'] as String);
      final read = RegExp(r"props\['([a-z_]+)'\]")
          .allMatches(file.readAsStringSync())
          .map((m) => m.group(1)!)
          .toSet();
      expect(
        read.difference(allowedByFile[entry['file']] ?? <String>{}),
        isEmpty,
        reason: '${entry['type']} reads undeclared props',
      );
    }
  });

  testWidgets('every catalogued type dispatches to a widget', (tester) async {
    late BuildContext context;
    await tester.pumpWidget(Builder(builder: (ctx) {
      context = ctx;
      return const SizedBox();
    }));
    final allCapabilities = <String>['location', 'microphone', 'photo_upload', 'share'];
    for (final type in ComponentCatalog.entries.map((e) => e.type)) {
      final widget = BlockRegistry.build(
        context,
        LayoutBlock(type: type, props: const <String, dynamic>{}),
        site: const <String, dynamic>{},
        theme: LayoutTheme.fromJson(const <String, dynamic>{}),
        capabilities: allCapabilities,
      );
      expect(widget, isNotNull, reason: '$type must render');
    }
  });

  testWidgets('unknown and capability-gated types stay skipped', (tester) async {
    late BuildContext context;
    await tester.pumpWidget(Builder(builder: (ctx) {
      context = ctx;
      return const SizedBox();
    }));
    Widget? build(String type, List<String> caps) => BlockRegistry.build(
          context,
          LayoutBlock(type: type, props: const <String, dynamic>{}),
          site: const <String, dynamic>{},
          theme: LayoutTheme.fromJson(const <String, dynamic>{}),
          capabilities: caps,
        );
    expect(build('live_map', const <String>[]), isNull,
        reason: 'closed catalogue: unknown types are never rendered');
    expect(build('nearby_service', const <String>[]), isNull);
    expect(build('nearby_service', const <String>['location']), isNotNull);
    expect(build('hero_banner', const <String>[]), isNotNull,
        reason: 'v1 types must not need a capability');
  });

  test('capability tokens match the Android shell manifest vocabulary', () {
    const shipped = <String>['location', 'microphone', 'photo_upload', 'share'];
    for (final spec in ComponentCatalog.entries) {
      final cap = spec.capability;
      if (cap != null) {
        expect(shipped, contains(cap),
            reason: '${spec.type} gates on an unknown capability');
      }
    }
  });
}
