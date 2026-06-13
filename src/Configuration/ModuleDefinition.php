<?php
/**
 * PurePress 模块定义值对象。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Configuration;

final class ModuleDefinition
{
    /**
     * 模块唯一 ID。
     */
    private string $id;

    /**
     * 面向用户展示的模块名称。
     */
    private string $name;

    /**
     * 模块所属层级。
     */
    private string $group;

    /**
     * 面向用户展示的模块说明。
     */
    private string $description;

    /**
     * 模块实现类名。
     *
     * @var class-string
     */
    private string $moduleClass;

    /**
     * 创建模块定义。
     *
     * @param string       $id          模块唯一 ID。
     * @param string       $name        面向用户展示的模块名称。
     * @param string       $group       模块所属层级。
     * @param string       $description 面向用户展示的模块说明。
     * @param class-string $moduleClass 模块实现类名。
     */
    public function __construct(string $id, string $name, string $group, string $description, string $moduleClass)
    {
        $this->id = $id;
        $this->name = $name;
        $this->group = $group;
        $this->description = $description;
        $this->moduleClass = $moduleClass;
    }

    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * 获取面向用户展示的模块名称。
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * 获取模块所属层级。
     */
    public function group(): string
    {
        return $this->group;
    }

    /**
     * 获取面向用户展示的模块说明。
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * 获取模块实现类名。
     *
     * @return class-string
     */
    public function moduleClass(): string
    {
        return $this->moduleClass;
    }
}
