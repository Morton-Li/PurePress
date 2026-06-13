<?php
/**
 * PurePress 插件主协调器。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress;

use PurePress\Admin\SettingsPage;
use PurePress\Configuration\ModuleCatalog;
use PurePress\Configuration\OptionRepository;
use PurePress\Configuration\OptionSynchronizer;
use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;

final class Plugin
{
    /**
     * 插件单例实例。
     */
    private static ?self $instance = null;

    /**
     * 标记插件是否已经完成启动，避免重复注册 Hook。
     */
    private bool $booted = false;

    /**
     * 禁止外部直接实例化插件协调器。
     */
    private function __construct()
    {
    }

    /**
     * 获取插件单例实例。
     */
    public static function instance(): self
    {
        if (! self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 启动插件并注册系统模块与可配置模块。
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $hooks = new HookRegistry();
        $options = new OptionRepository();

        if ($options->storedVersion() !== PUREPRESS_VERSION) {
            (new OptionSynchronizer($options))->sync();
        }

        (new SettingsPage($options))->register($hooks);

        foreach ($this->modules() as $module) {
            if (! $options->isModuleEnabled($module->id())) {
                continue;
            }

            $module->register($hooks);
        }
    }

    /**
     * 返回可由用户独立启用的功能模块。
     *
     * @return list<ModuleInterface>
     */
    private function modules(): array
    {
        $modules = [];

        foreach (ModuleCatalog::definitions() as $definition) {
            $moduleClass = $definition->moduleClass();

            if (! is_a($moduleClass, ModuleInterface::class, true)) {
                continue;
            }

            $modules[] = new $moduleClass();
        }

        return $modules;
    }
}
