<?php
/**
 * 管理 WordPress XML-RPC 能力。
 *
 * Copyright (C) 2026 Morton Li
 *
 * This file is part of PurePress and is licensed under GPL-3.0-only.
 *
 * @package PurePress
 */

declare(strict_types=1);

namespace PurePress\Governance;

use PurePress\Contracts\ModuleInterface;
use PurePress\Support\HookRegistry;

final class XmlRpcModule implements ModuleInterface
{
    /**
     * 获取模块唯一 ID。
     */
    public function id(): string
    {
        return 'governance.xml_rpc';
    }

    /**
     * 注册 XML-RPC 治理相关 Hook。
     *
     * @param HookRegistry $hooks WordPress Hook 注册器。
     */
    public function register(HookRegistry $hooks): void
    {
        $hooks->action('init', [$this, 'blockXmlRpcRequest'], 0);
        $hooks->filter('xmlrpc_enabled', [$this, 'disableXmlRpc']);
        $hooks->filter('xmlrpc_methods', [$this, 'removeXmlRpcMethods']);
    }

    /**
     * 在 XML-RPC 请求进入业务处理前直接阻断。
     */
    public function blockXmlRpcRequest(): void
    {
        if (! defined('XMLRPC_REQUEST') || ! XMLRPC_REQUEST) {
            return;
        }

        if (function_exists('status_header')) {
            status_header(403);
        }

        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'PurePress 已关闭 XML-RPC 功能。';
        exit;
    }

    /**
     * 禁用 WordPress 内建 XML-RPC 可用性标记。
     */
    public function disableXmlRpc(): bool
    {
        return false;
    }

    /**
     * 移除所有 XML-RPC 方法，作为入口阻断之外的防护。
     *
     * @param array<string, callable|string> $methods WordPress 当前注册的 XML-RPC 方法。
     *
     * @return array<string, callable|string>
     */
    public function removeXmlRpcMethods(array $methods): array
    {
        return [];
    }
}
