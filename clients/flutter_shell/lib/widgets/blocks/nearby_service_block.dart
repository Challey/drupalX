import 'package:flutter/material.dart';

/// `nearby_service` (catalog v2) — location-gated "services near me" block.
///
/// Capability-gated by the catalogue (`capability: location`): the layout
/// engine skips this block unless the L1 document declares `location`, which
/// mirrors the Android shell 1.3.0 manifest switch (`capabilities: [location]`)
/// and `CarHailingNative.hasCapability('location')`. No geolocation API is
/// called from the shell itself — positioning stays in the H5 / native layer.
class NearbyServiceBlock extends StatelessWidget {
  const NearbyServiceBlock({super.key, required this.props, this.hasFix = false});

  final Map<String, dynamic> props;

  /// Set when the host already resolved a location (drives the copy only).
  final bool hasFix;

  @override
  Widget build(BuildContext context) {
    final title = props['title']?.toString() ?? '附近服务';
    final radius = int.tryParse('${props['radius_km'] ?? 5}') ?? 5;
    final query =
        props['query'] is Map<String, dynamic> ? props['query'] as Map<String, dynamic> : const <String, dynamic>{};
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Icon(Icons.near_me, size: 18, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: 6),
            Text('$title · ${radius}km',
                style: Theme.of(context).textTheme.titleMedium),
          ],
        ),
        const SizedBox(height: 8),
        Text(
          hasFix
              ? '按距离排序的服务入口（type=${query['type'] ?? 'service_entry'}）'
              : '开启定位后按距离排序；当前列表按默认顺序展示。',
          style: Theme.of(context).textTheme.bodySmall,
        ),
      ],
    );
  }
}
