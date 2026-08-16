import 'package:flutter/material.dart';

class ServiceGridBlock extends StatelessWidget {
  const ServiceGridBlock({super.key, required this.props});

  final Map<String, dynamic> props;

  @override
  Widget build(BuildContext context) {
    final labels = ['办事指南', '预约服务', '信息公开', '互动交流'];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('服务入口', style: Theme.of(context).textTheme.titleMedium),
        const SizedBox(height: 8),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: labels
              .map(
                (label) => ActionChip(
                  label: Text(label),
                  onPressed: () {},
                ),
              )
              .toList(),
        ),
      ],
    );
  }
}
