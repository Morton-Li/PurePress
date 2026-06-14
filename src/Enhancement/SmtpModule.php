<?php
/**
 * 使用 SMTP 接管 WordPress 邮件发送配置。
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
use PurePress\Support\HookRegistry;

final class SmtpModule implements ModuleInterface
{
    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return 'enhancement.smtp';
    }

    /**
     * 注册 SMTP 发信相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('phpmailer_init', [$this, 'configureMailer']);
    }

    /**
     * 配置 WordPress 内置 PHPMailer 实例。
     *
     * @param object $mailer WordPress 传入的 PHPMailer 实例。
     */
    public function configureMailer(object $mailer): void
    {
        $settings = (new OptionRepository())->moduleSettings($this->id());
        $host = trim((string) ($settings['host'] ?? ''));
        $fromEmail = trim((string) ($settings['from_email'] ?? ''));

        if ($host === '') {
            return;
        }

        if (method_exists($mailer, 'isSMTP')) {
            $mailer->isSMTP();
        }

        $mailer->Host = $host;
        $mailer->Port = (int) ($settings['port'] ?? 587);
        $mailer->SMTPAuth = (bool) ($settings['auth'] ?? true);

        $encryption = (string) ($settings['encryption'] ?? 'tls');

        if ($encryption === 'none') {
            $mailer->SMTPSecure = '';
        } else {
            $mailer->SMTPSecure = $encryption;
        }

        if ($mailer->SMTPAuth) {
            $mailer->Username = (string) ($settings['username'] ?? '');
            $mailer->Password = (string) ($settings['password'] ?? '');
        }

        if ($fromEmail !== '' && $this->isValidEmail($fromEmail)) {
            $mailer->From = $fromEmail;
        }

        $fromName = trim((string) ($settings['from_name'] ?? ''));

        if ($fromName !== '') {
            $mailer->FromName = $fromName;
        }
    }

    /**
     * 判断邮箱地址是否有效。
     *
     * @param string $email 邮箱地址。
     */
    private function isValidEmail(string $email): bool
    {
        if (function_exists('is_email')) {
            return (bool) is_email($email);
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
