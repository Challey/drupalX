import 'package:flutter/material.dart';

/// `rich_html` / `content` — server-sanitised body, rendered as plain text.
///
/// v1 kept this inline in the registry; v2 promotes it to a file so every
/// catalogued type maps 1:1 onto a widget (CI: `isomorph_check.py catalog`).
/// Remote HTML is never executed (F2-A): tags are stripped here as a second
/// line of defence, sanitising stays server-side. `content` is the Channel
/// alias and reads `props.body`, `rich_html` reads `props.html`.
class RichHtmlBlock extends StatelessWidget {
  const RichHtmlBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final raw = (props['html'] ?? props['body'] ?? '').toString();
    final text = raw.replaceAll(RegExp(r'<[^>]*>'), ' ').replaceAll(RegExp(r'\s+'), ' ').trim();
    if (text.isEmpty) {
      return const SizedBox.shrink();
    }
    return SelectableText(text);
  }
}
