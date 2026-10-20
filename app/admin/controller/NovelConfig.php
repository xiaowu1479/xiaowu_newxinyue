<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use app\admin\QfShop;
use app\model\ApiList as ApiListModel;
use app\service\PanBackendService;

/**
 * 小说转存配置（后台弹窗聚合接口）
 *
 * 入口：资源管理 →「小说配置」按钮（/qfadmin/source/index 弹窗内）
 * API 路由：/admin/novel_config/*（TP6 自动路由到本控制器方法）
 *
 * 职责划分（编排迁入 xinyue 后）：
 *   转存开关/目标账户 → xinyue 自己的 qf_conf（novel_pan_enabled / novel_pan_accounts）
 *   网盘账户 → 直连 pan-backend（qf_conf 独立配置键：pan_backend_base_url /
 *       pan_backend_admin_user / pan_backend_admin_pass，与接口配置/线路无关），
 *       PanBackendService 完成 admin login → issue-token（永久，Cache 缓存）→ 业务调用
 *   网盘服务的连接/令牌/服务地址统一在「网盘管理」页（PanManage）维护，本控制器不涉及
 *   小说服务自身的服务级配置（下载并发 / 服务账号）不在此维护——xinyue 调用
 *       no-novel-node 的 /book-fetch、/book-download 均无需凭证（仅其 /admin/* 需登录），
 *       这类配置归小说服务自己的后台管理
 *
 * 可管理项：
 *   getConfig()       - 聚合读取：转存设置 + 网盘账户列表
 *   savePanSettings() - 转存开关 + 目标账户（写 qf_conf novel_pan_*)
 *
 * 网盘账户的添加/删除统一在「网盘管理」页（PanManage）维护，此处仅消费其账户列表
 */
class NovelConfig extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = null; // 代理型控制器，无单一模型
    }

    /**
     * 取启用中的小说线路地址（pantype=11）
     * @return string|null null=无启用中的小说线路
     */
    private function getNovelBaseUrl()
    {
        $line = (new ApiListModel())
            ->where('status', 1)
            ->where('pantype', 11)
            ->order('weight desc')
            ->find();
        if (empty($line)) {
            return null;
        }
        return rtrim(trim(strval($line['url'])), '/');
    }

    /**
     * 聚合读取配置：转存设置 + 网盘账户列表
     * 单项失败不影响整体，错误随项返回（前端按项展示）
     */
    public function getConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $baseUrl = $this->getNovelBaseUrl();
        if ($baseUrl === null || $baseUrl === '') {
            return jerr('未找到启用中的小说线路（pantype=11），请先在「接口配置」添加');
        }
        $data = [
            'base_url' => $baseUrl,
            'pan_settings' => null,
            'accounts' => null,
            'accounts_error' => '',
        ];
        // 转存设置：xinyue 自己的 qf_conf（编排已迁入，不再依赖小说服务配置）
        $confRows = Db::name('conf')->where('conf_key', 'in', [
            'novel_pan_enabled', 'novel_pan_accounts', 'novel_pan_remote_dir', 'novel_transfer_concurrency',
        ])->column('conf_value', 'conf_key');
        $confAccounts = json_decode(strval($confRows['novel_pan_accounts'] ?? '[]'), true);
        $data['pan_settings'] = [
            'enabled' => intval($confRows['novel_pan_enabled'] ?? 0) === 1,
            'accounts' => is_array($confAccounts) ? array_values(array_map('strval', $confAccounts)) : [],
            'remote_dir' => strval($confRows['novel_pan_remote_dir'] ?? '/novel'),
            'concurrency' => max(1, intval($confRows['novel_transfer_concurrency'] ?? 3) ?: 3),
        ];

        // 网盘账户列表：直连 pan-backend（对象数组 {drive_type,userId,nickname,icon,disabled}）
        $accounts = PanBackendService::accountsList();
        $data['accounts'] = $accounts['ok'] ? $accounts['data'] : null;
        $data['accounts_error'] = $accounts['message'];
        return jok('获取成功', $data);
    }

    /**
     * 保存转存设置（写 qf_conf：novel_pan_enabled / novel_pan_accounts /
     * novel_pan_remote_dir / novel_transfer_concurrency）
     */
    public function savePanSettings()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $enabled = input('enabled') ? true : false;
        $accounts = input('accounts/a', []);
        if (!is_array($accounts)) {
            return jerr('账户参数异常');
        }
        $accounts = array_values(array_filter(array_map('strval', $accounts), function ($v) {
            return $v !== '';
        }));
        if ($enabled && empty($accounts)) {
            return jerr('开启转存时至少选择一个目标账户');
        }
        // 网盘目录：留空/非法回退默认 /novel；并发上限：1-10（请求为 FPM 内联同步执行，勿设过大）
        $remoteDir = trim(strval(input('remote_dir', '/novel')));
        if ($remoteDir === '' || $remoteDir[0] !== '/') {
            $remoteDir = '/novel';
        }
        $concurrency = max(1, min(10, intval(input('concurrency', 3)) ?: 3));
        $this->saveConfKey('novel_pan_enabled', $enabled ? '1' : '0');
        $this->saveConfKey('novel_pan_accounts', json_encode($accounts, JSON_UNESCAPED_UNICODE));
        $this->saveConfKey('novel_pan_remote_dir', $remoteDir);
        $this->saveConfKey('novel_transfer_concurrency', strval($concurrency));
        return jok('转存设置已保存');
    }

    /**
     * qf_conf 单键 upsert（存在则 update，不存在则 insert）
     */
    private function saveConfKey($key, $value)
    {
        $now = time();
        $row = Db::name('conf')->where('conf_key', $key)->find();
        if ($row) {
            Db::name('conf')->where('conf_id', intval($row['conf_id']))->update([
                'conf_value' => strval($value),
                'conf_updatetime' => $now,
            ]);
        } else {
            Db::name('conf')->insert([
                'conf_key' => $key,
                'conf_value' => strval($value),
                'conf_title' => $key,
                'conf_desc' => '',
                'conf_int' => 0,
                'conf_spec' => 0,
                'conf_content' => null,
                'conf_type' => 1,
                'conf_status' => 1,
                'conf_sort' => 0,
                'conf_system' => 1,
                'conf_createtime' => $now,
                'conf_updatetime' => $now,
            ]);
        }
    }
}
