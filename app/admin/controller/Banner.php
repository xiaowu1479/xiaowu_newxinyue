<?php

namespace app\admin\controller;

use think\App;
use app\admin\QfShop;
use app\model\Banner as BannerModel;

class Banner extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [
            "banner_title" => "like"
        ];
        $this->insertFields = [
            //允许添加的字段列表
            "banner_title", "banner_image", "banner_link", "banner_sort", "banner_status"
        ];
        $this->updateFields = [
            //允许更新的字段列表
            "banner_title", "banner_image", "banner_link", "banner_sort", "banner_status"
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            "banner_title" => "名称必须填写",
            "banner_image" => "图片必须上传",
        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            "banner_title" => "名称必须填写",
        ];
        $this->model = new BannerModel();
    }


    /**
     * 获取列表接口(不分页，返回全部)
     */
    public function getList()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //查询数据：按排序升序，同序号按ID升序
        $dataList = $this->model->order('banner_sort', 'asc')->order('banner_id', 'asc')->select();
        return jok('数据获取成功', $dataList);
    }

    /**
     * 切换状态(启用/禁用)
     */
    public function setStatus()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $banner_id = input("banner_id");
        if (!$banner_id) {
            return jerr("ID参数必须填写", 400);
        }

        $d = [
            input("type") => input("status") == 1 ? 0 : 1,
            "banner_updatetime" => time(),
        ];

        //根据主键获取一行数据
        $item = $this->model->where("banner_id", $banner_id)->field($this->selectDetail)->find();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        //单个操作
        $map = ["banner_id" => $banner_id];
        $this->model->where($map)->update($d);

        return jok("操作成功");
    }
}
