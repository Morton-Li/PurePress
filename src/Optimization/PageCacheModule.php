<?php
/**
 * 提供前台页面缓存。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Optimization;

use PurePress\Configuration\OptionRepository;
use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;

final class PageCacheModule implements ModuleInterface
{
    public const string MODULE_ID = 'optimization.page_cache';
    public const string CLEANUP_HOOK = 'purepress_cleanup_expired_page_cache';

    private const string DATA_ROOT = 'purepress';
    private const string CACHE_DIRECTORY = 'cache';
    private const string PAGE_DIRECTORY = 'page';

    /**
     * 模块配置。
     *
     * @var array<string,mixed>
     */
    private array $settings = [];

    /**
     * 当前请求将要写入的缓存文件。
     */
    private string $pendingCacheFile = '';

    /**
     * 当前请求是否正在等待写入缓存。
     */
    private bool $pendingStore = false;

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册页面缓存 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        self::syncCleanupSchedule();

        $hooks->action('template_redirect', [$this, 'serveCachedPage'], -1000, 0);
        $hooks->action('template_redirect', [$this, 'startOutputBuffer'], 1000, 0);
        $hooks->action(self::CLEANUP_HOOK, [self::class, 'cleanupExpiredStatic'], 10, 0);
        $hooks->action('save_post', [self::class, 'clear'], 10, 0);
        $hooks->action('deleted_post', [self::class, 'clear'], 10, 0);
        $hooks->action('transition_comment_status', [self::class, 'clear'], 10, 0);
        $hooks->action('wp_insert_comment', [self::class, 'clear'], 10, 0);
        $hooks->action('edit_comment', [self::class, 'clear'], 10, 0);
        $hooks->action('deleted_comment', [self::class, 'clear'], 10, 0);
        $hooks->action('wp_update_nav_menu', [self::class, 'clear'], 10, 0);
        $hooks->action('created_term', [self::class, 'clear'], 10, 0);
        $hooks->action('edited_term', [self::class, 'clear'], 10, 0);
        $hooks->action('delete_term', [self::class, 'clear'], 10, 0);
        $hooks->action('switch_theme', [self::class, 'clear'], 10, 0);
    }

    /**
     * 如果当前请求命中缓存，直接输出缓存页面。
     */
    public function serveCachedPage(): void
    {
        if (! $this->isCacheableRequest()) {
            return;
        }

        $cacheFile = $this->cacheFilePath();

        if ($cacheFile === '' || ! is_readable($cacheFile) || $this->isExpired($cacheFile)) {
            return;
        }

        $html = file_get_contents($cacheFile);

        if (! is_string($html) || ! $this->looksLikeHtmlDocument($html)) {
            return;
        }

        if (! headers_sent()) {
            status_header(200);
            header('Content-Type: text/html; charset=' . $this->blogCharset());
            header('X-Page-Cache: HIT');
        }

        echo $this->injectConsoleLog($html, (int) filemtime($cacheFile));
        exit;
    }

    /**
     * 在可缓存请求上开启输出缓冲，用于生成缓存文件。
     */
    public function startOutputBuffer(): void
    {
        if (! $this->isCacheableRequest()) {
            return;
        }

        $cacheFile = $this->cacheFilePath();

        if ($cacheFile === '') {
            return;
        }

        $this->pendingCacheFile = $cacheFile;
        $this->pendingStore = true;

        if (! headers_sent()) {
            header('X-Page-Cache: MISS');
        }

        ob_start([$this, 'storeOutput']);
    }

    /**
     * 将可缓存的 HTML 输出写入页面缓存。
     *
     * @param string $html 页面 HTML。
     */
    public function storeOutput(string $html): string
    {
        if (! $this->pendingStore || $this->pendingCacheFile === '') {
            return $html;
        }

        $this->pendingStore = false;

        if (! $this->isCacheableResponse($html)) {
            return $html;
        }

        $directory = dirname($this->pendingCacheFile);

        if (! self::ensureDirectory($directory)) {
            return $html;
        }

        $temporaryFile = $this->pendingCacheFile . '.' . bin2hex(random_bytes(6)) . '.tmp';

        $cachedHtml = $this->injectConsoleLog($html, time());

        if (false === @file_put_contents($temporaryFile, $cachedHtml, LOCK_EX)) {
            @unlink($temporaryFile);
            return $html;
        }

        @chmod($temporaryFile, 0644);

        if (! @rename($temporaryFile, $this->pendingCacheFile)) {
            @unlink($temporaryFile);
        }

        return $html;
    }

    /**
     * 清空页面缓存。
     */
    public static function clear(): void
    {
        $path = self::cacheRootPath();

        if ($path === '' || ! is_dir($path)) {
            return;
        }

        self::deleteDirectory($path);
    }

    /**
     * 同步过期缓存清理计划。
     */
    public static function syncCleanupSchedule(): void
    {
        $settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        if ((bool) ($settings['enabled'] ?? false) && (bool) ($settings['scheduled_cleanup'] ?? true)) {
            self::scheduleCleanup();
            return;
        }

        self::unscheduleCleanup();
    }

    /**
     * 安排过期页面缓存定时清理。
     */
    public static function scheduleCleanup(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (false === wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK);
        }
    }

    /**
     * 取消过期页面缓存定时清理。
     */
    public static function unscheduleCleanup(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        }
    }

    /**
     * WP-Cron 入口：删除过期页面缓存。
     */
    public static function cleanupExpiredStatic(): void
    {
        $settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        if (! (bool) ($settings['enabled'] ?? false) || ! (bool) ($settings['scheduled_cleanup'] ?? true)) {
            return;
        }

        self::cleanupExpired(self::ttlSecondsFromSettings($settings));
    }

    /**
     * 删除过期页面缓存文件。
     *
     * @param int|null $ttlSeconds 缓存有效期秒数；为空时读取当前配置。
     */
    public static function cleanupExpired(?int $ttlSeconds = null): void
    {
        $path = self::cacheRootPath();

        if ($path === '' || ! is_dir($path)) {
            return;
        }

        if (null === $ttlSeconds) {
            $ttlSeconds = self::ttlSecondsFromSettings((new OptionRepository())->moduleSettings(self::MODULE_ID));
        }

        self::deleteExpiredFiles($path, max(60, $ttlSeconds));
    }

    /**
     * 获取页面缓存状态。
     *
     * @return array{path: string, files: int, size: int}
     */
    public static function status(): array
    {
        $path = self::cacheRootPath();

        if ($path === '' || ! is_dir($path)) {
            return [
                'path' => $path,
                'files' => 0,
                'size' => 0,
            ];
        }

        $stats = self::directoryStats($path);

        return [
            'path' => $path,
            'files' => $stats['files'],
            'size' => $stats['size'],
        ];
    }

    /**
     * 判断当前请求是否允许读取或写入页面缓存。
     */
    private function isCacheableRequest(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
            return false;
        }

        if ((defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) || (defined('WP_CLI') && WP_CLI)) {
            return false;
        }

        if (function_exists('is_admin') && is_admin()) {
            return false;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return false;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return false;
        }

        if ($this->hasQueryString() || $this->hasBypassRequestHeader() || $this->hasKnownVisitorCookie()) {
            return false;
        }

        if ($this->isExcludedPath($this->requestPath())) {
            return false;
        }

        return ! $this->isNonCacheableWordPressView();
    }

    /**
     * 判断当前响应是否可以写入页面缓存。
     *
     * @param string $html 页面 HTML。
     */
    private function isCacheableResponse(string $html): bool
    {
        if ($html === '' || ! $this->looksLikeHtmlDocument($html)) {
            return false;
        }

        if ((defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) || http_response_code() !== 200) {
            return false;
        }

        foreach (headers_list() as $header) {
            $header = strtolower($header);

            if (str_starts_with($header, 'set-cookie:')) {
                return false;
            }

            if (str_starts_with($header, 'content-type:') && ! str_contains($header, 'text/html')) {
                return false;
            }

            if (
                str_starts_with($header, 'cache-control:')
                && (
                    str_contains($header, 'no-store')
                    || str_contains($header, 'private')
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 获取当前请求对应的缓存文件路径。
     */
    private function cacheFilePath(): string
    {
        $cacheRoot = self::cacheRootPath();
        $scheme = $this->requestScheme();
        $host = $this->requestHost();
        $path = $this->requestPath();

        if ($cacheRoot === '' || $scheme === '' || $host === '' || $path === '') {
            return '';
        }

        $relativePath = $this->cacheRelativePath($path);

        if ($relativePath === '') {
            return '';
        }

        return $cacheRoot . '/' . $scheme . '/' . $this->safeHost($host) . '/' . $relativePath . '/index.html';
    }

    /**
     * 判断缓存文件是否已经过期。
     *
     * @param string $cacheFile 缓存文件路径。
     */
    private function isExpired(string $cacheFile): bool
    {
        $cachedAt = (int) filemtime($cacheFile);

        return $cachedAt <= 0 || $cachedAt + $this->ttlSeconds() < time();
    }

    /**
     * 判断输出内容是否像完整 HTML 文档。
     *
     * @param string $html 页面 HTML。
     */
    private function looksLikeHtmlDocument(string $html): bool
    {
        return stripos($html, '<html') !== false && stripos($html, '</html>') !== false;
    }

    /**
     * 在缓存命中页面中注入浏览器控制台提示。
     *
     * @param string $html     页面 HTML。
     * @param int    $cachedAt 缓存生成时间戳。
     */
    private function injectConsoleLog(string $html, int $cachedAt): string
    {
        if (! (bool) ($this->settings['console_log'] ?? true)) {
            return $html;
        }

        if (str_contains($html, 'data-page-cache-hit="1"')) {
            return $html;
        }

        $message = sprintf(
            'Page cache hit. Generated at: %s',
            function_exists('wp_date') ? wp_date('Y-m-d H:i:s', $cachedAt) : date('Y-m-d H:i:s', $cachedAt)
        );
        $encodedMessage = function_exists('wp_json_encode') ? wp_json_encode($message) : json_encode($message);

        if (! is_string($encodedMessage)) {
            return $html;
        }

        $script = "\n<script data-page-cache-hit=\"1\">console.info({$encodedMessage});</script>\n";

        if (false !== stripos($html, '</body>')) {
            $updated = preg_replace('/<\/body>/i', $script . '</body>', $html, 1);

            return is_string($updated) ? $updated : $html;
        }

        return $html . $script;
    }

    /**
     * 判断当前 WordPress 视图是否不适合缓存。
     */
    private function isNonCacheableWordPressView(): bool
    {
        foreach (['is_404', 'is_search', 'is_preview', 'is_feed', 'is_trackback', 'is_robots', 'is_embed'] as $functionName) {
            if (function_exists($functionName) && $functionName()) {
                return true;
            }
        }

        if (function_exists('is_customize_preview') && is_customize_preview()) {
            return true;
        }

        return false;
    }

    /**
     * 判断请求是否带查询参数。
     */
    private function hasQueryString(): bool
    {
        $queryString = $_SERVER['QUERY_STRING'] ?? '';

        return is_string($queryString) && trim($queryString) !== '';
    }

    /**
     * 判断请求是否显式要求绕过缓存。
     */
    private function hasBypassRequestHeader(): bool
    {
        foreach (['HTTP_CACHE_CONTROL', 'HTTP_PRAGMA'] as $serverKey) {
            $value = $_SERVER[$serverKey] ?? '';

            if (! is_string($value)) {
                continue;
            }

            $value = strtolower($value);

            if (str_contains($value, 'no-cache') || str_contains($value, 'no-store')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断当前访客是否携带会影响页面个性化的 Cookie。
     */
    private function hasKnownVisitorCookie(): bool
    {
        $prefixes = [
            'wordpress_logged_in_',
            'wordpress_sec_',
            'wp-postpass_',
            'comment_author_',
            'woocommerce_',
            'wp_woocommerce_session_',
        ];
        $exactNames = [
            'woocommerce_cart_hash',
            'woocommerce_items_in_cart',
        ];

        foreach (array_keys($_COOKIE) as $cookieName) {
            if (in_array($cookieName, $exactNames, true)) {
                return true;
            }

            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $cookieName, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 判断请求路径是否在排除列表中。
     *
     * @param string $path 当前请求路径。
     */
    private function isExcludedPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        foreach ($this->excludedPaths() as $excludedPath) {
            if ($excludedPath === '') {
                continue;
            }

            if (str_ends_with($excludedPath, '*')) {
                $prefix = rtrim(substr($excludedPath, 0, -1), '/');

                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    return true;
                }

                continue;
            }

            $prefix = rtrim($excludedPath, '/');

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取排除路径列表。
     *
     * @return list<string>
     */
    private function excludedPaths(): array
    {
        $paths = $this->settings['excluded_paths'] ?? [];

        if (! is_array($paths)) {
            return [];
        }

        $normalized = [];

        foreach ($paths as $path) {
            if (! is_scalar($path)) {
                continue;
            }

            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $normalized[] = '/' . ltrim($path, '/');
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 读取当前请求路径。
     */
    private function requestPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        if (! is_string($requestUri) || $requestUri === '') {
            return '/';
        }

        $path = parse_url($requestUri, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        $path = '/' . ltrim(rawurldecode($path), '/');
        $path = preg_replace('#/+#', '/', $path);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * 读取当前请求协议。
     */
    private function requestScheme(): string
    {
        return function_exists('is_ssl') && is_ssl() ? 'https' : 'http';
    }

    /**
     * 读取当前请求主机名。
     */
    private function requestHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';

        if (! is_string($host) || trim($host) === '') {
            $host = function_exists('home_url') ? (string) parse_url(home_url('/'), PHP_URL_HOST) : '';
        }

        $host = strtolower(trim($host));

        if (str_contains($host, ':')) {
            $parsedHost = parse_url('http://' . $host, PHP_URL_HOST);
            $host = is_string($parsedHost) && $parsedHost !== '' ? $parsedHost : $host;
        }

        return $host;
    }

    /**
     * 读取缓存有效期秒数。
     */
    private function ttlSeconds(): int
    {
        return self::ttlSecondsFromSettings($this->settings);
    }

    /**
     * 从配置读取缓存有效期秒数。
     *
     * @param array<string,mixed> $settings 模块配置。
     */
    private static function ttlSecondsFromSettings(array $settings): int
    {
        $minutes = is_numeric($settings['ttl_minutes'] ?? null) ? (int) $settings['ttl_minutes'] : 30;
        $minutes = max(1, min(1440, $minutes));

        return $minutes * 60;
    }

    /**
     * 获取站点字符集。
     */
    private function blogCharset(): string
    {
        $charset = function_exists('get_option') ? (string) get_option('blog_charset') : 'UTF-8';
        $charset = trim($charset);

        return $charset !== '' ? $charset : 'UTF-8';
    }

    /**
     * 将主机名限制为安全目录名。
     *
     * @param string $host 原始主机名。
     */
    private function safeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/[^a-z0-9.-]+/', '-', $host);

        return is_string($host) && $host !== '' ? trim($host, '.-') : 'site';
    }

    /**
     * 将请求路径转换为可被 Nginx 直接映射的缓存相对路径。
     *
     * @param string $path 请求路径。
     */
    private function cacheRelativePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return '';
        }

        $path = trim((string) preg_replace('#/+#', '/', str_replace('\\', '/', $path)), '/');

        if ($path === '') {
            return '.';
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    /**
     * 获取页面缓存根目录。
     */
    private static function cacheRootPath(): string
    {
        if (! defined('WP_CONTENT_DIR')) {
            return '';
        }

        return rtrim((string) WP_CONTENT_DIR, '/\\') . '/' . self::DATA_ROOT . '/' . self::CACHE_DIRECTORY . '/' . self::PAGE_DIRECTORY;
    }

    /**
     * 确保目录存在且可写。
     *
     * @param string $directory 目录路径。
     */
    private static function ensureDirectory(string $directory): bool
    {
        if ($directory === '') {
            return false;
        }

        if (is_dir($directory)) {
            return is_writable($directory);
        }

        if (function_exists('wp_mkdir_p')) {
            return wp_mkdir_p($directory);
        }

        return @mkdir($directory, 0755, true);
    }

    /**
     * 递归删除目录。
     *
     * @param string $path 目录路径。
     */
    private static function deleteDirectory(string $path): void
    {
        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && ! is_link($itemPath)) {
                self::deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }

    /**
     * 递归删除过期缓存文件，并移除清理后为空的子目录。
     *
     * @param string $path       目录路径。
     * @param int    $ttlSeconds 缓存有效期秒数。
     */
    private static function deleteExpiredFiles(string $path, int $ttlSeconds): void
    {
        $items = scandir($path);

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && ! is_link($itemPath)) {
                self::deleteExpiredFiles($itemPath, $ttlSeconds);
                self::removeDirectoryIfEmpty($itemPath);
                continue;
            }

            if (is_file($itemPath) && self::isExpiredFile($itemPath, $ttlSeconds)) {
                @unlink($itemPath);
            }
        }

        self::removeDirectoryIfEmpty($path);
    }

    /**
     * 判断缓存文件是否过期。
     *
     * @param string $path       文件路径。
     * @param int    $ttlSeconds 缓存有效期秒数。
     */
    private static function isExpiredFile(string $path, int $ttlSeconds): bool
    {
        $modifiedAt = (int) filemtime($path);

        return $modifiedAt <= 0 || $modifiedAt + $ttlSeconds < time();
    }

    /**
     * 如果目录为空则删除。
     *
     * @param string $path 目录路径。
     */
    private static function removeDirectoryIfEmpty(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if (is_array($items) && count(array_diff($items, ['.', '..'])) === 0) {
            @rmdir($path);
        }
    }

    /**
     * 统计目录内缓存文件数量和大小。
     *
     * @param string $path 目录路径。
     *
     * @return array{files: int, size: int}
     */
    private static function directoryStats(string $path): array
    {
        $files = 0;
        $size = 0;
        $items = scandir($path);

        if (! is_array($items)) {
            return [
                'files' => 0,
                'size' => 0,
            ];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && ! is_link($itemPath)) {
                $childStats = self::directoryStats($itemPath);
                $files += $childStats['files'];
                $size += $childStats['size'];
                continue;
            }

            if (is_file($itemPath)) {
                $files++;
                $size += (int) filesize($itemPath);
            }
        }

        return [
            'files' => $files,
            'size' => $size,
        ];
    }
}
