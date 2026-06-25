<?php
/**
 * 管理 PurePress GeoIP 本地数据库。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Governance;

use GeoIp2\Database\Reader;
use PurePress\Configuration\OptionKeys;
use Throwable;

final class GeoIpDatabase
{
    private const string DOWNLOAD_URL = 'https://git.io/GeoLite2-City.mmdb';
    private const string DATABASE_FILE = 'GeoLite2-City.mmdb';
    private const string DATA_ROOT = 'purepress';
    private const string DATA_DIRECTORY = 'data';
    private const string GEOIP_DIRECTORY = 'geoip';
    private const int MINIMUM_DATABASE_SIZE = 1024;
    private const int METADATA_SEARCH_BYTES = 131072;

    /**
     * 获取 GeoIP 数据库状态。
     *
     * @return array{exists: bool, path: string, updated_at: int|null, size: int}
     */
    public function status(): array
    {
        $path = self::databasePath();
        $exists = $path !== '' && is_readable($path);

        return [
            'exists' => $exists,
            'path' => $path,
            'updated_at' => $exists ? $this->lastUpdatedAt($path) : null,
            'size' => $exists ? (int) filesize($path) : 0,
        ];
    }

    /**
     * 下载并替换 GeoIP 数据库。
     *
     * @return array{success: bool, message: string}
     */
    public function update(): array
    {
        $directory = self::databaseDirectoryPath();
        $databasePath = self::databasePath();

        if ($directory === '' || $databasePath === '') {
            return [
                'success' => false,
                'message' => '无法定位 GeoIP 数据目录。',
            ];
        }

        if (! $this->ensureDatabaseDirectory($directory)) {
            return [
                'success' => false,
                'message' => '无法创建 GeoIP 数据目录。',
            ];
        }

        $temporaryFile = $this->temporaryFile();

        if ($temporaryFile === '') {
            return [
                'success' => false,
                'message' => '无法创建 GeoIP 临时下载文件。',
            ];
        }

        if (! function_exists('wp_remote_get')) {
            @unlink($temporaryFile);

            return [
                'success' => false,
                'message' => '当前环境无法使用 WordPress HTTP API。',
            ];
        }

        $response = wp_remote_get(
            self::DOWNLOAD_URL,
            [
                'timeout' => 120,
                'redirection' => 5,
                'stream' => true,
                'filename' => $temporaryFile,
            ]
        );

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            @unlink($temporaryFile);

            return [
                'success' => false,
                'message' => 'GeoIP 数据库下载失败。',
            ];
        }

        $responseCode = function_exists('wp_remote_retrieve_response_code')
            ? (int) wp_remote_retrieve_response_code($response)
            : 0;

        if ($responseCode < 200 || $responseCode >= 300 || ! $this->isValidDatabaseFile($temporaryFile)) {
            @unlink($temporaryFile);

            return [
                'success' => false,
                'message' => 'GeoIP 数据库文件无效。',
            ];
        }

        $stagingFile = $databasePath . '.tmp';
        @unlink($stagingFile);

        if (! @copy($temporaryFile, $stagingFile)) {
            @unlink($temporaryFile);

            return [
                'success' => false,
                'message' => '无法写入 GeoIP 数据库。',
            ];
        }

        @unlink($temporaryFile);

        if (! $this->isValidDatabaseFile($stagingFile)) {
            @unlink($stagingFile);

            return [
                'success' => false,
                'message' => 'GeoIP 数据库文件无效。',
            ];
        }

        if (is_file($databasePath) && strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            @unlink($databasePath);
        }

        if (! @rename($stagingFile, $databasePath)) {
            @unlink($stagingFile);

            return [
                'success' => false,
                'message' => '无法替换 GeoIP 数据库。',
            ];
        }

        @chmod($databasePath, 0644);

        if (function_exists('update_option')) {
            update_option(OptionKeys::geoIpDatabaseUpdatedAt(), time(), false);
        }

        return [
            'success' => true,
            'message' => 'GeoIP 数据库已更新。',
        ];
    }

    /**
     * 根据 IP 地址查询归属地。
     *
     * @param string $ip IP 地址。
     *
     * @return array{country: string, region: string, city: string, timezone: string, display: string}
     */
    public function lookup(string $ip): array
    {
        if (
            ! class_exists(Reader::class)
            || self::databasePath() === ''
            || ! is_readable(self::databasePath())
            || false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            return $this->emptyLocation();
        }

        $reader = null;

        try {
            $reader = new Reader(self::databasePath(), ['zh-CN', 'zh-TW', 'en']);
            $record = $reader->city($ip);
            $country = $this->localizedName($record->country);
            $region = $this->localizedName($record->mostSpecificSubdivision);
            $city = $this->localizedName($record->city);
            $timezone = is_string($record->location->timeZone) ? $record->location->timeZone : '';
            $display = implode(' ', $this->compactLocationParts([$country, $region, $city]));

            return [
                'country' => $country,
                'region' => $region,
                'city' => $city,
                'timezone' => $timezone,
                'display' => $display,
            ];
        } catch (Throwable) {
            return $this->emptyLocation();
        } finally {
            if ($reader instanceof Reader) {
                $reader->close();
            }
        }
    }

    /**
     * 获取 GeoIP 数据库路径。
     */
    public static function databasePath(): string
    {
        $directory = self::databaseDirectoryPath();

        return $directory === '' ? '' : $directory . '/' . self::DATABASE_FILE;
    }

    /**
     * 删除 PurePress 运行数据目录。
     */
    public static function deleteDataRoot(): void
    {
        $path = self::dataRootPath();

        if ($path === '' || ! is_dir($path)) {
            return;
        }

        self::deleteDirectory($path);
    }

    /**
     * 获取 GeoIP 数据库目录路径。
     */
    private static function databaseDirectoryPath(): string
    {
        $dataRoot = self::dataRootPath();

        return $dataRoot === '' ? '' : $dataRoot . '/' . self::DATA_DIRECTORY . '/' . self::GEOIP_DIRECTORY;
    }

    /**
     * 获取 PurePress 运行数据根目录。
     */
    private static function dataRootPath(): string
    {
        if (! defined('WP_CONTENT_DIR')) {
            return '';
        }

        return rtrim((string) WP_CONTENT_DIR, '/\\') . '/' . self::DATA_ROOT;
    }

    /**
     * 确保 GeoIP 数据库目录与基础防访问文件存在。
     *
     * @param string $directory GeoIP 数据库目录。
     */
    private function ensureDatabaseDirectory(string $directory): bool
    {
        if (! is_dir($directory)) {
            $created = function_exists('wp_mkdir_p') ? wp_mkdir_p($directory) : mkdir($directory, 0755, true);

            if (! $created && ! is_dir($directory)) {
                return false;
            }
        }

        foreach ($this->protectableDirectories($directory) as $protectableDirectory) {
            $this->ensureProtectionFiles($protectableDirectory);
        }

        return is_writable($directory);
    }

    /**
     * 获取需要放置基础防访问文件的目录。
     *
     * @param string $geoIpDirectory GeoIP 数据库目录。
     *
     * @return list<string>
     */
    private function protectableDirectories(string $geoIpDirectory): array
    {
        $dataRoot = self::dataRootPath();

        if ($dataRoot === '') {
            return [$geoIpDirectory];
        }

        return array_values(
            array_filter(
                [
                    $dataRoot,
                    $dataRoot . '/' . self::DATA_DIRECTORY,
                    $geoIpDirectory,
                ],
                'is_dir'
            )
        );
    }

    /**
     * 写入基础防访问文件。
     *
     * @param string $directory 目录路径。
     */
    private function ensureProtectionFiles(string $directory): void
    {
        $indexFile = $directory . '/index.php';
        $htaccessFile = $directory . '/.htaccess';

        if (! is_file($indexFile)) {
            @file_put_contents($indexFile, "<?php\n// Silence is golden.\n");
        }

        if (! is_file($htaccessFile)) {
            @file_put_contents($htaccessFile, "Require all denied\nDeny from all\n");
        }
    }

    /**
     * 创建下载临时文件。
     */
    private function temporaryFile(): string
    {
        if (function_exists('wp_tempnam')) {
            $temporaryFile = wp_tempnam(self::DATABASE_FILE);

            return is_string($temporaryFile) ? $temporaryFile : '';
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'purepress-geoip-');

        return is_string($temporaryFile) ? $temporaryFile : '';
    }

    /**
     * 判断文件是否是可用的 MaxMind 数据库。
     *
     * @param string $path 文件路径。
     */
    private function isValidDatabaseFile(string $path): bool
    {
        if (! is_readable($path)) {
            return false;
        }

        $size = filesize($path);

        if (! is_int($size) || $size < self::MINIMUM_DATABASE_SIZE) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if (! is_resource($handle)) {
            return false;
        }

        $offset = max(0, $size - self::METADATA_SEARCH_BYTES);
        fseek($handle, $offset);
        $tail = fread($handle, self::METADATA_SEARCH_BYTES);
        fclose($handle);

        return is_string($tail) && str_contains($tail, 'MaxMind.com');
    }

    /**
     * 获取数据库最后更新时间。
     *
     * @param string $path 数据库路径。
     */
    private function lastUpdatedAt(string $path): ?int
    {
        $stored = function_exists('get_option') ? get_option(OptionKeys::geoIpDatabaseUpdatedAt(), null) : null;

        if (is_numeric($stored) && (int) $stored > 0) {
            return (int) $stored;
        }

        $modifiedAt = filemtime($path);

        return is_int($modifiedAt) ? $modifiedAt : null;
    }

    /**
     * 读取 GeoIP 记录的本地化名称。
     *
     * @param object $record GeoIP 记录对象。
     */
    private function localizedName(object $record): string
    {
        if (isset($record->names) && is_array($record->names)) {
            foreach (['zh-CN', 'zh-TW', 'en'] as $locale) {
                if (isset($record->names[$locale]) && is_string($record->names[$locale]) && $record->names[$locale] !== '') {
                    return $record->names[$locale];
                }
            }
        }

        return isset($record->name) && is_string($record->name) ? $record->name : '';
    }

    /**
     * 合并并去重地理位置片段。
     *
     * @param list<string> $parts 地理位置片段。
     *
     * @return list<string>
     */
    private function compactLocationParts(array $parts): array
    {
        $compacted = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || in_array($part, $compacted, true)) {
                continue;
            }

            $compacted[] = $part;
        }

        return $compacted;
    }

    /**
     * 获取空地理位置结果。
     *
     * @return array{country: string, region: string, city: string, timezone: string, display: string}
     */
    private function emptyLocation(): array
    {
        return [
            'country' => '',
            'region' => '',
            'city' => '',
            'timezone' => '',
            'display' => '',
        ];
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

            if (is_dir($itemPath)) {
                self::deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
