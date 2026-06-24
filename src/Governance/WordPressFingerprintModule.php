<?php
/**
 * 隐藏常见 WordPress 识别特征。
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

final class WordPressFingerprintModule implements ModuleInterface
{
    private const string PUBLIC_CORE_PATH = '/core/';
    private const string PUBLIC_THEMES_PATH = '/themes/';
    private const string PUBLIC_ADMIN_PATH = '/console/';

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return 'governance.wordpress_fingerprint';
    }

    /**
     * 注册 WordPress 特征隐藏相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('wp', [$this, 'removeFrontendGenerator']);
        $hooks->action('init', [$this, 'bootstrapPublicAdminAuthCookie'], 0);
        $hooks->action('admin_init', [$this, 'disableAdminAssetConcatenation'], 0);
        $hooks->filter('admin_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('network_admin_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('user_admin_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('self_admin_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('site_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('network_site_url', [$this, 'rewriteAdminUrl']);
        $hooks->filter('wp_redirect', [$this, 'rewriteAdminUrl']);
        $hooks->filter('send_auth_cookies', [$this, 'syncPublicAdminAuthCookie'], PHP_INT_MAX, 6);
        $hooks->filter('robots_txt', [$this, 'removeAdminPathFromRobots']);
        $hooks->filter('includes_url', [$this, 'rewriteAssetUrl']);
        $hooks->filter('content_url', [$this, 'rewriteAssetUrl']);
        $hooks->filter('theme_file_uri', [$this, 'rewriteAssetUrl']);
        $hooks->filter('stylesheet_directory_uri', [$this, 'rewriteAssetUrl']);
        $hooks->filter('template_directory_uri', [$this, 'rewriteAssetUrl']);
        $hooks->filter('stylesheet_uri', [$this, 'rewriteAssetUrl']);
        $hooks->filter('script_loader_src', [$this, 'rewriteAssetUrl']);
        $hooks->filter('style_loader_src', [$this, 'rewriteAssetUrl']);
        $hooks->filter('script_module_loader_src', [$this, 'rewriteAssetUrl']);
    }

    /**
     * 移除前台 HTML head 中的 WordPress generator 元数据。
     */
    public function removeFrontendGenerator(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        remove_action('wp_head', 'wp_generator');
    }

    /**
     * 关闭后台脚本和样式合并，避免 WordPress Core 输出硬编码的 `/wp-admin/load-*` 地址。
     */
    public function disableAdminAssetConcatenation(): void
    {
        global $concatenate_scripts;

        if (! function_exists('is_admin') || ! is_admin()) {
            return;
        }

        $concatenate_scripts = false;
    }

    /**
     * 将 WordPress 后台地址改写为固定公开路径。
     *
     * @param string $url WordPress 原始后台 URL。
     */
    public function rewriteAdminUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts) || ! isset($parts['path'])) {
            return $url;
        }

        $rewrittenPath = $this->rewriteAdminPath((string) $parts['path']);

        if ($rewrittenPath === $parts['path']) {
            return $url;
        }

        $parts['path'] = $rewrittenPath;

        return $this->buildUrl($parts);
    }

    /**
     * 移除 robots.txt 中默认暴露的 `/wp-admin/` 规则。
     *
     * @param string $output WordPress 已生成的 robots.txt 内容。
     */
    public function removeAdminPathFromRobots(string $output): string
    {
        $lines = preg_split('/\R/', $output);

        if (! is_array($lines)) {
            return $output;
        }

        $lines = array_values(
            array_filter(
                $lines,
                static fn (string $line): bool => ! str_contains($line, '/wp-admin/')
            )
        );

        return implode("\n", $lines);
    }

    /**
     * 为已有登录会话补齐公开后台地址所需的认证 Cookie。
     *
     * 插件更新前已经登录的用户通常只有 `/wp-admin` path 下的后台认证 Cookie。
     * 访问 `/console` 时，浏览器不会发送旧 path 的 Cookie，因此这里通过已存在且有效的前台登录 Cookie
     * 在当前请求内补齐后台认证 Cookie，避免被 auth_redirect 误判为未登录。
     */
    public function bootstrapPublicAdminAuthCookie(): void
    {
        if (! $this->isPublicAdminRequest() || ! defined('LOGGED_IN_COOKIE')) {
            return;
        }

        $scheme = $this->currentAuthCookieScheme();
        $cookieName = $this->authCookieNameForScheme($scheme);

        if ($cookieName === '' || $this->cookieValue($cookieName) !== '') {
            return;
        }

        $loggedInCookie = $this->cookieValue(LOGGED_IN_COOKIE);

        if (
            $loggedInCookie === ''
            || ! function_exists('wp_validate_auth_cookie')
            || ! function_exists('wp_parse_auth_cookie')
            || ! function_exists('wp_generate_auth_cookie')
            || ! defined('COOKIE_DOMAIN')
        ) {
            return;
        }

        $userId = wp_validate_auth_cookie($loggedInCookie, 'logged_in');
        $cookieParts = wp_parse_auth_cookie($loggedInCookie, 'logged_in');

        if (! is_int($userId) || ! is_array($cookieParts)) {
            return;
        }

        $expiration = isset($cookieParts['expiration']) && is_scalar($cookieParts['expiration'])
            ? (int) $cookieParts['expiration']
            : 0;
        $token = isset($cookieParts['token']) && is_scalar($cookieParts['token'])
            ? (string) $cookieParts['token']
            : '';

        if ($expiration <= time() || $token === '') {
            return;
        }

        $authCookie = wp_generate_auth_cookie($userId, $expiration, $scheme, $token);

        if ($authCookie === '') {
            return;
        }

        $_COOKIE[$cookieName] = $authCookie;
        $this->setPublicAdminAuthCookie($cookieName, $authCookie, 0, $scheme === 'secure_auth');
    }

    /**
     * 同步公开后台地址所需的认证 Cookie。
     *
     * WordPress 默认后台认证 Cookie 的 path 是 `/wp-admin`，访问 `/console` 时浏览器不会携带该 Cookie。
     * 这里复用 WordPress 生成认证 Cookie 的参数，仅额外补充公开后台路径下的同名 Cookie。
     *
     * @param bool   $send       是否允许 WordPress 发送认证 Cookie。
     * @param int    $expire     Cookie 浏览器过期时间，0 表示会话 Cookie。
     * @param int    $expiration WordPress 认证 Cookie 内部过期时间。
     * @param int    $userId     用户 ID，清理 Cookie 时为 0。
     * @param string $scheme     认证 Cookie 类型，可为 auth 或 secure_auth。
     * @param string $token      当前登录会话 Token。
     */
    public function syncPublicAdminAuthCookie(
        bool $send,
        int $expire,
        int $expiration,
        int $userId,
        string $scheme,
        string $token
    ): bool {
        if (! $send) {
            return $send;
        }

        if ($userId === 0 && $expiration === 0 && $scheme === '' && $token === '') {
            $this->clearPublicAdminAuthCookies();
            return $send;
        }

        $cookieName = $this->authCookieNameForScheme($scheme);

        if (
            $cookieName === ''
            || $userId <= 0
            || $expiration <= 0
            || $token === ''
            || ! function_exists('wp_generate_auth_cookie')
            || ! defined('COOKIE_DOMAIN')
        ) {
            return $send;
        }

        $authCookie = wp_generate_auth_cookie($userId, $expiration, $scheme, $token);

        if ($authCookie === '') {
            return $send;
        }

        $this->setPublicAdminAuthCookie(
            $cookieName,
            $authCookie,
            $expire,
            $scheme === 'secure_auth'
        );

        return $send;
    }

    /**
     * 将前台输出中的 WordPress 核心与主题资源路径改写为无 wp 前缀路径。
     *
     * @param string $url WordPress 原始资源 URL。
     */
    public function rewriteAssetUrl(string $url): string
    {
        $adminUrl = $this->rewriteAdminUrl($url);

        if ($adminUrl !== $url) {
            return $adminUrl;
        }

        if (function_exists('is_admin') && is_admin()) {
            return $url;
        }

        if ($url === '' || ! defined('WPINC')) {
            return $url;
        }

        $parts = wp_parse_url($url);

        if (! is_array($parts) || ! isset($parts['path'])) {
            return $url;
        }

        $rewrittenPath = $this->rewriteAssetPath($parts['path']);

        if ($rewrittenPath === $parts['path']) {
            return $url;
        }

        $parts['path'] = $rewrittenPath;

        return $this->buildUrl($parts);
    }

    /**
     * 将 WordPress 资源路径改写为公开虚拟路径。
     *
     * @param string $path URL path 部分。
     */
    private function rewriteAssetPath(string $path): string
    {
        if (defined('WPINC')) {
            $corePath = '/' . trim(WPINC, '/') . '/';

            if (str_contains($path, $corePath)) {
                return (string) preg_replace(
                    '#/' . preg_quote(trim(WPINC, '/'), '#') . '/#',
                    self::PUBLIC_CORE_PATH,
                    $path,
                    1
                );
            }
        }

        $themesPath = $this->themeUrlPath();

        if ($themesPath !== '' && str_contains($path, $themesPath)) {
            return (string) preg_replace(
                '#' . preg_quote($themesPath, '#') . '#',
                self::PUBLIC_THEMES_PATH,
                $path,
                1
            );
        }

        return $path;
    }

    /**
     * 将 URL path 中的 `/wp-admin` 段替换为固定后台公开路径。
     *
     * @param string $path URL path 部分。
     */
    private function rewriteAdminPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        return (string) preg_replace(
            '#(^|/)wp-admin(?=/|$)#',
            '$1' . trim(self::PUBLIC_ADMIN_PATH, '/'),
            $path,
            1
        );
    }

    /**
     * 获取公开后台地址对应的 Cookie path。
     */
    private function publicAdminCookiePath(): string
    {
        $siteCookiePath = defined('SITECOOKIEPATH') ? SITECOOKIEPATH : '/';

        return rtrim((string) $siteCookiePath, '/') . '/' . trim(self::PUBLIC_ADMIN_PATH, '/');
    }

    /**
     * 判断当前请求是否命中公开后台地址。
     */
    private function isPublicAdminRequest(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if (! is_string($requestUri) || $requestUri === '') {
            return false;
        }

        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return false;
        }

        return preg_match(
            '#(^|/)' . preg_quote(trim(self::PUBLIC_ADMIN_PATH, '/'), '#') . '(?=/|$)#',
            rawurldecode($path)
        ) === 1;
    }

    /**
     * 读取当前请求应使用的后台认证 Cookie 类型。
     */
    private function currentAuthCookieScheme(): string
    {
        $isSecureRequest = function_exists('is_ssl') && is_ssl();
        $forceSecureAdmin = function_exists('force_ssl_admin') && force_ssl_admin();

        return $isSecureRequest || $forceSecureAdmin ? 'secure_auth' : 'auth';
    }

    /**
     * 根据 WordPress 认证类型读取对应 Cookie 名称。
     *
     * @param string $scheme WordPress 认证类型。
     */
    private function authCookieNameForScheme(string $scheme): string
    {
        if ($scheme === 'secure_auth' && defined('SECURE_AUTH_COOKIE')) {
            return SECURE_AUTH_COOKIE;
        }

        if ($scheme === 'auth' && defined('AUTH_COOKIE')) {
            return AUTH_COOKIE;
        }

        return '';
    }

    /**
     * 读取指定 Cookie 的字符串值。
     *
     * @param string $cookieName Cookie 名称。
     */
    private function cookieValue(string $cookieName): string
    {
        $value = $_COOKIE[$cookieName] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * 写入公开后台路径下的后台认证 Cookie。
     *
     * @param string $cookieName Cookie 名称。
     * @param string $value      Cookie 值。
     * @param int    $expire     Cookie 浏览器过期时间，0 表示会话 Cookie。
     * @param bool   $secure     是否仅允许 HTTPS 传输。
     */
    private function setPublicAdminAuthCookie(string $cookieName, string $value, int $expire, bool $secure): void
    {
        if (! defined('COOKIE_DOMAIN')) {
            return;
        }

        setcookie(
            $cookieName,
            $value,
            $expire,
            $this->publicAdminCookiePath(),
            COOKIE_DOMAIN,
            $secure,
            true
        );
    }

    /**
     * 清理公开后台路径下的认证 Cookie。
     */
    private function clearPublicAdminAuthCookies(): void
    {
        if (! defined('COOKIE_DOMAIN')) {
            return;
        }

        $past = time() - (defined('YEAR_IN_SECONDS') ? YEAR_IN_SECONDS : 31536000);

        foreach ($this->authCookieNames() as $cookieName) {
            setcookie($cookieName, ' ', $past, $this->publicAdminCookiePath(), COOKIE_DOMAIN);
        }
    }

    /**
     * 获取所有后台认证 Cookie 名称。
     *
     * @return array<int,string>
     */
    private function authCookieNames(): array
    {
        $cookieNames = [];

        if (defined('AUTH_COOKIE')) {
            $cookieNames[] = AUTH_COOKIE;
        }

        if (defined('SECURE_AUTH_COOKIE')) {
            $cookieNames[] = SECURE_AUTH_COOKIE;
        }

        return array_values(array_unique($cookieNames));
    }

    /**
     * 获取主题资源在 URL 中的原始路径前缀。
     */
    private function themeUrlPath(): string
    {
        if (! defined('WP_CONTENT_URL')) {
            return '/wp-content/themes/';
        }

        $contentPath = wp_parse_url(WP_CONTENT_URL, PHP_URL_PATH);

        if (! is_string($contentPath) || $contentPath === '') {
            return '/wp-content/themes/';
        }

        return '/' . trim($contentPath, '/') . '/themes/';
    }

    /**
     * 根据 wp_parse_url 的解析结果重新组装 URL。
     *
     * @param array<string, int|string> $parts URL 组成部分。
     */
    private function buildUrl(array $parts): string
    {
        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . ':';
        }

        if (isset($parts['host'])) {
            $url .= '//';

            if (isset($parts['user'])) {
                $url .= $parts['user'];

                if (isset($parts['pass'])) {
                    $url .= ':' . $parts['pass'];
                }

                $url .= '@';
            }

            $url .= $parts['host'];

            if (isset($parts['port'])) {
                $url .= ':' . $parts['port'];
            }
        }

        $url .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }
}
