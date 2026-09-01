// Closed component catalog mirror of
// clients/flutter_shell/assets/config/component_catalog.json (v2).
// Kept as a literal (mini programs cannot read the Flutter asset bundle);
// scripts/ci/clients-isomorph-smoke.sh fails when the two sets drift.
const catalogSchemaVersion = 2;

const known = {
  hero_banner: true,
  notice_ticker: true,
  article_list: true,
  notice_list: true,
  product_grid: true,
  service_grid: true,
  profile_header: true,
  rich_html: true,
  content: true,
  web_link: true,
  empty: true,
  error: true,
  article_detail: true,
  notice_detail: true,
  product_detail: true,
  search_bar: true,
  quick_actions: true,
  nearby_service: true
};

// Blocks that need an L1 capability token before they may render; same names
// as the Android shell manifest capabilities (shell 1.3.0).
const capabilityByType = {
  nearby_service: 'location'
};

function parseLayout(raw) {
  const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
  const layout = data.data && data.spec !== 'DX-APP-LAYOUT' ? data.data : data;
  return layout;
}

function pageBlocks(layout, pageId) {
  const page = (layout.pages || {})[pageId] || { blocks: [] };
  const caps = (layout && layout.capabilities) || [];
  return (page.blocks || []).filter((b) => {
    if (!known[b.type]) {
      return false;
    }
    const need = capabilityByType[b.type];
    return !need || caps.indexOf(need) !== -1;
  });
}

function requestChannel(path, { apiBase, token, useFixtures, fixture }) {
  if (useFixtures) {
    return Promise.resolve(fixture);
  }
  return new Promise((resolve, reject) => {
    wx.request({
      url: apiBase + path,
      method: 'GET',
      header: {
        Authorization: 'Bearer ' + token,
        Accept: 'application/json'
      },
      success(res) {
        if (res.statusCode === 304) {
          resolve(null);
          return;
        }
        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(res.data);
          return;
        }
        reject(new Error('HTTP ' + res.statusCode));
      },
      fail: reject
    });
  });
}

module.exports = {
  catalogSchemaVersion,
  capabilityByType,
  known,
  parseLayout,
  pageBlocks,
  requestChannel
};
