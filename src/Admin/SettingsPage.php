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
use PurePress\Configuration\OptionRepository;
use PurePress\Support\HookRegistry;

final class SettingsPage
{
    private const PAGE_SLUG = 'purepress';

    /**
     * PurePress 预留的配置层级。
     *
     * 当前只显示存在模块的层级；后续新增 Optimization、Enhancement、Integration、
     * Replacement、Configuration 模块时会自动显示对应标签页。
     *
     * @var list<string>
     */
    private const GROUPS = [
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

            <?php if (isset($_GET['purepress_saved'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p>设置已保存。</p>
                </div>
            <?php endif; ?>

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
                    <fieldset style="margin: 16px 0; padding: 16px; border: 1px solid #dcdcde; background: #fff;">
                        <legend>
                            <strong><?php echo esc_html($module->name()); ?></strong>
                        </legend>
                        <p><?php echo esc_html($module->description()); ?></p>
                        <label>
                            <input
                                type="checkbox"
                                name="modules[]"
                                value="<?php echo esc_attr($module->id()); ?>"
                                <?php checked($enabled); ?>
                            >
                            启用
                        </label>
                    </fieldset>
                <?php endforeach; ?>

                <?php submit_button('保存设置'); ?>
            </form>
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
     * @return list<\PurePress\Configuration\ModuleDefinition>
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
}
