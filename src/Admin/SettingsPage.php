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
use PurePress\Support\HookRegistry;

final class SettingsPage
{
    private const string PAGE_SLUG = 'purepress';
    private const string SMTP_MODULE_ID = 'enhancement.smtp';

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
                                <label>
                                    <input
                                        type="checkbox"
                                        name="modules[]"
                                        value="<?php echo esc_attr($module->id()); ?>"
                                        <?php checked($enabled); ?>
                                    >
                                    启用
                                </label>
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
                <fieldset class="purepress-module">
                    <legend>
                        <strong>发送测试邮件</strong>
                    </legend>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('purepress_send_test_email'); ?>
                        <input type="hidden" name="action" value="purepress_send_test_email">
                        <p class="purepress-module__description">测试邮件使用已保存的 SMTP 配置发送。</p>
                        <div class="purepress-module__fields">
                            <label for="purepress-test-email">收件邮箱</label>
                            <input id="purepress-test-email" type="email" name="recipient" required>
                        </div>
                        <?php submit_button('发送测试邮件', 'secondary', 'submit', false); ?>
                    </form>
                </fieldset>
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
        if (! in_array(self::SMTP_MODULE_ID, $allowedModuleIds, true)) {
            return;
        }

        $smtpSettings = $this->submittedSmtpSettings();

        if ([] === $smtpSettings) {
            return;
        }

        $this->options->saveModuleSettings(self::SMTP_MODULE_ID, $smtpSettings);
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

        return [
            'host' => $this->sanitizeTextValue($rawSettings['host'] ?? ''),
            'port' => $this->sanitizePort($rawSettings['port'] ?? 587),
            'encryption' => $this->sanitizeEncryption($rawSettings['encryption'] ?? 'tls'),
            'auth' => isset($rawSettings['auth']),
            'username' => $this->sanitizeTextValue($rawSettings['username'] ?? ''),
            'password' => $password !== '' ? $password : (string) ($currentSettings['password'] ?? ''),
            'from_email' => $this->sanitizeEmailValue($rawSettings['from_email'] ?? ''),
            'from_name' => $this->sanitizeTextValue($rawSettings['from_name'] ?? ''),
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
     */
    private function sanitizeEncryption(mixed $value): string
    {
        $encryption = is_scalar($value) ? (string) $value : 'tls';

        return in_array($encryption, ['none', 'ssl', 'tls'], true) ? $encryption : 'tls';
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
        if ($module->id() !== self::SMTP_MODULE_ID) {
            return;
        }

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
        <?php
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
}
