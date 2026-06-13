<?php
/**
 * PurePress 配置同步器。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

final class OptionSynchronizer
{
    /**
     * 配置读写仓库。
     */
    private OptionRepository $options;

    /**
     * 创建配置同步器。
     *
     * @param OptionRepository $options 配置读写仓库。
     */
    public function __construct(OptionRepository $options)
    {
        $this->options = $options;
    }

    /**
     * 同步当前版本定义的配置键。
     *
     * 会补齐缺失配置，并删除属于 PurePress 但当前版本不再定义的配置。
     */
    public function sync(): void
    {
        $this->options->saveVersion();

        foreach (ModuleCatalog::definitions() as $definition) {
            $this->options->createModuleIfMissing($definition->id());
        }

        $this->deleteDeprecatedOptions();
    }

    /**
     * 删除当前版本不再定义的 PurePress 配置键。
     */
    private function deleteDeprecatedOptions(): void
    {
        if (! function_exists('delete_option')) {
            return;
        }

        $definedLookup = array_fill_keys(OptionKeys::defined(), true);

        foreach ($this->storedOptionNames() as $optionName) {
            if (isset($definedLookup[$optionName])) {
                continue;
            }

            delete_option($optionName);
        }
    }

    /**
     * 获取数据库中已有的 PurePress 配置键名。
     *
     * @return list<string>
     */
    private function storedOptionNames(): array
    {
        global $wpdb;

        if (
            ! isset($wpdb)
            || ! method_exists($wpdb, 'get_col')
            || ! method_exists($wpdb, 'prepare')
            || ! method_exists($wpdb, 'esc_like')
        ) {
            return [];
        }

        $optionNames = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(OptionKeys::PREFIX) . '%'
            )
        );

        return is_array($optionNames) ? array_values(array_filter($optionNames, 'is_string')) : [];
    }
}
