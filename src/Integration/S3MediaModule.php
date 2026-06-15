<?php
/**
 * 使用 S3 兼容对象存储接管 WordPress 媒体文件。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Integration;

use Aws\S3\S3Client;
use PurePress\Configuration\OptionRepository;
use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;
use Throwable;

final class S3MediaModule implements ModuleInterface
{
    private const string MODULE_ID = 'integration.s3_media';
    private const string REMOTE_META_KEY = '_purepress_s3_media';
    private const string ERROR_META_KEY = '_purepress_s3_media_error';

    /**
     * 本次请求中已上传到远端的本地文件映射。
     *
     * @var array<string, string>
     */
    private array $uploadedFiles = [];

    /**
     * 本次请求中为图片编辑临时拉回的本地文件。
     *
     * @var list<string>
     */
    private array $downloadedEditFiles = [];

    /**
     * 缓存后的模块配置。
     *
     * @var array<string, mixed>|null
     */
    private ?array $settings = null;

    /**
     * 缓存后的 S3 客户端。
     */
    private ?S3Client $client = null;

    /**
     * S3 客户端初始化失败原因。
     */
    private string $clientError = '';

    /**
     * 媒体目录路径服务。
     */
    private ?S3MediaPathService $pathService = null;

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return self::MODULE_ID;
    }

    /**
     * 注册 S3 媒体接管相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->filter('wp_generate_attachment_metadata', [$this, 'uploadAttachmentFiles'], 20, 3);
        $hooks->filter('wp_update_attachment_metadata', [$this, 'syncUpdatedAttachmentMetadata'], 20, 2);
        $hooks->filter('wp_get_attachment_url', [$this, 'remoteAttachmentUrl'], 20, 2);
        $hooks->filter('attachment_link', [$this, 'remoteAttachmentLink'], 20, 2);
        $hooks->filter('image_downsize', [$this, 'remoteImageDownsize'], 20, 3);
        $hooks->filter('wp_calculate_image_srcset', [$this, 'remoteImageSrcset'], 20, 5);
        $hooks->filter('wp_prepare_attachment_for_js', [$this, 'prepareAttachmentForJs'], 20, 3);
        $hooks->filter('load_image_to_edit_path', [$this, 'loadRemoteImageForEdit'], 20, 3);
        $hooks->filter('purepress_move_attachment_to_media_folder', [$this, 'moveAttachmentToMediaFolder'], 20, 3);
        $hooks->action('delete_attachment', [$this, 'deleteRemoteObjects'], 10, 2);
        $hooks->action('shutdown', [$this, 'cleanupDownloadedEditFiles'], 20, 0);
    }

    /**
     * 上传附件原图、缩略图和派生尺寸到远端，并清理本地工作文件。
     *
     * @param mixed $data         附件元数据。
     * @param int   $attachmentId 附件 ID。
     * @param string $context     元数据生成上下文。
     *
     * @return mixed
     */
    public function uploadAttachmentFiles(mixed $data, int $attachmentId, string $context = ''): mixed
    {
        if ($context !== 'create' || ! is_array($data) || ! $this->isConfigured()) {
            return $data;
        }

        $uploadDir = $this->uploadDir();

        if ([] === $uploadDir) {
            return $data;
        }

        $sourceMainRelativePath = ! empty($data['file']) && is_string($data['file'])
            ? $this->sanitizeRelativePath((string) $data['file'])
            : $this->attachmentRelativePath($attachmentId);
        $targetMainRelativePath = $this->pathService()->targetRelativePathForAttachment($attachmentId, $sourceMainRelativePath);

        $remote = [
            'bucket' => (string) $this->settings()['bucket'],
            'path_prefix' => $this->pathPrefix(),
            'public_base_url' => (string) ($this->settings()['public_base_url'] ?? ''),
            'files' => [],
        ];

        if ($sourceMainRelativePath === '' || $targetMainRelativePath === '') {
            return $data;
        }

        $localFiles = [];
        $mainPath = $this->absolutePath($sourceMainRelativePath, $uploadDir);
        $mainUpload = $this->ensureRemoteFile($mainPath, $targetMainRelativePath, (string) get_post_mime_type($attachmentId));

        if (! $mainUpload['success']) {
            update_post_meta($attachmentId, self::ERROR_META_KEY, $mainUpload['message']);
            return $data;
        }

        $remote['files']['original'] = $mainUpload['key'];
        $localFiles[] = $mainPath;

        if (isset($data['sizes']) && is_array($data['sizes'])) {
            foreach ($data['sizes'] as $sizeName => $sizeData) {
                if (! is_array($sizeData) || empty($sizeData['file']) || ! is_string($sizeData['file'])) {
                    continue;
                }

                $targetSizeFile = $this->targetSiblingFile($sourceMainRelativePath, $targetMainRelativePath, $sizeData['file']);
                $sourceSizeRelativePath = $this->relativeSiblingPath($sourceMainRelativePath, $sizeData['file']);
                $targetSizeRelativePath = $this->relativeSiblingPath($targetMainRelativePath, $targetSizeFile);
                $sizePath = $this->absolutePath($sourceSizeRelativePath, $uploadDir);
                $sizeUpload = $this->ensureRemoteFile($sizePath, $targetSizeRelativePath, (string) ($sizeData['mime-type'] ?? get_post_mime_type($attachmentId)));

                if (! $sizeUpload['success']) {
                    unset($data['sizes'][$sizeName]);
                    continue;
                }

                $remote['files']['sizes'][(string) $sizeName] = $sizeUpload['key'];
                $data['sizes'][$sizeName]['file'] = $targetSizeFile;
                $localFiles[] = $sizePath;
            }
        }

        if (! empty($data['original_image']) && is_string($data['original_image'])) {
            $targetOriginalFile = $this->targetSiblingFile($sourceMainRelativePath, $targetMainRelativePath, $data['original_image'], '-original');
            $sourceOriginalRelativePath = $this->relativeSiblingPath($sourceMainRelativePath, $data['original_image']);
            $targetOriginalRelativePath = $this->relativeSiblingPath($targetMainRelativePath, $targetOriginalFile);
            $originalPath = $this->absolutePath($sourceOriginalRelativePath, $uploadDir);
            $originalUpload = $this->ensureRemoteFile($originalPath, $targetOriginalRelativePath, (string) get_post_mime_type($attachmentId));

            if ($originalUpload['success']) {
                $remote['files']['original_image'] = $originalUpload['key'];
                $data['original_image'] = $targetOriginalFile;
                $localFiles[] = $originalPath;
            }
        }

        if (! empty($data['filesize']) || ! is_readable($mainPath)) {
            $data['filesize'] = isset($data['filesize']) ? (int) $data['filesize'] : 0;
        } else {
            $data['filesize'] = (int) filesize($mainPath);
        }

        $data['file'] = $targetMainRelativePath;
        update_post_meta($attachmentId, '_wp_attached_file', $targetMainRelativePath);
        update_post_meta($attachmentId, self::REMOTE_META_KEY, $remote);
        delete_post_meta($attachmentId, self::ERROR_META_KEY);
        $this->deleteLocalFiles($localFiles, (string) $uploadDir['basedir']);

        return $data;
    }

    /**
     * 同步图片编辑、重新生成缩略图等更新后的附件元数据到远端。
     *
     * @param mixed $data         附件元数据。
     * @param int   $attachmentId 附件 ID。
     *
     * @return mixed
     */
    public function syncUpdatedAttachmentMetadata(mixed $data, int $attachmentId): mixed
    {
        if (! is_array($data) || ! $this->isConfigured() || ! $this->hasRemoteMeta($attachmentId)) {
            return $data;
        }

        $uploadDir = $this->uploadDir();

        if ([] === $uploadDir) {
            return $data;
        }

        $mainRelativePath = ! empty($data['file']) && is_string($data['file'])
            ? $this->sanitizeRelativePath((string) $data['file'])
            : $this->attachmentRelativePath($attachmentId);

        if ($mainRelativePath === '') {
            return $data;
        }

        $existingRemote = get_post_meta($attachmentId, self::REMOTE_META_KEY, true);
        $existingFiles = is_array($existingRemote) && isset($existingRemote['files']) && is_array($existingRemote['files'])
            ? $existingRemote['files']
            : [];
        $remoteFiles = [];
        $localFiles = [];
        $mainPath = $this->absolutePath($mainRelativePath, $uploadDir);

        if (is_readable($mainPath)) {
            $mainUpload = $this->ensureRemoteFile($mainPath, $mainRelativePath, (string) get_post_mime_type($attachmentId));

            if (! $mainUpload['success']) {
                update_post_meta($attachmentId, self::ERROR_META_KEY, $mainUpload['message']);
                return $data;
            }

            $remoteFiles['original'] = $mainUpload['key'];
            $localFiles[] = $mainPath;
        }

        if (isset($data['sizes']) && is_array($data['sizes'])) {
            foreach ($data['sizes'] as $sizeName => $sizeData) {
                if (! is_array($sizeData) || empty($sizeData['file']) || ! is_string($sizeData['file'])) {
                    continue;
                }

                $sizeRelativePath = $this->relativeSiblingPath($mainRelativePath, $sizeData['file']);
                $sizePath = $this->absolutePath($sizeRelativePath, $uploadDir);

                if (! is_readable($sizePath)) {
                    continue;
                }

                $sizeUpload = $this->ensureRemoteFile($sizePath, $sizeRelativePath, (string) ($sizeData['mime-type'] ?? get_post_mime_type($attachmentId)));

                if (! $sizeUpload['success']) {
                    update_post_meta($attachmentId, self::ERROR_META_KEY, $sizeUpload['message']);
                    continue;
                }

                $remoteFiles['sizes'][(string) $sizeName] = $sizeUpload['key'];
                $localFiles[] = $sizePath;
            }
        }

        if (! empty($data['original_image']) && is_string($data['original_image'])) {
            $originalRelativePath = $this->relativeSiblingPath($mainRelativePath, $data['original_image']);
            $originalPath = $this->absolutePath($originalRelativePath, $uploadDir);

            if (is_readable($originalPath)) {
                $originalUpload = $this->ensureRemoteFile($originalPath, $originalRelativePath, (string) get_post_mime_type($attachmentId));

                if ($originalUpload['success']) {
                    $remoteFiles['original_image'] = $originalUpload['key'];
                    $localFiles[] = $originalPath;
                }
            }
        }

        if ($remoteFiles === []) {
            return $data;
        }

        $previousKeys = array_values(array_diff($this->flattenKeys($existingFiles), $this->flattenKeys($remoteFiles)));

        if ($previousKeys !== []) {
            $remoteFiles['previous'] = $previousKeys;
        }

        update_post_meta(
            $attachmentId,
            self::REMOTE_META_KEY,
            [
                'bucket' => (string) $this->settings()['bucket'],
                'path_prefix' => $this->pathPrefix(),
                'public_base_url' => (string) ($this->settings()['public_base_url'] ?? ''),
                'files' => $remoteFiles,
            ]
        );
        delete_post_meta($attachmentId, self::ERROR_META_KEY);
        $this->deleteLocalFiles(array_merge($localFiles, $this->downloadedEditFiles), (string) $uploadDir['basedir']);
        $this->downloadedEditFiles = [];

        return $data;
    }

    /**
     * 将附件 URL 替换为远端公开 URL。
     *
     * @param string|false $url          WordPress 原始 URL。
     * @param int          $attachmentId 附件 ID。
     */
    public function remoteAttachmentUrl(string|false $url, int $attachmentId): string|false
    {
        $relativePath = $this->attachmentRelativePath($attachmentId);

        if ($relativePath === '' || ! $this->hasRemoteMeta($attachmentId)) {
            return $url;
        }

        return $this->publicUrl($relativePath);
    }

    /**
     * 将附件固定链接替换为远端文件公开 URL。
     *
     * WordPress 会为媒体文件创建 attachment post，并通过 get_attachment_link()
     * 输出附件页面固定链接；S3 接管后附件页面不再是实际文件位置。
     *
     * @param string $link         WordPress 原始附件固定链接。
     * @param int    $attachmentId 附件 ID。
     */
    public function remoteAttachmentLink(string $link, int $attachmentId): string
    {
        $remoteUrl = $this->remoteAttachmentUrl($link, $attachmentId);

        if (! is_string($remoteUrl) || $remoteUrl === '') {
            return $link;
        }

        return $remoteUrl;
    }

    /**
     * 将图片尺寸 URL 替换为远端公开 URL。
     *
     * @param mixed        $downsize     现有图片尺寸结果。
     * @param int          $attachmentId 附件 ID。
     * @param string|int[] $size         请求的图片尺寸。
     *
     * @return mixed
     */
    public function remoteImageDownsize(mixed $downsize, int $attachmentId, string|array $size): mixed
    {
        if ($downsize || ! $this->hasRemoteMeta($attachmentId)) {
            return $downsize;
        }

        $metadata = wp_get_attachment_metadata($attachmentId);

        if (! is_array($metadata) || empty($metadata['file']) || ! is_string($metadata['file'])) {
            return $downsize;
        }

        if (is_string($size) && isset($metadata['sizes'][$size]) && is_array($metadata['sizes'][$size])) {
            $sizeData = $metadata['sizes'][$size];

            if (! empty($sizeData['file']) && is_string($sizeData['file'])) {
                return [
                    $this->publicUrl($this->relativeSiblingPath((string) $metadata['file'], $sizeData['file'])),
                    (int) ($sizeData['width'] ?? 0),
                    (int) ($sizeData['height'] ?? 0),
                    true,
                ];
            }
        }

        return [
            $this->publicUrl((string) $metadata['file']),
            (int) ($metadata['width'] ?? 0),
            (int) ($metadata['height'] ?? 0),
            false,
        ];
    }

    /**
     * 将响应式图片 srcset 中的本地上传目录 URL 替换为远端公开 URL。
     *
     * @param array<int,array<string,mixed>> $sources      srcset 候选资源。
     * @param array<int,int>                 $sizeArray    请求的图片尺寸。
     * @param string                         $imageSrc     主图片 URL。
     * @param array<string,mixed>            $imageMeta    图片元数据。
     * @param int                            $attachmentId 附件 ID。
     *
     * @return array<int,array<string,mixed>>
     */
    public function remoteImageSrcset(array $sources, array $sizeArray, string $imageSrc, array $imageMeta, int $attachmentId): array
    {
        unset($sizeArray, $imageSrc, $imageMeta);

        if ($attachmentId <= 0 || ! $this->hasRemoteMeta($attachmentId)) {
            return $sources;
        }

        foreach ($sources as $width => $source) {
            if (! is_array($source) || empty($source['url']) || ! is_string($source['url'])) {
                continue;
            }

            $relativePath = $this->relativePathFromUploadUrl($source['url']);

            if ($relativePath === '') {
                continue;
            }

            $source['url'] = $this->publicUrl($relativePath);
            $sources[$width] = $source;
        }

        return $sources;
    }

    /**
     * 为媒体库 JS 数据补充远端状态，并确保主 URL 使用远端地址。
     *
     * @param array<string,mixed> $response   媒体库响应数据。
     * @param object             $attachment 附件对象。
     * @param array<string,mixed>|false $meta 附件元数据。
     *
     * @return array<string,mixed>
     */
    public function prepareAttachmentForJs(array $response, object $attachment, array|false $meta): array
    {
        unset($meta);

        if (! isset($attachment->ID) || ! $this->hasRemoteMeta((int) $attachment->ID)) {
            return $response;
        }

        $remoteUrl = $this->remoteAttachmentUrl((string) ($response['url'] ?? ''), (int) $attachment->ID);

        if (is_string($remoteUrl)) {
            $response['url'] = $remoteUrl;
            $response['link'] = $remoteUrl;
        }

        $response['purepressS3'] = [
            'enabled' => true,
        ];

        return $response;
    }

    /**
     * 图片编辑时将远端文件拉回本地工作目录。
     *
     * @param string|false $filepath     WordPress 当前解析出的本地路径或 URL。
     * @param int          $attachmentId 附件 ID。
     * @param string|int[] $size         请求的图片尺寸。
     */
    public function loadRemoteImageForEdit(string|false $filepath, int $attachmentId, string|array $size): string|false
    {
        if (! $this->hasRemoteMeta($attachmentId) || ! $this->isConfigured()) {
            return $filepath;
        }

        $uploadDir = $this->uploadDir();

        if ([] === $uploadDir) {
            return $filepath;
        }

        $relativePath = $this->imageRelativePathForSize($attachmentId, $size);

        if ($relativePath === '') {
            return $filepath;
        }

        $localPath = $this->absolutePath($relativePath, $uploadDir);

        if (is_readable($localPath)) {
            return $localPath;
        }

        if (! function_exists('wp_mkdir_p') || ! wp_mkdir_p(dirname($localPath))) {
            return $filepath;
        }

        $client = $this->client();

        if (null === $client) {
            return $filepath;
        }

        try {
            $result = $client->getObject(
                [
                    'Bucket' => (string) $this->settings()['bucket'],
                    'Key' => $this->objectKey($relativePath),
                ]
            );
            $written = file_put_contents($localPath, (string) $result['Body']);
        } catch (Throwable $throwable) {
            update_post_meta($attachmentId, self::ERROR_META_KEY, $throwable->getMessage());
            return $filepath;
        }

        if (false === $written) {
            update_post_meta($attachmentId, self::ERROR_META_KEY, 'PurePress 无法写入图片编辑工作文件。');
            return $filepath;
        }

        $this->downloadedEditFiles[] = $localPath;

        return $localPath;
    }

    /**
     * 请求结束时清理图片编辑临时拉回的本地文件。
     */
    public function cleanupDownloadedEditFiles(): void
    {
        if ($this->downloadedEditFiles === []) {
            return;
        }

        $uploadDir = $this->uploadDir();

        if ($uploadDir !== []) {
            $this->deleteLocalFiles($this->downloadedEditFiles, (string) $uploadDir['basedir']);
        }

        $this->downloadedEditFiles = [];
    }

    /**
     * 移动已接管媒体到指定媒体目录，并同步远端对象 Key。
     *
     * @param mixed $result       前一个存储处理器结果。
     * @param int   $attachmentId 附件 ID。
     * @param int   $folderId     目标媒体目录 ID，0 表示移出目录。
     *
     * @return mixed
     */
    public function moveAttachmentToMediaFolder(mixed $result, int $attachmentId, int $folderId): mixed
    {
        if (is_wp_error($result) || false === $result || ! $this->hasRemoteMeta($attachmentId)) {
            return $result;
        }

        if (! $this->isConfigured()) {
            return new \WP_Error('purepress_s3_not_configured', 'PurePress S3 兼容对象存储尚未完成配置。');
        }

        $client = $this->client();

        if (null === $client) {
            return new \WP_Error(
                'purepress_s3_client_unavailable',
                $this->clientError !== '' ? $this->clientError : 'PurePress 无法初始化 S3 客户端。'
            );
        }

        $currentRelativePath = $this->attachmentRelativePath($attachmentId);

        if ($currentRelativePath === '') {
            return new \WP_Error('purepress_s3_missing_attachment_path', 'PurePress 无法读取当前媒体文件路径。');
        }

        $targetRelativePath = $folderId > 0
            ? $this->pathService()->targetRelativePathForFolder($folderId, $currentRelativePath, $attachmentId)
            : $this->pathService()->uniqueRelativePath(wp_basename($currentRelativePath), $attachmentId);

        if ($targetRelativePath === '' || $targetRelativePath === $currentRelativePath) {
            return true;
        }

        $remote = get_post_meta($attachmentId, self::REMOTE_META_KEY, true);
        $remoteFiles = is_array($remote) && isset($remote['files']) && is_array($remote['files'])
            ? $remote['files']
            : [];
        $metadata = wp_get_attachment_metadata($attachmentId);
        $metadata = is_array($metadata) ? $metadata : [];
        $updatedMetadata = $this->metadataForMovedAttachment($metadata, $currentRelativePath, $targetRelativePath);
        $filePairs = $this->remoteFilePairsForMove($currentRelativePath, $targetRelativePath, $metadata, $updatedMetadata, $remoteFiles);

        if ([] === $filePairs) {
            return new \WP_Error('purepress_s3_missing_remote_files', 'PurePress 无法解析需要移动的远端媒体文件。');
        }

        $bucket = is_array($remote) && isset($remote['bucket']) && is_scalar($remote['bucket'])
            ? (string) $remote['bucket']
            : (string) $this->settings()['bucket'];

        $copiedKeys = [];

        foreach ($filePairs as $filePair) {
            $copyError = $this->copyRemoteObject($client, $bucket, $filePair['source_key'], $filePair['target_key']);

            if ($copyError instanceof \WP_Error) {
                $this->deleteRemoteKeys($client, $bucket, $copiedKeys);
                update_post_meta($attachmentId, self::ERROR_META_KEY, $copyError->get_error_message());
                return $copyError;
            }

            if ($filePair['source_key'] !== $filePair['target_key']) {
                $copiedKeys[] = $filePair['target_key'];
            }
        }

        $updatedRemoteFiles = $this->applyMovedRemoteFileKeys($remoteFiles, $filePairs);

        update_post_meta($attachmentId, '_wp_attached_file', $targetRelativePath);
        wp_update_attachment_metadata($attachmentId, $updatedMetadata);
        update_post_meta(
            $attachmentId,
            self::REMOTE_META_KEY,
            [
                'bucket' => $bucket,
                'path_prefix' => $this->pathPrefix(),
                'public_base_url' => (string) ($this->settings()['public_base_url'] ?? ''),
                'files' => $updatedRemoteFiles,
            ]
        );
        delete_post_meta($attachmentId, self::ERROR_META_KEY);

        $staleKeys = [];

        foreach ($filePairs as $filePair) {
            if ($filePair['source_key'] !== $filePair['target_key']) {
                $staleKeys[] = $filePair['source_key'];
            }
        }

        $deleteError = $this->deleteRemoteKeys($client, $bucket, $staleKeys);

        if ($deleteError instanceof \WP_Error) {
            update_post_meta($attachmentId, self::ERROR_META_KEY, $deleteError->get_error_message());
        }

        return true;
    }

    /**
     * 删除附件时同步删除远端对象。
     *
     * @param int    $attachmentId 附件 ID。
     * @param object $post         附件文章对象。
     */
    public function deleteRemoteObjects(int $attachmentId, object $post): void
    {
        unset($post);

        $remote = get_post_meta($attachmentId, self::REMOTE_META_KEY, true);

        if (! is_array($remote) || empty($remote['files']) || ! is_array($remote['files'])) {
            return;
        }

        $keys = $this->flattenKeys($remote['files']);

        if ([] === $keys || null === $this->client()) {
            return;
        }

        try {
            $this->client()->deleteObjects(
                [
                    'Bucket' => (string) ($remote['bucket'] ?? $this->settings()['bucket']),
                    'Delete' => [
                        'Objects' => array_map(
                            static fn (string $key): array => ['Key' => $key],
                            array_values(array_unique($keys))
                        ),
                        'Quiet' => true,
                    ],
                ]
            );
        } catch (Throwable $throwable) {
            update_post_meta($attachmentId, self::ERROR_META_KEY, $throwable->getMessage());
        }
    }

    /**
     * 判断指定附件是否已经被 PurePress 接管到远端。
     *
     * @param int $attachmentId 附件 ID。
     */
    private function hasRemoteMeta(int $attachmentId): bool
    {
        return is_array(get_post_meta($attachmentId, self::REMOTE_META_KEY, true));
    }

    /**
     * 获取媒体目录路径服务。
     */
    private function pathService(): S3MediaPathService
    {
        if (! ($this->pathService instanceof S3MediaPathService)) {
            $this->pathService = new S3MediaPathService();
        }

        return $this->pathService;
    }

    /**
     * 生成远端移动所需的源对象和目标对象映射。
     *
     * @param string              $currentRelativePath 当前主文件相对路径。
     * @param string              $targetRelativePath  目标主文件相对路径。
     * @param array<string,mixed> $sourceMetadata      当前附件元数据。
     * @param array<string,mixed> $targetMetadata      目标附件元数据。
     * @param array<string,mixed> $remoteFiles         远端文件元数据。
     *
     * @return list<array{kind: string, name: string, source_key: string, target_key: string}>
     */
    private function remoteFilePairsForMove(string $currentRelativePath, string $targetRelativePath, array $sourceMetadata, array $targetMetadata, array $remoteFiles): array
    {
        $filePairs = [
            [
                'kind' => 'original',
                'name' => '',
                'source_key' => $this->remoteFileKey($remoteFiles, 'original', '', $currentRelativePath),
                'target_key' => $this->objectKey($targetRelativePath),
            ],
        ];

        if (isset($sourceMetadata['sizes']) && is_array($sourceMetadata['sizes'])) {
            foreach ($sourceMetadata['sizes'] as $sizeName => $sizeData) {
                if (! is_array($sizeData) || empty($sizeData['file']) || ! is_string($sizeData['file'])) {
                    continue;
                }

                $targetSizeFile = isset($targetMetadata['sizes'][$sizeName]['file']) && is_string($targetMetadata['sizes'][$sizeName]['file'])
                    ? $targetMetadata['sizes'][$sizeName]['file']
                    : $sizeData['file'];

                $filePairs[] = [
                    'kind' => 'size',
                    'name' => (string) $sizeName,
                    'source_key' => $this->remoteFileKey(
                        $remoteFiles,
                        'size',
                        (string) $sizeName,
                        $this->relativeSiblingPath($currentRelativePath, $sizeData['file'])
                    ),
                    'target_key' => $this->objectKey($this->relativeSiblingPath($targetRelativePath, $targetSizeFile)),
                ];
            }
        }

        if (! empty($sourceMetadata['original_image']) && is_string($sourceMetadata['original_image'])) {
            $targetOriginalFile = ! empty($targetMetadata['original_image']) && is_string($targetMetadata['original_image'])
                ? $targetMetadata['original_image']
                : $sourceMetadata['original_image'];

            $filePairs[] = [
                'kind' => 'original_image',
                'name' => '',
                'source_key' => $this->remoteFileKey(
                    $remoteFiles,
                    'original_image',
                    '',
                    $this->relativeSiblingPath($currentRelativePath, $sourceMetadata['original_image'])
                ),
                'target_key' => $this->objectKey($this->relativeSiblingPath($targetRelativePath, $targetOriginalFile)),
            ];
        }

        return array_values(
            array_filter(
                $filePairs,
                static fn (array $filePair): bool => $filePair['source_key'] !== '' && $filePair['target_key'] !== ''
            )
        );
    }

    /**
     * 生成移动后附件元数据。
     *
     * @param array<string,mixed> $metadata            当前附件元数据。
     * @param string              $currentRelativePath 当前主文件相对路径。
     * @param string              $targetRelativePath  目标主文件相对路径。
     *
     * @return array<string,mixed>
     */
    private function metadataForMovedAttachment(array $metadata, string $currentRelativePath, string $targetRelativePath): array
    {
        $metadata['file'] = $targetRelativePath;

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $sizeName => $sizeData) {
                if (! is_array($sizeData) || empty($sizeData['file']) || ! is_string($sizeData['file'])) {
                    continue;
                }

                $metadata['sizes'][$sizeName]['file'] = $this->targetSiblingFile($currentRelativePath, $targetRelativePath, $sizeData['file']);
            }
        }

        if (! empty($metadata['original_image']) && is_string($metadata['original_image'])) {
            $metadata['original_image'] = $this->targetSiblingFile($currentRelativePath, $targetRelativePath, $metadata['original_image'], '-original');
        }

        return $metadata;
    }

    /**
     * 根据主文件目标文件名生成派生文件目标文件名。
     *
     * @param string $sourceMainRelativePath 源主文件相对路径。
     * @param string $targetMainRelativePath 目标主文件相对路径。
     * @param string $sourceSiblingFile      源派生文件名。
     * @param string $fallbackSuffix         无法按源主文件前缀替换时使用的后缀。
     */
    private function targetSiblingFile(string $sourceMainRelativePath, string $targetMainRelativePath, string $sourceSiblingFile, string $fallbackSuffix = ''): string
    {
        $sourceFile = wp_basename($sourceSiblingFile);
        $sourceMainBase = pathinfo(wp_basename($sourceMainRelativePath), PATHINFO_FILENAME);
        $targetMainBase = pathinfo(wp_basename($targetMainRelativePath), PATHINFO_FILENAME);

        if ($sourceMainBase === '' || $targetMainBase === '' || $sourceMainBase === $targetMainBase) {
            return $sourceFile;
        }

        if (str_starts_with($sourceFile, $sourceMainBase . '-')) {
            return $targetMainBase . substr($sourceFile, strlen($sourceMainBase));
        }

        if ($fallbackSuffix === '') {
            return $sourceFile;
        }

        $extension = pathinfo($sourceFile, PATHINFO_EXTENSION);

        return $targetMainBase . $fallbackSuffix . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * 读取远端文件 Key，缺失时根据相对路径回退生成。
     *
     * @param array<string,mixed> $remoteFiles  远端文件元数据。
     * @param string              $kind         文件类型。
     * @param string              $sizeName     图片尺寸名称。
     * @param string              $relativePath 文件相对路径。
     */
    private function remoteFileKey(array $remoteFiles, string $kind, string $sizeName, string $relativePath): string
    {
        if ($kind === 'size') {
            $key = $remoteFiles['sizes'][$sizeName] ?? '';

            return is_string($key) && $key !== '' ? $key : $this->objectKey($relativePath);
        }

        $key = $remoteFiles[$kind] ?? '';

        return is_string($key) && $key !== '' ? $key : $this->objectKey($relativePath);
    }

    /**
     * 将移动后的远端 Key 写回远端文件元数据。
     *
     * @param array<string,mixed> $remoteFiles 远端文件元数据。
     * @param list<array{kind: string, name: string, source_key: string, target_key: string}> $filePairs 移动文件映射。
     *
     * @return array<string,mixed>
     */
    private function applyMovedRemoteFileKeys(array $remoteFiles, array $filePairs): array
    {
        foreach ($filePairs as $filePair) {
            if ($filePair['kind'] === 'size') {
                if (! isset($remoteFiles['sizes']) || ! is_array($remoteFiles['sizes'])) {
                    $remoteFiles['sizes'] = [];
                }

                $remoteFiles['sizes'][$filePair['name']] = $filePair['target_key'];
                continue;
            }

            $remoteFiles[$filePair['kind']] = $filePair['target_key'];
        }

        return $remoteFiles;
    }

    /**
     * 复制远端对象。
     *
     * @param S3Client $client    S3 客户端。
     * @param string   $bucket    Bucket 名称。
     * @param string   $sourceKey 源对象 Key。
     * @param string   $targetKey 目标对象 Key。
     */
    private function copyRemoteObject(S3Client $client, string $bucket, string $sourceKey, string $targetKey): ?\WP_Error
    {
        if ($sourceKey === $targetKey) {
            return null;
        }

        try {
            $client->copyObject(
                [
                    'Bucket' => $bucket,
                    'CopySource' => $this->copySource($bucket, $sourceKey),
                    'Key' => $targetKey,
                ]
            );
        } catch (Throwable $throwable) {
            return new \WP_Error('purepress_s3_copy_failed', 'PurePress 移动远端媒体文件失败：' . $throwable->getMessage());
        }

        return null;
    }

    /**
     * 删除远端对象 Key。
     *
     * @param S3Client    $client S3 客户端。
     * @param string      $bucket Bucket 名称。
     * @param list<string> $keys  对象 Key 列表。
     */
    private function deleteRemoteKeys(S3Client $client, string $bucket, array $keys): ?\WP_Error
    {
        $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));

        if ([] === $keys) {
            return null;
        }

        try {
            $client->deleteObjects(
                [
                    'Bucket' => $bucket,
                    'Delete' => [
                        'Objects' => array_map(
                            static fn (string $key): array => ['Key' => $key],
                            $keys
                        ),
                        'Quiet' => true,
                    ],
                ]
            );
        } catch (Throwable $throwable) {
            return new \WP_Error('purepress_s3_delete_failed', 'PurePress 清理旧远端媒体文件失败：' . $throwable->getMessage());
        }

        return null;
    }

    /**
     * 生成 S3 CopySource 值。
     *
     * @param string $bucket Bucket 名称。
     * @param string $key    对象 Key。
     */
    private function copySource(string $bucket, string $key): string
    {
        return str_replace('%2F', '/', rawurlencode($bucket . '/' . $key));
    }

    /**
     * 确保本地文件已经存在于远端对象存储。
     *
     * @param string $absolutePath 本地绝对路径。
     * @param string $relativePath 相对上传目录的路径。
     * @param string $contentType  文件 MIME 类型。
     *
     * @return array{success: bool, key: string, message: string}
     */
    private function ensureRemoteFile(string $absolutePath, string $relativePath, string $contentType): array
    {
        $normalizedPath = $this->normalizePath($absolutePath);

        if (isset($this->uploadedFiles[$normalizedPath])) {
            return [
                'success' => true,
                'key' => $this->uploadedFiles[$normalizedPath],
                'message' => '',
            ];
        }

        return $this->uploadFile($absolutePath, $relativePath, $contentType);
    }

    /**
     * 上传本地文件到远端对象存储。
     *
     * @param string $absolutePath 本地绝对路径。
     * @param string $relativePath 相对上传目录的路径。
     * @param string $contentType  文件 MIME 类型。
     *
     * @return array{success: bool, key: string, message: string}
     */
    private function uploadFile(string $absolutePath, string $relativePath, string $contentType): array
    {
        if (! is_readable($absolutePath)) {
            return [
                'success' => false,
                'key' => '',
                'message' => 'PurePress 无法读取待上传的媒体文件。',
            ];
        }

        $client = $this->client();

        if (null === $client) {
            return [
                'success' => false,
                'key' => '',
                'message' => $this->clientError !== '' ? $this->clientError : 'PurePress 无法初始化 S3 客户端。',
            ];
        }

        $key = $this->objectKey($relativePath);
        $parameters = [
            'Bucket' => (string) $this->settings()['bucket'],
            'Key' => $key,
            'SourceFile' => $absolutePath,
        ];

        if ($contentType !== '') {
            $parameters['ContentType'] = $contentType;
        }

        try {
            $client->putObject($parameters);
        } catch (Throwable $throwable) {
            return [
                'success' => false,
                'key' => '',
                'message' => $throwable->getMessage(),
            ];
        }

        $this->uploadedFiles[$this->normalizePath($absolutePath)] = $key;

        return [
            'success' => true,
            'key' => $key,
            'message' => '',
        ];
    }

    /**
     * 获取 S3 客户端。
     */
    private function client(): ?S3Client
    {
        if ($this->client instanceof S3Client) {
            return $this->client;
        }

        if (! class_exists(S3Client::class)) {
            $this->clientError = 'PurePress 缺少 Composer 依赖，请使用包含 vendor 目录的安装包。';
            return null;
        }

        if (! $this->isConfigured()) {
            $this->clientError = 'PurePress S3 兼容对象存储尚未完成配置。';
            return null;
        }

        $settings = $this->settings();
        $configuration = [
            'version' => 'latest',
            'region' => (string) ($settings['region'] ?: 'auto'),
            'credentials' => [
                'key' => (string) $settings['access_key'],
                'secret' => (string) $settings['secret_key'],
            ],
            'use_path_style_endpoint' => (bool) ($settings['path_style'] ?? true),
            'http' => [
                'connect_timeout' => 10,
                'timeout' => 30,
            ],
        ];

        if ((string) ($settings['endpoint'] ?? '') !== '') {
            $configuration['endpoint'] = (string) $settings['endpoint'];
        }

        try {
            $this->client = new S3Client($configuration);
        } catch (Throwable $throwable) {
            $this->clientError = 'PurePress 无法初始化 S3 客户端：' . $throwable->getMessage();
            return null;
        }

        return $this->client;
    }

    /**
     * 判断当前模块是否具备最小可用配置。
     */
    private function isConfigured(): bool
    {
        $settings = $this->settings();

        return (string) ($settings['bucket'] ?? '') !== ''
            && (string) ($settings['access_key'] ?? '') !== ''
            && (string) ($settings['secret_key'] ?? '') !== ''
            && (string) ($settings['public_base_url'] ?? '') !== '';
    }

    /**
     * 获取模块配置。
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        if (null === $this->settings) {
            $this->settings = (new OptionRepository())->moduleSettings(self::MODULE_ID);
        }

        return $this->settings;
    }

    /**
     * 获取当前上传目录信息。
     *
     * @return array<string,mixed>
     */
    private function uploadDir(): array
    {
        if (! function_exists('wp_get_upload_dir')) {
            return [];
        }

        $uploadDir = wp_get_upload_dir();

        return is_array($uploadDir) ? $uploadDir : [];
    }

    /**
     * 根据附件 ID 获取上传目录相对路径。
     *
     * @param int $attachmentId 附件 ID。
     */
    private function attachmentRelativePath(int $attachmentId): string
    {
        $file = get_post_meta($attachmentId, '_wp_attached_file', true);

        return is_string($file) ? $this->sanitizeRelativePath($file) : '';
    }

    /**
     * 根据附件和尺寸获取上传目录相对路径。
     *
     * @param int          $attachmentId 附件 ID。
     * @param string|int[] $size         请求的图片尺寸。
     */
    private function imageRelativePathForSize(int $attachmentId, string|array $size): string
    {
        $mainRelativePath = $this->attachmentRelativePath($attachmentId);

        if ($mainRelativePath === '') {
            return '';
        }

        if (! is_string($size) || $size === 'full') {
            return $mainRelativePath;
        }

        $metadata = wp_get_attachment_metadata($attachmentId);

        if (
            is_array($metadata)
            && isset($metadata['sizes'][$size])
            && is_array($metadata['sizes'][$size])
            && ! empty($metadata['sizes'][$size]['file'])
            && is_string($metadata['sizes'][$size]['file'])
        ) {
            return $this->relativeSiblingPath($mainRelativePath, $metadata['sizes'][$size]['file']);
        }

        return $mainRelativePath;
    }

    /**
     * 根据相对上传目录路径生成本地绝对路径。
     *
     * @param string              $relativePath 相对上传目录路径。
     * @param array<string,mixed> $uploadDir    上传目录信息。
     */
    private function absolutePath(string $relativePath, array $uploadDir): string
    {
        return rtrim($this->normalizePath((string) $uploadDir['basedir']), '/') . '/' . $this->sanitizeRelativePath($relativePath);
    }

    /**
     * 根据主文件相对路径和同级文件名生成派生文件相对路径。
     *
     * @param string $mainRelativePath 主文件相对路径。
     * @param string $siblingFile      同级派生文件名。
     */
    private function relativeSiblingPath(string $mainRelativePath, string $siblingFile): string
    {
        $directory = trim(dirname($this->sanitizeRelativePath($mainRelativePath)), '.');
        $siblingFile = wp_basename($siblingFile);

        return $this->sanitizeRelativePath(($directory !== '' ? $directory . '/' : '') . $siblingFile);
    }

    /**
     * 从上传目录 URL 中还原相对路径。
     *
     * @param string $url 上传目录下的文件 URL。
     */
    private function relativePathFromUploadUrl(string $url): string
    {
        $uploadDir = $this->uploadDir();

        if ([] === $uploadDir || empty($uploadDir['baseurl'])) {
            return '';
        }

        $urlPath = parse_url($url, PHP_URL_PATH);
        $basePath = parse_url((string) $uploadDir['baseurl'], PHP_URL_PATH);

        if (! is_string($urlPath) || ! is_string($basePath)) {
            return '';
        }

        $urlPath = '/' . ltrim($urlPath, '/');
        $basePath = rtrim('/' . ltrim($basePath, '/'), '/');

        if (! str_starts_with($urlPath, $basePath . '/')) {
            return '';
        }

        return $this->sanitizeRelativePath(substr($urlPath, strlen($basePath) + 1));
    }

    /**
     * 生成远端对象 Key。
     *
     * @param string $relativePath 相对上传目录路径。
     */
    private function objectKey(string $relativePath): string
    {
        $prefix = $this->pathPrefix();
        $relativePath = $this->sanitizeRelativePath($relativePath);

        return $prefix !== '' ? $prefix . '/' . $relativePath : $relativePath;
    }

    /**
     * 生成远端公开访问 URL。
     *
     * @param string $relativePath 相对上传目录路径。
     */
    private function publicUrl(string $relativePath): string
    {
        $settings = $this->settings();
        $baseUrl = rtrim((string) ($settings['public_base_url'] ?? ''), '/');
        $urlPrefix = $this->pathPrefix();
        $relativePath = $this->sanitizeRelativePath($relativePath);
        $path = $urlPrefix !== '' ? $urlPrefix . '/' . $relativePath : $relativePath;

        return $baseUrl . '/' . $path;
    }

    /**
     * 获取远端对象和公开 URL 共用的路径前缀。
     */
    private function pathPrefix(): string
    {
        $settings = $this->settings();

        if (isset($settings['path_prefix']) && is_scalar($settings['path_prefix'])) {
            return $this->sanitizeRelativePath((string) $settings['path_prefix']);
        }

        if (isset($settings['object_prefix']) && is_scalar($settings['object_prefix'])) {
            return $this->sanitizeRelativePath((string) $settings['object_prefix']);
        }

        return '';
    }

    /**
     * 清理本地工作文件。
     *
     * @param list<string> $files   本地文件列表。
     * @param string       $baseDir 上传目录根路径。
     */
    private function deleteLocalFiles(array $files, string $baseDir): void
    {
        $baseDir = rtrim($this->normalizePath($baseDir), '/');
        $directories = [];

        foreach (array_values(array_unique($files)) as $file) {
            $file = $this->normalizePath($file);

            if (! str_starts_with($file, $baseDir . '/') || ! is_file($file)) {
                continue;
            }

            $directories[] = dirname($file);

            if (function_exists('wp_delete_file')) {
                wp_delete_file($file);
            } else {
                @unlink($file);
            }
        }

        $this->deleteEmptyLocalDirectories($directories, $baseDir);
    }

    /**
     * 清理上传目录下已经为空的工作目录。
     *
     * @param list<string> $directories 本地目录列表。
     * @param string       $baseDir     上传目录根路径。
     */
    private function deleteEmptyLocalDirectories(array $directories, string $baseDir): void
    {
        $baseDir = rtrim($this->normalizePath($baseDir), '/');
        $directories = array_values(array_unique(array_map([$this, 'normalizePath'], $directories)));
        usort(
            $directories,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left)
        );

        foreach ($directories as $directory) {
            $directory = rtrim($directory, '/');

            while (
                $directory !== ''
                && $directory !== $baseDir
                && str_starts_with($directory, $baseDir . '/')
                && is_dir($directory)
                && $this->isDirectoryEmpty($directory)
            ) {
                @rmdir($directory);
                $directory = dirname($directory);
            }
        }
    }

    /**
     * 判断本地目录是否为空。
     *
     * @param string $directory 本地目录路径。
     */
    private function isDirectoryEmpty(string $directory): bool
    {
        $items = scandir($directory);

        if (! is_array($items)) {
            return false;
        }

        return count(array_diff($items, ['.', '..'])) === 0;
    }

    /**
     * 展平远端对象 Key 列表。
     *
     * @param array<string,mixed> $files 远端文件元数据。
     *
     * @return list<string>
     */
    private function flattenKeys(array $files): array
    {
        $keys = [];

        foreach ($files as $value) {
            if (is_string($value) && $value !== '') {
                $keys[] = $value;
                continue;
            }

            if (is_array($value)) {
                $keys = array_merge($keys, $this->flattenKeys($value));
            }
        }

        return array_values(array_unique($keys));
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

    /**
     * 标准化本地路径分隔符。
     *
     * @param string $path 本地路径。
     */
    private function normalizePath(string $path): string
    {
        return function_exists('wp_normalize_path') ? wp_normalize_path($path) : str_replace('\\', '/', $path);
    }
}
