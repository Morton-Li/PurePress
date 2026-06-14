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

    /**
     * 允许通过虚拟资源路径访问的静态文件类型。
     *
     * @var array<string, string>
     */
    private const array ASSET_MIME_TYPES = [
        'avif' => 'image/avif',
        'css' => 'text/css; charset=utf-8',
        'eot' => 'application/vnd.ms-fontobject',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml; charset=utf-8',
        'ttf' => 'font/ttf',
        'wasm' => 'application/wasm',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

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
        $hooks->action('init', [$this, 'serveAsset'], 0);
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
     * 将前台输出中的 WordPress 核心与主题资源路径改写为无 wp 前缀路径。
     *
     * @param string $url WordPress 原始资源 URL。
     */
    public function rewriteAssetUrl(string $url): string
    {
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
     * 响应虚拟资源路径，将其映射回 WordPress 真实静态资源。
     */
    public function serveAsset(): void
    {
        $requestPath = $this->requestPath();

        if (! in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
            if ($this->isVirtualAssetRequest($requestPath)) {
                $this->sendAssetMethodNotAllowed();
            }

            return;
        }

        if (str_starts_with($requestPath, self::PUBLIC_CORE_PATH)) {
            $this->serveMappedAsset($requestPath, self::PUBLIC_CORE_PATH, $this->coreRootPath());
        }

        if (str_starts_with($requestPath, self::PUBLIC_THEMES_PATH)) {
            $this->serveMappedAsset($requestPath, self::PUBLIC_THEMES_PATH, $this->themeRootPath());
        }
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
                    $this->publicAssetPath(self::PUBLIC_CORE_PATH),
                    $path,
                    1
                );
            }
        }

        $themesPath = $this->themeUrlPath();

        if ($themesPath !== '' && str_contains($path, $themesPath)) {
            return (string) preg_replace(
                '#' . preg_quote($themesPath, '#') . '#',
                $this->publicAssetPath(self::PUBLIC_THEMES_PATH),
                $path,
                1
            );
        }

        return $path;
    }

    /**
     * 响应已识别的虚拟资源路径。
     *
     * @param string      $requestPath 当前请求路径。
     * @param string      $publicPath  公开虚拟路径前缀。
     * @param string|null $rootPath    真实资源根目录。
     */
    private function serveMappedAsset(string $requestPath, string $publicPath, ?string $rootPath): void
    {
        if ($rootPath === null) {
            $this->sendAssetNotFound();
        }

        $relativePath = rawurldecode(substr($requestPath, strlen($publicPath)));
        $relativePath = str_replace('\\', '/', $relativePath);

        if (! $this->isSafeAssetPath($relativePath)) {
            $this->sendAssetNotFound();
        }

        $assetPath = realpath($rootPath . DIRECTORY_SEPARATOR . $relativePath);

        if (! is_string($assetPath) || ! is_file($assetPath) || ! str_starts_with($assetPath, $rootPath . DIRECTORY_SEPARATOR)) {
            $this->sendAssetNotFound();
        }

        $this->sendAssetFile($assetPath);
    }

    /**
     * 获取当前请求路径，并兼容 WordPress 安装在子目录的场景。
     */
    private function requestPath(): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = wp_parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        $homePath = wp_parse_url(home_url('/'), PHP_URL_PATH);

        if (is_string($homePath) && $homePath !== '/') {
            $homePath = '/' . trim($homePath, '/');

            if (str_starts_with($path, $homePath . '/')) {
                $path = substr($path, strlen($homePath));
            }
        }

        $frontControllerPath = '/index.php/';

        if (str_starts_with($path, $frontControllerPath)) {
            $path = '/' . substr($path, strlen($frontControllerPath));
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * 获取公开资源路径。
     *
     * 当站点永久链接结构使用 index.php 前端控制器时，输出 /index.php/{path}，
     * 避免服务器静态资源规则直接拦截虚拟资源路径导致 PHP 映射逻辑无法执行。
     *
     * @param string $publicPath 公开虚拟路径前缀。
     */
    private function publicAssetPath(string $publicPath): string
    {
        if (function_exists('get_option')) {
            $permalinkStructure = get_option('permalink_structure');

            if (is_string($permalinkStructure) && str_starts_with($permalinkStructure, '/index.php/')) {
                return '/index.php' . $publicPath;
            }
        }

        return $publicPath;
    }

    /**
     * 获取 WordPress 核心资源真实根路径。
     */
    private function coreRootPath(): ?string
    {
        if (! defined('ABSPATH') || ! defined('WPINC')) {
            return null;
        }

        $coreRoot = realpath(ABSPATH . trim(WPINC, '/'));

        return is_string($coreRoot) ? $coreRoot : null;
    }

    /**
     * 获取 WordPress 主题资源真实根路径。
     */
    private function themeRootPath(): ?string
    {
        if (function_exists('get_theme_root')) {
            $themeRoot = realpath(get_theme_root());

            if (is_string($themeRoot)) {
                return $themeRoot;
            }
        }

        if (! defined('WP_CONTENT_DIR')) {
            return null;
        }

        $themeRoot = realpath(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'themes');

        return is_string($themeRoot) ? $themeRoot : null;
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
     * 判断当前请求是否为 PurePress 虚拟资源请求。
     *
     * @param string $requestPath 当前请求路径。
     */
    private function isVirtualAssetRequest(string $requestPath): bool
    {
        return str_starts_with($requestPath, self::PUBLIC_CORE_PATH)
            || str_starts_with($requestPath, self::PUBLIC_THEMES_PATH);
    }

    /**
     * 判断资源相对路径是否安全且属于允许输出的静态文件类型。
     *
     * @param string $relativePath 资源相对路径。
     */
    private function isSafeAssetPath(string $relativePath): bool
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/')) {
            return false;
        }

        $segments = explode('/', $relativePath);

        if (in_array('..', $segments, true)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));

        return isset(self::ASSET_MIME_TYPES[$extension]);
    }

    /**
     * 输出核心静态资源文件。
     *
     * @param string $assetPath 已通过安全校验的真实文件路径。
     */
    private function sendAssetFile(string $assetPath): void
    {
        $extension = strtolower((string) pathinfo($assetPath, PATHINFO_EXTENSION));
        $mimeType = self::ASSET_MIME_TYPES[$extension] ?? 'application/octet-stream';
        $lastModified = filemtime($assetPath);

        if (! is_int($lastModified)) {
            $lastModified = time();
        }

        $etag = '"' . sha1($assetPath . '|' . $lastModified . '|' . filesize($assetPath)) . '"';

        if (
            (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag)
            || (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModified)
        ) {
            status_header(304);
            exit;
        }

        status_header(200);

        if (! headers_sent()) {
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($assetPath));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
            header('ETag: ' . $etag);
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($assetPath);
        }

        exit;
    }

    /**
     * 输出核心资源未找到响应。
     */
    private function sendAssetNotFound(): void
    {
        status_header(404);

        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'PurePress 未找到请求的核心资源。';
        exit;
    }

    /**
     * 输出核心资源请求方法不允许响应。
     */
    private function sendAssetMethodNotAllowed(): void
    {
        status_header(405);

        if (! headers_sent()) {
            header('Allow: GET, HEAD');
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'PurePress 不允许使用当前请求方法访问核心资源。';
        exit;
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
