const dxep = require('../../utils/dxep.js');
const govLayout = require('../../fixtures/app_layout_gov.js');
const siteFixture = require('../../fixtures/site.js');

Page({
  data: {
    title: 'DrupalX',
    tabs: [],
    tabIndex: 0,
    blocks: [],
    error: ''
  },
  onLoad() {
    this.bootstrap();
  },
  async bootstrap() {
    const app = getApp();
    try {
      const siteEnv = await dxep.requestChannel('/api/dx/v1/channel/site', {
        apiBase: app.globalData.apiBase,
        token: app.globalData.token,
        useFixtures: app.globalData.useFixtures,
        fixture: siteFixture
      });
      const layoutEnv = await dxep.requestChannel('/api/dx/v1/channel/app-layout', {
        apiBase: app.globalData.apiBase,
        token: app.globalData.token,
        useFixtures: app.globalData.useFixtures,
        fixture: govLayout
      });
      const site = siteEnv.data || siteEnv;
      const layout = dxep.parseLayout(layoutEnv);
      app.globalData.site = site;
      app.globalData.layout = layout;
      const tabs = (layout.navigation && layout.navigation.items) || [];
      const pageId = tabs[0] ? tabs[0].page : 'page_home';
      this.setData({
        title: (layout.theme && layout.theme.display_name) || 'DrupalX',
        tabs,
        tabIndex: 0,
        blocks: dxep.pageBlocks(layout, pageId),
        error: ''
      });
    } catch (e) {
      this.setData({ error: String(e.message || e) });
    }
  },
  onTab(e) {
    const index = Number(e.currentTarget.dataset.index || 0);
    const app = getApp();
    const layout = app.globalData.layout;
    if (!layout) return;
    const tabs = this.data.tabs;
    const pageId = tabs[index] ? tabs[index].page : 'page_home';
    this.setData({
      tabIndex: index,
      blocks: dxep.pageBlocks(layout, pageId)
    });
  }
});
