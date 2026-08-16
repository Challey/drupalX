App({
  globalData: {
    apiBase: '',
    token: '',
    useFixtures: true,
    layout: null,
    site: null
  },
  onLaunch() {
    try {
      const cfg = require('./config.js');
      this.globalData.apiBase = (cfg.apiBase || '').replace(/\/+$/, '');
      this.globalData.token = cfg.token || '';
      this.globalData.useFixtures = !!cfg.useFixtures;
    } catch (e) {
      this.globalData.useFixtures = true;
    }
  }
});
