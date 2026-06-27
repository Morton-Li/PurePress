<?php
/**
 * PurePress 模块目录。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

use PurePress\Enhancement\GuestCommentsModule;
use PurePress\Enhancement\MediaFoldersModule;
use PurePress\Enhancement\SmtpModule;
use PurePress\Governance\LoginAddressModule;
use PurePress\Governance\LoginAuditModule;
use PurePress\Governance\RegistrationEmailVerificationModule;
use PurePress\Governance\RegistrationRateLimitModule;
use PurePress\Governance\RestApiModule;
use PurePress\Governance\WordPressFingerprintModule;
use PurePress\Governance\XmlRpcModule;
use PurePress\Integration\S3MediaModule;
use PurePress\Optimization\PageCacheModule;

final class ModuleCatalog
{
    /**
     * 获取可配置模块定义。
     *
     * @return list<ModuleDefinition>
     */
    public static function definitions(): array
    {
        return [
            new ModuleDefinition(
                'governance.rest_api',
                'REST API 控制',
                'Governance',
                '限制未登录用户访问 REST API。',
                RestApiModule::class
            ),
            new ModuleDefinition(
                'governance.xml_rpc',
                'XML-RPC 控制',
                'Governance',
                '关闭 XML-RPC 功能。',
                XmlRpcModule::class
            ),
            new ModuleDefinition(
                'governance.wordpress_fingerprint',
                '隐藏 WordPress 特征',
                'Governance',
                '隐藏常见 WordPress 识别特征，降低自动化信息收集暴露面。',
                WordPressFingerprintModule::class,
                '启用后，PurePress 会将前台核心资源输出为 /core/...，主题资源输出为 /themes/...，将后台地址输出为 /console/...，并建议在 Nginx server 块中屏蔽 /wp-admin 直访、补充公开路径重写规则。',
                <<<'NGINX'
if ($request_uri ~* "^/wp-admin(?:/|\?|$)") {
    return 404;
}

location = /console {
    return 301 /console/;
}

location ^~ /console/ {
    rewrite ^/console/(.*)$ /wp-admin/$1 last;
}

location ~ ^/core/(.+)$ {
    try_files /wp-includes/$1 =404;
}

location ~ ^/themes/(.+)$ {
    try_files /wp-content/themes/$1 =404;
}
NGINX
            ),
            new ModuleDefinition(
                'governance.login_address',
                '登录入口控制',
                'Governance',
                '管理 WordPress 默认登录与注册地址。',
                LoginAddressModule::class
            ),
            new ModuleDefinition(
                'governance.login_audit',
                '登录审计',
                'Governance',
                '记录用户最后一次成功登录的时间、IP 与归属地。',
                LoginAuditModule::class,
                'GeoIP 数据库默认不随插件打包。启用后可在本模块中手动更新数据库。'
            ),
            new ModuleDefinition(
                'governance.registration_rate_limit',
                '注册频率限制',
                'Governance',
                '限制注册请求触发邮件的频率。',
                RegistrationRateLimitModule::class
            ),
            new ModuleDefinition(
                'governance.registration_email_verification',
                '注册邮箱验证',
                'Governance',
                '先验证注册邮箱，再创建 WordPress 用户。',
                RegistrationEmailVerificationModule::class,
                'WordPress 原生注册会先创建用户并发送设置密码链接。PurePress 改为先写入待验证记录，邮箱验证后再创建用户，减少注册机占用用户表与邮箱。'
            ),
            new ModuleDefinition(
                'optimization.page_cache',
                '页面缓存',
                'Optimization',
                '为匿名访客缓存前台 HTML 页面。',
                PageCacheModule::class,
                '默认使用 PHP 输出缓存，不修改 wp-config.php，也不写入服务器重写规则。下方为最小 Nginx 读取示例；未配置 Nginx 时，PHP 缓存仍然有效。',
                <<<'NGINX'
set $page_cache_file /__page_cache_disabled__;

if ($request_method = GET) {
    set $page_cache_file /wp-content/purepress/cache/page/$scheme/$host$uri/index.html;
}

if ($query_string != "") {
    set $page_cache_file /__page_cache_disabled__;
}

if ($http_cache_control ~* "no-cache|no-store") {
    set $page_cache_file /__page_cache_disabled__;
}

if ($http_pragma ~* "no-cache") {
    set $page_cache_file /__page_cache_disabled__;
}

if ($http_cookie ~* "(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_)") {
    set $page_cache_file /__page_cache_disabled__;
}

location / {
    try_files $page_cache_file $uri $uri/ /index.php?$args;
}
NGINX
            ),
            new ModuleDefinition(
                'enhancement.guest_comments',
                '免登录文章回复',
                'Enhancement',
                '允许未登录访客提交文章评论。',
                GuestCommentsModule::class,
                '启用后，访客无需登录即可使用文章评论表单；评论仍遵循 WordPress 原生评论开关、审核、姓名邮箱必填、评论 Cookie 与重复评论检查。'
            ),
            new ModuleDefinition(
                'enhancement.smtp',
                'SMTP 发信',
                'Enhancement',
                '使用 SMTP 接管 WordPress 默认邮件发送。',
                SmtpModule::class
            ),
            new ModuleDefinition(
                'enhancement.media_folders',
                '媒体库目录',
                'Enhancement',
                '在媒体库中提供目录化管理，并让对象存储路径跟随目录结构。',
                MediaFoldersModule::class
            ),
            new ModuleDefinition(
                'integration.s3_media',
                'S3 兼容对象存储',
                'Integration',
                '使用 S3 兼容对象存储接管 WordPress 媒体文件。',
                S3MediaModule::class
            ),
        ];
    }

    /**
     * 获取可配置模块 ID 列表。
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(
            static fn (ModuleDefinition $definition): string => $definition->id(),
            self::definitions()
        );
    }
}
