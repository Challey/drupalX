const known = {
  hero_banner: true,
  notice_ticker: true,
  article_list: true,
  notice_list: true,
  product_grid: true,
  service_grid: true,
  profile_header: true,
  rich_html: true,
  web_link: true,
  empty: true,
  error: true
};

function parseLayout(raw) {
  const data = typeof raw === 'string' ? JSON.parse(raw) : raw;
  const layout = data.data && data.spec !== 'DX-APP-LAYOUT' ? data.data : data;
  return layout;
}

function pageBlocks(layout, pageId) {
  const page = (layout.pages || {})[pageId] || { blocks: [] };
  return (page.blocks || []).filter((b) => known[b.type]);
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
  known,
  parseLayout,
  pageBlocks,
  requestChannel
};
