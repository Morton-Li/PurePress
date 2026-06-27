<?php
/**
 * 评论后可见内容。
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
use WP_Comment;
use WP_Post;

final class CommentRequiredContentModule implements ModuleInterface
{
    public const string MODULE_ID = 'enhancement.comment_required_content';

    private const string SHORTCODE = 'comment_required';
    private const string ENDPOINT_QUERY_KEY = 'purepress_comment_required_content';
    private const string COOKIE_PREFIX = 'comment_access_';

    /**
     * 模块配置。
     *
     * @var array<string,mixed>
     */
    private array $settings = [];

    /**
     * 当前请求中每篇文章已渲染的隐藏片段计数。
     *
     * @var array<int,int>
     */
    private array $segmentCounters = [];

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册评论后可见内容相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);

        $hooks->action('init', [$this, 'registerShortcode'], 10, 0);
        $hooks->action('wp_enqueue_scripts', [$this, 'enqueueFrontendAssets'], 10, 0);
        $hooks->action('template_redirect', [$this, 'handleContentRequest'], 0, 0);
        $hooks->filter('comment_post_redirect', [$this, 'setAccessCookieAfterComment'], 10, 2);
    }

    /**
     * 注册短代码。
     */
    public function registerShortcode(): void
    {
        if (function_exists('add_shortcode')) {
            add_shortcode(self::SHORTCODE, [$this, 'renderShortcode']);
        }
    }

    /**
     * 加载前台资源。
     */
    public function enqueueFrontendAssets(): void
    {
        if (function_exists('is_admin') && is_admin()) {
            return;
        }

        if (! function_exists('is_singular') || ! is_singular()) {
            return;
        }

        wp_enqueue_style(
            'purepress-comment-required-content',
            plugins_url('assets/frontend/comment-required-content.css', PUREPRESS_FILE),
            [],
            PUREPRESS_VERSION
        );

        wp_enqueue_script(
            'purepress-comment-required-content',
            plugins_url('assets/frontend/comment-required-content.js', PUREPRESS_FILE),
            [],
            PUREPRESS_VERSION,
            true
        );

        wp_localize_script(
            'purepress-comment-required-content',
            'PurePressCommentRequiredContent',
            [
                'endpoint' => add_query_arg(self::ENDPOINT_QUERY_KEY, '1', home_url('/')),
                'errorText' => $this->settingText('error_text', '内容加载失败，请稍后重试。'),
            ]
        );
    }

    /**
     * 渲染 `[comment_required]...[/comment_required]` 占位容器。
     *
     * @param array<string,mixed>|string $attributes 短代码属性。
     * @param string|null                $content    被保护的内容。
     */
    public function renderShortcode(array|string $attributes = [], ?string $content = null): string
    {
        unset($attributes);

        $postId = $this->currentPostId();

        if ($postId <= 0 || null === $content) {
            return '';
        }

        $segmentIndex = $this->nextSegmentIndex($postId);
        $token = $this->createToken($postId, $segmentIndex, $content);
        $placeholder = $this->placeholderHtml($this->settingText('placeholder_text', '评论后可查看此内容。'));
        $loadingText = $this->settingText('loading_text', '正在检查评论状态...');

        return sprintf(
            '<div class="purepress-comment-required" data-purepress-comment-required="1" data-token="%1$s" aria-live="polite"><div class="purepress-comment-required__body">%2$s</div><div class="purepress-comment-required__loading" hidden>%3$s</div></div>',
            esc_attr($token),
            $placeholder,
            esc_html($loadingText)
        );
    }

    /**
     * 处理隐藏内容懒加载请求。
     */
    public function handleContentRequest(): void
    {
        if (! isset($_GET[self::ENDPOINT_QUERY_KEY])) {
            return;
        }

        $this->sendNoStoreHeaders();

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';

        if (! is_string($requestMethod) || strtoupper($requestMethod) !== 'POST') {
            $this->sendJson(false, ['html' => $this->placeholderHtml('请求方式无效。')], 405);
        }

        $token = $_POST['token'] ?? '';
        $token = is_scalar($token) ? (string) wp_unslash($token) : '';
        $payload = $this->decodeToken($token);

        if ([] === $payload) {
            $this->sendJson(false, ['html' => $this->placeholderHtml('内容请求无效。')], 400);
        }

        $postId = (int) ($payload['post_id'] ?? 0);
        $segmentIndex = (int) ($payload['segment'] ?? 0);
        $segmentHash = isset($payload['hash']) && is_scalar($payload['hash']) ? (string) $payload['hash'] : '';
        $content = $this->segmentContent($postId, $segmentIndex, $segmentHash);

        if ($content === '') {
            $this->sendJson(false, ['html' => $this->placeholderHtml('内容不存在或已更新。')], 404);
        }

        if (! $this->canViewHiddenContent($postId)) {
            $this->sendJson(
                true,
                [
                    'unlocked' => false,
                    'html' => $this->placeholderHtml($this->settingText('placeholder_text', '评论后可查看此内容。')),
                ]
            );
        }

        $this->sendJson(
            true,
            [
                'unlocked' => true,
                'html' => $this->renderUnlockedContent($content),
            ]
        );
    }

    /**
     * 评论提交成功后写入文章级访问 Cookie。
     *
     * @param string     $location 评论提交后的跳转地址。
     * @param WP_Comment $comment  评论对象。
     */
    public function setAccessCookieAfterComment(string $location, WP_Comment $comment): string
    {
        $postId = (int) $comment->comment_post_ID;
        $commentId = (int) $comment->comment_ID;

        if ($postId <= 0 || $commentId <= 0 || ! $this->isViewableComment($comment)) {
            return $location;
        }

        $expires = time() + $this->settingInt('cookie_lifetime_days', 365, 1, 3650) * DAY_IN_SECONDS;
        $value = $this->signCookiePayload(
            [
                'post_id' => $postId,
                'comment_id' => $commentId,
                'expires' => $expires,
            ]
        );

        $cookieName = $this->accessCookieName($postId);

        if (! headers_sent()) {
            $cookieOptions = [
                'expires' => $expires,
                'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
                'secure' => function_exists('is_ssl') && is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ];

            if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '') {
                $cookieOptions['domain'] = COOKIE_DOMAIN;
            }

            setcookie($cookieName, $value, $cookieOptions);
        }

        $_COOKIE[$cookieName] = $value;

        return $location;
    }

    /**
     * 判断当前访问者是否可以查看指定文章的隐藏内容。
     *
     * @param int $postId 文章 ID。
     */
    private function canViewHiddenContent(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        if (function_exists('current_user_can') && current_user_can('edit_post', $postId)) {
            return true;
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in() && $this->currentUserHasCommented($postId)) {
            return true;
        }

        return $this->hasValidAccessCookie($postId);
    }

    /**
     * 判断当前登录用户是否已经在文章下留下有效评论。
     *
     * @param int $postId 文章 ID。
     */
    private function currentUserHasCommented(int $postId): bool
    {
        if (! function_exists('get_current_user_id') || ! function_exists('get_comments')) {
            return false;
        }

        $userId = (int) get_current_user_id();

        if ($userId <= 0) {
            return false;
        }

        $comments = get_comments(
            [
                'post_id' => $postId,
                'user_id' => $userId,
                'status' => 'all',
                'number' => 10,
            ]
        );

        foreach ($comments as $comment) {
            if ($comment instanceof WP_Comment && $this->isViewableComment($comment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断当前请求是否携带有效访问 Cookie。
     *
     * @param int $postId 文章 ID。
     */
    private function hasValidAccessCookie(int $postId): bool
    {
        $cookieName = $this->accessCookieName($postId);
        $cookieValue = $_COOKIE[$cookieName] ?? '';
        $cookieValue = is_scalar($cookieValue) ? (string) $cookieValue : '';
        $payload = $this->decodeSignedPayload($cookieValue);

        if ([] === $payload || (int) ($payload['post_id'] ?? 0) !== $postId) {
            return false;
        }

        if ((int) ($payload['expires'] ?? 0) < time()) {
            return false;
        }

        $commentId = (int) ($payload['comment_id'] ?? 0);
        $comment = function_exists('get_comment') ? get_comment($commentId) : null;

        return $comment instanceof WP_Comment
            && (int) $comment->comment_post_ID === $postId
            && $this->isViewableComment($comment);
    }

    /**
     * 判断评论是否可用于解锁隐藏内容。
     *
     * @param WP_Comment $comment 评论对象。
     */
    private function isViewableComment(WP_Comment $comment): bool
    {
        return in_array((string) $comment->comment_approved, ['1', '0'], true);
    }

    /**
     * 读取指定文章指定隐藏片段的内容。
     *
     * @param int    $postId       文章 ID。
     * @param int    $segmentIndex 片段序号。
     * @param string $segmentHash  片段内容哈希。
     */
    private function segmentContent(int $postId, int $segmentIndex, string $segmentHash): string
    {
        $post = function_exists('get_post') ? get_post($postId) : null;

        if (! $post instanceof WP_Post || ! is_string($post->post_content)) {
            return '';
        }

        $segments = $this->extractSegments($post->post_content);
        $content = $segments[$segmentIndex] ?? '';

        if (! is_string($content) || $content === '') {
            return '';
        }

        return hash('sha256', $content) === $segmentHash ? $content : '';
    }

    /**
     * 从文章内容中提取所有 `[comment_required]` 片段。
     *
     * @param string $postContent 文章原始内容。
     *
     * @return array<int,string>
     */
    private function extractSegments(string $postContent): array
    {
        if (! function_exists('get_shortcode_regex')) {
            return [];
        }

        $pattern = get_shortcode_regex([self::SHORTCODE]);
        $matched = preg_match_all('/' . $pattern . '/s', $postContent, $matches, PREG_SET_ORDER);

        if (false === $matched || 0 === $matched) {
            return [];
        }

        $segments = [];

        foreach ($matches as $match) {
            if (($match[2] ?? '') !== self::SHORTCODE) {
                continue;
            }

            if (($match[1] ?? '') === '[' && ($match[6] ?? '') === ']') {
                continue;
            }

            $segments[] = isset($match[5]) && is_string($match[5]) ? $match[5] : '';
        }

        return $segments;
    }

    /**
     * 渲染解锁后的隐藏内容。
     *
     * @param string $content 原始隐藏内容。
     */
    private function renderUnlockedContent(string $content): string
    {
        $html = function_exists('apply_filters') ? apply_filters('the_content', $content) : $content;

        return '<div class="purepress-comment-required__content">' . $html . '</div>';
    }

    /**
     * 渲染占位提示。
     *
     * @param string $message 提示文案。
     */
    private function placeholderHtml(string $message): string
    {
        return sprintf(
            '<div class="purepress-comment-required__placeholder"><span class="purepress-comment-required__icon" aria-hidden="true"></span><span class="purepress-comment-required__text">%s</span></div>',
            esc_html($message)
        );
    }

    /**
     * 获取当前文章 ID。
     */
    private function currentPostId(): int
    {
        $postId = function_exists('get_the_ID') ? (int) get_the_ID() : 0;

        if ($postId > 0) {
            return $postId;
        }

        global $post;

        return $post instanceof WP_Post ? (int) $post->ID : 0;
    }

    /**
     * 获取并递增当前文章隐藏片段序号。
     *
     * @param int $postId 文章 ID。
     */
    private function nextSegmentIndex(int $postId): int
    {
        $current = $this->segmentCounters[$postId] ?? 0;
        $this->segmentCounters[$postId] = $current + 1;

        return $current;
    }

    /**
     * 创建隐藏片段访问令牌。
     *
     * @param int    $postId       文章 ID。
     * @param int    $segmentIndex 片段序号。
     * @param string $content      片段原始内容。
     */
    private function createToken(int $postId, int $segmentIndex, string $content): string
    {
        return $this->signPayload(
            [
                'post_id' => $postId,
                'segment' => $segmentIndex,
                'hash' => hash('sha256', $content),
            ]
        );
    }

    /**
     * 解码隐藏片段访问令牌。
     *
     * @param string $token 访问令牌。
     *
     * @return array<string,mixed>
     */
    private function decodeToken(string $token): array
    {
        return $this->decodeSignedPayload($token);
    }

    /**
     * 生成文章级访问 Cookie 名称。
     *
     * @param int $postId 文章 ID。
     */
    private function accessCookieName(int $postId): string
    {
        $siteHash = defined('COOKIEHASH') ? COOKIEHASH : $this->siteHash();

        return self::COOKIE_PREFIX . substr(hash('sha256', $siteHash . '|' . $postId), 0, 20);
    }

    /**
     * 签名 Cookie 数据。
     *
     * @param array<string,mixed> $payload Cookie 数据。
     */
    private function signCookiePayload(array $payload): string
    {
        return $this->signPayload($payload);
    }

    /**
     * 签名结构化数据。
     *
     * @param array<string,mixed> $payload 结构化数据。
     */
    private function signPayload(array $payload): string
    {
        $json = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
        $json = is_string($json) ? $json : '{}';
        $encodedPayload = $this->base64UrlEncode($json);
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey());

        return $encodedPayload . '.' . $signature;
    }

    /**
     * 解码并校验签名数据。
     *
     * @param string $value 签名数据。
     *
     * @return array<string,mixed>
     */
    private function decodeSignedPayload(string $value): array
    {
        if (! str_contains($value, '.')) {
            return [];
        }

        [$encodedPayload, $signature] = explode('.', $value, 2);
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->signingKey());

        if (! hash_equals($expectedSignature, $signature)) {
            return [];
        }

        $json = $this->base64UrlDecode($encodedPayload);
        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * 返回签名密钥。
     */
    private function signingKey(): string
    {
        return function_exists('wp_salt') ? wp_salt('auth') : 'purepress-comment-required-content';
    }

    /**
     * 返回站点哈希。
     */
    private function siteHash(): string
    {
        $url = function_exists('home_url') ? (string) home_url('/') : 'purepress';

        return hash('sha256', $url);
    }

    /**
     * Base64 URL Safe 编码。
     *
     * @param string $value 原始字符串。
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Base64 URL Safe 解码。
     *
     * @param string $value 编码字符串。
     */
    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * 输出 JSON 响应并终止请求。
     *
     * @param bool                $success    请求是否成功。
     * @param array<string,mixed> $data       响应数据。
     * @param int                 $statusCode HTTP 状态码。
     */
    private function sendJson(bool $success, array $data, int $statusCode = 200): void
    {
        if (function_exists('wp_send_json')) {
            wp_send_json(
                [
                    'success' => $success,
                    'data' => $data,
                ],
                $statusCode
            );
        }

        if (! headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            [
                'success' => $success,
                'data' => $data,
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * 输出禁止缓存响应头。
     */
    private function sendNoStoreHeaders(): void
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }

        if (! headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, nofollow', false);
        }
    }

    /**
     * 读取文本配置。
     *
     * @param string $key     配置键。
     * @param string $default 默认值。
     */
    private function settingText(string $key, string $default): string
    {
        $value = $this->settings[$key] ?? '';
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : $default;
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
