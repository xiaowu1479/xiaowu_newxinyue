<?php

namespace app\admin\controller;

use think\App;
use app\admin\QfShop;
use app\model\NovelRecommend as NovelRecommendModel;

class NovelRecommend extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        // 控制器名与表名不一致，显式指定（表 qf_novel_recommend，主键 novel_recommend_id）
        $this->table = 'novel_recommend';
        $this->pk = 'novel_recommend_id';
        $this->pk_value = input($this->pk);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [
            "novel_recommend_title" => "like"
        ];
        $this->insertFields = [
            //允许添加的字段列表
            "novel_recommend_title", "novel_recommend_cover", "novel_recommend_link", "novel_recommend_sort", "novel_recommend_status"
        ];
        $this->updateFields = [
            //允许更新的字段列表
            "novel_recommend_title", "novel_recommend_cover", "novel_recommend_link", "novel_recommend_sort", "novel_recommend_status"
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            "novel_recommend_title" => "书名必须填写",
            "novel_recommend_cover" => "封面必须上传",
        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            "novel_recommend_title" => "书名必须填写",
        ];
        $this->model = new NovelRecommendModel();
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
        $dataList = $this->model->order('novel_recommend_sort', 'asc')->order('novel_recommend_id', 'asc')->select();
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
        $novel_recommend_id = input("novel_recommend_id");
        if (!$novel_recommend_id) {
            return jerr("ID参数必须填写", 400);
        }

        $d = [
            input("type") => input("status") == 1 ? 0 : 1,
            "novel_recommend_updatetime" => time(),
        ];

        //根据主键获取一行数据
        $item = $this->model->where("novel_recommend_id", $novel_recommend_id)->field($this->selectDetail)->find();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        //单个操作
        $map = ["novel_recommend_id" => $novel_recommend_id];
        $this->model->where($map)->update($d);

        return jok("操作成功");
    }
}
