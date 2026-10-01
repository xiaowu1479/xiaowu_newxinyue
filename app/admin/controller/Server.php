<?php

namespace app\admin\controller;

use think\App;
use app\admin\QfShop;
use app\model\Server as ServerModel;

class Server extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->selectList = "*";
        $this->selectDetail = "*";
        $this->searchFilter = [
            "server_domain" => "like",
            "server_ip" => "like",
            "server_status" => "=",
        ];
        $this->model = new ServerModel();
    }

    /**
     * 获取列表（不分页，返回全部）
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $dataList = $this->model->order('server_last_check', 'desc')->select();
        return jok('数据获取成功', $dataList);
    }

    /**
     * 删除
     */
    public function delete()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "必须填写", 400);
        }
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        $this->deleteBySingle();
        return jok('删除成功');
    }

    /**
     * 切换状态（启用/禁用）
     */
    public function setStatus()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $server_id = input("server_id");
        if (!$server_id) {
            return jerr("ID参数必须填写", 400);
        }
        $item = $this->model->where("server_id", $server_id)->find();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        $this->model->where("server_id", $server_id)->update([
            "server_status" => $item['server_status'] == 0 ? 1 : 0,
            "server_updatetime" => time(),
        ]);
        return jok("操作成功");
    }

    /**
     * 清空所有记录
     */
    public function clearAll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $this->model->where('1=1')->delete();
        return jok('已清空所有记录');
    }
}
