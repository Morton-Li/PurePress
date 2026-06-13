# PurePress

PurePress 是一个面向 WordPress 的综合优化与治理插件。

目标是对 WordPress 进行统一治理、能力增强和体验优化，使其成为一个更现代、更高效、更适合长期内容运营的 CMS。

PurePress 不追求让 WordPress 变得更小，而是让 WordPress 变得更合理。

## 运行要求

- PHP 8.5+
- WordPress 插件运行环境

## 功能方向

PurePress 将围绕以下层次逐步建设：

- Governance：治理 WordPress 默认行为、后台行为、REST API、Heartbeat、通知、远端请求等。
- Optimization：优化资源加载、媒体加载、Cron、数据库维护、缓存 Header 等。
- Enhancement：提供 SMTP、SEO Meta、OpenGraph、Canonical、Sitemap、安全 Header 等基础能力。
- Integration：集成 CDN、对象存储、邮件服务等外部基础设施。
- Replacement：替换 WordPress 中较弱的默认实现。
- Configuration：统一管理所有功能开关，保证模块可独立启用和关闭。

## 已支持能力

| 能力              | 层级         | 说明                    |
|-----------------|------------|-----------------------|
| REST API 控制     | Governance | 限制未登录用户访问 REST API。   |
| XML-RPC 控制      | Governance | 关闭 XML-RPC 功能。        |
| 隐藏 WordPress 特征 | Governance | 隐藏前端 WordPress 常见识别特征。 |

## License

PurePress is licensed under GPL 3.0 only.

- SPDX identifier: `GPL-3.0-only`
- Full license text: [LICENSE](LICENSE)
- Official license page: https://www.gnu.org/licenses/gpl-3.0.html

Copyright (C) 2026 Morton Li.
