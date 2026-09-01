import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// `web_link` — opens the target in the system browser (whitelisted domain).
///
/// Promoted out of the registry in catalog v2; behaviour is unchanged: the
/// shell never navigates the in-app WebView to an external host.
class WebLinkBlock extends StatelessWidget {
  const WebLinkBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final title = props['title']?.toString() ?? '外链';
    final url = props['url']?.toString() ?? '';
    return ListTile(
      contentPadding: EdgeInsets.zero,
      title: Text(title),
      subtitle: Text(url),
      trailing: const Icon(Icons.open_in_new),
      onTap: url.isEmpty ? null : () => _open(url),
    );
  }

  Future<void> _open(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || !uri.hasScheme) {
      return;
    }
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }
}
