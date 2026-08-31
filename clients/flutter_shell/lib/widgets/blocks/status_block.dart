import 'package:flutter/material.dart';

/// `empty` / `error` — placeholder blocks of the closed catalogue.
///
/// v1 returned `SizedBox.shrink()` from the registry; v2 keeps that default
/// (so delivered layouts are pixel-identical) but renders `props.text` when a
/// tenant supplies copy, which is what the mini-program already does page-side.
class StatusBlock extends StatelessWidget {
  const StatusBlock({super.key, required this.props, this.isError = false});

  final Map<String, dynamic> props;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final text = props['text']?.toString() ?? '';
    if (text.isEmpty) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Text(
        text,
        style: TextStyle(
          color: isError
              ? Theme.of(context).colorScheme.error
              : Theme.of(context).colorScheme.outline,
        ),
      ),
    );
  }
}
