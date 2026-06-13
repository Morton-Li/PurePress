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

use PurePress\Governance\RestApiModule;
use PurePress\Governance\WordPressFingerprintModule;
use PurePress\Governance\XmlRpcModule;

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
                '限制未登录用户使用REST API接口',
                'Governance',
                '限制未登录用户访问 REST API，不影响默认文章评论。',
                RestApiModule::class
            ),
            new ModuleDefinition(
                'governance.xml_rpc',
                '关闭XML-RPC功能',
                'Governance',
                '关闭 XML-RPC 功能，不影响默认文章评论。',
                XmlRpcModule::class
            ),
            new ModuleDefinition(
                'governance.wordpress_fingerprint',
                '隐藏WordPress特征',
                'Governance',
                '隐藏常见 WordPress 识别特征，降低自动化信息收集暴露面。',
                WordPressFingerprintModule::class
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
