<?php

namespace app\admin\controller;

use think\App;
use app\admin\QfShop;
use app\model\Conf as ConfModel;

class Conf extends QfShop
{
    private const FRONTEND_TEMPLATE_KEY = 'frontend_template';
    private const NAV_MENU_ITEMS_KEY = 'nav_menu_items';

    public function __construct(App $app)
    {
        parent::__construct($app);
        //筛选字段
        $this->searchFilter = [
            "conf_id" => "=", //相同筛选
            "conf_key" => "like", //相似筛选
            "conf_value" => "like", //相似筛选
            "conf_title" => "like", //相似筛选
            "conf_status" => "=", //相同筛选
            "conf_type" => "=", //相同筛选
        ];
        $this->insertFields = [
            "conf_key", "conf_value", "conf_title", "conf_desc", "conf_status", "conf_type", "conf_spec", "conf_content", "conf_sort", "conf_system"
        ];
        $this->updateFields = [
            "conf_key", "conf_value", "conf_title", "conf_desc", "conf_status", "conf_type", "conf_spec", "conf_content", "conf_sort", "conf_system"
        ];
        $this->insertRequire = [
            'conf_title' => "参数名称必须填写",
            'conf_key' => "参数字段必须填写",
        ];
        $this->updateRequire = [
            'conf_title' => "参数名称必须填写",
            'conf_key' => "参数字段必须填写",
        ];
        $this->model = new ConfModel();
    }

    private function getFrontendTemplateDefaults(string $value = 'simple'): array
    {
        return [
            'conf_key' => self::FRONTEND_TEMPLATE_KEY,
            'conf_value' => $value,
            'conf_title' => '前台模板',
            'conf_desc' => '切换站点前台模板风格',
            'conf_spec' => 2,
            'conf_content' => "经典模板=>classic\n简约模板=>simple",
            'conf_type' => 3,
            'conf_status' => 1,
            'conf_sort' => 82,
            'conf_system' => 1,
        ];
    }

    private function getNavMenuItemsDefaults(string $value = 'home,ranking,top250,game,category,contact'): array
    {
        return [
            'conf_key' => self::NAV_MENU_ITEMS_KEY,
            'conf_value' => $value,
            'conf_title' => '顶部导航显示项',
            'conf_desc' => '控制前台顶部导航和手机端抽屉菜单显示哪些按钮',
            'conf_spec' => 3,
            'conf_content' => "首页=>home\n夸克榜单=>ranking\nTop250=>top250\n游戏排行=>game\n最近更新=>category\n联系我们=>contact",
            'conf_type' => 3,
            'conf_status' => 1,
            'conf_sort' => 81,
            'conf_system' => 1,
        ];
    }

    private function upsertFrontendTemplateConfig(string $value): void
    {
        $value = in_array($value, ['classic', 'simple'], true) ? $value : 'simple';
        $item = $this->model->where('conf_key', self::FRONTEND_TEMPLATE_KEY)->find();
        $data = $this->getFrontendTemplateDefaults($value);
        $data['conf_updatetime'] = time();

        if ($item) {
            $this->model->where('conf_key', self::FRONTEND_TEMPLATE_KEY)->update($data);
            return;
        }

        $data['conf_createtime'] = time();
        $this->model->insert($data);
    }

