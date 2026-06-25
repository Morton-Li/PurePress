<?php
/**
 * 限制注册请求触发邮件的频率。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Governance;

use PurePress\Configuration\OptionRepository;
use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;
use WP_Error;

final class RegistrationRateLimitModule implements ModuleInterface
{
    public const string MODULE_ID = 'governance.registration_rate_limit';

    private const string SCOPE_EMAIL = 'email';
    private const string SCOPE_IP = 'ip';

    /**
     * 模块配置。
     *
     * @var array<string,mixed>
     */
    private array $settings = [];

    /**
     * 频率记录存储。
     */
    private RegistrationRateLimitStore $store;

    /**
     * 创建注册频率限制模块。
     */
    public function __construct()
    {
        $this->store = new RegistrationRateLimitStore();
    }

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册注册频率限制 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        RegistrationRateLimitStore::install();

        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        $hooks->filter('registration_errors', [$this, 'validateRegistrationFrequency'], 1000, 3);
        $hooks->filter('wpmu_validate_user_signup', [$this, 'validateMultisiteRegistrationFrequency'], 1000);
    }

    /**
     * 在 WordPress 创建用户前检查注册频率。
     *
     * 如果 WordPress 或其他插件已经产生注册错误，PurePress 不计数、不干预。
     * 通过频率检查后，注册流程继续交还给 WordPress。
     *
     * @param WP_Error $errors             注册错误对象。
     * @param string   $sanitizedUserLogin 已清洗用户名。
     * @param string   $userEmail          注册邮箱。
     */
    public function validateRegistrationFrequency(WP_Error $errors, string $sanitizedUserLogin, string $userEmail): WP_Error
    {
        unset($sanitizedUserLogin);

        return $this->applyFrequencyLimit($errors, $userEmail, 'purepress_registration_rate_limited');
    }

    /**
     * 在 Multisite 写入待激活记录前检查注册频率。
     *
     * @param array<string,mixed> $result Multisite 注册校验结果。
     *
     * @return array<string,mixed>
     */
    public function validateMultisiteRegistrationFrequency(array $result): array
    {
        $errors = $result['errors'] ?? null;
        $userEmail = isset($result['user_email']) && is_scalar($result['user_email']) ? (string) $result['user_email'] : '';

        if (! $errors instanceof WP_Error) {
            return $result;
        }

        $result['errors'] = $this->applyFrequencyLimit($errors, $userEmail, 'generic');

        return $result;
    }

    /**
     * 对邮箱和 IP 应用注册频率限制。
     *
     * @param WP_Error $errors    注册错误对象。
     * @param string   $userEmail 注册邮箱。
     * @param string   $errorCode 频率限制错误代码。
     */
    private function applyFrequencyLimit(WP_Error $errors, string $userEmail, string $errorCode): WP_Error
    {
        if ((bool) apply_filters('purepress_skip_registration_rate_limit', false)) {
            return $errors;
        }

        if ($errors->has_errors()) {
            return $errors;
        }

        $email = $this->normalizeEmail($userEmail);

        if ($email === '') {
            return $errors;
        }

        $ip = $this->currentRequestIp();
        $emailLimit = $this->settingInt('email_limit', 8, 1, 1000);
        $emailWindowSeconds = $this->settingInt('email_window_minutes', 30, 1, 1440) * MINUTE_IN_SECONDS;
        $ipLimit = $this->settingInt('ip_limit', 20, 1, 10000);
        $ipWindowSeconds = $this->settingInt('ip_window_minutes', 60, 1, 1440) * MINUTE_IN_SECONDS;

        $this->store->deleteExpired();

        if ($this->store->wouldExceed(self::SCOPE_EMAIL, $email, $emailLimit)) {
            $errors->add($errorCode, '注册请求过于频繁，请稍后再试。');
            return $errors;
        }

        if ($ip !== '' && $this->store->wouldExceed(self::SCOPE_IP, $ip, $ipLimit)) {
            $errors->add($errorCode, '注册请求过于频繁，请稍后再试。');
            return $errors;
        }

        $this->store->record(self::SCOPE_EMAIL, $email, $emailWindowSeconds);

        if ($ip !== '') {
            $this->store->record(self::SCOPE_IP, $ip, $ipWindowSeconds);
        }

        return $errors;
    }

    /**
     * 标准化注册邮箱。
     *
     * @param string $email 原始邮箱。
     */
    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        $email = function_exists('sanitize_email') ? sanitize_email($email) : $email;

        if (function_exists('is_email')) {
            return is_email($email) ? $email : '';
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * 读取当前请求 IP。
     *
     * 第一版只信任服务器确认的 REMOTE_ADDR，避免直接信任可伪造的代理 Header。
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
     * 读取并约束整数配置。
     *
     * @param string $key     配置键。
     * @param int    $default 默认值。
     * @param int    $min     最小允许值。
     * @param int    $max     最大允许值。
     */
    private function settingInt(string $key, int $default, int $min, int $max): int
    {
        $number = is_numeric($this->settings[$key] ?? null) ? (int) $this->settings[$key] : $default;

        if ($number < $min) {
            return $min;
        }

        if ($number > $max) {
            return $max;
        }

        return $number;
    }
}
