<?php

namespace app\qfadmin\controller;

use app\qfadmin\QfShop;
use think\facade\View;

/**
 * 服务器管理页面控制器（隐藏入口，无菜单节点）
 */
class Server extends QfShop
{
    /**
     * 渲染服务器管理页面
     */
    public function index()
    {
        $error = $this->checkLogin();
        if ($error) {
            return $error;
        }
        // 构造虚拟节点，供模板 header/menu 使用
        View::assign('node', [
            'node_id' => 0,
            'node_title' => '服务器管理',
            'node_desc' => '已安装本项目的服务器列表',
            'node_pid' => 0,
        ]);
        View::assign('menu', 0);
        return View::fetch();
    }

    /**
     * 登录验证（不依赖 node 表记录）
     */
    protected function checkLogin()
    {
        $callback = "/qfadmin/server/index";
        $access_token = cookie('access_token');
        if (!$access_token) {
            return redirect('/qfadmin/admin/login/?callback=' . urlencode($callback));
        }
        View::assign("access_token", $access_token);
        $this->admin = $this->adminModel->getAdminByAccessToken($access_token);
        if (!$this->admin) {
            return redirect('/qfadmin/admin/login/?callback=' . urlencode($callback));
        }
        if ($this->admin['admin_status'] > 0) {
            return $this->error("抱歉，你的帐号已被禁用，暂时无法登录系统！");
        }
        cookie("access_token", $access_token);
        View::assign('adminInfo', $this->admin);
        $this->group = $this->groupModel->where('group_id', $this->admin['admin_group'])->find();
        if ($this->group) {
            if ($this->group['group_id'] != 1 && $this->group['group_status'] == 1) {
                return $this->error("抱歉，你所在的用户组已被禁用，暂时无法登录系统");
            }
            $menuList = $this->authModel->getAdminMenuListByAdminId($this->group['group_id']);
            View::assign('menuLists', $menuList);
            View::assign('action', $this->request->action());
        } else {
            return $this->error("抱歉，没有查到你的用户组信息，暂时无法登录系统");
        }
    }
}
