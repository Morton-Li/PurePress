<?php
/**
 * Plugin Name: PurePress
 * Plugin URI: https://github.com/Morton-Li/PurePress
 * Description: 面向 WordPress 的统一治理、体验优化、能力增强与基础设施集成插件。
 * Version: 1.5.0
 * Requires at least: 7.0
 * Tested up to: 7.0
 * Requires PHP: 8.5
 * Author: Morton Li
 * License: GPL-3.0-only
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: purepress
 *
 * Copyright (C) 2026 Morton Li
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation, version 3.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY.
 * See the GNU General Public License for more details.
 *
 * @package PurePress
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('PUREPRESS_VERSION', '1.5.0');
define('PUREPRESS_FILE', __FILE__);
define('PUREPRESS_PATH', plugin_dir_path(__FILE__));

$purepressAutoload = PUREPRESS_PATH . 'vendor/autoload.php';

if (is_readable($purepressAutoload)) {
    require_once $purepressAutoload;
} else {
    spl_autoload_register(
        static function (string $className): void {
            $prefix = 'PurePress\\';

            if (! str_starts_with($className, $prefix)) {
                return;
            }

            $relativeClass = substr($className, strlen($prefix));
            $file = PUREPRESS_PATH . 'src/' . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }
    );
}

register_activation_hook(PUREPRESS_FILE, [PurePress\Lifecycle\Installer::class, 'activate']);
register_deactivation_hook(PUREPRESS_FILE, [PurePress\Lifecycle\Deactivator::class, 'deactivate']);
register_uninstall_hook(PUREPRESS_FILE, [PurePress\Lifecycle\Uninstaller::class, 'uninstall']);

add_action(
    'plugins_loaded',
    static function (): void {
        PurePress\Plugin::instance()->boot();
    }
);
