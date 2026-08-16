import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';

class HeroBannerBlock extends StatelessWidget {
  const HeroBannerBlock({
    super.key,
    required this.props,
    required this.site,
    required this.theme,
  });

  final Map<String, dynamic> props;
  final Map<String, dynamic> site;
  final LayoutTheme theme;

  @override
  Widget build(BuildContext context) {
    final org = site['org_profile'] as Map<String, dynamic>? ?? {};
    final brand = org['brand'] as Map<String, dynamic>? ?? {};
    final name = brand['display_name']?.toString() ??
        theme.displayName;
    final primary = Theme.of(context).colorScheme.primary;
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(20, 28, 20, 28),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: [primary, primary.withOpacity(0.75)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            name,
            style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                ),
          ),
          const SizedBox(height: 8),
          Text(
            '官方信息与服务',
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                  color: Colors.white.withOpacity(0.9),
                ),
          ),
        ],
      ),
    );
  }
}
