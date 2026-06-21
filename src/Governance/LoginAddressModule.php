<?php
/**
 * 管理 WordPress 登录与注册地址。
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

final class LoginAddressModule implements ModuleInterface
{
    public const string MODULE_ID = 'governance.login_address';
    public const string MODE_DEFAULT = 'default';
    public const string MODE_DISABLED = 'disabled';
    public const string MODE_CUSTOM = 'custom';

    /**
     * 模块配置。
     *
     * @var array<string,mixed>
     */
    private array $settings = [];

    /**
     * 当前请求是否直接访问默认登录入口。
     */
    private bool $blockedLoginRequest = false;

    /**
     * 当前请求是否直接访问默认注册地址。
     */
    private bool $blockedSignupRequest = false;

    /**
     * 当前请求是否命中自定义登录地址。
     */
    private bool $customLoginRequest = false;

    /**
     * 当前请求是否命中自定义注册地址。
     */
    private bool $customSignupRequest = false;

    /**
     * 请求上下文被修改前的请求 URI。
     */
    private string $originalRequestUri = '';

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册登录与注册地址治理 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        if ($this->mode() === self::MODE_DEFAULT) {
            return;
        }

        $this->inspectCurrentRequest();

        $hooks->action('init', [$this, 'registerRewriteRules'], 0);
        $hooks->action('wp_loaded', [$this, 'handleEntryRequest'], 0);
        $hooks->filter('site_url', [$this, 'filterSiteUrl'], 20, 4);
        $hooks->filter('network_site_url', [$this, 'filterNetworkSiteUrl'], 20, 3);
        $hooks->filter('wp_redirect', [$this, 'filterRedirectUrl'], 20, 2);
        $hooks->filter('wp_signup_location', [$this, 'filterSignupLocation']);
    }

    /**
     * 注册自定义登录和注册地址重写规则。
     */
    public function registerRewriteRules(): void
    {
        self::addRewriteRulesForSettings($this->settings);
    }

    /**
     * 根据指定配置注册自定义登录和注册地址重写规则。
     *
     * @param array<string,mixed> $settings 登录入口配置。
     */
    public static function addRewriteRulesForSettings(array $settings): void
    {
        $mode = isset($settings['mode']) && is_scalar($settings['mode']) ? (string) $settings['mode'] : self::MODE_DEFAULT;

        if ($mode !== self::MODE_CUSTOM || ! function_exists('add_rewrite_rule')) {
            return;
        }

        $loginPath = trim((string) ($settings['login_path'] ?? 'login'), '/');
        $signupPath = trim((string) ($settings['signup_path'] ?? 'signup'), '/');

        if ($loginPath === '' || $signupPath === '') {
            return;
        }

        add_rewrite_rule(
            self::rewriteRulePattern($loginPath),
            'index.php?error=404',
            'top'
        );
        add_rewrite_rule(
            self::rewriteRulePattern($signupPath),
            'index.php?error=404',
            'top'
        );
    }

    /**
     * 从当前 WordPress 重写对象中移除指定配置对应的规则。
     *
     * @param array<string,mixed> $settings 登录入口配置。
     */
    public static function removeRewriteRulesForSettings(array $settings): void
    {
        global $wp_rewrite;

        if (
            ! isset($wp_rewrite)
            || ! isset($wp_rewrite->extra_rules_top)
            || ! is_array($wp_rewrite->extra_rules_top)
        ) {
            return;
        }

        foreach (['login_path', 'signup_path'] as $pathKey) {
            $path = isset($settings[$pathKey]) && is_scalar($settings[$pathKey])
                ? trim((string) $settings[$pathKey], '/')
                : '';

            if ($path !== '') {
                unset($wp_rewrite->extra_rules_top[self::rewriteRulePattern($path)]);
            }
        }
    }

    /**
     * 处理被屏蔽的 Core 入口或加载自定义入口对应的 Core 程序。
     */
    public function handleEntryRequest(): void
    {
        if ($this->blockedLoginRequest) {
            if ($this->mode() === self::MODE_DISABLED && $this->canUseDefaultLogoutRequest()) {
                $this->restoreLoginRequestContext();
                return;
            }

            $this->renderNotFound();
        }

        if ($this->blockedSignupRequest) {
            $this->renderNotFound();
        }

        if ($this->customLoginRequest) {
            require ABSPATH . 'wp-login.php';
            exit;
        }

        if ($this->customSignupRequest) {
            ob_start();
            require ABSPATH . 'wp-signup.php';
            $output = ob_get_clean();

            if (is_string($output)) {
                echo $this->rewriteSignupFormActions($output);
            }

            exit;
        }
    }

    /**
     * 替换 `site_url()` 生成的默认登录或注册地址。
     *
     * @param string      $url     WordPress 已生成的 URL。
     * @param string      $path    请求的相对路径。
     * @param string|null $scheme  URL 协议上下文。
     * @param int|null    $blogId  站点 ID。
     */
    public function filterSiteUrl(string $url, string $path, ?string $scheme, ?int $blogId): string
    {
        unset($path, $scheme, $blogId);

        return $this->filterCoreEntryUrl($url);
    }

    /**
     * 替换 `network_site_url()` 生成的默认登录或注册地址。
     *
     * @param string      $url    WordPress 已生成的 URL。
     * @param string      $path   请求的相对路径。
     * @param string|null $scheme URL 协议上下文。
     */
    public function filterNetworkSiteUrl(string $url, string $path, ?string $scheme): string
    {
        unset($path, $scheme);

        return $this->filterCoreEntryUrl($url);
    }

    /**
     * 替换重定向目标中的默认登录或注册地址。
     *
     * @param string $location 重定向目标。
     * @param int    $status   HTTP 状态码。
     */
    public function filterRedirectUrl(string $location, int $status): string
    {
        unset($status);

        return $this->filterCoreEntryUrl($location);
    }

    /**
     * 替换 Multisite 默认注册地址。
     *
     * @param string $location 默认注册地址。
     */
    public function filterSignupLocation(string $location): string
    {
        if ($this->mode() === self::MODE_CUSTOM) {
            return $this->signupUrl();
        }

        return $this->mode() === self::MODE_DISABLED ? network_home_url('/') : $location;
    }

    /**
     * 检查当前请求并调整 WordPress 入口上下文。
     */
    private function inspectCurrentRequest(): void
    {
        global $pagenow;

        $requestPath = $this->requestPath();

        if ($requestPath === '') {
            return;
        }

        if ($this->mode() === self::MODE_CUSTOM && $requestPath === $this->relativeHomePath($this->loginPath())) {
            $this->customLoginRequest = true;
            $_SERVER['SCRIPT_NAME'] = '/' . $this->loginPath();
            $_SERVER['PHP_SELF'] = '/' . $this->loginPath();
            $pagenow = 'wp-login.php';

            return;
        }

        if ($this->mode() === self::MODE_CUSTOM && $requestPath === $this->relativeHomePath($this->signupPath())) {
            $this->customSignupRequest = true;
            $_SERVER['SCRIPT_NAME'] = '/wp-signup.php';
            $_SERVER['PHP_SELF'] = '/wp-signup.php';
            $pagenow = 'wp-signup.php';

            return;
        }

        if ($this->isPostPasswordRequest()) {
            return;
        }

        if ($this->isDefaultLoginPath($requestPath)) {
            $this->blockedLoginRequest = true;
            $this->hideCoreEntryRequest();

            return;
        }

        if ($this->isDefaultSignupPath($requestPath)) {
            $this->blockedSignupRequest = true;
            $this->hideCoreEntryRequest();
        }
    }

    /**
     * 将默认入口请求暂时伪装为普通前台请求。
     */
    private function hideCoreEntryRequest(): void
    {
        global $pagenow;

        $this->originalRequestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '';
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('-/', 10);
        $pagenow = 'index.php';
    }

    /**
     * 在关闭模式下恢复合法退出请求的默认入口上下文。
     */
    private function restoreLoginRequestContext(): void
    {
        global $pagenow;

        if ($this->originalRequestUri !== '') {
            $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
        }

        $pagenow = 'wp-login.php';
    }

    /**
     * 判断关闭模式下的默认入口请求是否为合法退出请求。
     */
    private function canUseDefaultLogoutRequest(): bool
    {
        $action = $this->requestAction();
        $nonce = $_REQUEST['_wpnonce'] ?? '';

        if (
            $action !== 'logout'
            || ! function_exists('is_user_logged_in')
            || ! is_user_logged_in()
            || ! is_scalar($nonce)
        ) {
            return false;
        }

        $nonce = function_exists('wp_unslash') ? wp_unslash((string) $nonce) : (string) $nonce;

        return function_exists('wp_verify_nonce') && (bool) wp_verify_nonce($nonce, 'log-out');
    }

    /**
     * 判断当前请求是否为密码保护文章提交。
     */
    private function isPostPasswordRequest(): bool
    {
        return $this->requestAction() === 'postpass' && isset($_POST['post_password']);
    }

    /**
     * 读取当前登录动作。
     */
    private function requestAction(): string
    {
        $action = $_REQUEST['action'] ?? '';

        if (! is_scalar($action)) {
            return '';
        }

        $action = function_exists('wp_unslash') ? wp_unslash((string) $action) : (string) $action;

        return function_exists('sanitize_key') ? sanitize_key($action) : strtolower(trim($action));
    }

    /**
     * 判断请求路径是否为 WordPress 默认登录入口。
     *
     * @param string $requestPath 当前请求路径。
     */
    private function isDefaultLoginPath(string $requestPath): bool
    {
        $corePaths = [
            $this->relativeSitePath('wp-login.php'),
            $this->relativeHomePath('wp-login.php'),
        ];
        $aliasPaths = [
            $this->relativeSitePath('login.php'),
            $this->relativeSitePath('login'),
            $this->relativeHomePath('login.php'),
            $this->relativeHomePath('login'),
        ];

        foreach (array_values(array_unique($corePaths)) as $corePath) {
            if ($requestPath === $corePath || str_starts_with($requestPath, $corePath . '/')) {
                return true;
            }
        }

        return in_array($requestPath, array_values(array_unique($aliasPaths)), true);
    }

    /**
     * 判断请求路径是否为 WordPress 默认注册地址。
     *
     * @param string $requestPath 当前请求路径。
     */
    private function isDefaultSignupPath(string $requestPath): bool
    {
        $corePaths = array_values(
            array_unique(
                [
                    $this->relativeSitePath('wp-signup.php'),
                    $this->relativeHomePath('wp-signup.php'),
                ]
            )
        );

        foreach ($corePaths as $corePath) {
            if ($requestPath === $corePath || str_starts_with($requestPath, $corePath . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 替换 URL 中的 Core 入口文件。
     *
     * @param string $url 原始 URL。
     */
    private function filterCoreEntryUrl(string $url): string
    {
        if ($this->urlTargetsEntry($url, 'wp-login.php')) {
            if ($this->isPostPasswordUrl($url)) {
                return $url;
            }

            if ($this->mode() === self::MODE_DISABLED) {
                return $this->isLogoutUrl($url) ? $url : home_url('/');
            }

            return $this->replaceEntryFilename($url, 'wp-login.php', $this->loginPath());
        }

        if ($this->urlTargetsEntry($url, 'wp-signup.php')) {
            if ($this->mode() === self::MODE_DISABLED) {
                return network_home_url('/');
            }

            return $this->replaceEntryFilename($url, 'wp-signup.php', $this->signupPath());
        }

        return $url;
    }

    /**
     * 判断 URL 路径是否指向指定 Core 入口文件。
     *
     * @param string $url      待检查 URL。
     * @param string $filename Core 入口文件名。
     */
    private function urlTargetsEntry(string $url, string $filename): bool
    {
        $path = wp_parse_url(html_entity_decode($url), PHP_URL_PATH);

        if (! is_string($path)) {
            return false;
        }

        $path = rtrim(rawurldecode($path), '/');

        return str_ends_with($path, '/' . $filename) || $path === $filename;
    }

    /**
     * 将 URL 路径末尾的入口文件替换为自定义路径。
     *
     * @param string $url      原始 URL。
     * @param string $filename Core 入口文件名。
     * @param string $path     自定义路径。
     */
    private function replaceEntryFilename(string $url, string $filename, string $path): string
    {
        $parts = preg_split('/([?#])/', $url, 2, PREG_SPLIT_DELIM_CAPTURE);

        if (! is_array($parts) || $parts === []) {
            return $url;
        }

        $parts[0] = (string) preg_replace(
            '#' . preg_quote($filename, '#') . '$#',
            $path,
            $parts[0]
        );

        return implode('', $parts);
    }

    /**
     * 判断 URL 是否为密码保护文章提交地址。
     *
     * @param string $url 原始 URL。
     */
    private function isPostPasswordUrl(string $url): bool
    {
        return str_contains(html_entity_decode($url), 'action=postpass');
    }

    /**
     * 判断 URL 是否为退出登录地址。
     *
     * @param string $url 原始 URL。
     */
    private function isLogoutUrl(string $url): bool
    {
        return str_contains(html_entity_decode($url), 'action=logout');
    }

    /**
     * 将 Multisite 注册表单提交地址改为自定义注册地址。
     *
     * @param string $output WordPress 注册页面输出。
     */
    private function rewriteSignupFormActions(string $output): string
    {
        $signupUrl = esc_url($this->signupUrl());

        $output = str_replace(
            [
                'action="wp-signup.php"',
                "action='wp-signup.php'",
            ],
            [
                'action="' . $signupUrl . '"',
                "action='" . $signupUrl . "'",
            ],
            $output
        );

        return str_replace('wp-login.php', $this->loginPath(), $output);
    }

    /**
     * 返回 404 并终止默认入口请求。
     */
    private function renderNotFound(): never
    {
        if (function_exists('status_header')) {
            status_header(404);
        }

        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'Not Found';
        exit;
    }

    /**
     * 获取当前请求的标准化路径。
     */
    private function requestPath(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? rawurldecode($_SERVER['REQUEST_URI'])
            : '';
        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        return is_string($path) ? $this->normalizeUrlPath($path) : '';
    }

    /**
     * 获取相对站点 URL 路径。
     *
     * @param string $path 站点内相对路径。
     */
    private function relativeSitePath(string $path): string
    {
        $urlPath = wp_parse_url(site_url($path, 'relative'), PHP_URL_PATH);

        return is_string($urlPath) ? $this->normalizeUrlPath($urlPath) : '';
    }

    /**
     * 获取相对首页 URL 路径。
     *
     * @param string $path 首页下相对路径。
     */
    private function relativeHomePath(string $path): string
    {
        $urlPath = wp_parse_url(home_url('/' . ltrim($path, '/'), 'relative'), PHP_URL_PATH);

        return is_string($urlPath) ? $this->normalizeUrlPath($urlPath) : '';
    }

    /**
     * 标准化 URL 路径，使有无末尾斜杠视为同一路径。
     *
     * @param string $path 原始 URL 路径。
     */
    private function normalizeUrlPath(string $path): string
    {
        $path = preg_replace('#/+#', '/', str_replace('\\', '/', $path));

        return '/' . trim((string) $path, '/');
    }

    /**
     * 获取模块模式。
     */
    private function mode(): string
    {
        $mode = isset($this->settings['mode']) && is_scalar($this->settings['mode'])
            ? (string) $this->settings['mode']
            : self::MODE_DEFAULT;

        return in_array($mode, [self::MODE_DEFAULT, self::MODE_DISABLED, self::MODE_CUSTOM], true)
            ? $mode
            : self::MODE_DEFAULT;
    }

    /**
     * 获取自定义登录路径。
     */
    private function loginPath(): string
    {
        return trim((string) ($this->settings['login_path'] ?? 'login'), '/');
    }

    /**
     * 获取自定义注册地址。
     */
    private function signupPath(): string
    {
        return trim((string) ($this->settings['signup_path'] ?? 'signup'), '/');
    }

    /**
     * 获取自定义注册 URL。
     */
    private function signupUrl(): string
    {
        return network_site_url('/' . $this->signupPath());
    }

    /**
     * 生成兼容有无末尾斜杠的重写规则表达式。
     *
     * @param string $path 已标准化的站内相对路径。
     */
    private static function rewriteRulePattern(string $path): string
    {
        return '^' . preg_quote($path, '#') . '/?$';
    }
}
