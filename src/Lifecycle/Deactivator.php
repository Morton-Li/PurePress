<?php
/**
 * PurePress 插件停用处理。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Lifecycle;

use PurePress\Configuration\OptionRepository;
use PurePress\Governance\LoginAddressModule;
use PurePress\Governance\RegistrationEmailVerificationStore;
use PurePress\Optimization\PageCacheModule;

final class Deactivator
{
    /**
     * 插件停用时清理登录入口重写规则。
     */
    public static function deactivate(): void
    {
        $options = new OptionRepository();

        LoginAddressModule::removeRewriteRulesForSettings(
            $options->moduleSettings(LoginAddressModule::MODULE_ID)
        );
        RegistrationEmailVerificationStore::unscheduleCleanup();
        PageCacheModule::unscheduleCleanup();
        PageCacheModule::clear();

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }
}
