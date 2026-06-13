<?php
/**
 * 隐藏常见 WordPress 识别特征。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Governance;

use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;

final class WordPressFingerprintModule implements ModuleInterface
{
    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return 'governance.wordpress_fingerprint';
    }

    /**
     * 注册 WordPress 特征隐藏相关 Hook。
     *
     * 当前仅处理前台 HTML head 中的 WordPress generator 元数据。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('wp', [$this, 'removeFrontendGenerator']);
    }

    /**
     * 移除前台 HTML head 中的 WordPress generator 元数据。
     */
    public function removeFrontendGenerator(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        remove_action('wp_head', 'wp_generator');
    }
}
