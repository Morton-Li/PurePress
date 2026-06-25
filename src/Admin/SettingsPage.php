<?php
/**
 * PurePress 后台设置页。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Admin;

use PurePress\Configuration\ModuleCatalog;
use PurePress\Configuration\ModuleDefinition;
use PurePress\Configuration\OptionRepository;
use PurePress\Governance\GeoIpDatabase;
use PurePress\Governance\LoginAddressModule;
use PurePress\Governance\LoginAuditModule;
use PurePress\Governance\RegistrationRateLimitModule;
use PurePress\Support\HookRegistry;

final class SettingsPage
{
    private const string PAGE_SLUG = 'purepress';
    private const string LOGIN_ADDRESS_MODULE_ID = LoginAddressModule::MODULE_ID;
    private const string LOGIN_AUDIT_MODULE_ID = LoginAuditModule::MODULE_ID;
    private const string REGISTRATION_RATE_LIMIT_MODULE_ID = RegistrationRateLimitModule::MODULE_ID;
    private const string SMTP_MODULE_ID = 'enhancement.smtp';
    private const string S3_MEDIA_MODULE_ID = 'integration.s3_media';

    /**
     * PurePress 预留的配置层级。
     *
     * 当前只显示存在模块的层级；后续新增 Optimization、Enhancement、Integration、
     * Replacement、Configuration 模块时会自动显示对应标签页。
     *
     * @var list<string>
     */
    private const array GROUPS = [
        'Governance',
        'Optimization',
        'Enhancement',
        'Integration',
        'Replacement',
        'Configuration',
    ];

    /**
     * 配置读取与保存仓库。
     */
    private OptionRepository $options;

    /**
     * 创建设置页实例。
     *
     * @param OptionRepository $options 配置读取与保存仓库。
     */
    public function __construct(OptionRepository $options)
    {
        $this->options = $options;
    }

    /**
     * 注册后台菜单与保存动作。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('admin_menu', [$this, 'registerMenu']);
        $hooks->action('admin_post_purepress_save_settings', [$this, 'save']);
        $hooks->action('admin_post_purepress_send_test_email', [$this, 'sendTestEmail']);
        $hooks->action('admin_post_purepress_update_geoip_database', [$this, 'updateGeoIpDatabase']);
    }

    /**
     * 注册 PurePress 设置菜单。
     */
    public function registerMenu(): void
    {
        add_options_page(
            'PurePress 设置',
            'PurePress',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * 保存设置页提交的模块启用状态。
     */
    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('你没有权限修改 PurePress 设置。');
        }

        check_admin_referer('purepress_save_settings');

        $group = $this->submittedGroup();
        $moduleIds = $this->submittedModuleIds();
        $allowedModuleIds = $this->moduleIdsForGroup($group);

        $this->validateSubmittedModuleSettings($allowedModuleIds);
        $this->options->saveModuleStates($moduleIds, $allowedModuleIds);
        $this->saveSubmittedModuleSettings($allowedModuleIds);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'purepress_tab' => $group,
                    'purepress_saved' => '1',
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }

    /**
     * 发送 SMTP 测试邮件。
     */
    public function sendTestEmail(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('你没有权限发送 PurePress 测试邮件。');
        }

        check_admin_referer('purepress_send_test_email');

        $recipient = $this->submittedTestEmail();
        $result = 'invalid';

        if ($recipient !== '') {
            $smtpSettings = $this->options->moduleSettings(self::SMTP_MODULE_ID);

            if (! (bool) ($smtpSettings['enabled'] ?? false)) {
                $result = 'disabled';
            } elseif (trim((string) ($smtpSettings['host'] ?? '')) === '') {
                $result = 'unconfigured';
            } else {
                $result = wp_mail(
                    $recipient,
                    'PurePress SMTP 测试邮件',
                    '如果你收到这封邮件，说明 PurePress SMTP 发信配置已生效。'
                ) ? 'sent' : 'failed';
            }
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'purepress_tab' => 'Enhancement',
                    'purepress_test_mail' => $result,
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }

    /**
     * 更新 GeoIP 本地数据库。
     */
    public function updateGeoIpDatabase(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('你没有权限更新 PurePress GeoIP 数据库。');
        }

        check_admin_referer('purepress_update_geoip_database');

        if (! $this->options->isModuleEnabled(self::LOGIN_AUDIT_MODULE_ID)) {
            $result = 'disabled';
        } else {
            $result = (new GeoIpDatabase())->update()['success'] ? 'updated' : 'failed';
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => self::PAGE_SLUG,
                    'purepress_tab' => 'Governance',
                    'purepress_geoip' => $result,
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }

    /**
     * 渲染设置页。
     */
    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = $this->options->all();
        $visibleGroups = $this->visibleGroups();
        $activeGroup = $this->activeGroup($visibleGroups);
        $modules = $this->modulesForGroup($activeGroup);
        ?>
        <div class="wrap">
            <h1>PurePress 设置</h1>
            <style>
                .purepress-module {
                    margin: 16px 0;
                    padding: 12px 16px 16px;
                    border: 1px solid #dcdcde;
                    background: #fff;
                }

                .purepress-module legend {
                    padding: 0 4px;
                    margin-bottom: 0;
                }

                .purepress-module__layout--with-info {
                    display: grid;
                    grid-template-columns: minmax(260px, 420px) minmax(320px, 1fr);
                    gap: 24px;
                    align-items: start;
                }

                .purepress-module__description {
                    margin: 8px 0;
                }

                .purepress-module__info {
                    margin: 0;
                    padding: 12px;
                    border-left: 4px solid #72aee6;
                    background: #f6f7f7;
                }

                .purepress-module__info p {
                    margin: 0 0 8px;
                }

                .purepress-module__info pre {
                    margin: 8px 0 0;
                    padding: 12px;
                    overflow: auto;
                    border: 1px solid #dcdcde;
                    background: #fff;
                    white-space: pre-wrap;
                }

                .purepress-module__fields {
                    margin-top: 12px;
                    display: grid;
                    grid-template-columns: minmax(120px, 160px) minmax(220px, 360px);
                    gap: 10px 12px;
                    align-items: center;
                }

                .purepress-module__fields label {
                    font-weight: 600;
                }

                .purepress-module__fields input[type="text"],
                .purepress-module__fields input[type="email"],
                .purepress-module__fields input[type="number"],
                .purepress-module__fields input[type="password"],
                .purepress-module__fields select {
                    width: 100%;
                    max-width: 360px;
                }

                .purepress-module__actions {
                    margin-top: 16px;
                    padding-top: 12px;
                    border-top: 1px solid #dcdcde;
                }

                @media (max-width: 782px) {
                    .purepress-module__layout--with-info {
                        display: block;
                    }

                    .purepress-module__info {
                        margin-top: 12px;
                    }

                    .purepress-module__fields {
                        display: block;
                    }

                    .purepress-module__fields label,
                    .purepress-module__fields input,
                    .purepress-module__fields select {
                        display: block;
                        margin-top: 8px;
                    }
                }
            </style>

            <?php if (isset($_GET['purepress_saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>设置已保存。</p>
                </div>
            <?php endif; ?>

            <?php $this->renderTestMailNotice(); ?>
            <?php $this->renderGeoIpNotice(); ?>

            <p>PurePress 的所有能力都通过模块独立启用。默认情况下，功能模块保持关闭，由你按站点需要逐步打开。</p>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($visibleGroups as $group) : ?>
                    <a
                        class="nav-tab <?php echo $group === $activeGroup ? 'nav-tab-active' : ''; ?>"
                        href="<?php echo esc_url($this->tabUrl($group)); ?>"
                    >
                        <?php echo esc_html($group); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('purepress_save_settings'); ?>
                <input type="hidden" name="action" value="purepress_save_settings">
                <input type="hidden" name="group" value="<?php echo esc_attr($activeGroup); ?>">

                <h2><?php echo esc_html($activeGroup); ?></h2>

                <?php foreach ($modules as $module) : ?>
                    <?php $enabled = (bool) ($settings['modules'][$module->id()]['enabled'] ?? false); ?>
                    <fieldset class="purepress-module">
                        <legend>
                            <strong><?php echo esc_html($module->name()); ?></strong>
                        </legend>
                        <div class="purepress-module__layout <?php echo $module->hasAdditionalInfo() ? 'purepress-module__layout--with-info' : ''; ?>">
                            <div>
                                <p class="purepress-module__description"><?php echo esc_html($module->description()); ?></p>
                                <?php if ($module->id() === self::LOGIN_ADDRESS_MODULE_ID) : ?>
                                    <input type="hidden" name="modules[]" value="<?php echo esc_attr($module->id()); ?>">
                                <?php else : ?>
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="modules[]"
                                            value="<?php echo esc_attr($module->id()); ?>"
                                            <?php checked($enabled); ?>
                                        >
                                        启用
                                    </label>
                                <?php endif; ?>
                                <?php $this->renderModuleFields($module, $settings['modules'][$module->id()] ?? []); ?>
                            </div>

                            <?php if ($module->hasAdditionalInfo()) : ?>
                                <div class="purepress-module__info">
                                    <?php if ($module->details() !== '') : ?>
                                        <p><?php echo esc_html($module->details()); ?></p>
                                    <?php endif; ?>

                                    <?php if ($module->configurationExample() !== '') : ?>
                                        <pre><code><?php echo esc_html($module->configurationExample()); ?></code></pre>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <?php submit_button('保存设置'); ?>
            </form>

            <?php if ($activeGroup === 'Enhancement' && $this->hasModule($modules, self::SMTP_MODULE_ID)) : ?>
                <form id="purepress-smtp-test-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('purepress_send_test_email'); ?>
                    <input type="hidden" name="action" value="purepress_send_test_email">
                </form>
            <?php endif; ?>

            <?php if ($activeGroup === 'Governance' && $this->hasModule($modules, self::LOGIN_AUDIT_MODULE_ID) && (bool) ($settings['modules'][self::LOGIN_AUDIT_MODULE_ID]['enabled'] ?? false)) : ?>
                <form id="purepress-geoip-update-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('purepress_update_geoip_database'); ?>
                    <input type="hidden" name="action" value="purepress_update_geoip_database">
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 读取并清洗用户提交的模块 ID。
     *
     * @return list<string>
     */
    private function submittedModuleIds(): array
    {
        $rawModuleIds = $_POST['modules'] ?? [];

        if (! is_array($rawModuleIds)) {
            return [];
        }

        $moduleIds = [];

        foreach ($rawModuleIds as $rawModuleId) {
            if (! is_scalar($rawModuleId)) {
                continue;
            }

            $moduleId = (string) $rawModuleId;

            if (function_exists('wp_unslash')) {
                $moduleId = (string) wp_unslash($moduleId);
            }

            $moduleIds[] = $this->sanitizeModuleId($moduleId);
        }

        return array_values(array_filter(array_unique($moduleIds)));
    }

    /**
     * 保存已提交的模块扩展配置。
     *
     * @param list<string> $allowedModuleIds 当前标签页允许保存的模块 ID。
     */
    private function saveSubmittedModuleSettings(array $allowedModuleIds): void
    {
        if (in_array(self::LOGIN_ADDRESS_MODULE_ID, $allowedModuleIds, true)) {
            $loginAddressSettings = $this->submittedLoginAddressSettings();
            $currentSettings = $this->options->moduleSettings(self::LOGIN_ADDRESS_MODULE_ID);

            $this->options->saveModuleSettings(self::LOGIN_ADDRESS_MODULE_ID, $loginAddressSettings);

            if (
                (string) ($currentSettings['mode'] ?? '') !== $loginAddressSettings['mode']
                || (string) ($currentSettings['login_path'] ?? '') !== $loginAddressSettings['login_path']
                || (string) ($currentSettings['signup_path'] ?? '') !== $loginAddressSettings['signup_path']
            ) {
                $this->refreshLoginAddressRewriteRules($loginAddressSettings, $currentSettings);
            }
        }

        if (in_array(self::SMTP_MODULE_ID, $allowedModuleIds, true)) {
            $smtpSettings = $this->submittedSmtpSettings();

            if ([] !== $smtpSettings) {
                $this->options->saveModuleSettings(self::SMTP_MODULE_ID, $smtpSettings);
            }
        }

        if (in_array(self::S3_MEDIA_MODULE_ID, $allowedModuleIds, true)) {
            $s3Settings = $this->submittedS3MediaSettings();

            if ([] !== $s3Settings) {
                $this->options->saveModuleSettings(self::S3_MEDIA_MODULE_ID, $s3Settings);
            }
        }

        if (in_array(self::REGISTRATION_RATE_LIMIT_MODULE_ID, $allowedModuleIds, true)) {
            $this->options->saveModuleSettings(
                self::REGISTRATION_RATE_LIMIT_MODULE_ID,
                $this->submittedRegistrationRateLimitSettings()
            );
        }
    }

    /**
     * 在写入任何模块配置前验证当前标签页提交内容。
     *
     * @param list<string> $allowedModuleIds 当前标签页允许保存的模块 ID。
     */
    private function validateSubmittedModuleSettings(array $allowedModuleIds): void
    {
        if (in_array(self::LOGIN_ADDRESS_MODULE_ID, $allowedModuleIds, true)) {
            $this->submittedLoginAddressSettings();
        }
    }

    /**
     * 读取并清洗登录入口控制配置。
     *
     * @return array{mode: string, login_path: string, signup_path: string}
     */
    private function submittedLoginAddressSettings(): array
    {
        $rawSettings = $_POST['module_settings'][self::LOGIN_ADDRESS_MODULE_ID] ?? [];

        if (! is_array($rawSettings)) {
            $rawSettings = [];
        }

        if (function_exists('wp_unslash')) {
            $rawSettings = wp_unslash($rawSettings);
        }

        $mode = is_scalar($rawSettings['mode'] ?? null) ? (string) $rawSettings['mode'] : LoginAddressModule::MODE_DEFAULT;
        $mode = in_array(
            $mode,
            [
                LoginAddressModule::MODE_DEFAULT,
                LoginAddressModule::MODE_DISABLED,
                LoginAddressModule::MODE_CUSTOM,
            ],
            true
        ) ? $mode : LoginAddressModule::MODE_DEFAULT;
        $loginPath = $this->sanitizeEntryPath($rawSettings['login_path'] ?? 'login');
        $signupPath = $this->sanitizeEntryPath($rawSettings['signup_path'] ?? 'signup');

        if ($mode === LoginAddressModule::MODE_CUSTOM) {
            if ($loginPath === '' || $signupPath === '') {
                wp_die('登录地址和注册地址必须是有效的站内相对路径。');
            }

            if ($loginPath === $signupPath) {
                wp_die('登录地址和注册地址不能相同。');
            }

            if ($this->isReservedEntryPath($loginPath) || $this->isReservedEntryPath($signupPath)) {
                wp_die('登录地址或注册地址使用了 WordPress 保留路径。');
            }
        }

        return [
            'mode' => $mode,
            'login_path' => $loginPath !== '' ? $loginPath : 'login',
            'signup_path' => $signupPath !== '' ? $signupPath : 'signup',
        ];
    }

    /**
     * 读取并清洗 SMTP 配置。
     *
     * @return array<string, mixed>
     */
    private function submittedSmtpSettings(): array
    {
        $rawSettings = $_POST['module_settings'][self::SMTP_MODULE_ID] ?? [];

        if (! is_array($rawSettings)) {
            return [];
        }

        if (function_exists('wp_unslash')) {
            $rawSettings = wp_unslash($rawSettings);
        }

        $currentSettings = $this->options->moduleSettings(self::SMTP_MODULE_ID);
        $password = $this->sanitizeSecretValue($rawSettings['password'] ?? '');
        $port = $this->sanitizePort($rawSettings['port'] ?? 587);

        return [
            'host' => $this->sanitizeTextValue($rawSettings['host'] ?? ''),
            'port' => $port,
            'encryption' => $this->sanitizeEncryption($rawSettings['encryption'] ?? 'tls', $port),
            'auth' => isset($rawSettings['auth']),
            'username' => $this->sanitizeTextValue($rawSettings['username'] ?? ''),
            'password' => $password !== '' ? $password : (string) ($currentSettings['password'] ?? ''),
            'from_email' => $this->sanitizeEmailValue($rawSettings['from_email'] ?? ''),
            'from_name' => $this->sanitizeTextValue($rawSettings['from_name'] ?? ''),
        ];
    }

    /**
     * 读取并清洗 S3 兼容对象存储配置。
     *
     * @return array<string, mixed>
     */
    private function submittedS3MediaSettings(): array
    {
        $rawSettings = $_POST['module_settings'][self::S3_MEDIA_MODULE_ID] ?? [];

        if (! is_array($rawSettings)) {
            return [];
        }

        if (function_exists('wp_unslash')) {
            $rawSettings = wp_unslash($rawSettings);
        }

        $currentSettings = $this->options->moduleSettings(self::S3_MEDIA_MODULE_ID);
        $secretKey = $this->sanitizeSecretValue($rawSettings['secret_key'] ?? '');

        return [
            'endpoint' => $this->sanitizeUrlValue($rawSettings['endpoint'] ?? ''),
            'region' => $this->sanitizeTextValue($rawSettings['region'] ?? 'auto'),
            'bucket' => $this->sanitizeTextValue($rawSettings['bucket'] ?? ''),
            'access_key' => $this->sanitizeTextValue($rawSettings['access_key'] ?? ''),
            'secret_key' => $secretKey !== '' ? $secretKey : (string) ($currentSettings['secret_key'] ?? ''),
            'path_style' => isset($rawSettings['path_style']),
            'path_prefix' => $this->sanitizePathValue($rawSettings['path_prefix'] ?? ''),
            'public_base_url' => $this->sanitizeUrlValue($rawSettings['public_base_url'] ?? ''),
        ];
    }

    /**
     * 读取并清洗注册频率限制配置。
     *
     * @return array{email_limit: int, email_window_minutes: int, ip_limit: int, ip_window_minutes: int}
     */
    private function submittedRegistrationRateLimitSettings(): array
    {
        $rawSettings = $_POST['module_settings'][self::REGISTRATION_RATE_LIMIT_MODULE_ID] ?? [];

        if (! is_array($rawSettings)) {
            $rawSettings = [];
        }

        if (function_exists('wp_unslash')) {
            $rawSettings = wp_unslash($rawSettings);
        }

        return [
            'email_limit' => $this->sanitizePositiveInteger($rawSettings['email_limit'] ?? 8, 8, 1, 1000),
            'email_window_minutes' => $this->sanitizePositiveInteger($rawSettings['email_window_minutes'] ?? 30, 30, 1, 1440),
            'ip_limit' => $this->sanitizePositiveInteger($rawSettings['ip_limit'] ?? 20, 20, 1, 10000),
            'ip_window_minutes' => $this->sanitizePositiveInteger($rawSettings['ip_window_minutes'] ?? 60, 60, 1, 1440),
        ];
    }

    /**
     * 读取并清洗测试邮件收件人。
     */
    private function submittedTestEmail(): string
    {
        $recipient = $_POST['recipient'] ?? '';

        if (is_scalar($recipient)) {
            $recipient = (string) $recipient;

            if (function_exists('wp_unslash')) {
                $recipient = (string) wp_unslash($recipient);
            }
        } else {
            $recipient = '';
        }

        return $this->sanitizeEmailValue($recipient);
    }

    /**
     * 读取并清洗用户提交的层级。
     */
    private function submittedGroup(): string
    {
        $group = $_POST['group'] ?? '';

        if (is_scalar($group)) {
            $group = (string) $group;

            if (function_exists('wp_unslash')) {
                $group = (string) wp_unslash($group);
            }
        } else {
            $group = '';
        }

        return in_array($group, self::GROUPS, true) ? $group : $this->visibleGroups()[0];
    }

    /**
     * 清洗用户提交的模块 ID。
     *
     * 模块 ID 允许使用点号表达层级，例如 `governance.rest_api`。
     *
     * @param string $moduleId 用户提交的原始模块 ID。
     */
    private function sanitizeModuleId(string $moduleId): string
    {
        return (string) preg_replace('/[^a-z0-9_.-]/', '', strtolower($moduleId));
    }

    /**
     * 清洗普通文本配置值。
     *
     * @param mixed $value 原始配置值。
     */
    private function sanitizeTextValue(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim($value);
    }

    /**
     * 清洗敏感配置值。
     *
     * 密码类字段需要保留常见特殊字符，避免使用普通文本清洗导致凭据被改写。
     *
     * @param mixed $value 原始敏感配置值。
     */
    private function sanitizeSecretValue(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return str_replace("\0", '', $value);
    }

    /**
     * 清洗邮箱配置值。
     *
     * @param mixed $value 原始配置值。
     */
    private function sanitizeEmailValue(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        $email = function_exists('sanitize_email') ? sanitize_email($value) : trim($value);

        if (function_exists('is_email')) {
            return is_email($email) ? $email : '';
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * 清洗 URL 配置值。
     *
     * @param mixed $value 原始 URL 值。
     */
    private function sanitizeUrlValue(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return '';
        }

        return function_exists('esc_url_raw') ? esc_url_raw($value) : filter_var($value, FILTER_SANITIZE_URL);
    }

    /**
     * 清洗对象路径或 URL 路径前缀。
     *
     * @param mixed $value 原始路径值。
     */
    private function sanitizePathValue(mixed $value): string
    {
        $path = is_scalar($value) ? trim((string) $value) : '';
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path);
        $path = trim((string) $path, '/');

        return str_replace(["\0", '..'], '', $path);
    }

    /**
     * 清洗正整数配置值。
     *
     * @param mixed $value   原始数值。
     * @param int   $default 默认值。
     * @param int   $min     最小允许值。
     * @param int   $max     最大允许值。
     */
    private function sanitizePositiveInteger(mixed $value, int $default, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $default;

        if ($number < $min) {
            return $min;
        }

        if ($number > $max) {
            return $max;
        }

        return $number;
    }

    /**
     * 清洗登录或注册地址。
     *
     * 支持 `/login`、`/login/` 和 `login` 三种输入形式，并统一保存为 `login`。
     *
     * @param mixed $value 原始路径值。
     */
    private function sanitizeEntryPath(mixed $value): string
    {
        $path = is_scalar($value) ? trim((string) $value) : '';

        if ($path === '' || str_contains($path, "\0") || str_contains($path, '..')) {
            return '';
        }

        $parsed = wp_parse_url($path);

        if (
            false === $parsed
            || isset($parsed['scheme'])
            || isset($parsed['host'])
            || isset($parsed['query'])
            || isset($parsed['fragment'])
        ) {
            return '';
        }

        $path = isset($parsed['path']) && is_string($parsed['path']) ? $parsed['path'] : '';
        $path = trim((string) preg_replace('#/+#', '/', str_replace('\\', '/', $path)), '/');

        if ($path === '') {
            return '';
        }

        $segments = explode('/', strtolower($path));

        foreach ($segments as $segment) {
            if ($segment === '' || ! preg_match('/^[a-z0-9_-]+$/', $segment)) {
                return '';
            }
        }

        return implode('/', $segments);
    }

    /**
     * 判断登录或注册地址是否占用 WordPress 保留路径。
     *
     * @param string $path 已清洗的相对路径。
     */
    private function isReservedEntryPath(string $path): bool
    {
        $firstSegment = explode('/', $path)[0];

        return in_array(
            $firstSegment,
            [
                'admin',
                'dashboard',
                'index',
                'wp-admin',
                'wp-content',
                'wp-includes',
                'wp-json',
                'wp-login',
                'wp-signup',
                'wp-register',
                'xmlrpc',
            ],
            true
        );
    }

    /**
     * 根据最新配置刷新登录与注册地址重写规则。
     *
     * @param array{mode: string, login_path: string, signup_path: string} $settings        最新登录入口配置。
     * @param array<string,mixed>                                         $currentSettings 保存前登录入口配置。
     */
    private function refreshLoginAddressRewriteRules(array $settings, array $currentSettings): void
    {
        LoginAddressModule::removeRewriteRulesForSettings($currentSettings);
        LoginAddressModule::addRewriteRulesForSettings($settings);

        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }
    }

    /**
     * 清洗 SMTP 端口。
     *
     * @param mixed $value 原始端口值。
     */
    private function sanitizePort(mixed $value): int
    {
        $port = (int) (is_scalar($value) ? $value : 587);

        if ($port < 1 || $port > 65535) {
            return 587;
        }

        return $port;
    }

    /**
     * 清洗 SMTP 加密方式。
     *
     * @param mixed $value 原始加密方式。
     * @param int   $port  SMTP 端口。
     */
    private function sanitizeEncryption(mixed $value, int $port): string
    {
        $encryption = is_scalar($value) ? (string) $value : 'tls';
        $encryption = in_array($encryption, ['none', 'ssl', 'tls'], true) ? $encryption : 'tls';

        if ($port === 465 && $encryption === 'tls') {
            return 'ssl';
        }

        return $encryption;
    }

    /**
     * 获取当前存在模块的层级列表。
     *
     * @return list<string>
     */
    private function visibleGroups(): array
    {
        $groups = [];

        foreach (self::GROUPS as $group) {
            if ([] === $this->modulesForGroup($group)) {
                continue;
            }

            $groups[] = $group;
        }

        return [] === $groups ? ['Governance'] : $groups;
    }

    /**
     * 获取当前激活的层级。
     *
     * @param list<string> $visibleGroups 当前可见层级列表。
     */
    private function activeGroup(array $visibleGroups): string
    {
        $requestedGroup = $_GET['purepress_tab'] ?? '';

        if (is_scalar($requestedGroup)) {
            $requestedGroup = (string) $requestedGroup;
        } else {
            $requestedGroup = '';
        }

        return in_array($requestedGroup, $visibleGroups, true) ? $requestedGroup : $visibleGroups[0];
    }

    /**
     * 获取指定层级的模块定义。
     *
     * @param string $group 层级名称，例如 `Governance`。
     *
     * @return list<ModuleDefinition>
     */
    private function modulesForGroup(string $group): array
    {
        return array_values(
            array_filter(
                ModuleCatalog::definitions(),
                static fn ($module): bool => $module->group() === $group
            )
        );
    }

    /**
     * 获取指定层级允许保存的模块 ID。
     *
     * @param string $group 层级名称，例如 `Governance`。
     *
     * @return list<string>
     */
    private function moduleIdsForGroup(string $group): array
    {
        return array_map(
            static fn ($module): string => $module->id(),
            $this->modulesForGroup($group)
        );
    }

    /**
     * 获取层级标签页 URL。
     *
     * @param string $group 层级名称，例如 `Governance`。
     */
    private function tabUrl(string $group): string
    {
        return add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'purepress_tab' => $group,
            ],
            admin_url('options-general.php')
        );
    }

    /**
     * 渲染模块扩展配置字段。
     *
     * @param ModuleDefinition    $module         模块定义。
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderModuleFields(ModuleDefinition $module, array $moduleSettings): void
    {
        if ($module->id() === self::LOGIN_ADDRESS_MODULE_ID) {
            $this->renderLoginAddressFields($moduleSettings);
            return;
        }

        if ($module->id() === self::LOGIN_AUDIT_MODULE_ID) {
            $this->renderLoginAuditFields($moduleSettings);
            return;
        }

        if ($module->id() === self::REGISTRATION_RATE_LIMIT_MODULE_ID) {
            $this->renderRegistrationRateLimitFields($moduleSettings);
            return;
        }

        if ($module->id() === self::SMTP_MODULE_ID) {
            $this->renderSmtpFields($moduleSettings);
            return;
        }

        if ($module->id() === self::S3_MEDIA_MODULE_ID) {
            $this->renderS3MediaFields($moduleSettings);
        }
    }

    /**
     * 渲染登录入口控制配置字段。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderLoginAddressFields(array $moduleSettings): void
    {
        $fieldPrefix = 'module_settings[' . self::LOGIN_ADDRESS_MODULE_ID . ']';
        $mode = (string) ($moduleSettings['mode'] ?? LoginAddressModule::MODE_DEFAULT);
        $customMode = $mode === LoginAddressModule::MODE_CUSTOM;
        ?>
        <div class="purepress-module__fields">
            <label for="purepress-login-address-mode">模式</label>
            <select id="purepress-login-address-mode" name="<?php echo esc_attr($fieldPrefix); ?>[mode]">
                <option value="<?php echo esc_attr(LoginAddressModule::MODE_DEFAULT); ?>" <?php selected($mode, LoginAddressModule::MODE_DEFAULT); ?>>默认</option>
                <option value="<?php echo esc_attr(LoginAddressModule::MODE_DISABLED); ?>" <?php selected($mode, LoginAddressModule::MODE_DISABLED); ?>>关闭默认入口</option>
                <option value="<?php echo esc_attr(LoginAddressModule::MODE_CUSTOM); ?>" <?php selected($mode, LoginAddressModule::MODE_CUSTOM); ?>>自定义地址</option>
            </select>

            <label class="purepress-login-address-custom-field" for="purepress-login-path" <?php echo $customMode ? '' : 'hidden'; ?>>登录地址</label>
            <input
                class="purepress-login-address-custom-field"
                id="purepress-login-path"
                type="text"
                name="<?php echo esc_attr($fieldPrefix); ?>[login_path]"
                value="<?php echo esc_attr('/' . trim((string) ($moduleSettings['login_path'] ?? 'login'), '/')); ?>"
                placeholder="/login"
                <?php echo $customMode ? '' : 'hidden'; ?>
            >

            <label class="purepress-login-address-custom-field" for="purepress-signup-path" <?php echo $customMode ? '' : 'hidden'; ?>>注册地址</label>
            <input
                class="purepress-login-address-custom-field"
                id="purepress-signup-path"
                type="text"
                name="<?php echo esc_attr($fieldPrefix); ?>[signup_path]"
                value="<?php echo esc_attr('/' . trim((string) ($moduleSettings['signup_path'] ?? 'signup'), '/')); ?>"
                placeholder="/signup"
                <?php echo $customMode ? '' : 'hidden'; ?>
            >
        </div>
        <script>
            (function () {
                var mode = document.getElementById('purepress-login-address-mode');
                var fields = document.querySelectorAll('.purepress-login-address-custom-field');

                if (!mode) {
                    return;
                }

                function updateFields() {
                    var hidden = mode.value !== '<?php echo esc_js(LoginAddressModule::MODE_CUSTOM); ?>';

                    fields.forEach(function (field) {
                        field.hidden = hidden;
                    });
                }

                mode.addEventListener('change', updateFields);
                updateFields();
            }());
        </script>
        <?php
    }

    /**
     * 渲染登录审计配置字段。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderLoginAuditFields(array $moduleSettings): void
    {
        $enabled = (bool) ($moduleSettings['enabled'] ?? false);
        $status = (new GeoIpDatabase())->status();
        ?>
        <div class="purepress-module__actions">
            <?php if ($enabled) : ?>
                <?php if ($status['exists']) : ?>
                    <p class="purepress-module__description">
                        GeoIP 数据库已存在，最后更新：<?php echo esc_html($this->formattedGeoIpUpdatedAt($status['updated_at'])); ?>，文件大小：<?php echo esc_html($this->formattedFileSize($status['size'])); ?>。
                    </p>
                <?php else : ?>
                    <p class="purepress-module__description">GeoIP 数据库不存在，无法解析 IP 归属地。登录审计仍会记录最后登录时间和 IP。</p>
                <?php endif; ?>
                <p>
                    <button class="button button-secondary" type="submit" form="purepress-geoip-update-form">更新 GeoIP 数据库</button>
                </p>
            <?php else : ?>
                <p class="purepress-module__description">启用并保存后，可以更新 GeoIP 数据库并解析最后登录位置。</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * 渲染注册频率限制配置字段。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderRegistrationRateLimitFields(array $moduleSettings): void
    {
        $fieldPrefix = 'module_settings[' . self::REGISTRATION_RATE_LIMIT_MODULE_ID . ']';
        ?>
        <div class="purepress-module__fields">
            <label for="purepress-registration-email-limit">单邮箱次数</label>
            <input
                id="purepress-registration-email-limit"
                type="number"
                min="1"
                max="1000"
                name="<?php echo esc_attr($fieldPrefix); ?>[email_limit]"
                value="<?php echo esc_attr((string) ($moduleSettings['email_limit'] ?? 8)); ?>"
            >

            <label for="purepress-registration-email-window">单邮箱时间窗口（分钟）</label>
            <input
                id="purepress-registration-email-window"
                type="number"
                min="1"
                max="1440"
                name="<?php echo esc_attr($fieldPrefix); ?>[email_window_minutes]"
                value="<?php echo esc_attr((string) ($moduleSettings['email_window_minutes'] ?? 30)); ?>"
            >

            <label for="purepress-registration-ip-limit">单 IP 次数</label>
            <input
                id="purepress-registration-ip-limit"
                type="number"
                min="1"
                max="10000"
                name="<?php echo esc_attr($fieldPrefix); ?>[ip_limit]"
                value="<?php echo esc_attr((string) ($moduleSettings['ip_limit'] ?? 20)); ?>"
            >

            <label for="purepress-registration-ip-window">单 IP 时间窗口（分钟）</label>
            <input
                id="purepress-registration-ip-window"
                type="number"
                min="1"
                max="1440"
                name="<?php echo esc_attr($fieldPrefix); ?>[ip_window_minutes]"
                value="<?php echo esc_attr((string) ($moduleSettings['ip_window_minutes'] ?? 60)); ?>"
            >
        </div>
        <?php
    }

    /**
     * 渲染 SMTP 配置字段。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderSmtpFields(array $moduleSettings): void
    {
        $fieldPrefix = 'module_settings[' . self::SMTP_MODULE_ID . ']';
        $encryption = (string) ($moduleSettings['encryption'] ?? 'tls');
        ?>
        <div class="purepress-module__fields">
            <label for="purepress-smtp-host">SMTP 主机</label>
            <input id="purepress-smtp-host" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[host]" value="<?php echo esc_attr((string) ($moduleSettings['host'] ?? '')); ?>">

            <label for="purepress-smtp-port">端口</label>
            <input id="purepress-smtp-port" type="number" min="1" max="65535" name="<?php echo esc_attr($fieldPrefix); ?>[port]" value="<?php echo esc_attr((string) ($moduleSettings['port'] ?? 587)); ?>">

            <label for="purepress-smtp-encryption">加密方式</label>
            <select id="purepress-smtp-encryption" name="<?php echo esc_attr($fieldPrefix); ?>[encryption]">
                <option value="none" <?php selected($encryption, 'none'); ?>>无</option>
                <option value="ssl" <?php selected($encryption, 'ssl'); ?>>SSL</option>
                <option value="tls" <?php selected($encryption, 'tls'); ?>>TLS</option>
            </select>

            <label for="purepress-smtp-auth">SMTP 认证</label>
            <label>
                <input id="purepress-smtp-auth" type="checkbox" name="<?php echo esc_attr($fieldPrefix); ?>[auth]" value="1" <?php checked((bool) ($moduleSettings['auth'] ?? true)); ?>>
                启用
            </label>

            <label for="purepress-smtp-username">用户名</label>
            <input id="purepress-smtp-username" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[username]" value="<?php echo esc_attr((string) ($moduleSettings['username'] ?? '')); ?>" autocomplete="username">

            <label for="purepress-smtp-password">密码</label>
            <input id="purepress-smtp-password" type="password" name="<?php echo esc_attr($fieldPrefix); ?>[password]" value="" placeholder="<?php echo esc_attr(((string) ($moduleSettings['password'] ?? '')) !== '' ? '已保存，留空则保持不变' : ''); ?>" autocomplete="new-password">

            <label for="purepress-smtp-from-email">发件人邮箱</label>
            <input id="purepress-smtp-from-email" type="email" name="<?php echo esc_attr($fieldPrefix); ?>[from_email]" value="<?php echo esc_attr((string) ($moduleSettings['from_email'] ?? '')); ?>">

            <label for="purepress-smtp-from-name">发件人名称</label>
            <input id="purepress-smtp-from-name" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[from_name]" value="<?php echo esc_attr((string) ($moduleSettings['from_name'] ?? '')); ?>">
        </div>

        <div class="purepress-module__actions">
            <p class="purepress-module__description">测试邮件使用已保存的 SMTP 配置发送。</p>
            <div class="purepress-module__fields">
                <label for="purepress-test-email">测试收件邮箱</label>
                <input id="purepress-test-email" type="email" name="recipient" form="purepress-smtp-test-form" required>
            </div>
            <p>
                <button class="button button-secondary" type="submit" form="purepress-smtp-test-form">发送测试邮件</button>
            </p>
        </div>
        <?php
    }

    /**
     * 渲染 S3 兼容对象存储配置字段。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function renderS3MediaFields(array $moduleSettings): void
    {
        $fieldPrefix = 'module_settings[' . self::S3_MEDIA_MODULE_ID . ']';
        ?>
        <div class="purepress-module__fields">
            <label for="purepress-s3-endpoint">Endpoint</label>
            <input id="purepress-s3-endpoint" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[endpoint]" value="<?php echo esc_attr((string) ($moduleSettings['endpoint'] ?? '')); ?>" placeholder="https://example.r2.cloudflarestorage.com">

            <label for="purepress-s3-region">Region</label>
            <input id="purepress-s3-region" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[region]" value="<?php echo esc_attr((string) ($moduleSettings['region'] ?? 'auto')); ?>">

            <label for="purepress-s3-bucket">Bucket</label>
            <input id="purepress-s3-bucket" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[bucket]" value="<?php echo esc_attr((string) ($moduleSettings['bucket'] ?? '')); ?>">

            <label for="purepress-s3-access-key">Access Key</label>
            <input id="purepress-s3-access-key" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[access_key]" value="<?php echo esc_attr((string) ($moduleSettings['access_key'] ?? '')); ?>" autocomplete="username">

            <label for="purepress-s3-secret-key">Secret Key</label>
            <input id="purepress-s3-secret-key" type="password" name="<?php echo esc_attr($fieldPrefix); ?>[secret_key]" value="" placeholder="<?php echo esc_attr(((string) ($moduleSettings['secret_key'] ?? '')) !== '' ? '已保存，留空则保持不变' : ''); ?>" autocomplete="new-password">

            <label for="purepress-s3-path-style">Path-style endpoint</label>
            <label>
                <input id="purepress-s3-path-style" type="checkbox" name="<?php echo esc_attr($fieldPrefix); ?>[path_style]" value="1" <?php checked((bool) ($moduleSettings['path_style'] ?? true)); ?>>
                启用
            </label>

            <label for="purepress-s3-path-prefix">路径前缀</label>
            <input id="purepress-s3-path-prefix" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[path_prefix]" value="<?php echo esc_attr($this->s3PathPrefix($moduleSettings)); ?>" placeholder="uploads">

            <label for="purepress-s3-public-base-url">公开访问域名</label>
            <input id="purepress-s3-public-base-url" type="text" name="<?php echo esc_attr($fieldPrefix); ?>[public_base_url]" value="<?php echo esc_attr((string) ($moduleSettings['public_base_url'] ?? '')); ?>" placeholder="https://media.example.com">
        </div>
        <?php
    }

    /**
     * 获取 S3 兼容对象存储路径前缀。
     *
     * @param array<string,mixed> $moduleSettings 模块配置。
     */
    private function s3PathPrefix(array $moduleSettings): string
    {
        if (isset($moduleSettings['path_prefix']) && is_scalar($moduleSettings['path_prefix'])) {
            return (string) $moduleSettings['path_prefix'];
        }

        if (isset($moduleSettings['object_prefix']) && is_scalar($moduleSettings['object_prefix'])) {
            return (string) $moduleSettings['object_prefix'];
        }

        return '';
    }

    /**
     * 判断模块列表中是否存在指定模块。
     *
     * @param list<ModuleDefinition> $modules  模块列表。
     * @param string                 $moduleId 模块 ID。
     */
    private function hasModule(array $modules, string $moduleId): bool
    {
        foreach ($modules as $module) {
            if ($module->id() === $moduleId) {
                return true;
            }
        }

        return false;
    }

    /**
     * 渲染测试邮件发送结果提示。
     */
    private function renderTestMailNotice(): void
    {
        $result = $_GET['purepress_test_mail'] ?? '';

        if (! is_scalar($result) || $result === '') {
            return;
        }

        $messages = [
            'sent' => ['notice-success', '测试邮件已发送。'],
            'failed' => ['notice-error', '测试邮件发送失败，请检查 SMTP 配置。'],
            'invalid' => ['notice-error', '请输入有效的测试收件邮箱。'],
            'disabled' => ['notice-warning', 'SMTP 发信模块未启用，无法发送测试邮件。'],
            'unconfigured' => ['notice-warning', '请先保存 SMTP 主机后再发送测试邮件。'],
        ];

        $result = (string) $result;

        if (! isset($messages[$result])) {
            return;
        }

        [$className, $message] = $messages[$result];
        ?>
        <div class="notice <?php echo esc_attr($className); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * 渲染 GeoIP 数据库更新结果提示。
     */
    private function renderGeoIpNotice(): void
    {
        $result = $_GET['purepress_geoip'] ?? '';

        if (! is_scalar($result) || $result === '') {
            return;
        }

        $messages = [
            'updated' => ['notice-success', 'GeoIP 数据库已更新。'],
            'failed' => ['notice-error', 'GeoIP 数据库更新失败，请稍后重试或检查服务器网络。'],
            'disabled' => ['notice-warning', '登录审计模块未启用，无法更新 GeoIP 数据库。'],
        ];

        $result = (string) $result;

        if (! isset($messages[$result])) {
            return;
        }

        [$className, $message] = $messages[$result];
        ?>
        <div class="notice <?php echo esc_attr($className); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
        <?php
    }

    /**
     * 格式化 GeoIP 数据库更新时间。
     *
     * @param int|null $timestamp 更新时间戳。
     */
    private function formattedGeoIpUpdatedAt(?int $timestamp): string
    {
        if (! is_int($timestamp) || $timestamp <= 0) {
            return '未知';
        }

        $format = trim((string) get_option('date_format') . ' ' . (string) get_option('time_format'));

        return function_exists('wp_date')
            ? wp_date($format, $timestamp)
            : date_i18n($format, $timestamp);
    }

    /**
     * 格式化文件大小。
     *
     * @param int $size 文件字节数。
     */
    private function formattedFileSize(int $size): string
    {
        if ($size <= 0) {
            return '0 B';
        }

        return function_exists('size_format') ? size_format($size, 2) : number_format($size) . ' B';
    }
}
