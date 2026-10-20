<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use app\admin\QfShop;
use app\model\SourceSubscribe as SourceSubscribeModel;
use app\service\SubscribeService;

/**
 * 资源订阅管理（后台 API）
 *
 * 页面入口：资源管理 → 订阅按钮（/qfadmin/source/index 弹窗内）
 * API 路由：/admin/source_subscribe/*
 */
class SourceSubscribe extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        // 覆盖基类自动推断：控制器名 SourceSubscribe 经 strtolower 得到 sourcesubscribe，
        // 但实际表名为 qf_source_subscribe、主键为 subscribe_id（均带下划线），
        // 必须显式覆盖，否则 update/delete 等依赖 pk 的方法会报 "sourcesubscribe_id必须填写"
        $this->table = 'source_subscribe';
        $this->pk = 'subscribe_id';
        $this->pk_value = input($this->pk);

        $this->selectList = "*";
        $this->selectDetail = "*";
        $this->searchFilter = [
            "name" => "like",
        ];
        $this->insertFields = [
            "name", "url", "code", "client_id", "client_secret",
            "source_category_id", "is_time", "pull_mode", "interval_minutes", "status"
        ];
        $this->updateFields = [
            "name", "url", "code", "client_id", "client_secret",
            "source_category_id", "is_time", "pull_mode", "interval_minutes", "status"
        ];
        $this->insertRequire = [
            "name" => "订阅名称必须填写",
            "url" => "PanBot 服务地址必须填写",
            "code" => "订阅码必须填写",
            "client_id" => "client_id 必须填写",
            "client_secret" => "client_secret 必须填写",
        ];
        $this->updateRequire = [
            "subscribe_id" => "订阅ID必须填写",
            "name" => "订阅名称必须填写",
            "url" => "PanBot 服务地址必须填写",
            "code" => "订阅码必须填写",
            "client_id" => "client_id 必须填写",
            "client_secret" => "client_secret 必须填写",
        ];
        $this->model = new SourceSubscribeModel();
        // 基类默认排序 = strtolower(controller) + "_id desc" → sourcesubscribe_id desc，
        // 对实际主键 subscribe_id 非法。请求体里提供 order 时走自定义，否则按 subscribe_id desc
        $this->model->setDefaultOrder(['subscribe_id' => 'desc']);
    }

    /**
     * 重写列表：基类默认排序生成的是 sourcesubscribe_id desc（来自 strtolower(controller)），
     * 改为 subscribe_id desc，避免对不存在的列排序触发 SQL 异常
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $map = $this->getDataFilterFromRequest();
        $order = input('order') ? urldecode(input('order')) : 'subscribe_id desc';
        $this->setGetListPerPage();
        $dataList = $this->model->getListByPage($map, $order, $this->selectList);
        return jok('数据获取成功', $dataList);
    }

    /**
     * 添加订阅（重写：校验拉取间隔与入库方式，补时间戳）
     */
    public function add()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $error = $this->validateInsertFields();
        if ($error) {
            return $error;
        }
        $data = $this->getInsertDataFromRequest();
        $error = $this->validateSubscribeData($data);
        if ($error) {
            return $error;
        }
        $data['create_time'] = time();
        $data['update_time'] = time();
        $this->model->insertGetId($data);
        return jok('添加成功');
    }

    /**
     * 修改订阅（重写：校验拉取间隔与入库方式，补更新时间）
     */
    public function update()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        $error = $this->validateUpdateFields();
        if ($error) {
            return $error;
        }
        $data = $this->getUpdateDataFromRequest();
        $error = $this->validateSubscribeData($data);
        if ($error) {
            return $error;
        }
        $data['update_time'] = time();
        $this->model->where($this->pk, $this->pk_value)->update($data);
        return jok('修改成功');
    }

    /**
     * 设置订阅启停状态
     */
    public function setStatus()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        $status = intval(input('status', 1));
        if (!in_array($status, [0, 1])) {
            return jerr('状态参数异常');
        }
        $this->model->where($this->pk, $this->pk_value)->update([
            'status' => $status,
            'update_time' => time(),
        ]);
        return jok($status == 1 ? '已启用' : '已停用');
    }

    /**
     * 立即拉取指定订阅（忽略到期判断；与定时拉取共用同一把锁）
     */
    public function pullNow()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        $result = SubscribeService::run(intval($this->pk_value));
        if ($result['code'] != 1) {
            return jerr($result['message']);
        }
        $first = $result['data']['results'][0] ?? [];
        if (intval($first['code'] ?? 1) != 1) {
            return jerr($first['message'] ?? '拉取失败');
        }
        return jok($result['message'], $first);
    }

    /**
     * 订阅数据校验：拉取间隔、入库方式、时效枚举
     * @param array $data
     * @return mixed jerr 响应或 null
     */
    private function validateSubscribeData($data)
    {
        if (isset($data['interval_minutes'])) {
            $interval = intval($data['interval_minutes']);
            if ($interval < 5 || $interval > 1440) {
                return jerr('拉取间隔须在 5~1440 分钟之间');
            }
        }
        if (isset($data['pull_mode']) && !in_array(intval($data['pull_mode']), [1, 2])) {
            return jerr('入库方式参数异常');
        }
        if (isset($data['is_time']) && !in_array(intval($data['is_time']), [0, 1, 2])) {
            return jerr('资源时效参数异常');
        }
        return null;
    }
}
