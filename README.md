# Kina 聚合登录

> WordPress 聚合登录插件，支持 QQ、微信、支付宝、GitHub 扫码登录，自动创建/绑定账号，免密一键登录。

[![Version](https://img.shields.io/badge/version-3.0.0-blue)](https://github.com/muyi3919/kina-social-login)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 功能特性

- **聚合登录**：QQ、微信、支付宝、GitHub 四平台统一接入（uniqueker.top 接口）
- **扫码免密**：首次扫码可选择「创建新账号」或「绑定已有账号」，二次扫码直接登录
- **自动注册**：支持首次登录自动创建 WordPress 用户，后台可开关
- **后台绑定**：用户可在个人资料页随时绑定/解绑社交账号
- **数据隔离**：v3.0 起使用全新数据表 `wp_kina_social_accounts`，与旧版完全隔离
- **Font Awesome 图标**：登录按钮和后台界面统一使用品牌图标
- **弹窗交互**：登录/绑定采用弹出窗口，体验流畅

---

## 安装

### 方法一：直接上传

1. 下载 `kina-social-login.php`
2. 上传到 `/wp-content/plugins/` 目录
3. 在 WordPress 后台 **插件** 中激活「Kina 社交登录」
4. 进入 **设置 → Kina 社交登录**，填写 AppID 和 AppKey

### 方法二：Git Clone

```bash
cd /path/to/wp-content/plugins/
git clone https://github.com/muyi3919/kina-social-login.git
```

然后在后台激活插件。

---

## 配置

在 **设置 → Kina 社交登录** 页面配置：

| 配置项 | 说明 |
|--------|------|
| AppID | 从 uniqueker.top 获取的 AppID |
| AppKey | 从 uniqueker.top 获取的 AppKey |
| 自动注册 | 勾选后首次扫码可直接创建新账号；关闭则只能绑定已有账号 |

---

## 使用

### 前台登录

安装并配置完成后，WordPress 登录页面会自动显示四个社交登录按钮：

- QQ 登录（蓝色 `#12B7F5`）
- 微信登录（绿色 `#07C160`）
- 支付宝登录（蓝色 `#1677FF`）
- GitHub 登录（黑色 `#24292F`）

点击按钮后弹出扫码/授权窗口，授权完成后：

- **已绑定用户**：自动登录，窗口关闭
- **首次登录**：弹窗显示两个选项卡
  - **创建新账号**：填写用户名（默认填充社交昵称）和邮箱，一键创建并登录
  - **绑定已有账号**：输入已有 WordPress 账号的账密，绑定后登录

### 后台绑定

已登录用户可在 **用户 → 个人资料 → 社交账号绑定** 区域：

- 查看当前已绑定的社交账号
- 点击「绑定」跳转授权，绑定新社交账号
- 点击「解绑」解除已有绑定

---

## 数据表结构

v3.0 起使用全新表 `wp_kina_social_accounts`：

```sql
CREATE TABLE wp_kina_social_accounts (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    user_id bigint(20) unsigned NOT NULL,
    social_type varchar(20) NOT NULL,
    social_uid varchar(100) NOT NULL,
    access_token varchar(255) DEFAULT NULL,
    nickname varchar(100) DEFAULT NULL,
    faceimg varchar(500) DEFAULT NULL,
    bind_time datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY unique_social (social_type, social_uid),
    KEY user_id (user_id)
);
```

---

## 更新日志

| 版本  | 更新内容 |
|------|---------|
| v3.0.0  | 全新数据表 `wp_kina_social_accounts`，与旧版完全隔离；首次扫码登录支持「创建新账号」和「绑定已有账号」双选项卡；新增自动注册开关；修复旧版字段名 `type` → `social_type` 导致的写入失败问题 |
| v2.0.3  | 修复：增加数据库写入错误检测，暴露字段名不匹配问题 |
| v2.0.2  | 修复：`form.action` 被 `<input name="action">` 覆盖，改为 `form.getAttribute('action')` |
| v2.0.1  | 修复：`template_redirect` 被主题拦截导致回调 404，改用 `init` hook |
| v2.0.0 | 大版本更新：前台扫码未绑定可直接关联已有账号（输入用户名密码绑定）；图标全部换成 Font Awesome 6 |
| v1.0.5  | 修复：登录按钮改用 JS 动态创建表单，解决表单嵌套问题；`form.action` 改为 `form.getAttribute('action')` |
| v1.0.4  | 修复：CSS 选择器加 `#login` / `.login` 前缀；新增 `check_admin_callback()` 处理后台绑定回调 |
| v1.0.3  | 修复：删除不存在的 `handle_bind_callback()` 方法，绑定回调统一走 `handle_callback()` |
| v1.0.2  | 修复：POST 参数 `type` 通过 hidden input 传递；CSS 加 `!important` 覆盖主题样式 |
| v1.0.1  | 修复：登录按钮直接指向 API 导致显示 JSON，改为后端请求再跳转 |
| v1.0.0 | 初始版本，支持 QQ/微信/支付宝/GitHub 聚合登录，后台绑定，前台扫码登录 |

---

## 作者

**kina漫记** · [kina.ink](https://kina.ink)

---

## License

GPL-2.0+
