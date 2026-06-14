<?php
/**
 * 管理 WordPress REST API 访问策略。
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
use WP_Error;

final class RestApiModule implements ModuleInterface
{
    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return 'governance.rest_api';
    }

    /**
     * 注册 REST API 治理相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('wp', [$this, 'removeRestApiDiscoveryLinks']);
        $hooks->action('template_redirect', [$this, 'removeRestApiDiscoveryHeaders'], 0);
        $hooks->filter('rest_authentication_errors', [$this, 'denyAnonymousRequests']);
        $hooks->filter('rest_jsonp_enabled', [$this, 'disableJsonp']);
    }

    /**
     * 移除前台 HTML head 中的 REST API 发现链接。
     */
    public function removeRestApiDiscoveryLinks(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        remove_action('wp_head', 'rest_output_link_wp_head');
    }

    /**
     * 移除前台响应头中的 REST API 发现信息。
     */
    public function removeRestApiDiscoveryHeaders(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        remove_action('template_redirect', 'rest_output_link_header', 11);
    }

    /**
     * 限制未登录用户访问 REST API。
     *
     * 如果其他插件或 WordPress 已经返回认证结果，则尊重现有结果，避免覆盖更早的安全决策。
     *
     * @param mixed $result 现有认证结果。`null` 表示尚未做出允许或拒绝决定。
     */
    public function denyAnonymousRequests(mixed $result): mixed
    {
        if (null !== $result) {
            return $result;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return $result;
        }

        if (! class_exists(WP_Error::class)) {
            return $result;
        }

        return new WP_Error(
            'purepress_rest_api_forbidden',
            'PurePress 已限制未登录用户访问 REST API。',
            [
                'status' => 401,
            ]
        );
    }

    /**
     * 关闭 REST API JSONP 支持。
     */
    public function disableJsonp(): bool
    {
        return false;
    }
}
