<?php
/**
 * WordPress Hook 注册封装。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Support;

final class HookRegistry
{
    /**
     * 注册 Action Hook。
     *
     * @param string   $hookName     Hook 名称。
     * @param callable $callback     回调函数。
     * @param int      $priority     执行优先级。
     * @param int      $acceptedArgs 回调接收的参数数量。
     */
    public function action(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_action($hookName, $callback, $priority, $acceptedArgs);
    }

    /**
     * 注册 Filter Hook。
     *
     * @param string   $hookName     Hook 名称。
     * @param callable $callback     回调函数。
     * @param int      $priority     执行优先级。
     * @param int      $acceptedArgs 回调接收的参数数量。
     */
    public function filter(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_filter($hookName, $callback, $priority, $acceptedArgs);
    }
}
