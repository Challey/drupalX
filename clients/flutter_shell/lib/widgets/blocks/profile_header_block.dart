import 'package:flutter/material.dart';
import 'package:dx_flutter_shell/layout/app_layout.dart';

class ProfileHeaderBlock extends StatelessWidget {
  const ProfileHeaderBlock({
    super.key,
    required this.site,
    required this.theme,
  });

  final Map<String, dynamic> site;
  final LayoutTheme theme;

  @override
  Widget build(BuildContext context) {
    final org = site['org_profile'] as Map<String, dynamic>? ?? {};
    final title = org['title']?.toString() ?? theme.displayName;
    return ListTile(
      contentPadding: EdgeInsets.zero,
      leading: CircleAvatar(
        backgroundColor: Theme.of(context).colorScheme.primary,
        child: Text(
          title.isNotEmpty ? title.substring(0, 1) : 'D',
          style: const TextStyle(color: Colors.white),
        ),
      ),
      title: Text(title),
      subtitle: Text(org['org_type']?.toString() ?? 'portal'),
    );
  }
}
