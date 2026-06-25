<?php
/**
 * 记录用户最后一次成功登录信息。
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
use WP_User;

final class LoginAuditModule implements ModuleInterface
{
    public const string MODULE_ID = 'governance.login_audit';

    private const string META_LAST_LOGIN_AT = '_purepress_login_audit_last_login_at';
    private const string META_LAST_LOGIN_IP = '_purepress_login_audit_last_login_ip';
    private const string META_LAST_LOGIN_GEO = '_purepress_login_audit_last_login_geo';
    private const string COLUMN_LAST_LOGIN_AT = 'purepress_last_login_at';
    private const string COLUMN_LAST_LOGIN_IP = 'purepress_last_login_ip';
    private const string COLUMN_LAST_LOGIN_GEO = 'purepress_last_login_geo';

    /**
     * GeoIP 数据库管理器。
     */
    private GeoIpDatabase $geoIpDatabase;

    /**
     * 创建登录审计模块。
     */
    public function __construct()
    {
        $this->geoIpDatabase = new GeoIpDatabase();
    }

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册登录审计相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('wp_login', [$this, 'recordLogin'], 10, 2);
        $hooks->filter('manage_users_columns', [$this, 'registerUserColumns']);
        $hooks->filter('manage_users_custom_column', [$this, 'renderUserColumn'], 10, 3);
        $hooks->action('show_user_profile', [$this, 'renderUserProfileAudit']);
        $hooks->action('edit_user_profile', [$this, 'renderUserProfileAudit']);
    }

    /**
     * 记录最后一次成功登录信息。
     *
     * @param string  $userLogin 用户登录名。
     * @param WP_User $user      已登录用户对象。
     */
    public function recordLogin(string $userLogin, WP_User $user): void
    {
        unset($userLogin);

        $userId = (int) $user->ID;

        if ($userId <= 0) {
            return;
        }

        $ip = $this->currentRequestIp();
        $geo = $ip !== '' ? $this->geoIpDatabase->lookup($ip) : [];

        update_user_meta($userId, self::META_LAST_LOGIN_AT, time());
        update_user_meta($userId, self::META_LAST_LOGIN_IP, $ip);
        update_user_meta($userId, self::META_LAST_LOGIN_GEO, $geo);
    }

    /**
     * 注册用户列表审计列。
     *
     * @param array<string,string> $columns WordPress 用户列表列定义。
     *
     * @return array<string,string>
     */
    public function registerUserColumns(array $columns): array
    {
        $columns[self::COLUMN_LAST_LOGIN_AT] = '最后登录时间';
        $columns[self::COLUMN_LAST_LOGIN_IP] = '最后登录 IP';
        $columns[self::COLUMN_LAST_LOGIN_GEO] = '登录位置';

        return $columns;
    }

    /**
     * 渲染用户列表审计列。
     *
     * @param string $output     默认列输出。
     * @param string $columnName 列名。
     * @param int    $userId     用户 ID。
     */
    public function renderUserColumn(string $output, string $columnName, int $userId): string
    {
        return match ($columnName) {
            self::COLUMN_LAST_LOGIN_AT => esc_html($this->formattedLastLoginAt($userId)),
            self::COLUMN_LAST_LOGIN_IP => esc_html($this->lastLoginIp($userId)),
            self::COLUMN_LAST_LOGIN_GEO => esc_html($this->lastLoginLocation($userId)),
            default => $output,
        };
    }

    /**
     * 在用户资料页展示登录审计信息。
     *
     * @param WP_User $user 用户对象。
     */
    public function renderUserProfileAudit(WP_User $user): void
    {
        $userId = (int) $user->ID;
        ?>
        <h2>PurePress 登录审计</h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">最后登录时间</th>
                    <td><?php echo esc_html($this->formattedLastLoginAt($userId)); ?></td>
                </tr>
                <tr>
                    <th scope="row">最后登录 IP</th>
                    <td><?php echo esc_html($this->lastLoginIp($userId)); ?></td>
                </tr>
                <tr>
                    <th scope="row">登录位置</th>
                    <td><?php echo esc_html($this->lastLoginLocation($userId)); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * 读取当前请求 IP。
     *
     * 第一版只信任服务器已经确认的 REMOTE_ADDR，避免直接信任可伪造的代理 Header。
     */
    private function currentRequestIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if (! is_string($ip)) {
            return '';
        }

        $ip = trim($ip);

        return false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * 格式化最后登录时间。
     *
     * @param int $userId 用户 ID。
     */
    private function formattedLastLoginAt(int $userId): string
    {
        $timestamp = $this->lastLoginTimestamp($userId);

        if ($timestamp <= 0) {
            return '未记录';
        }

        $format = trim((string) get_option('date_format') . ' ' . (string) get_option('time_format'));

        return function_exists('wp_date')
            ? wp_date($format, $timestamp)
            : date_i18n($format, $timestamp);
    }

    /**
     * 读取最后登录时间戳。
     *
     * @param int $userId 用户 ID。
     */
    private function lastLoginTimestamp(int $userId): int
    {
        $timestamp = get_user_meta($userId, self::META_LAST_LOGIN_AT, true);

        return is_numeric($timestamp) ? (int) $timestamp : 0;
    }

    /**
     * 读取最后登录 IP。
     *
     * @param int $userId 用户 ID。
     */
    private function lastLoginIp(int $userId): string
    {
        if ($this->lastLoginTimestamp($userId) <= 0) {
            return '未记录';
        }

        $ip = get_user_meta($userId, self::META_LAST_LOGIN_IP, true);

        return is_scalar($ip) && (string) $ip !== '' ? (string) $ip : '未记录';
    }

    /**
     * 读取最后登录位置。
     *
     * @param int $userId 用户 ID。
     */
    private function lastLoginLocation(int $userId): string
    {
        if ($this->lastLoginTimestamp($userId) <= 0) {
            return '未记录';
        }

        $geo = get_user_meta($userId, self::META_LAST_LOGIN_GEO, true);

        if (! is_array($geo)) {
            return '未解析';
        }

        $display = isset($geo['display']) && is_scalar($geo['display']) ? trim((string) $geo['display']) : '';
        $timezone = isset($geo['timezone']) && is_scalar($geo['timezone']) ? trim((string) $geo['timezone']) : '';

        if ($display === '') {
            return '未解析';
        }

        return $timezone !== '' ? $display . ' / ' . $timezone : $display;
    }
}
