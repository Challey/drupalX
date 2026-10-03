# 教育档案「保存并继续」修复包 v1.0.8

路径约定：WSL 与生产均为 `/home/wwwroot/drupalX`

## 部署

```bash
cd /home/wwwroot/drupalX
git fetch origin
git reset --hard origin/master

# 确认
ls web/modules/custom/dx_edu_profile_fix/
ls web/modules/custom/dx_youth/templates/dx-youth-edu-profile-setup.html.twig

# 启用修复模块并清缓存
drush en dx_edu_profile_fix -y
drush cr
```

**重要：** 不要用 `rsync --delete` 同步整个 `dx_youth/`，以免删掉生产独有的 `src/Controller` 等 PHP 文件。

## 验证

登录后打开 https://www.drupal.org.cn/edu/profile/setup

- 顶部黄色条显示 **版本 v1.0.8**
- 点击「保存并继续」出现「正在保存…」
