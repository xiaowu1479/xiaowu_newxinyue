<?php

namespace app\qfadmin\controller;

use app\qfadmin\QfShop;
use think\facade\View;

/**
 * 资源同步页面控制器（渲染 HTML 视图）
 *
 * 菜单位置：系统设置 → 资源同步
 * 页面路由：/qfadmin/sync_server/index
 * API 路由：/admin/sync_server/*（由 app\admin\controller\SyncServer 处理）
 */
class SyncServer extends QfShop
{
    /**
     * 渲染资源同步主页面（含服务器列表 + 添加/编辑弹窗 + 同步日志弹窗）
     */
    public function index()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        return View::fetch();
    }
}
