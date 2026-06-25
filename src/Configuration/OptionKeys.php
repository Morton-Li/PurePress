<?php
/**
 * PurePress 配置键名生成器。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

final class OptionKeys
{
    /**
     * PurePress 在 WordPress options 表中的键名前缀。
     */
    public const PREFIX = 'purepress_';

    /**
     * 获取版本记录键名。
     */
    public static function version(): string
    {
        return self::PREFIX . 'version';
    }

    /**
     * 获取 GeoIP 数据库最后更新时间键名。
     */
    public static function geoIpDatabaseUpdatedAt(): string
    {
        return self::PREFIX . 'geoip_database_updated_at';
    }

    /**
     * 获取模块配置键名。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     */
    public static function module(string $moduleId): string
    {
        return self::PREFIX . 'module_' . self::normalize($moduleId);
    }

    /**
     * 获取当前版本定义的全部配置键名。
     *
     * @return list<string>
     */
    public static function defined(): array
    {
        $keys = [
            self::version(),
            self::geoIpDatabaseUpdatedAt(),
        ];

        foreach (ModuleCatalog::definitions() as $definition) {
            $keys[] = self::module($definition->id());
        }

        return $keys;
    }

    /**
     * 将模块 ID 转换为稳定的 option key 片段。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     */
    private static function normalize(string $moduleId): string
    {
        $normalized = strtolower($moduleId);
        $normalized = str_replace('.', '_', $normalized);

        return (string) preg_replace('/[^a-z0-9_]/', '_', $normalized);
    }
}
