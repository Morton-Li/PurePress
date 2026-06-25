<?php
/**
 * PurePress 默认配置。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

final class Defaults
{
    /**
     * 模块默认配置。
     *
     * @var array<string, array<string, mixed>>
     */
    private const MODULES = [
        'governance.rest_api' => [
            'enabled' => false,
        ],
        'governance.xml_rpc' => [
            'enabled' => false,
        ],
        'governance.wordpress_fingerprint' => [
            'enabled' => false,
        ],
        'governance.login_address' => [
            'enabled' => true,
            'mode' => 'default',
            'login_path' => 'login',
            'signup_path' => 'signup',
        ],
        'governance.login_audit' => [
            'enabled' => false,
        ],
        'governance.registration_rate_limit' => [
            'enabled' => false,
            'email_limit' => 8,
            'email_window_minutes' => 30,
            'ip_limit' => 20,
            'ip_window_minutes' => 60,
        ],
        'governance.registration_email_verification' => [
            'enabled' => false,
            'expiration_minutes' => 60,
        ],
        'enhancement.smtp' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'auth' => true,
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '',
        ],
        'enhancement.media_folders' => [
            'enabled' => false,
        ],
        'integration.s3_media' => [
            'enabled' => false,
            'endpoint' => '',
            'region' => 'auto',
            'bucket' => '',
            'access_key' => '',
            'secret_key' => '',
            'path_style' => true,
            'path_prefix' => '',
            'public_base_url' => '',
        ],
    ];

    /**
     * 获取指定模块的默认配置。
     *
     * @param string $moduleId 模块 ID，例如 `governance.rest_api`。
     *
     * @return array<string, mixed>
     */
    public static function module(string $moduleId): array
    {
        return self::MODULES[$moduleId] ?? [];
    }
}
