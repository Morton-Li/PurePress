<?php
/**
 * 允许未登录访客在文章下发表评论。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Enhancement;

use PurePress\Configuration\OptionRepository;
use PurePress\Contracts\ModuleInterface;
use PurePress\Governance\RegistrationRateLimitStore;
use PurePress\Support\HookRegistry;

final class GuestCommentsModule implements ModuleInterface
{
    public const string MODULE_ID = 'enhancement.guest_comments';

    private const string SCOPE_IP = 'guest_comment_ip';
    private const string SCOPE_EMAIL = 'guest_comment_email';

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
     * 创建免登录文章回复模块。
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
     * 注册免登录文章回复 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        RegistrationRateLimitStore::install();

        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        $hooks->filter('pre_option_comment_registration', [$this, 'allowGuestCommentRegistration'], 10, 1);
        $hooks->filter('preprocess_comment', [$this, 'validateGuestCommentFrequency'], 5, 1);
    }

    /**
     * 在评论相关链路中允许未登录访客提交评论。
     *
     * 该逻辑只影响当前请求读取到的 `comment_registration` 值，不会修改
     * WordPress 全局讨论设置，也不会改变站点注册开关。
     *
     * @param mixed $preOption WordPress 传入的预过滤 option 值。
     *
     * @return mixed
     */
    public function allowGuestCommentRegistration(mixed $preOption): mixed
    {
        if ($this->shouldBypassLoginRequirement()) {
            return false;
        }

        return $preOption;
    }

    /**
     * 对未登录访客评论应用 PurePress 频率限制。
     *
     * @param array<string,mixed> $commentData WordPress 标准化前的评论数据。
     *
     * @return array<string,mixed>
     */
    public function validateGuestCommentFrequency(array $commentData): array
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return $commentData;
        }

        if (! $this->isCommentSubmissionData($commentData)) {
            return $commentData;
        }

        $this->store->deleteExpired();

        $email = $this->normalizeEmail($commentData['comment_author_email'] ?? '');
        $ip = $this->currentRequestIp($commentData['comment_author_IP'] ?? '');
        $windowSeconds = $this->settingInt('window_minutes', 10, 1, 1440) * MINUTE_IN_SECONDS;

        if ($email !== '') {
            $emailLimit = $this->settingInt('email_limit', 5, 1, 1000);

            if ($this->store->wouldExceed(self::SCOPE_EMAIL, $email, $emailLimit)) {
                $this->rejectComment();
            }
        }

        if ($ip !== '') {
            $ipLimit = $this->settingInt('ip_limit', 5, 1, 10000);

            if ($this->store->wouldExceed(self::SCOPE_IP, $ip, $ipLimit)) {
                $this->rejectComment();
            }
        }

        if ($email !== '') {
            $this->store->record(self::SCOPE_EMAIL, $email, $windowSeconds);
        }

        if ($ip !== '') {
            $this->store->record(self::SCOPE_IP, $ip, $windowSeconds);
        }

        return $commentData;
    }

    /**
     * 判断当前是否应该绕过“必须登录才能评论”的全局设置。
     */
    private function shouldBypassLoginRequirement(): bool
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        if ($this->isCommentSubmissionRequest()) {
            return true;
        }

        if (function_exists('is_admin') && is_admin()) {
            return false;
        }

        return $this->isFrontendCommentContext();
    }

    /**
     * 判断当前请求是否是评论提交请求。
     */
    private function isCommentSubmissionRequest(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        if (is_string($method) && strtoupper($method) === 'POST') {
            $postId = $_POST['comment_post_ID'] ?? null;
            $comment = $_POST['comment'] ?? null;

            if (null !== $postId && null !== $comment) {
                return true;
            }
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $phpSelf = $_SERVER['PHP_SELF'] ?? '';

        foreach ([$scriptName, $phpSelf] as $script) {
            if (is_string($script) && str_ends_with($script, '/wp-comments-post.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断当前前台页面是否可能渲染文章评论区域。
     */
    private function isFrontendCommentContext(): bool
    {
        if (! function_exists('is_singular') || ! is_singular()) {
            return false;
        }

        if (function_exists('comments_open') && ! comments_open()) {
            return false;
        }

        return true;
    }

    /**
     * 判断传入数据是否为标准评论提交数据。
     *
     * @param array<string,mixed> $commentData 评论数据。
     */
    private function isCommentSubmissionData(array $commentData): bool
    {
        return isset($commentData['comment_post_ID'], $commentData['comment_content'])
            && is_numeric($commentData['comment_post_ID'])
            && trim((string) $commentData['comment_content']) !== '';
    }

    /**
     * 标准化评论邮箱。
     *
     * @param mixed $email 原始邮箱。
     */
    private function normalizeEmail(mixed $email): string
    {
        $email = is_scalar($email) ? strtolower(trim((string) $email)) : '';
        $email = function_exists('sanitize_email') ? sanitize_email($email) : $email;

        if (function_exists('is_email')) {
            return is_email($email) ? $email : '';
        }

        return false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * 读取当前评论请求 IP。
     *
     * @param mixed $commentIp WordPress 评论数据中的 IP。
     */
    private function currentRequestIp(mixed $commentIp): string
    {
        $ip = is_scalar($commentIp) ? trim((string) $commentIp) : '';

        if ($ip === '') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ip = is_string($ip) ? trim($ip) : '';
        }

        return false !== filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * 拒绝当前评论提交。
     */
    private function rejectComment(): void
    {
        $message = '评论提交过于频繁，请稍后再试。';

        if (function_exists('wp_die')) {
            wp_die(
                $message,
                '评论提交过于频繁',
                [
                    'response' => 429,
                    'back_link' => true,
                ]
            );
        }

        exit;
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
