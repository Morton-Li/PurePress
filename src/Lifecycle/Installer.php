<?php
/**
 * PurePress 插件激活处理。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Lifecycle;

use PurePress\Configuration\OptionRepository;
use PurePress\Configuration\OptionSynchronizer;

final class Installer
{
    /**
     * 插件激活时同步当前版本配置记录。
     */
    public static function activate(): void
    {
        (new OptionSynchronizer(new OptionRepository()))->sync();
    }
}
