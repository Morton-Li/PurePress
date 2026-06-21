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

use PurePress\Enhancement\MediaFoldersModule;
use PurePress\Enhancement\SmtpModule;
use PurePress\Governance\LoginAddressModule;
use PurePress\Governance\RestApiModule;
use PurePress\Governance\WordPressFingerprintModule;
use PurePress\Governance\XmlRpcModule;
use PurePress\Integration\S3MediaModule;

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
                '启用资源路径隐藏后，PurePress 会将前台核心资源输出为 /core/...，主题资源输出为 /themes/...。为避免这些公开路径进入 PHP，建议在 Nginx server 块中增加静态重写规则。',
                <<<'NGINX'
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
