<?php
/**
 * 注册邮箱验证待处理记录存储。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Governance;

use PurePress\Configuration\OptionKeys;

final class RegistrationEmailVerificationStore
{
    public const string CLEANUP_HOOK = 'purepress_cleanup_pending_registrations';

    private const string SCHEMA_VERSION = '1';

    /**
     * 确保注册邮箱验证表存在。
     */
    public static function install(): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_charset_collate')) {
            return;
        }

        if (function_exists('get_option') && get_option(OptionKeys::registrationEmailVerificationSchemaVersion()) === self::SCHEMA_VERSION) {
            return;
        }

        if (defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        if (! function_exists('dbDelta')) {
            return;
        }

        $tableName = self::tableName();
        $charsetCollate = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$tableName} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_login varchar(60) NOT NULL,
                user_email varchar(200) NOT NULL,
                user_login_hash char(64) NOT NULL,
                user_email_hash char(64) NOT NULL,
                token_hash char(64) NOT NULL,
                request_ip varchar(45) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                meta longtext NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY user_login_hash (user_login_hash),
                KEY user_email_hash (user_email_hash),
                KEY expires_at (expires_at)
            ) {$charsetCollate};"
        );

        if (function_exists('update_option')) {
            update_option(OptionKeys::registrationEmailVerificationSchemaVersion(), self::SCHEMA_VERSION, false);
        }
    }

    /**
     * 删除注册邮箱验证表和清理计划。
     */
    public static function uninstall(): void
    {
        global $wpdb;

        self::unscheduleCleanup();

        if (! isset($wpdb) || ! method_exists($wpdb, 'query')) {
            return;
        }

        $wpdb->query('DROP TABLE IF EXISTS ' . self::tableName());
    }

    /**
     * 安排待验证注册记录定时清理。
     */
    public static function scheduleCleanup(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (false === wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK);
        }
    }

    /**
     * 移除待验证注册记录定时清理。
     */
    public static function unscheduleCleanup(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        }
    }

    /**
     * 静态清理入口。
     */
    public static function deleteExpiredStatic(): void
    {
        if (function_exists('get_option') && get_option(OptionKeys::registrationEmailVerificationSchemaVersion()) !== self::SCHEMA_VERSION) {
            return;
        }

        (new self())->deleteExpired();
    }

    /**
     * 创建待验证注册记录。
     *
     * @param string              $userLogin         用户名。
     * @param string              $userEmail         邮箱。
     * @param int                 $expirationSeconds 有效期秒数。
     * @param string              $requestIp         请求 IP。
     * @param array<string,mixed> $meta              附加上下文。
     *
     * @return array{id: int, token: string}
     */
    public function create(string $userLogin, string $userEmail, int $expirationSeconds, string $requestIp, array $meta = []): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'insert')) {
            return ['id' => 0, 'token' => ''];
        }

        $token = $this->generateToken();
        $now = time();
        $metaJson = function_exists('wp_json_encode') ? wp_json_encode($meta) : json_encode($meta);
        $loginHash = $this->hashIdentifier(strtolower($userLogin));
        $emailHash = $this->hashIdentifier(strtolower($userEmail));

        $this->deleteByLoginOrEmailHash($loginHash, $emailHash);

        $inserted = $wpdb->insert(
            self::tableName(),
            [
                'user_login' => $userLogin,
                'user_email' => $userEmail,
                'user_login_hash' => $loginHash,
                'user_email_hash' => $emailHash,
                'token_hash' => $this->hashIdentifier($token),
                'request_ip' => $requestIp,
                'created_at' => $this->mysqlTime($now),
                'expires_at' => $this->mysqlTime($now + $expirationSeconds),
                'meta' => is_string($metaJson) ? $metaJson : '{}',
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (! $inserted) {
            return ['id' => 0, 'token' => ''];
        }

        return [
            'id' => (int) $wpdb->insert_id,
            'token' => $token,
        ];
    }

    /**
     * 通过用户名和 token 查找待验证注册记录。
     *
     * @param string $userLogin 用户名。
     * @param string $token     明文 token。
     *
     * @return array<string,mixed>
     */
    public function findByLoginAndToken(string $userLogin, string $token): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) {
            return [];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE user_login_hash = %s AND token_hash = %s LIMIT 1',
                $this->hashIdentifier(strtolower($userLogin)),
                $this->hashIdentifier($token)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * 删除指定待验证注册记录。
     *
     * @param int $id 待验证记录 ID。
     */
    public function deleteById(int $id): void
    {
        global $wpdb;

        if ($id <= 0 || ! isset($wpdb) || ! method_exists($wpdb, 'delete')) {
            return;
        }

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
    }

    /**
     * 删除同用户名或同邮箱的旧待验证记录。
     *
     * @param string $loginHash 用户名哈希。
     * @param string $emailHash 邮箱哈希。
     */
    private function deleteByLoginOrEmailHash(string $loginHash, string $emailHash): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'query') || ! method_exists($wpdb, 'prepare')) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::tableName() . ' WHERE user_login_hash = %s OR user_email_hash = %s',
                $loginHash,
                $emailHash
            )
        );
    }

    /**
     * 删除已过期待验证注册记录。
     */
    public function deleteExpired(): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'query') || ! method_exists($wpdb, 'prepare')) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::tableName() . ' WHERE expires_at < %s',
                $this->mysqlTime(time())
            )
        );
    }

    /**
     * 判断待验证记录是否过期。
     *
     * @param array<string,mixed> $pending 待验证注册记录。
     */
    public function isExpired(array $pending): bool
    {
        $expiresAt = isset($pending['expires_at']) && is_scalar($pending['expires_at']) ? (string) $pending['expires_at'] : '';

        return $expiresAt === '' || strtotime($expiresAt) <= time();
    }

    /**
     * 生成注册验证 token。
     */
    private function generateToken(): string
    {
        if (function_exists('wp_generate_password')) {
            return wp_generate_password(32, false, false);
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * 将时间戳转换为 UTC MySQL 时间。
     *
     * @param int $timestamp Unix 时间戳。
     */
    private function mysqlTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * 哈希标识，避免为查询索引额外保存可枚举明文。
     *
     * @param string $identifier 原始标识。
     */
    private function hashIdentifier(string $identifier): string
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'purepress';

        return hash_hmac('sha256', $identifier, $salt);
    }

    /**
     * 获取注册邮箱验证表名。
     */
    private static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'purepress_pending_registrations';
    }
}
