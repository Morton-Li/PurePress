<?php
/**
 * 注册频率限制记录存储。
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

final class RegistrationRateLimitStore
{
    private const string SCHEMA_VERSION = '1';

    /**
     * 确保注册频率限制表存在。
     */
    public static function install(): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! isset($wpdb->prefix) || ! method_exists($wpdb, 'get_charset_collate')) {
            return;
        }

        if (function_exists('get_option') && get_option(OptionKeys::registrationRateLimitSchemaVersion()) === self::SCHEMA_VERSION) {
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
                scope varchar(16) NOT NULL,
                identifier_hash char(64) NOT NULL,
                window_started_at datetime NOT NULL,
                attempts int(10) unsigned NOT NULL DEFAULT 0,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY scope_identifier (scope,identifier_hash),
                KEY expires_at (expires_at)
            ) {$charsetCollate};"
        );

        if (function_exists('update_option')) {
            update_option(OptionKeys::registrationRateLimitSchemaVersion(), self::SCHEMA_VERSION, false);
        }
    }

    /**
     * 删除注册频率限制表。
     */
    public static function uninstall(): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'query')) {
            return;
        }

        $wpdb->query('DROP TABLE IF EXISTS ' . self::tableName());
    }

    /**
     * 判断下一次请求是否会超过限制。
     *
     * @param string $scope      限制范围，例如 `email` 或 `ip`。
     * @param string $identifier 原始标识，例如邮箱或 IP。
     * @param int    $limit      时间窗口内允许的最大次数。
     */
    public function wouldExceed(string $scope, string $identifier, int $limit): bool
    {
        $row = $this->findRow($scope, $identifier);

        if ([] === $row || $this->isExpired($row)) {
            return false;
        }

        return (int) ($row['attempts'] ?? 0) >= $limit;
    }

    /**
     * 记录一次通过校验的注册请求。
     *
     * @param string $scope         限制范围，例如 `email` 或 `ip`。
     * @param string $identifier    原始标识，例如邮箱或 IP。
     * @param int    $windowSeconds 时间窗口秒数。
     */
    public function record(string $scope, string $identifier, int $windowSeconds): void
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'insert') || ! method_exists($wpdb, 'update')) {
            return;
        }

        $row = $this->findRow($scope, $identifier);
        $now = time();
        $expiresAt = $this->mysqlTime($now + $windowSeconds);

        if ([] === $row) {
            $wpdb->insert(
                self::tableName(),
                [
                    'scope' => $scope,
                    'identifier_hash' => $this->hashIdentifier($identifier),
                    'window_started_at' => $this->mysqlTime($now),
                    'attempts' => 1,
                    'expires_at' => $expiresAt,
                ],
                ['%s', '%s', '%s', '%d', '%s']
            );
            return;
        }

        if ($this->isExpired($row)) {
            $wpdb->update(
                self::tableName(),
                [
                    'window_started_at' => $this->mysqlTime($now),
                    'attempts' => 1,
                    'expires_at' => $expiresAt,
                ],
                ['id' => (int) ($row['id'] ?? 0)],
                ['%s', '%d', '%s'],
                ['%d']
            );
            return;
        }

        $wpdb->update(
            self::tableName(),
            ['attempts' => (int) ($row['attempts'] ?? 0) + 1],
            ['id' => (int) ($row['id'] ?? 0)],
            ['%d'],
            ['%d']
        );
    }

    /**
     * 清理已过期的频率窗口。
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
     * 查询指定范围与标识的频率记录。
     *
     * @param string $scope      限制范围。
     * @param string $identifier 原始标识。
     *
     * @return array<string,mixed>
     */
    private function findRow(string $scope, string $identifier): array
    {
        global $wpdb;

        if (! isset($wpdb) || ! method_exists($wpdb, 'get_row') || ! method_exists($wpdb, 'prepare')) {
            return [];
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE scope = %s AND identifier_hash = %s LIMIT 1',
                $scope,
                $this->hashIdentifier($identifier)
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : [];
    }

    /**
     * 判断频率窗口是否已经过期。
     *
     * @param array<string,mixed> $row 数据库记录。
     */
    private function isExpired(array $row): bool
    {
        $expiresAt = isset($row['expires_at']) && is_scalar($row['expires_at']) ? (string) $row['expires_at'] : '';

        return $expiresAt === '' || strtotime($expiresAt) <= time();
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
     * 哈希邮箱或 IP 标识，避免在频率表中保存明文。
     *
     * @param string $identifier 原始标识。
     */
    private function hashIdentifier(string $identifier): string
    {
        $salt = function_exists('wp_salt') ? wp_salt('auth') : 'purepress';

        return hash_hmac('sha256', $identifier, $salt);
    }

    /**
     * 获取注册频率限制表名。
     */
    private static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'purepress_registration_rate_limits';
    }
}
