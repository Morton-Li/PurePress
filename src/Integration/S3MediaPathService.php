<?php
/**
 * S3 媒体目录路径服务。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Integration;

use PurePress\Enhancement\MediaFoldersModule;

final class S3MediaPathService
{
    /**
     * 根据附件所属媒体目录生成目标上传目录相对路径。
     *
     * @param int    $attachmentId       附件 ID。
     * @param string $sourceRelativePath WordPress 原始上传目录相对路径。
     */
    public function targetRelativePathForAttachment(int $attachmentId, string $sourceRelativePath): string
    {
        $folderId = $this->folderIdForAttachment($attachmentId);

        if ($folderId <= 0) {
            return $this->sanitizeRelativePath($sourceRelativePath);
        }

        return $this->targetRelativePathForFolder($folderId, $sourceRelativePath, $attachmentId);
    }

    /**
     * 根据指定媒体目录生成目标上传目录相对路径。
     *
     * @param int    $folderId           媒体目录 ID。
     * @param string $sourceRelativePath WordPress 原始上传目录相对路径。
     * @param int    $attachmentId       当前附件 ID，用于排除自身重名。
     */
    public function targetRelativePathForFolder(int $folderId, string $sourceRelativePath, int $attachmentId = 0): string
    {
        $folderPath = $this->folderPath($folderId);

        if ($folderPath === '') {
            return $this->sanitizeRelativePath($sourceRelativePath);
        }

        $targetRelativePath = $this->sanitizeRelativePath($folderPath . '/' . wp_basename($sourceRelativePath));

        return $this->uniqueRelativePath($targetRelativePath, $attachmentId);
    }

    /**
     * 获取附件当前所属媒体目录 ID。
     *
     * @param int $attachmentId 附件 ID。
     */
    public function folderIdForAttachment(int $attachmentId): int
    {
        $terms = wp_get_object_terms(
            $attachmentId,
            MediaFoldersModule::TAXONOMY,
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
     * 根据目录 ID 获取完整目录路径。
     *
     * @param int $folderId 媒体目录 ID。
     */
    public function folderPath(int $folderId): string
    {
        if ($folderId <= 0) {
            return '';
        }

        $term = get_term($folderId, MediaFoldersModule::TAXONOMY);

        if (! $term || is_wp_error($term)) {
            return '';
        }

        $ancestors = array_reverse(get_ancestors($folderId, MediaFoldersModule::TAXONOMY, 'taxonomy'));
        $segments = [];

        foreach (array_merge($ancestors, [$folderId]) as $termId) {
            $folderTerm = get_term((int) $termId, MediaFoldersModule::TAXONOMY);

            if (! $folderTerm || is_wp_error($folderTerm)) {
                continue;
            }

            $segment = get_term_meta((int) $termId, MediaFoldersModule::PATH_SEGMENT_META_KEY, true);
            $segment = is_string($segment) && $segment !== '' ? $segment : $folderTerm->slug;
            $segment = $this->sanitizeRelativePath($segment);

            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $this->sanitizeRelativePath(implode('/', $segments));
    }

    /**
     * 生成不与其他附件 `_wp_attached_file` 冲突的相对路径。
     *
     * @param string $relativePath 目标相对路径。
     * @param int    $attachmentId 当前附件 ID。
     */
    public function uniqueRelativePath(string $relativePath, int $attachmentId = 0): string
    {
        $relativePath = $this->sanitizeRelativePath($relativePath);
        $directory = trim(dirname($relativePath), '.');
        $filename = wp_basename($relativePath);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $relativePath;
        $suffix = 1;

        while ($this->relativePathExists($candidate, $attachmentId)) {
            $candidateFilename = $name . '-' . $suffix . ($extension !== '' ? '.' . $extension : '');
            $candidate = $this->sanitizeRelativePath(($directory !== '' ? $directory . '/' : '') . $candidateFilename);
            ++$suffix;
        }

        return $candidate;
    }

    /**
     * 判断相对路径是否已经被其他附件使用。
     *
     * @param string $relativePath 相对路径。
     * @param int    $attachmentId 当前附件 ID。
     */
    private function relativePathExists(string $relativePath, int $attachmentId): bool
    {
        $attachments = get_posts(
            [
                'post_type' => 'attachment',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'post__not_in' => $attachmentId > 0 ? [$attachmentId] : [],
                'meta_query' => [
                    [
                        'key' => '_wp_attached_file',
                        'value' => $relativePath,
                    ],
                ],
            ]
        );

        return is_array($attachments) && [] !== $attachments;
    }

    /**
     * 清洗上传目录相对路径。
     *
     * @param string $path 原始路径。
     */
    private function sanitizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        return str_replace(["\0", '..'], '', $path);
    }
}
