<?php
/**
 * 注册邮箱验证。
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

final class RegistrationEmailVerificationModule implements ModuleInterface
{
    public const string MODULE_ID = 'governance.registration_email_verification';

    private const string ERROR_PENDING = 'purepress_registration_pending';
    private const string QUERY_STATUS = 'purepress_registration';
    private const string QUERY_VERIFY = 'purepress_verify_registration';

    /**
     * 模块配置。
     *
     * @var array<string,mixed>
     */
    private array $settings = [];

    /**
     * 待验证注册记录存储。
     */
    private RegistrationEmailVerificationStore $store;

    /**
     * 当前是否正在由 PurePress 创建已验证用户。
     */
    private bool $creatingVerifiedUser = false;

    /**
     * 创建注册邮箱验证模块。
     */
    public function __construct()
    {
        $this->store = new RegistrationEmailVerificationStore();
    }

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册注册邮箱验证 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        RegistrationEmailVerificationStore::install();
        RegistrationEmailVerificationStore::scheduleCleanup();

        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        $hooks->filter('registration_errors', [$this, 'interceptRegistration'], 2000, 3);
        $hooks->action('login_form_rp', [$this, 'maybeHandleNativePasswordSetup'], 0);
        $hooks->action('login_form_resetpass', [$this, 'maybeHandleNativePasswordSetup'], 0);
        $hooks->action('template_redirect', [$this, 'maybeHandleFrontendRequest'], 0);
    }

    /**
     * 捕获前台注册，在创建真实用户前改为待邮箱验证状态。
     *
     * @param WP_Error $errors             注册错误对象。
     * @param string   $sanitizedUserLogin 已清洗用户名。
     * @param string   $userEmail          注册邮箱。
     */
    public function interceptRegistration(WP_Error $errors, string $sanitizedUserLogin, string $userEmail): WP_Error
    {
        if ($this->creatingVerifiedUser || $errors->has_errors() || $this->shouldSkipRegistrationInterception()) {
            return $errors;
        }

        $userLogin = $this->normalizeLogin($sanitizedUserLogin);
        $email = $this->normalizeEmail($userEmail);

        if ($userLogin === '' || $email === '') {
            return $errors;
        }

        $this->store->deleteExpired();

        $pending = $this->store->create(
            $userLogin,
            $email,
            $this->expirationSeconds(),
            $this->currentRequestIp(),
            $this->registrationMeta()
        );

        if ($pending['id'] <= 0 || $pending['token'] === '') {
            $errors->add('purepress_registration_pending_failed', '注册验证请求无法创建，请稍后再试。');
            return $errors;
        }

        if (! $this->sendVerificationEmail($userLogin, $email, $pending['token'])) {
            $this->store->deleteById($pending['id']);
            $errors->add('purepress_registration_mail_failed', '验证邮件发送失败，请稍后再试。');
            return $errors;
        }

        if ($this->shouldRedirectPendingRegistration()) {
            wp_safe_redirect($this->statusUrl('pending'));
            exit;
        }

        $errors->add(
            self::ERROR_PENDING,
            '验证邮件已发送，请前往邮箱完成注册。',
            [
                'status' => 202,
                'redirect_url' => $this->statusUrl('pending'),
            ]
        );

        return $errors;
    }

    /**
     * 处理 WordPress 登录入口中的注册验证设置密码请求。
     */
    public function maybeHandleNativePasswordSetup(): void
    {
        $this->handlePasswordSetupRequest(false, false);
    }

    /**
     * 处理 PurePress 前台验证入口和注册状态页。
     */
    public function maybeHandleFrontendRequest(): void
    {
        if ($this->isFrontendVerificationRequest()) {
            $this->handlePasswordSetupRequest(true, true);
            return;
        }

        $status = $this->requestedStatus();

        if ($status !== '') {
            $this->renderStatusPage($status);
        }
    }

    /**
     * 处理待验证注册的设置密码请求。
     *
     * @param bool $frontendRequest 是否为 PurePress 前台验证入口。
     * @param bool $strict          是否在未命中 pending 时展示 PurePress 失效页。
     */
    private function handlePasswordSetupRequest(bool $frontendRequest, bool $strict): void
    {
        $credentials = $this->submittedVerificationCredentials();

        if ($credentials['login'] === '' || $credentials['key'] === '') {
            if ($strict) {
                $this->renderStatusPage('invalid');
            }

            return;
        }

        $pending = $this->store->findByLoginAndToken($credentials['login'], $credentials['key']);

        if ([] === $pending) {
            if ($strict) {
                $this->renderStatusPage('invalid');
            }

            return;
        }

        if ($this->store->isExpired($pending)) {
            $this->store->deleteById((int) ($pending['id'] ?? 0));
            wp_safe_redirect($this->statusUrl('expired'));
            exit;
        }

        if ($this->isPasswordSetupSubmission()) {
            $this->completePendingRegistration($pending, $credentials['key'], $frontendRequest);
            return;
        }

        $this->renderPasswordSetupPage($pending, $credentials['key'], new WP_Error(), $frontendRequest);
    }

    /**
     * 完成待验证注册并创建真实 WordPress 用户。
     *
     * @param array<string,mixed> $pending         待验证注册记录。
     * @param string              $token           明文 token。
     * @param bool                $frontendRequest 是否为 PurePress 前台验证入口。
     */
    private function completePendingRegistration(array $pending, string $token, bool $frontendRequest): void
    {
        $errors = $this->passwordSubmissionErrors();

        if ($errors->has_errors()) {
            $this->renderPasswordSetupPage($pending, $token, $errors, $frontendRequest);
            return;
        }

        $password = $this->submittedPassword();
        $userLogin = isset($pending['user_login']) && is_scalar($pending['user_login']) ? (string) $pending['user_login'] : '';
        $userEmail = isset($pending['user_email']) && is_scalar($pending['user_email']) ? (string) $pending['user_email'] : '';
        $userId = $this->createVerifiedUser($userLogin, $userEmail, $password);

        if ($userId instanceof WP_Error) {
            $errors->add('purepress_registration_create_failed', $userId->get_error_message());
            $this->renderPasswordSetupPage($pending, $token, $errors, $frontendRequest);
            return;
        }

        $this->store->deleteById((int) ($pending['id'] ?? 0));

        wp_safe_redirect($this->statusUrl('verified'));
        exit;
    }

    /**
     * 创建已完成邮箱验证的真实用户。
     *
     * @param string $userLogin 用户名。
     * @param string $userEmail 邮箱。
     * @param string $password  用户提交的新密码。
     *
     * @return int|WP_Error
     */
    private function createVerifiedUser(string $userLogin, string $userEmail, string $password): int|WP_Error
    {
        $this->creatingVerifiedUser = true;

        add_filter('purepress_skip_registration_rate_limit', '__return_true', 1000);
        add_filter('wp_send_new_user_notification_to_user', '__return_false', 1000);

        $userId = register_new_user($userLogin, $userEmail);

        remove_filter('wp_send_new_user_notification_to_user', '__return_false', 1000);
        remove_filter('purepress_skip_registration_rate_limit', '__return_true', 1000);

        $this->creatingVerifiedUser = false;

        if ($userId instanceof WP_Error) {
            return $userId;
        }

        $userId = (int) $userId;

        if ($userId <= 0) {
            return new WP_Error('purepress_registration_create_failed', '用户创建失败，请重新注册。');
        }

        wp_set_password($password, $userId);
        update_user_meta($userId, 'default_password_nag', false);

        return $userId;
    }

    /**
     * 发送注册邮箱验证邮件。
     *
     * @param string $userLogin 用户名。
     * @param string $email     收件邮箱。
     * @param string $token     明文 token。
     */
    private function sendVerificationEmail(string $userLogin, string $email, string $token): bool
    {
        $blogName = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $url = $this->verificationUrl($userLogin, $token);
        $expirationMinutes = $this->expirationMinutes();
        $message = sprintf('用户名：%s', $userLogin) . "\r\n\r\n";
        $message .= "请通过以下地址设置密码并完成注册：\r\n\r\n";
        $message .= $url . "\r\n\r\n";
        $message .= sprintf('此链接将在 %d 分钟后失效。', $expirationMinutes) . "\r\n";

        return wp_mail(
            $email,
            wp_specialchars_decode(sprintf('[%s] 注册邮箱验证', $blogName), ENT_QUOTES),
            $message
        );
    }

    /**
     * 渲染设置密码页面。
     *
     * @param array<string,mixed> $pending         待验证注册记录。
     * @param string              $token           明文 token。
     * @param WP_Error            $errors          表单错误。
     * @param bool                $frontendRequest 是否为 PurePress 前台验证入口。
     */
    private function renderPasswordSetupPage(array $pending, string $token, WP_Error $errors, bool $frontendRequest): never
    {
        $userLogin = isset($pending['user_login']) && is_scalar($pending['user_login']) ? (string) $pending['user_login'] : '';
        $userEmail = isset($pending['user_email']) && is_scalar($pending['user_email']) ? (string) $pending['user_email'] : '';
        $actionUrl = $frontendRequest
            ? add_query_arg(self::QUERY_VERIFY, '1', home_url('/'))
            : network_site_url('wp-login.php?action=resetpass', 'login_post');

        if (! $frontendRequest && function_exists('login_header')) {
            login_header('设置密码', '', $errors);
        } else {
            $this->renderPageHeader('设置密码');
            $this->renderRegistrationPageStyles();
        }
        ?>
        <?php if ($frontendRequest) : ?>
            <main class="purepress-registration-page">
                <section class="purepress-registration-card" aria-labelledby="purepress-registration-title">
                    <p class="purepress-registration-kicker">邮箱验证</p>
                    <h1 id="purepress-registration-title">设置登录密码</h1>
                    <p class="purepress-registration-summary">
                        邮箱验证已通过，请为账号 <?php echo esc_html($userLogin); ?> 设置登录密码，完成注册。
                    </p>
                    <?php if ($userEmail !== '') : ?>
                        <p class="purepress-registration-meta">验证邮箱：<?php echo esc_html($userEmail); ?></p>
                    <?php endif; ?>
                    <?php $this->renderPasswordSetupErrors($errors); ?>
                    <?php $this->renderPasswordSetupForm($actionUrl, $userLogin, $token, true); ?>
                </section>
            </main>
        <?php else : ?>
            <?php $this->renderPasswordSetupForm($actionUrl, $userLogin, $token, false); ?>
        <?php endif; ?>
        <?php
        if (! $frontendRequest && function_exists('login_footer')) {
            login_footer();
        } else {
            $this->renderPageFooter();
        }

        exit;
    }

    /**
     * 渲染注册状态页。
     *
     * @param string $status 状态代码。
     */
    private function renderStatusPage(string $status): void
    {
        $messages = [
            'pending' => [
                'title' => '验证邮件已发送',
                'body' => sprintf('请前往邮箱完成注册。验证链接将在 %d 分钟后失效。', $this->expirationMinutes()),
            ],
            'verified' => [
                'title' => '注册已完成',
                'body' => '你的账号已创建，现在可以登录。',
            ],
            'expired' => [
                'title' => '验证链接已失效',
                'body' => '请重新提交注册信息获取新的验证邮件。',
            ],
            'invalid' => [
                'title' => '验证链接无效',
                'body' => '请检查邮件链接，或重新提交注册信息。',
            ],
        ];

        if (! isset($messages[$status])) {
            return;
        }

        $this->renderPageHeader($messages[$status]['title']);
        $this->renderRegistrationPageStyles();
        ?>
        <main class="purepress-registration-page">
            <section class="purepress-registration-card" aria-labelledby="purepress-registration-status-title">
                <p class="purepress-registration-kicker">注册状态</p>
                <h1 id="purepress-registration-status-title"><?php echo esc_html($messages[$status]['title']); ?></h1>
                <p class="purepress-registration-summary"><?php echo esc_html($messages[$status]['body']); ?></p>
            <?php if ($status === 'verified') : ?>
                <p>
                    <a class="purepress-registration-button" href="<?php echo esc_url($this->verifiedActionUrl()); ?>"><?php echo esc_html($this->verifiedActionLabel()); ?></a>
                </p>
            <?php endif; ?>
            </section>
        </main>
        <?php
        $this->renderPageFooter();
        exit;
    }

    /**
     * 渲染设置密码表单。
     *
     * @param string $actionUrl       表单提交地址。
     * @param string $userLogin       用户名。
     * @param string $token           明文 token。
     * @param bool   $frontendRequest 是否为 PurePress 前台验证入口。
     */
    private function renderPasswordSetupForm(string $actionUrl, string $userLogin, string $token, bool $frontendRequest): void
    {
        $inputClass = $frontendRequest ? 'purepress-registration-input' : 'input';
        $buttonClass = $frontendRequest ? 'purepress-registration-button' : 'button button-primary';
        ?>
        <form class="<?php echo esc_attr($frontendRequest ? 'purepress-registration-form' : ''); ?>" method="post" action="<?php echo esc_url($actionUrl); ?>">
            <?php wp_nonce_field('purepress_complete_registration'); ?>
            <input type="hidden" name="purepress_pending_registration" value="1">
            <input type="hidden" name="login" value="<?php echo esc_attr($userLogin); ?>">
            <input type="hidden" name="key" value="<?php echo esc_attr($token); ?>">

            <p>
                <label for="purepress-pass1">新密码</label>
                <input id="purepress-pass1" class="<?php echo esc_attr($inputClass); ?>" type="password" name="pass1" autocomplete="new-password" required>
            </p>
            <p>
                <label for="purepress-pass2">确认新密码</label>
                <input id="purepress-pass2" class="<?php echo esc_attr($inputClass); ?>" type="password" name="pass2" autocomplete="new-password" required>
            </p>
            <p class="submit">
                <button class="<?php echo esc_attr($buttonClass); ?>" type="submit">完成注册</button>
            </p>
        </form>
        <?php
    }

    /**
     * 渲染设置密码错误提示。
     *
     * @param WP_Error $errors 表单错误。
     */
    private function renderPasswordSetupErrors(WP_Error $errors): void
    {
        if (! $errors->has_errors()) {
            return;
        }
        ?>
        <div class="purepress-registration-errors" role="alert">
            <?php foreach ($errors->get_error_messages() as $message) : ?>
                <p><?php echo esc_html($message); ?></p>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * 渲染注册验证前台页面样式。
     */
    private function renderRegistrationPageStyles(): void
    {
        ?>
        <style>
            .purepress-registration-page {
                width: min(100% - 32px, 760px);
                margin: 56px auto;
            }

            .purepress-registration-card {
                padding: 32px;
                border: 1px solid rgba(20, 24, 32, 0.12);
                border-radius: 8px;
                background: #fff;
                box-shadow: 0 18px 45px rgba(20, 24, 32, 0.08);
            }

            .purepress-registration-kicker {
                margin: 0 0 8px;
                color: #666;
                font-size: 13px;
                font-weight: 700;
            }

            .purepress-registration-card h1 {
                margin: 0;
                font-size: 28px;
                line-height: 1.25;
            }

            .purepress-registration-summary {
                margin: 12px 0 0;
                color: #333;
                font-size: 16px;
                line-height: 1.7;
            }

            .purepress-registration-meta {
                margin: 8px 0 0;
                color: #666;
                font-size: 14px;
            }

            .purepress-registration-form {
                margin-top: 24px;
            }

            .purepress-registration-form p {
                margin: 0 0 16px;
            }

            .purepress-registration-form label {
                display: block;
                margin-bottom: 6px;
                font-weight: 700;
            }

            .purepress-registration-input {
                width: 100%;
                min-height: 44px;
                box-sizing: border-box;
                border: 1px solid rgba(20, 24, 32, 0.18);
                border-radius: 6px;
                padding: 8px 12px;
                font: inherit;
            }

            .purepress-registration-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-height: 42px;
                border: 0;
                border-radius: 6px;
                padding: 0 18px;
                background: #1d2327;
                color: #fff;
                font-weight: 700;
                text-decoration: none;
                cursor: pointer;
            }

            .purepress-registration-button:hover,
            .purepress-registration-button:focus {
                background: #000;
                color: #fff;
            }

            .purepress-registration-errors {
                margin: 18px 0 0;
                border-left: 4px solid #d63638;
                padding: 10px 12px;
                background: #fcf0f1;
                color: #5c0f12;
            }

            .purepress-registration-errors p {
                margin: 0;
            }

            @media (max-width: 600px) {
                .purepress-registration-page {
                    width: min(100% - 24px, 760px);
                    margin: 32px auto;
                }

                .purepress-registration-card {
                    padding: 24px;
                }
            }
        </style>
        <?php
    }

    /**
     * 渲染前台页面头部。
     *
     * @param string $title 页面标题。
     */
    private function renderPageHeader(string $title): void
    {
        if (function_exists('status_header')) {
            status_header(200);
        }

        if (function_exists('get_header')) {
            get_header();
            return;
        }

        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title></head><body>';
    }

    /**
     * 渲染前台页面底部。
     */
    private function renderPageFooter(): void
    {
        if (function_exists('get_footer')) {
            get_footer();
            return;
        }

        echo '</body></html>';
    }

    /**
     * 读取提交的验证凭据。
     *
     * @return array{login: string, key: string}
     */
    private function submittedVerificationCredentials(): array
    {
        return [
            'login' => $this->requestString('login'),
            'key' => $this->requestString('key'),
        ];
    }

    /**
     * 校验设置密码表单。
     */
    private function passwordSubmissionErrors(): WP_Error
    {
        $errors = new WP_Error();
        $nonce = $this->requestString('_wpnonce');

        if (! function_exists('wp_verify_nonce') || ! wp_verify_nonce($nonce, 'purepress_complete_registration')) {
            $errors->add('purepress_invalid_nonce', '请求已失效，请重新打开邮件链接。');
            return $errors;
        }

        $pass1 = $this->requestString('pass1');
        $pass2 = $this->requestString('pass2');

        if ($pass1 === '') {
            $errors->add('password_reset_empty', '请输入新密码。');
        } elseif ($pass1 !== $pass2) {
            $errors->add('password_reset_mismatch', '两次输入的密码不一致。');
        }

        return $errors;
    }

    /**
     * 读取用户提交的新密码。
     */
    private function submittedPassword(): string
    {
        return $this->requestString('pass1');
    }

    /**
     * 判断是否为设置密码表单提交。
     */
    private function isPasswordSetupSubmission(): bool
    {
        return $this->requestString('purepress_pending_registration') === '1'
            && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    /**
     * 判断是否为 PurePress 前台验证入口请求。
     */
    private function isFrontendVerificationRequest(): bool
    {
        return $this->requestString(self::QUERY_VERIFY) === '1'
            || $this->requestString('purepress_pending_registration') === '1';
    }

    /**
     * 读取请求中的注册状态。
     */
    private function requestedStatus(): string
    {
        $status = $this->requestString(self::QUERY_STATUS);

        return in_array($status, ['pending', 'verified', 'expired', 'invalid'], true) ? $status : '';
    }

    /**
     * 判断是否应跳过注册拦截。
     */
    private function shouldSkipRegistrationInterception(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }

        if (! function_exists('is_admin') || ! is_admin()) {
            return false;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        return ! $this->isUnauthenticatedAdminPostRequest();
    }

    /**
     * 判断当前请求是否为未登录用户通过 admin-post.php 提交的前台表单。
     *
     * `admin-post.php` 属于 WordPress 管理入口，`is_admin()` 会返回 true；
     * 但它也常被主题用于承载前台注册表单，不能按后台管理页面处理。
     */
    private function isUnauthenticatedAdminPostRequest(): bool
    {
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        global $pagenow;

        if (isset($pagenow) && $pagenow === 'admin-post.php') {
            return true;
        }

        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $serverKey) {
            $script = $_SERVER[$serverKey] ?? '';

            if (! is_string($script)) {
                continue;
            }

            if ($script === 'admin-post.php' || str_ends_with($script, '/admin-post.php')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断当前注册请求是否可以直接跳转。
     */
    private function shouldRedirectPendingRegistration(): bool
    {
        if (headers_sent() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT'])
            ? strtolower($_SERVER['HTTP_ACCEPT'])
            : '';

        return ! str_contains($accept, 'application/json');
    }

    /**
     * 生成注册状态页 URL。
     *
     * @param string $status 状态代码。
     */
    private function statusUrl(string $status): string
    {
        return add_query_arg(self::QUERY_STATUS, $status, home_url('/'));
    }

    /**
     * 获取注册完成后的操作地址。
     */
    private function verifiedActionUrl(): string
    {
        return $this->loginAddressMode() === LoginAddressModule::MODE_DISABLED
            ? home_url('/')
            : wp_login_url();
    }

    /**
     * 获取注册完成后的操作文案。
     */
    private function verifiedActionLabel(): string
    {
        return $this->loginAddressMode() === LoginAddressModule::MODE_DISABLED ? '返回首页' : '前往登录';
    }

    /**
     * 生成注册验证 URL。
     *
     * @param string $userLogin 用户名。
     * @param string $token     明文 token。
     */
    private function verificationUrl(string $userLogin, string $token): string
    {
        if ($this->loginAddressMode() === LoginAddressModule::MODE_DISABLED) {
            return add_query_arg(
                [
                    self::QUERY_VERIFY => '1',
                    'login' => $userLogin,
                    'key' => $token,
                ],
                home_url('/')
            );
        }

        return network_site_url(
            'wp-login.php?action=rp&login=' . rawurlencode($userLogin) . '&key=' . rawurlencode($token),
            'login'
        );
    }

    /**
     * 获取登录入口控制模式。
     */
    private function loginAddressMode(): string
    {
        $settings = (new OptionRepository())->moduleSettings(LoginAddressModule::MODULE_ID);
        $mode = isset($settings['mode']) && is_scalar($settings['mode']) ? (string) $settings['mode'] : LoginAddressModule::MODE_DEFAULT;

        return in_array($mode, [LoginAddressModule::MODE_DEFAULT, LoginAddressModule::MODE_DISABLED, LoginAddressModule::MODE_CUSTOM], true)
            ? $mode
            : LoginAddressModule::MODE_DEFAULT;
    }

    /**
     * 获取待验证注册有效期分钟数。
     */
    private function expirationMinutes(): int
    {
        return $this->settingInt('expiration_minutes', 60, 5, 1440);
    }

    /**
     * 获取待验证注册有效期秒数。
     */
    private function expirationSeconds(): int
    {
        return $this->expirationMinutes() * MINUTE_IN_SECONDS;
    }

    /**
     * 读取注册请求附加上下文。
     *
     * @return array<string,mixed>
     */
    private function registrationMeta(): array
    {
        return [
            'request_uri' => isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
            'redirect_to' => $this->requestString('redirect_to'),
        ];
    }

    /**
     * 标准化用户名。
     *
     * @param string $userLogin 原始用户名。
     */
    private function normalizeLogin(string $userLogin): string
    {
        return function_exists('sanitize_user') ? sanitize_user($userLogin) : trim($userLogin);
    }

    /**
     * 标准化邮箱。
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
     * 读取请求字符串值。
     *
     * @param string $key 请求字段名。
     */
    private function requestString(string $key): string
    {
        $value = $_REQUEST[$key] ?? '';

        if (! is_scalar($value)) {
            return '';
        }

        $value = function_exists('wp_unslash') ? wp_unslash((string) $value) : (string) $value;

        return trim($value);
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
