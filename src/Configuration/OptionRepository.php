<?php
/**
 * 从 WordPress options 中读取和保存 PurePress 配置。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

final class OptionRepository
{
    /**
     * 获取完整配置，并与默认配置递归合并。
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $settings = [
            'modules' => [],
        ];

        foreach (ModuleCatalog::definitions() as $definition) {
            $settings['modules'][$definition->id()] = $this->moduleSettings($definition->id());
        }

        return $settings;
    }

    /**
     * 判断指定模块是否启用。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     */
    public function isModuleEnabled(string $moduleId): bool
    {
        return (bool) ($this->moduleSettings($moduleId)['enabled'] ?? false);
    }

    /**
     * 保存模块启用状态。
     *
     * @param list<string> $enabledModuleIds 用户提交的已启用模块 ID 列表。
     * @param list<string> $allowedModuleIds 允许保存的模块 ID 白名单。
     */
    public function saveModuleStates(array $enabledModuleIds, array $allowedModuleIds): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        $enabledLookup = array_fill_keys($enabledModuleIds, true);

        foreach ($allowedModuleIds as $moduleId) {
            $moduleSettings = $this->moduleSettings($moduleId);
            $moduleSettings['enabled'] = isset($enabledLookup[$moduleId]);

            update_option(OptionKeys::module($moduleId), $moduleSettings, false);
        }
    }

    /**
     * 写入插件版本记录。
     */
    public function saveVersion(): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(OptionKeys::version(), PUREPRESS_VERSION, false);
    }

    /**
     * 写入指定模块的默认配置。
     *
     * 如果配置已存在，则保留现有配置，避免覆盖用户设置。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     */
    public function createModuleIfMissing(string $moduleId): void
    {
        if (! function_exists('add_option')) {
            return;
        }

        add_option(OptionKeys::module($moduleId), Defaults::module($moduleId), '', false);
    }

    /**
     * 读取指定模块配置，并与模块默认配置合并。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     *
     * @return array<string, mixed>
     */
    private function moduleSettings(string $moduleId): array
    {
        $stored = [];

        if (function_exists('get_option')) {
            $option = get_option(OptionKeys::module($moduleId), []);
            $stored = is_array($option) ? $option : [];
        }

        return array_replace_recursive(Defaults::module($moduleId), $stored);
    }
}
