<?php
/**
 * 媒体库目录增强。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Enhancement;

use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;
use WP_Query;
use WP_Term;

final class MediaFoldersModule implements ModuleInterface
{
    public const string TAXONOMY = 'purepress_media_folder';
    public const string REQUEST_KEY = 'purepress_media_folder';
    public const string PATH_SEGMENT_META_KEY = '_purepress_media_folder_path_segment';

    private const string MODULE_ID = 'enhancement.media_folders';
    private const string NONCE_ACTION = 'purepress_media_folders';

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册媒体库目录相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('init', [$this, 'registerTaxonomy']);
        $hooks->action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        $hooks->action('add_attachment', [$this, 'assignUploadedAttachment']);
        $hooks->filter('ajax_query_attachments_args', [$this, 'filterAjaxAttachmentQuery']);
        $hooks->action('pre_get_posts', [$this, 'filterAdminMediaQuery']);
        $hooks->filter('wp_prepare_attachment_for_js', [$this, 'prepareAttachmentForJs'], 20, 3);
        $hooks->action('wp_ajax_purepress_media_folders_create', [$this, 'ajaxCreateFolder']);
        $hooks->action('wp_ajax_purepress_media_folders_rename', [$this, 'ajaxRenameFolder']);
        $hooks->action('wp_ajax_purepress_media_folders_delete', [$this, 'ajaxDeleteFolder']);
        $hooks->action('wp_ajax_purepress_media_folders_move_attachment', [$this, 'ajaxMoveAttachment']);
    }

    /**
     * 注册媒体目录 taxonomy。
     */
    public function registerTaxonomy(): void
    {
        register_taxonomy(
            self::TAXONOMY,
            ['attachment'],
            [
                'labels' => [
                    'name' => '媒体目录',
                    'singular_name' => '媒体目录',
                    'search_items' => '搜索媒体目录',
                    'all_items' => '全部媒体目录',
                    'parent_item' => '父级媒体目录',
                    'parent_item_colon' => '父级媒体目录：',
                    'edit_item' => '编辑媒体目录',
                    'update_item' => '更新媒体目录',
                    'add_new_item' => '新建媒体目录',
                    'new_item_name' => '新媒体目录名称',
                    'menu_name' => '媒体目录',
                ],
                'hierarchical' => true,
                'public' => false,
                'show_ui' => false,
                'show_admin_column' => false,
                'show_in_rest' => false,
                'query_var' => self::REQUEST_KEY,
                'rewrite' => false,
                'update_count_callback' => '_update_generic_term_count',
            ]
        );
    }

    /**
     * 在媒体库与媒体弹窗相关后台页面加载目录资源。
     *
     * @param string $hookSuffix 当前后台页面 Hook。
     */
    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if (! in_array($hookSuffix, ['upload.php', 'post.php', 'post-new.php', 'media.php', 'customize.php', 'site-editor.php'], true)) {
            return;
        }

        wp_enqueue_style(
            'purepress-media-folders',
            plugins_url('assets/admin/media-folders.css', PUREPRESS_FILE),
            [],
            PUREPRESS_VERSION
        );

        wp_enqueue_script(
            'purepress-media-folders',
            plugins_url('assets/admin/media-folders.js', PUREPRESS_FILE),
            ['jquery', 'media-views'],
            PUREPRESS_VERSION,
            true
        );

        wp_localize_script(
            'purepress-media-folders',
            'PurePressMediaFolders',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(self::NONCE_ACTION),
                'requestKey' => self::REQUEST_KEY,
                'currentFolder' => $this->requestedFolderValue(),
                'folders' => $this->foldersForJs(),
                'labels' => [
                    'all' => '全部媒体',
                    'unassigned' => '未归档媒体',
                    'create' => '新建目录',
                    'rename' => '重命名',
                    'delete' => '删除',
                    'move' => '移动到当前目录',
                    'unassign' => '移出目录',
                    'newFolderPrompt' => '请输入新目录名称',
                    'renamePrompt' => '请输入新的目录名称',
                    'selectFolderFirst' => '请先选择一个具体目录。',
                    'selectAttachmentFirst' => '请先选择要移动的媒体。',
                    'deleteConfirm' => '确认删除该空目录？',
                    'requestFailed' => '操作失败，请稍后重试。',
                    'folderFilter' => '媒体目录',
                ],
            ]
        );
    }

    /**
     * 新上传附件时记录当前媒体目录。
     *
     * @param int $attachmentId 附件 ID。
     */
    public function assignUploadedAttachment(int $attachmentId): void
    {
        $folderId = $this->submittedFolderId();

        if ($folderId <= 0 || ! $this->folderExists($folderId)) {
            return;
        }

        wp_set_object_terms($attachmentId, [$folderId], self::TAXONOMY, false);
    }

    /**
     * 为媒体弹窗 Ajax 查询增加目录筛选。
     *
     * @param array<string,mixed> $query 附件查询参数。
     *
     * @return array<string,mixed>
     */
    public function filterAjaxAttachmentQuery(array $query): array
    {
        $folderValue = $query[self::REQUEST_KEY] ?? '';
        unset($query[self::REQUEST_KEY]);

        return $this->applyFolderQuery($query, $folderValue);
    }

    /**
     * 为媒体库列表查询增加目录筛选。
     *
     * @param WP_Query $query WordPress 查询对象。
     */
    public function filterAdminMediaQuery(WP_Query $query): void
    {
        global $pagenow;

        if (! is_admin() || $pagenow !== 'upload.php' || ! $query->is_main_query()) {
            return;
        }

        $folderValue = $this->requestedFolderValue();

        if ($folderValue === 'all') {
            return;
        }

        $args = $this->applyFolderQuery(
            [
                'tax_query' => $query->get('tax_query') ?: [],
            ],
            $folderValue
        );

        if (isset($args['tax_query'])) {
            $query->set('tax_query', $args['tax_query']);
        }
    }

    /**
     * 为媒体库 JS 数据补充所属目录。
     *
     * @param array<string,mixed>       $response   媒体库响应数据。
     * @param object                    $attachment 附件对象。
     * @param array<string,mixed>|false $meta       附件元数据。
     *
     * @return array<string,mixed>
     */
    public function prepareAttachmentForJs(array $response, object $attachment, array|false $meta): array
    {
        unset($meta);

        if (! isset($attachment->ID)) {
            return $response;
        }

        $folderId = $this->folderIdForAttachment((int) $attachment->ID);
        $response['purepressMediaFolder'] = [
            'id' => $folderId,
        ];

        return $response;
    }

    /**
     * Ajax：创建媒体目录。
     */
    public function ajaxCreateFolder(): void
    {
        $this->authorizeAjax();

        $name = $this->postedText('name');
        $parentId = $this->postedFolderId('parent');

        if ($name === '') {
            wp_send_json_error(['message' => '目录名称不能为空。'], 400);
        }

        if ($parentId > 0 && ! $this->folderExists($parentId)) {
            wp_send_json_error(['message' => '父级目录不存在。'], 404);
        }

        $result = wp_insert_term(
            $name,
            self::TAXONOMY,
            [
                'parent' => $parentId,
                'slug' => $this->uniqueFolderSlug($name, $parentId),
            ]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        $termId = (int) $result['term_id'];
        $term = get_term($termId, self::TAXONOMY);

        if ($term instanceof WP_Term) {
            update_term_meta($termId, self::PATH_SEGMENT_META_KEY, $term->slug);
        }

        wp_send_json_success(['folders' => $this->foldersForJs()]);
    }

    /**
     * Ajax：重命名媒体目录显示名称。
     */
    public function ajaxRenameFolder(): void
    {
        $this->authorizeAjax();

        $folderId = $this->postedFolderId('folder');
        $name = $this->postedText('name');

        if ($folderId <= 0 || ! $this->folderExists($folderId)) {
            wp_send_json_error(['message' => '目录不存在。'], 404);
        }

        if ($name === '') {
            wp_send_json_error(['message' => '目录名称不能为空。'], 400);
        }

        $result = wp_update_term(
            $folderId,
            self::TAXONOMY,
            [
                'name' => $name,
            ]
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['folders' => $this->foldersForJs()]);
    }

    /**
     * Ajax：删除空媒体目录。
     */
    public function ajaxDeleteFolder(): void
    {
        $this->authorizeAjax();

        $folderId = $this->postedFolderId('folder');

        if ($folderId <= 0 || ! $this->folderExists($folderId)) {
            wp_send_json_error(['message' => '目录不存在。'], 404);
        }

        $children = get_terms(
            [
                'taxonomy' => self::TAXONOMY,
                'hide_empty' => false,
                'parent' => $folderId,
                'fields' => 'ids',
            ]
        );

        if (! is_wp_error($children) && is_array($children) && [] !== $children) {
            wp_send_json_error(['message' => '只能删除空目录。'], 400);
        }

        $objects = get_objects_in_term($folderId, self::TAXONOMY);

        if (! is_wp_error($objects) && is_array($objects) && [] !== $objects) {
            wp_send_json_error(['message' => '只能删除没有媒体文件的目录。'], 400);
        }

        $result = wp_delete_term($folderId, self::TAXONOMY);

        if (! $result || is_wp_error($result)) {
            $message = is_wp_error($result) ? $result->get_error_message() : '目录删除失败。';
            wp_send_json_error(['message' => $message], 400);
        }

        wp_send_json_success(['folders' => $this->foldersForJs()]);
    }

    /**
     * Ajax：移动附件到媒体目录。
     */
    public function ajaxMoveAttachment(): void
    {
        $this->authorizeAjax();

        $folderId = $this->postedFolderId('folder');
        $attachmentIds = $this->postedAttachmentIds();

        if ($folderId > 0 && ! $this->folderExists($folderId)) {
            wp_send_json_error(['message' => '目标目录不存在。'], 404);
        }

        if ([] === $attachmentIds) {
            wp_send_json_error(['message' => '请先选择媒体文件。'], 400);
        }

        foreach ($attachmentIds as $attachmentId) {
            if (! current_user_can('edit_post', $attachmentId)) {
                wp_send_json_error(['message' => '你没有权限移动选中的媒体文件。'], 403);
            }

            $storageResult = apply_filters('purepress_move_attachment_to_media_folder', true, $attachmentId, $folderId);

            if (is_wp_error($storageResult)) {
                wp_send_json_error(['message' => $storageResult->get_error_message()], 400);
            }

            if (false === $storageResult) {
                wp_send_json_error(['message' => '媒体文件移动失败。'], 400);
            }

            $result = $folderId > 0
                ? wp_set_object_terms($attachmentId, [$folderId], self::TAXONOMY, false)
                : wp_set_object_terms($attachmentId, [], self::TAXONOMY, false);

            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()], 400);
            }
        }

        wp_send_json_success(['folders' => $this->foldersForJs()]);
    }

    /**
     * 校验 Ajax 权限和 nonce。
     */
    private function authorizeAjax(): void
    {
        if (! current_user_can('upload_files')) {
            wp_send_json_error(['message' => '你没有权限管理媒体目录。'], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    /**
     * 获取前端目录树数据。
     *
     * @return list<array<string,mixed>>
     */
    private function foldersForJs(): array
    {
        $terms = get_terms(
            [
                'taxonomy' => self::TAXONOMY,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        $items = [];

        foreach ($terms as $term) {
            if (! $term instanceof WP_Term) {
                continue;
            }

            $items[$term->term_id] = [
                'id' => (int) $term->term_id,
                'parent' => (int) $term->parent,
                'name' => $term->name,
                'slug' => $term->slug,
                'path' => $this->folderPath($term),
                'count' => (int) $term->count,
                'children' => [],
            ];
        }

        foreach ($items as $termId => $item) {
            $parentId = (int) $item['parent'];

            if ($parentId > 0 && isset($items[$parentId])) {
                $items[$parentId]['children'][] = &$items[$termId];
            }
        }

        $tree = [];

        foreach ($items as $item) {
            if ((int) $item['parent'] === 0) {
                $tree[] = $item;
            }
        }

        return $tree;
    }

    /**
     * 获取附件所属媒体目录 ID。
     *
     * @param int $attachmentId 附件 ID。
     */
    private function folderIdForAttachment(int $attachmentId): int
    {
        $terms = wp_get_object_terms(
            $attachmentId,
            self::TAXONOMY,
            [
                'fields' => 'ids',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms) || [] === $terms) {
            return 0;
        }

        return (int) $terms[0];
    }

    /**
     * 判断媒体目录是否存在。
     *
     * @param int $folderId 媒体目录 ID。
     */
    private function folderExists(int $folderId): bool
    {
        if ($folderId <= 0) {
            return false;
        }

        $term = get_term($folderId, self::TAXONOMY);

        return $term instanceof WP_Term && ! is_wp_error($term);
    }

    /**
     * 读取请求中的媒体目录值。
     */
    private function requestedFolderValue(): string
    {
        $value = $_REQUEST[self::REQUEST_KEY] ?? 'all';

        if (is_scalar($value)) {
            $value = (string) $value;

            if (function_exists('wp_unslash')) {
                $value = (string) wp_unslash($value);
            }
        } else {
            $value = 'all';
        }

        return $this->sanitizeFolderValue($value);
    }

    /**
     * 读取上传请求中的媒体目录 ID。
     */
    private function submittedFolderId(): int
    {
        $value = $_REQUEST[self::REQUEST_KEY] ?? 0;

        if (is_scalar($value) && function_exists('wp_unslash')) {
            $value = wp_unslash((string) $value);
        }

        return max(0, (int) $value);
    }

    /**
     * 读取 Ajax 提交中的媒体目录 ID。
     *
     * @param string $key 请求字段名。
     */
    private function postedFolderId(string $key): int
    {
        $value = $_POST[$key] ?? 0;

        if (is_scalar($value) && function_exists('wp_unslash')) {
            $value = wp_unslash((string) $value);
        }

        return max(0, (int) $value);
    }

    /**
     * 读取 Ajax 提交中的文本字段。
     *
     * @param string $key 请求字段名。
     */
    private function postedText(string $key): string
    {
        $value = $_POST[$key] ?? '';

        if (is_scalar($value)) {
            $value = (string) $value;

            if (function_exists('wp_unslash')) {
                $value = (string) wp_unslash($value);
            }
        } else {
            $value = '';
        }

        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim($value);
    }

    /**
     * 读取 Ajax 提交中的附件 ID 列表。
     *
     * @return list<int>
     */
    private function postedAttachmentIds(): array
    {
        $rawIds = $_POST['attachments'] ?? [];

        if (! is_array($rawIds)) {
            $rawIds = [$rawIds];
        }

        $attachmentIds = [];

        foreach ($rawIds as $rawId) {
            if (! is_scalar($rawId)) {
                continue;
            }

            $attachmentIds[] = (int) (function_exists('wp_unslash') ? wp_unslash((string) $rawId) : $rawId);
        }

        return array_values(array_filter(array_unique($attachmentIds), static fn (int $id): bool => $id > 0));
    }

    /**
     * 将媒体目录筛选注入 WP_Query 参数。
     *
     * @param array<string,mixed> $query       查询参数。
     * @param mixed               $folderValue 媒体目录筛选值。
     *
     * @return array<string,mixed>
     */
    private function applyFolderQuery(array $query, mixed $folderValue): array
    {
        $folderValue = is_scalar($folderValue) ? $this->sanitizeFolderValue((string) $folderValue) : 'all';

        if ($folderValue === 'all') {
            return $query;
        }

        $taxQuery = isset($query['tax_query']) && is_array($query['tax_query']) ? $query['tax_query'] : [];

        if ($folderValue === 'unassigned') {
            $taxQuery[] = [
                'taxonomy' => self::TAXONOMY,
                'operator' => 'NOT EXISTS',
            ];
        } else {
            $folderId = (int) $folderValue;

            if ($folderId <= 0) {
                return $query;
            }

            $taxQuery[] = [
                'taxonomy' => self::TAXONOMY,
                'field' => 'term_id',
                'terms' => [$folderId],
                'include_children' => true,
            ];
        }

        $query['tax_query'] = $taxQuery;

        return $query;
    }

    /**
     * 清洗媒体目录筛选值。
     *
     * @param string $value 原始值。
     */
    private function sanitizeFolderValue(string $value): string
    {
        $value = trim($value);

        if ($value === '' || $value === 'all') {
            return 'all';
        }

        if ($value === 'unassigned') {
            return 'unassigned';
        }

        $folderId = (int) $value;

        return $folderId > 0 ? (string) $folderId : 'all';
    }

    /**
     * 生成同级唯一目录 slug。
     *
     * @param string $name     目录名称。
     * @param int    $parentId 父级目录 ID。
     */
    private function uniqueFolderSlug(string $name, int $parentId): string
    {
        $baseSlug = sanitize_title($name);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'folder';
        $slug = $baseSlug;
        $suffix = 1;

        while (term_exists($slug, self::TAXONOMY, $parentId)) {
            $slug = $baseSlug . '-' . $suffix;
            ++$suffix;
        }

        return $slug;
    }

    /**
     * 获取媒体目录完整路径。
     *
     * @param WP_Term $term 媒体目录 term。
     */
    private function folderPath(WP_Term $term): string
    {
        $ancestors = array_reverse(get_ancestors($term->term_id, self::TAXONOMY, 'taxonomy'));
        $segments = [];

        foreach (array_merge($ancestors, [$term->term_id]) as $termId) {
            $segment = get_term_meta((int) $termId, self::PATH_SEGMENT_META_KEY, true);
            $termObject = get_term((int) $termId, self::TAXONOMY);

            if (! $termObject instanceof WP_Term || is_wp_error($termObject)) {
                continue;
            }

            $segment = is_string($segment) && $segment !== '' ? $segment : $termObject->slug;
            $segments[] = $segment;
        }

        return trim(implode('/', $segments), '/');
    }
}
