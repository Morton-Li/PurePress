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
