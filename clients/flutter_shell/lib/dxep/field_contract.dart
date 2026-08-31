/// Generated mirror of `clients/field-contract.json` (DX-FIELD-CONTRACT).
///
/// Keys are canonical wire paths (`<resource>.<dotted.path>`, `[]` for
/// list elements, `*` for dynamic map keys); values are contract types.
/// This is the copy the shell can consult at runtime; the JSON stays the
/// source of truth. Both directions are gated:
///   * flutter test test/field_contract_test.dart
///   * python3 tools/clients/isomorph_check.py fields
/// Regenerate with:
///   python3 tools/clients/isomorph_check.py mirror --write
library;

const int dxepFieldContractSchemaVersion = 1;
const String dxepFieldContractSpec = 'DX-FIELD-CONTRACT';

const Map<String, String> dxepFieldContract = <String, String>{
  'envelope.ok': 'boolean',
  'envelope.api_version': 'string',
  'envelope.request_id': 'string',
  'envelope.tenant_id': 'string',
  'envelope.data': 'opaque',
  'envelope.meta': 'map',
  'envelope.meta.page': 'integer',
  'envelope.meta.page_size': 'integer',
  'envelope.meta.total': 'integer',
  'envelope.meta.revision': 'integer',
  'envelope.error': 'map',
  'envelope.error.code': 'string',
  'envelope.error.message': 'string',
  'envelope.error.details': 'list',
  'site.org_profile': 'map',
  'site.org_profile.id': 'string',
  'site.org_profile.type': 'string',
  'site.org_profile.title': 'string',
  'site.org_profile.org_type': 'string',
  'site.org_profile.contact': 'map',
  'site.org_profile.contact.website': 'nullable_string',
  'site.org_profile.brand': 'map',
  'site.org_profile.brand.display_name': 'string',
  'site.org_profile.brand.logo_url': 'nullable_string',
  'site.org_profile.brand.theme_pack': 'string',
  'site.theme': 'map',
  'site.theme.pack': 'string',
  'site.theme.label': 'string',
  'site.theme.primary': 'string',
  'site.layout': 'map',
  'site.layout.revision': 'integer',
  'site.layout.min_shell_version': 'string',
  'site.layout.checksum': 'nullable_string',
  'site.capabilities': 'list',
  'site.channels': 'list',
  'layout.spec': 'string',
  'layout.spec_version': 'string',
  'layout.tenant_id': 'string',
  'layout.checksum': 'string',
  'layout.layout_id': 'string',
  'layout.revision': 'integer',
  'layout.min_shell_version': 'string',
  'layout.capabilities': 'list',
  'layout.theme': 'map',
  'layout.theme.pack': 'string',
  'layout.theme.display_name': 'string',
  'layout.theme.primary': 'string',
  'layout.navigation': 'map',
  'layout.navigation.type': 'string',
  'layout.navigation.items': 'list',
  'layout.navigation.items[].id': 'string',
  'layout.navigation.items[].label': 'string',
  'layout.navigation.items[].icon': 'string',
  'layout.navigation.items[].page': 'string',
  'layout.pages': 'map',
  'layout.pages.*.blocks': 'list',
  'layout.pages.*.blocks[].type': 'string',
  'layout.pages.*.blocks[].props': 'map',
  'layout.routes': 'map',
  'layout.routes.*.type': 'string',
  'layout.routes.*.id_param': 'string',
  'content.id': 'string',
  'content.type': 'string',
  'content.status': 'string',
  'content.visibility': 'string',
  'content.title': 'string',
  'content.summary': 'nullable_string',
  'content.locale': 'string',
  'content.channel': 'list',
  'content.published_at': 'rfc3339',
  'content.updated_at': 'rfc3339',
  'content.created_at': 'rfc3339',
  'content.external_id': 'nullable_string',
  'content.sku': 'nullable_string',
  'content.price': 'map',
  'content.price.amount': 'string',
  'content.price.currency': 'string',
  'content.body': 'map',
  'content.body.format': 'string',
  'content.body.html': 'string',
  'content.body.text': 'string',
};

/// Contract type of a canonical path, or null when it is undeclared.
String? dxepFieldType(String path) => dxepFieldContract[path];

/// Whether a path exists in the frozen cross-end contract.
bool dxepFieldExists(String path) => dxepFieldContract.containsKey(path);
