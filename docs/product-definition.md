# PurePress 产品定义文档

## 项目名称

PurePress

## 项目定位

PurePress 是一个面向 WordPress 的综合优化与治理插件。

目标是对 WordPress 进行统一治理、能力增强和体验优化，使其成为一个更现代、更高效、更适合长期内容运营的 CMS。

PurePress 不追求让 WordPress 变得更小，而是让 WordPress 变得更合理。

## 项目理念

WordPress 最大的问题并不在于性能。

经过长期发展，WordPress 已经成为一个通用内容平台，同时兼顾博客、企业官网、电商、论坛、LMS、会员系统、API 平台等多种场景。

这种历史演进带来了大量：

- 历史兼容功能
- 后台噪音
- 远端依赖
- 重复能力
- 生态碎片化

与此同时，一些真正常用的基础能力却依赖多个第三方插件组合实现，例如：

- SMTP
- SEO
- OpenGraph
- Canonical
- Sitemap
- Security Headers
- CDN 集成
- 对象存储集成

PurePress 希望重新定义 WordPress 的默认体验。

## 核心目标

### 目标一：统一治理

统一管理：

- WordPress 默认行为
- 后台行为
- 前台行为
- 安全策略
- 邮件策略
- SEO 策略
- REST API 策略
- 媒体策略

避免多个插件分别接管同类能力。

### 目标二：减少插件依赖

尽量减少：

- SEO 插件
- SMTP 插件
- Head 清理插件
- Security Header 插件
- Dashboard 优化插件
- Heartbeat 插件
- Sitemap 插件
- CDN 集成插件
- 对象存储插件

通过 PurePress 提供统一实现。

### 目标三：降低远端噪音

重点治理：

- 更新检查
- 插件广告
- 插件遥测
- License 验证请求
- 推荐内容请求
- 后台无意义 API 请求

提升后台响应速度与专注度。

### 目标四：现代化基础能力

提供现代网站建设所需的基础设施支持：

- SMTP
- OpenGraph
- Canonical
- Sitemap
- Security Headers
- CDN 集成
- 对象存储集成
- 媒体访问策略
- 缓存策略

避免用户依赖多个低质量插件拼装。

### 目标五：保持 WordPress 兼容性

PurePress 必须：

- 不修改 WordPress Core
- 不 Fork WordPress
- 不侵入数据库结构
- 不改变内容模型
- 不影响主题生态兼容性

所有能力均通过插件层实现。

## 功能架构

### Governance（治理层）

负责系统治理与行为约束。

功能范围：

- Head 清理
- XML-RPC 管理
- REST API 管理
- Heartbeat 管理
- Dashboard 管理
- 后台通知管理
- 更新行为管理
- 远端请求治理
- 用户行为治理

### Optimization（优化层）

负责性能与体验优化。

功能范围：

- Block 资源治理
- 资源按需加载
- CSS / JS 精简
- 媒体加载优化
- Cron 优化
- 数据库维护
- 缓存 Header
- 浏览器缓存策略
- 图片策略优化

### Enhancement（增强层）

负责补充基础能力。

功能范围：

- SMTP
- SEO Meta
- OpenGraph
- Twitter Card
- Canonical
- Sitemap
- RSS 增强
- Robots 管理
- 安全 Header
- 登录保护
- 媒体管理增强

### Integration（集成层）

负责与外部基础设施对接。

#### CDN

支持：

- Cloudflare
- AWS CloudFront
- 腾讯云 CDN
- 阿里云 CDN
- 通用 CDN

能力：

- URL 重写
- 资源域名映射
- 缓存刷新
- 预热支持

#### 对象存储

支持：

- Amazon S3
- Cloudflare R2
- Backblaze B2
- 阿里云 OSS
- 腾讯云 COS
- MinIO
- S3 Compatible Storage

能力：

- 自动上传
- 自动替换 URL
- 本地保留策略
- 本地删除策略
- 回源策略

#### 邮件服务

支持：

- SMTP
- Amazon SES
- Resend
- Mailgun
- SendGrid
- Postmark

### Replacement（替换层）

替换 WordPress 默认弱实现。

功能范围：

- 邮件发送
- Sitemap 生成
- Meta 输出
- OpenGraph 输出
- Canonical 输出
- RSS 输出
- 安全 Header 输出

### Configuration（配置层）

统一管理所有功能开关。

原则：

- 所有功能默认可配置
- 所有模块独立启用
- 不存在强制绑定模块
- 不存在不可关闭功能

## 项目边界

### PurePress 应该做

系统治理：

- WordPress 行为治理
- 后台治理
- 前台治理

性能优化：

- 资源优化
- 媒体优化
- 缓存策略

基础能力：

- SMTP
- SEO
- Sitemap
- 安全策略

基础设施集成：

- CDN
- 对象存储
- 邮件服务

后台体验优化：

- Dashboard 优化
- 编辑体验优化
- 通知治理

### PurePress 不应该做

内容业务系统：

- 电商系统
- 会员系统
- LMS
- CRM
- ERP
- 工单系统

前端开发系统：

- 页面构建器
- 可视化主题编辑器
- Low-Code 系统

重型平台能力：

- CDN 服务本身
- 对象存储服务本身
- 统计分析平台
- WAF
- 身份认证平台

PurePress 可以集成这些服务，但不负责实现这些服务。

## 技术原则

### 插件优先

优先使用：

- Action
- Filter
- Hook
- Middleware 式治理

实现能力接管。

禁止修改 WordPress Core。

### 模块化

所有模块独立开发。

建议结构：

```text
PurePress
├── Governance
├── Optimization
├── Enhancement
├── Integration
├── Replacement
└── Configuration
```

### 最小侵入

优先：

- 接管
- 替换
- 约束

避免：

- 修改
- 覆盖
- Fork

### 长期兼容

设计必须兼容：

- WordPress 长期升级
- 主流主题
- 主流插件生态

避免形成新的技术孤岛。

## 产品愿景

> 一个更纯粹、更现代、更克制、更适合长期内容运营的 WordPress。

最终形成：

```text
WordPress Core
+
PurePress
+
优秀主题
```

即可满足个人博客、技术博客、品牌站点以及轻量内容平台的大部分需求，而无需依赖大量第三方插件。