    /**
     * 获取列表接口基类
     *
     * @return void
     */
    public function getList()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //从请求中获取筛选数据的数组
        $map = $this->getDataFilterFromRequest();
        //从请求中获取排序方式
        $order = "conf_sort desc, conf_id asc";
        //设置Model中的 per_page
        $this->setGetListPerPage();
        //查询数据
        $dataList = $this->model->getListByPage($map, $order, $this->selectList);
        return jok('数据获取成功', $dataList);
    }
    /**
     * 按类型获取配置
     */
    public function getConfigByType()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $type = input('type', 0);
        $datalist = $this->model->where('conf_status', 1)
            ->where('conf_type', $type)
            ->order("conf_sort desc, conf_id asc")
            ->select()->toArray();
        foreach ($datalist as $key => $value) {
            if ($value['conf_content']) {
                $datalist[$key]['conf_content'] = explode("\n", $value['conf_content']);
            }
        }
        return jok('', $datalist);
    }

    /**
     * 按类型更新配置
     */
    public function updateConfigByType()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        foreach (input("post.") as $k => $v) {
            if (in_array($k, ['access_token', 'plat', 'version', 'type'])) {
                continue;
            }
            $item = $this->model->where("conf_key", $k)->find();
            if (empty($item)) {
                continue;
            }
            if (is_array($v)) {
                $v = implode(",", $v);
            }
            $this->model->where("conf_key", $k)->update(["conf_value" => $v]);
        }
        return jok("配置修改成功");
    }

    /**
     * 读取基本配置
     *
     * @return void
     */
    public function getBaseConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $hiddenKeys = [
            'app_demand',
            'ranking_num',
            'ranking_m_num',
            'home_bg',
            'home_background',
            'home_color',
            'home_theme',
            'other_background',
            'home_css',
            'home_new_img',
        ];
        // 简约模板下隐藏最新列表、榜单名称、热播榜单
        $currentTemplate = (string) config('qfshop.frontend_template', 'simple');
        if ($currentTemplate === 'simple') {
            $hiddenKeys = array_merge($hiddenKeys, ['home_new', 'rank_name', 'ranking_type']);
        }
        $datalist = $this->model->where('conf_status', 1)
            ->whereNotIn('conf_key', $hiddenKeys)
            ->order("conf_sort desc ".$this->pk . " asc")->select()->toArray();
        $hasFrontendTemplate = false;
        $hasNavMenuItems = false;
        foreach ($datalist as $key => $value) {
            if ($value['conf_key'] === self::FRONTEND_TEMPLATE_KEY) {
                $hasFrontendTemplate = true;
            }
            if ($value['conf_key'] === self::NAV_MENU_ITEMS_KEY) {
                $hasNavMenuItems = true;
            }
            if($value['conf_content']){
                $contentArr = explode("\n", $value['conf_content']);
                if ($value['conf_key'] === self::NAV_MENU_ITEMS_KEY) {
                    $contentArr = array_values(array_filter($contentArr, function($item) {
                        return strpos($item, '=>demand') === false;
                    }));
                }
                $datalist[$key]['conf_content'] = $contentArr;
            }
        }
        if (!$hasFrontendTemplate) {
            $frontendTemplate = $this->getFrontendTemplateDefaults((string) config('qfshop.frontend_template', 'simple'));
            $frontendTemplate['conf_content'] = explode("\n", $frontendTemplate['conf_content']);
            $datalist[] = $frontendTemplate;
        }
        if (!$hasNavMenuItems) {
            $navMenuItems = $this->getNavMenuItemsDefaults((string) config('qfshop.nav_menu_items', 'home,ranking,top250,game,category,contact'));
            $navMenuItems['conf_content'] = explode("\n", $navMenuItems['conf_content']);
            $datalist[] = $navMenuItems;
        }
        return jok('', $datalist);
    }
    /**
     * 更新基础配置
     *
     * @return void
     */
    public function updateBaseConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        foreach (input("post.") as $k => $v) {
            if ($k === self::FRONTEND_TEMPLATE_KEY) {
                $this->upsertFrontendTemplateConfig((string) $v);
                continue;
            }
            if ($k === self::NAV_MENU_ITEMS_KEY && empty($this->model->where('conf_key', self::NAV_MENU_ITEMS_KEY)->find())) {
                $data = $this->getNavMenuItemsDefaults(is_array($v) ? implode(",", $v) : (string) $v);
                $data['conf_createtime'] = time();
                $this->model->insert($data);
                continue;
            }
            $map["conf_key"] = $k;
            $item = $this->model->where($map)->find();
            if (empty($item)) {
                continue;
            }
            if(is_array($v)){
                $v = implode(",",$v);
            }
            $this->model->where("conf_key", $k)->update(["conf_value" => $v]);
        }
        return jok("配置修改成功");
    }

    /**
     * 添加接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function add()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //校验Insert字段是否填写
        $error = $this->validateInsertFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Insert数据
        $data = $this->getInsertDataFromRequest();

        $res = $this->model->where('conf_key',$data['conf_key'])->find();
        if ($res) {
            return jerr("参数字段已存在");
        }
        
        //添加这行数据
        $data['conf_value'] = '';
        $this->insertRow($data);
        return jok('添加成功');
    }

    /**
     * 修改接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function update()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        //根据主键获取一行数据
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        //校验Update字段是否填写
        $error  = $this->validateUpdateFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Update数据
        $data = $this->getUpdateDataFromRequest();

        $res = $this->model->where('conf_key',$data['conf_key'])->find();
        if ($res['conf_id']!= input("conf_id") && $res) {
            return jerr("参数字段已存在");
        }
        //根据主键更新这条数据
        $this->updateByPk($data);
        return jok('修改成功');
    }

    /**
     * 删除接口基类
     *
     * @return void
     */
    public function delete()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "必须填写", 400);
        }
        if (isInteger($this->pk_value)) {
            //根据主键获取一行数据
            $item = $this->getRowByPk();
            if (empty($item)) {
                return jerr("数据查询失败", 404);
            }
            if($item['conf_system']==1){
                return jerr("系统参数，禁止删除", 404);
            }
            //单个操作
            $this->deleteBySingle();
        } else {
            //批量操作
            return jerr("暂不支持批量删除", 400);
        }
        return jok('删除成功');
    }
}
