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
        $hooks->filter('wp_handle_upload', [$this, 'uploadOriginalFile'], 20, 2);
        $hooks->filter('wp_generate_attachment_metadata', [$this, 'uploadAttachmentFiles'], 20, 3);
        $hooks->filter('wp_get_attachment_url', [$this, 'remoteAttachmentUrl'], 20, 2);
        $hooks->filter('image_downsize', [$this, 'remoteImageDownsize'], 20, 3);
        $hooks->filter('wp_prepare_attachment_for_js', [$this, 'prepareAttachmentForJs'], 20, 3);
        $hooks->action('delete_attachment', [$this, 'deleteRemoteObjects'], 10, 2);
    }

    /**
     * WordPress 将上传文件移动到工作区后，立即上传原始文件到远端。
     *
     * @param array<string,mixed> $upload  WordPress 上传结果。
     * @param string              $context 上传上下文。
     *
     * @return array<string,mixed>
     */
    public function uploadOriginalFile(array $upload, string $context): array
    {
        unset($context);

        if (isset($upload['error']) || ! isset($upload['file']) || ! is_string($upload['file'])) {
            return $upload;
        }

        if (! $this->isConfigured()) {
            return ['error' => 'PurePress S3 兼容对象存储尚未完成配置。'];
        }

        $relativePath = $this->relativePath($upload['file']);

        if ($relativePath === '') {
            return ['error' => 'PurePress 无法识别媒体文件路径。'];
        }

        $result = $this->uploadFile($upload['file'], $relativePath, (string) ($upload['type'] ?? ''));

        if (! $result['success']) {
            return ['error' => $result['message']];
        }

        $this->uploadedFiles[$this->normalizePath($upload['file'])] = $result['key'];
        $upload['url'] = $this->publicUrl($relativePath);

        return $upload;
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

        $mainRelativePath = ! empty($data['file']) && is_string($data['file'])
            ? $this->sanitizeRelativePath((string) $data['file'])
            : $this->attachmentRelativePath($attachmentId);

        $remote = [
            'bucket' => (string) $this->settings()['bucket'],
            'object_prefix' => (string) ($this->settings()['object_prefix'] ?? ''),
            'public_base_url' => (string) ($this->settings()['public_base_url'] ?? ''),
            'public_url_prefix' => (string) ($this->settings()['public_url_prefix'] ?? ''),
            'files' => [],
        ];

        if ($mainRelativePath === '') {
            return $data;
        }

        $localFiles = [];
        $mainPath = $this->absolutePath($mainRelativePath, $uploadDir);
        $mainUpload = $this->ensureRemoteFile($mainPath, $mainRelativePath, (string) get_post_mime_type($attachmentId));

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

                $sizeRelativePath = $this->relativeSiblingPath($mainRelativePath, $sizeData['file']);
                $sizePath = $this->absolutePath($sizeRelativePath, $uploadDir);
                $sizeUpload = $this->ensureRemoteFile($sizePath, $sizeRelativePath, (string) ($sizeData['mime-type'] ?? get_post_mime_type($attachmentId)));

                if (! $sizeUpload['success']) {
                    unset($data['sizes'][$sizeName]);
                    continue;
                }

                $remote['files']['sizes'][(string) $sizeName] = $sizeUpload['key'];
                $localFiles[] = $sizePath;
            }
        }

        if (! empty($data['original_image']) && is_string($data['original_image'])) {
            $originalRelativePath = $this->relativeSiblingPath($mainRelativePath, $data['original_image']);
            $originalPath = $this->absolutePath($originalRelativePath, $uploadDir);
            $originalUpload = $this->ensureRemoteFile($originalPath, $originalRelativePath, (string) get_post_mime_type($attachmentId));

            if ($originalUpload['success']) {
                $remote['files']['original_image'] = $originalUpload['key'];
                $localFiles[] = $originalPath;
            }
        }

        if (! empty($data['filesize']) || ! is_readable($mainPath)) {
            $data['filesize'] = isset($data['filesize']) ? (int) $data['filesize'] : 0;
        } else {
            $data['filesize'] = (int) filesize($mainPath);
        }

        update_post_meta($attachmentId, self::REMOTE_META_KEY, $remote);
        delete_post_meta($attachmentId, self::ERROR_META_KEY);
        $this->deleteLocalFiles($localFiles, (string) $uploadDir['basedir']);

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
        }

        $response['purepressS3'] = [
            'enabled' => true,
        ];

        return $response;
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
                'message' => 'PurePress 无法初始化 S3 客户端，请检查依赖和配置。',
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

        if (! class_exists(S3Client::class) || ! $this->isConfigured()) {
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

        $this->client = new S3Client($configuration);

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
     * 将本地绝对路径转换为相对上传目录路径。
     *
     * @param string $absolutePath 本地绝对路径。
     */
    private function relativePath(string $absolutePath): string
    {
        $uploadDir = $this->uploadDir();

        if ([] === $uploadDir || empty($uploadDir['basedir']) || ! is_string($uploadDir['basedir'])) {
            return '';
        }

        $baseDir = rtrim($this->normalizePath($uploadDir['basedir']), '/');
        $path = $this->normalizePath($absolutePath);

        if (! str_starts_with($path, $baseDir . '/')) {
            return '';
        }

        return $this->sanitizeRelativePath(substr($path, strlen($baseDir) + 1));
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
     * 生成远端对象 Key。
     *
     * @param string $relativePath 相对上传目录路径。
     */
    private function objectKey(string $relativePath): string
    {
        $prefix = $this->sanitizeRelativePath((string) ($this->settings()['object_prefix'] ?? ''));
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
        $urlPrefix = $this->sanitizeRelativePath((string) ($settings['public_url_prefix'] ?? ''));
        $relativePath = $this->sanitizeRelativePath($relativePath);
        $path = $urlPrefix !== '' ? $urlPrefix . '/' . $relativePath : $relativePath;

        return $baseUrl . '/' . $path;
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

        foreach (array_values(array_unique($files)) as $file) {
            $file = $this->normalizePath($file);

            if (! str_starts_with($file, $baseDir . '/') || ! is_file($file)) {
                continue;
            }

            if (function_exists('wp_delete_file')) {
                wp_delete_file($file);
            } else {
                @unlink($file);
            }
        }
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
