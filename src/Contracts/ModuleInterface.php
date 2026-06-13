<?php
/**
 * PurePress 模块契约。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Contracts;

use PurePress\Support\HookRegistry;

interface ModuleInterface
{
    /**
     * 获取模块唯一 ID。
     */
    public function id(): string;

    /**
     * 注册模块需要使用的 WordPress Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void;
}
