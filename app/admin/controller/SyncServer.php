<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use think\facade\Cache;
use app\admin\QfShop;
use app\model\SyncServer as SyncServerModel;
use app\model\SyncLog as SyncLogModel;
use app\service\SyncService;

/**
 * 资源同步服务器管理（后台 API）
 *
 * 菜单位置：系统设置 -> 资源同步
 * 页面路由：/qfadmin/sync_server/index（由 qfadmin 模块渲染）
 * API 路由：/admin/sync_server/*
 */
class SyncServer extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        // 覆盖基类自动推断：控制器名 SyncServer 经 strtolower 得到 syncserver，
        // 但实际表名为 qf_sync_server、主键为 sync_server_id（均带下划线），
        // 必须显式覆盖，否则 update/delete 等依赖 pk 的方法会报 "syncserver_id必须填写"
        $this->table = 'sync_server';
        $this->pk = 'sync_server_id';
        $this->pk_value = input($this->pk);

        $this->selectList = "*";
        $this->selectDetail = "*";
        $this->searchFilter = [
            "name" => "like",
        ];
        $this->insertFields = [
            "name", "domain", "api_key", "status",
            "default_category_id", "category_map",
            "sync_is_type", "auto_sync", "sync_hour", "remark"
        ];
        $this->updateFields = [
            "name", "domain", "api_key", "status",
            "default_category_id", "category_map",
            "sync_is_type", "auto_sync", "sync_hour", "remark"
        ];
        $this->insertRequire = [
            "name" => "服务器名称必须填写",
            "domain" => "域名必须填写",
            "api_key" => "密钥必须填写",
        ];
        $this->updateRequire = [
            "name" => "服务器名称必须填写",
            "domain" => "域名必须填写",
            "api_key" => "密钥必须填写",
        ];
        $this->model = new SyncServerModel();
    }

    /**
     * 添加服务器（重写：表使用 create_time/update_time 而非 {table}_createtime）
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
        $data['domain'] = rtrim(trim($data['domain']), '/');
        $data['create_time'] = time();
        $data['update_time'] = time();
        $this->model->insertGetId($data);
        return jok('添加成功');
    }

    /**
     * 修改服务器（重写：表使用 update_time 而非 {table}_updatetime）
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
        $data['domain'] = rtrim(trim($data['domain']), '/');
        $data['update_time'] = time();
        $this->model->where($this->pk, $this->pk_value)->update($data);
        return jok('修改成功');
    }

    /**
     * 获取服务器列表（不分页，返回全部）
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $dataList = $this->model->order('sync_server_id', 'desc')->select();
        return jok('数据获取成功', $dataList);
    }

    /**
     * 获取本地分类列表（供前端选择默认分类）
     */
    public function getCategoryList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $list = Db::name('source_category')
            ->where('status', 0)
            ->order('sort', 'desc')
            ->select();
        return jok('数据获取成功', $list);
    }

    /**
     * 测试连通性
     * 调用源站 /api/sync/ping 验证域名+密钥
     */
    public function test()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $domain = rtrim(trim(input('domain', '')), '/');
        $apiKey = trim(input('api_key', ''));
        if (empty($domain) || empty($apiKey)) {
            return jerr('域名和密钥必须填写');
        }
        $res = $this->callRemote($domain . '/api/sync/ping', ['api_key' => $apiKey]);
        if ($res === false) {
            return jerr('无法连接源站，请检查域名是否正确');
        }
        if (!isset($res['code']) || $res['code'] != 200) {
            return jerr($res['message'] ?? '密钥验证失败');
        }
        return jok('连接成功', $res['data'] ?? []);
    }

    /**
     * 执行同步
     * 委托给 SyncService::run()，核心逻辑与 CLI 定时命令共用同一份代码
     */
    public function sync()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $id = input('sync_server_id/d', 0);
        $result = SyncService::run($id);
        if ($result['code'] == 1) {
            return jok($result['message'], $result['data']);
        } else {
            return jerr($result['message']);
        }
    }

    /**
     * 同步日志列表
     */
    public function logs()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $serverId = input('sync_server_id/d', 0);
        $query = new SyncLogModel();
        if ($serverId > 0) {
            $query = $query->where('sync_server_id', $serverId);
        }
        $list = $query->order('sync_log_id', 'desc')
            ->limit(200)
            ->select();
        return jok('数据获取成功', $list);
    }

    /**
     * 重置增量同步时间（下次全量同步）
     */
    public function resetSyncTime()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $id = input('sync_server_id/d', 0);
        $server = $this->model->find($id);
        if (empty($server)) {
            return jerr('服务器不存在');
        }
        $this->model->where('sync_server_id', $id)->update([
            'last_sync_time' => 0,
            'update_time' => time(),
        ]);
        return jok('已重置，下次将全量同步');
    }

    // ========== 私有辅助方法 ==========

    /**
     * 调用源站 API
     */
    private function callRemote($url, $params)
    {
        $fullUrl = $url . '?' . http_build_query($params);
        $res = curlHelper($fullUrl, 'GET', null, [
            'User-Agent: Mozilla/5.0 (compatible; XinyueSync/1.0)',
            'Accept: application/json',
        ], '', '', 30);
        if (!empty($res['error'])) {
            return false;
        }
        $body = $res['body'] ?? '';
        if (empty($body)) {
            return false;
        }
        return json_decode($body, true);
    }

    /**
     * 获取资源同步密钥
     * 读取 qf_conf 表中 conf_key='sync_key' 的配置值
     * 该密钥用于本站作为源站时，校验他站拉取资源的请求身份
     */
    public function getSyncKey()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $item = $this->confModel->where('conf_key', 'sync_key')->find();
        return jok('数据获取成功', [
            'sync_key' => $item['conf_value'] ?? '',
        ]);
    }

    /**
     * 保存资源同步密钥
     * upsert qf_conf 表中 conf_key='sync_key' 的记录
     * 留空表示关闭同步功能（源站将拒绝所有拉取请求）
     */
    public function saveSyncKey()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $syncKey = trim(input('sync_key', ''));
        // 非空时校验最小长度，避免弱密钥
        if (!empty($syncKey) && mb_strlen($syncKey) < 8) {
            return jerr('同步密钥至少需要 8 个字符');
        }

        $item = $this->confModel->where('conf_key', 'sync_key')->find();
        if ($item) {
            $this->confModel->where('conf_key', 'sync_key')->update([
                'conf_value' => $syncKey,
                'conf_updatetime' => time(),
            ]);
        } else {
            $this->confModel->insert([
                'conf_key' => 'sync_key',
                'conf_value' => $syncKey,
                'conf_title' => '资源同步密钥',
                'conf_desc' => '供其他站点拉取本站资源时验证身份；留空=禁止拉取',
                'conf_type' => 1,
                'conf_status' => 1,
                'conf_sort' => 50,
                'conf_system' => 1,
                'conf_createtime' => time(),
                'conf_updatetime' => time(),
            ]);
        }
        return jok(empty($syncKey) ? '已清空同步密钥，他站将无法拉取本站资源' : '同步密钥保存成功');
    }
}
