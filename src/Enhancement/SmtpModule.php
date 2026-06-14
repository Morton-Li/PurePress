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

        $port = (int) ($settings['port'] ?? 587);
        $encryption = $this->normalizedEncryption((string) ($settings['encryption'] ?? 'tls'), $port);

        $mailer->Host = $host;
        $mailer->Port = $port;
        $mailer->SMTPAuth = (bool) ($settings['auth'] ?? true);
        $mailer->Timeout = 10;

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

    /**
     * 规范化 SMTP 加密方式。
     *
     * 465 端口通常使用隐式 TLS/SSL；如果错误配置为 STARTTLS，部分服务端会在握手阶段
     * 长时间等待，导致当前 WordPress 请求被阻塞。
     *
     * @param string $encryption 配置中的加密方式。
     * @param int    $port       SMTP 端口。
     */
    private function normalizedEncryption(string $encryption, int $port): string
    {
        if (! in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = 'tls';
        }

        if ($port === 465 && $encryption === 'tls') {
            return 'ssl';
        }

        return $encryption;
    }
}
