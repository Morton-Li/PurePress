<?php
/**
 * PurePress 插件卸载处理。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Lifecycle;

use PurePress\Configuration\OptionKeys;
use PurePress\Governance\GeoIpDatabase;
use PurePress\Governance\RegistrationEmailVerificationStore;
use PurePress\Governance\RegistrationRateLimitStore;
use PurePress\Optimization\PageCacheModule;

final class Uninstaller
{
    /**
     * 插件卸载时删除 PurePress 创建的配置记录。
     */
    public static function uninstall(): void
    {
        global $wpdb;

        GeoIpDatabase::deleteDataRoot();
        PageCacheModule::unscheduleCleanup();
        PageCacheModule::clear();
        RegistrationEmailVerificationStore::uninstall();
        RegistrationRateLimitStore::uninstall();

        if (
            ! isset($wpdb)
            || ! method_exists($wpdb, 'query')
            || ! method_exists($wpdb, 'prepare')
            || ! method_exists($wpdb, 'esc_like')
        ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like(OptionKeys::PREFIX) . '%'
            )
        );
    }
}
