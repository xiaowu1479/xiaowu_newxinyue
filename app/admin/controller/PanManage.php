<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use app\admin\QfShop;
use app\service\PanBackendService;

/**
 * 网盘管理（后台「网盘管理」板块统一入口）
 *
 * 入口：/qfadmin/source/deposit（网盘管理菜单）
 * API 路由：/admin/pan_manage/*（TP6 自动路由到本控制器方法）
 *
 * 职责：xinyue 全站网盘能力的统一管理面——
 *   网盘账户（绑定/解绑）、目录规划（保存目录/临时目录）、文件浏览均直连
 *   pan-backend（qf_conf 独立配置键，与接口配置/线路无关），xinyue 侧不落地
 *   任何网盘 cookie/token；业务代码需要网盘操作时统一走 app\service\PanBackendService。
 *
 * 目录规划存 qf_conf pan_account_dirs：
 *   JSON {"{driveType}_{userId}": {"save": "/目录", "temp": "/临时目录"}}
 *
 * 可管理项：
 *   getConfig()     - 聚合读取：网盘服务连接状态 + 服务地址/令牌回显 + 账户列表 + 目录规划
 *   saveBaseUrl()   - 保存网盘服务连接配置：地址+令牌（均必填，写 qf_conf 独立键；保存后探活）
 *   saveDirs()      - 保存某账户的目录规划（合并进 pan_account_dirs）
 *   ls()            - 目录浏览代理（/cloud-drive/ls，供前端 cascader 懒加载）
 *   accountLogin()  - 添加网盘账户（pan-backend /accounts/login，扫码/短信/cookie 透传）
 *   accountDelete() - 删除网盘账户（pan-backend /accounts/delete）
 *   getDrivers()    - 网盘驱动列表（pan-backend /drivers）
 */
class PanManage extends QfShop
{
    /** 目录规划配置键（qf_conf） */
    const DIRS_CONF_KEY = 'pan_account_dirs';

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = null; // 代理型控制器，无单一模型
    }

    /**
     * 聚合读取：网盘服务连接状态 + 账户列表 + 目录规划
     * 单项失败不影响整体，错误随项返回（前端按项展示）
     */
    public function getConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        // 账户列表探活（同时作为网盘服务连通性依据）
        $accounts = PanBackendService::accountsList();
        $service = PanBackendService::resolveService();
        $dirs = json_decode(strval(Db::name('conf')->where('conf_key', self::DIRS_CONF_KEY)->value('conf_value')), true);
        return jok('获取成功', [
            'pan_service' => [
                'base_url' => $service === null ? '' : strval($service['base_url']),
                'token' => $service === null ? '' : strval($service['token']),
                'connected' => $accounts['ok'] ? 1 : 0,
                'error' => $accounts['ok'] ? '' : $accounts['message'],
            ],
            'accounts' => $accounts['ok'] ? $accounts['data'] : null,
            'accounts_error' => $accounts['message'],
            'dirs' => is_array($dirs) ? $dirs : [],
        ]);
    }

    /**
     * 保存某账户的目录规划（body: {account, saveDir, tempDir}）
     * 两项均空 = 移除该账户的目录规划记录
     */
    public function saveDirs()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $account = trim(strval(input('account', '')));
        if (!preg_match('#^[A-Za-z0-9]+_[A-Za-z0-9_-]+$#', $account)) {
            return jerr('account 参数格式异常');
        }
        $saveDir = $this->normalizeDir(input('saveDir', ''));
        $tempDir = $this->normalizeDir(input('tempDir', ''));
        if ($saveDir === null || $tempDir === null) {
            return jerr('目录格式异常：必须为以 / 开头的绝对路径');
        }
        $dirs = json_decode(strval(Db::name('conf')->where('conf_key', self::DIRS_CONF_KEY)->value('conf_value')), true);
        if (!is_array($dirs)) {
            $dirs = [];
        }
        if ($saveDir === '' && $tempDir === '') {
            unset($dirs[$account]);
        } else {
            $dirs[$account] = ['save' => $saveDir, 'temp' => $tempDir];
        }
        $this->saveConfKey(self::DIRS_CONF_KEY, json_encode($dirs, JSON_UNESCAPED_UNICODE), '网盘账户目录规划', '各网盘账户的保存目录/临时目录（JSON）');
        return jok('目录规划已保存');
    }

    /**
     * 保存网盘服务连接配置（body: {baseUrl, token}）——网盘管理页统一入口，两项均必填
     * 网盘服务与「线路/接口配置」无关：地址与令牌写 qf_conf 独立键
     * （pan_backend_base_url / pan_backend_token），填什么用什么——不做登录签发、不做缓存。
     * 保存后立即探活回传连通结果。
     */
    public function saveBaseUrl()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $baseUrl = rtrim(trim(strval(input('baseUrl', ''))), '/');
        if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl) || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            return jerr('服务地址不能为空：需为 http(s):// 开头的完整地址');
        }
        $token = trim(strval(input('token', '')));
        // 兼容粘贴带 "Bearer " 前缀的令牌，统一剥掉
        $token = trim(preg_replace('#^Bearer\s+#i', '', $token));
        if ($token === '') {
            return jerr('服务令牌不能为空：请在 pan-backend 管理页签发后粘贴');
        }
        $this->saveConfKey(PanBackendService::CONF_KEY_BASE_URL, $baseUrl, '网盘服务地址', 'pan-backend 根地址（后台「网盘管理」页维护，与线路无关）');
        $this->saveConfKey(PanBackendService::CONF_KEY_TOKEN, $token, '网盘服务令牌', 'pan-backend 签发的 API token（网盘管理页手动维护，填什么用什么）');
        // 立即探活，回传连通结果
        $accounts = PanBackendService::accountsList();
        $msg = $accounts['ok'] ? '服务配置已保存，连接正常' : '服务配置已保存，但连接失败：' . $accounts['message'];
        return jok($msg, [
            'connected' => $accounts['ok'] ? 1 : 0,
            'error' => $accounts['ok'] ? '' : $accounts['message'],
        ]);
    }

    /**
     * 目录浏览代理（前端目录选择 cascader 懒加载用）
     * body: {account, path(可空=/{account}/), refresh}
     */
    public function ls()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $account = trim(strval(input('account', '')));
        if (!preg_match('#^[A-Za-z0-9]+_[A-Za-z0-9_-]+$#', $account)) {
            return jerr('account 参数格式异常');
        }
        $path = trim(strval(input('path', '')));
        if ($path === '') {
            $path = '/' . $account . '/';
        }
        // 越权防护：path 必须落在该账户前缀下（pan-backend 侧 assertPathOwner 另有兜底）
        if (strpos($path, '/' . $account . '/') !== 0) {
            return jerr('path 不属于该账户');
        }
        $result = PanBackendService::ls($path, 1, 0, input('refresh') ? true : false);
        if (!$result['ok']) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
    }

    /**
     * 添加网盘账户（直连 pan-backend POST /accounts/login 统一登录）
     * body: {driveType, action: qrcode_start|qrcode_poll|tv_start|tv_poll|sms_send|sms_submit|cookie,
     *        sessionMeta(JSON字符串，轮询透传), cookie, config, smsCode, captchaCode, userId}
     */
    public function accountLogin()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $body = [
            'driveType' => trim(strval(input('driveType', ''))),
            'action' => trim(strval(input('action', ''))),
            'sessionMeta' => strval(input('sessionMeta', '{}')),
            'cookie' => strval(input('cookie', '')),
            'config' => strval(input('config', '{}')),
            'userId' => strval(input('userId', '')),
            'smsCode' => strval(input('smsCode', '')),
            'captchaCode' => strval(input('captchaCode', '')),
        ];
        if ($body['driveType'] === '' || $body['action'] === '') {
            return jerr('driveType / action 参数不能为空');
        }
        $result = PanBackendService::accountLogin($body);
        if (!$result['ok']) {
            return jerr($result['message']);
        }
        return jok('操作成功', $result['data']);
    }

    /**
     * 删除网盘账户（直连 pan-backend POST /accounts/delete {account}）
     */
    public function accountDelete()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $account = trim(strval(input('account', '')));
        if ($account === '') {
            return jerr('account 参数不能为空');
        }
        $result = PanBackendService::accountDelete($account);
        if (!$result['ok']) {
            return jerr($result['message']);
        }
        return jok('账户已删除：' . $account);
    }

    /**
     * 网盘驱动列表（直连 pan-backend GET /drivers → DriverInfo[]：
     * {driveType, driveName, icon, loginMethods, configFields, ...}）
     */
    public function getDrivers()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $result = PanBackendService::drivers();
        if (!$result['ok']) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
    }

    /**
     * 目录路径归一化：''=未设置；否则必须以 / 开头（去尾斜杠，根目录 '/' 除外）
     * @return string|null null=格式非法
     */
    private function normalizeDir($dir)
    {
        $dir = trim(strval($dir));
        if ($dir === '') {
            return '';
        }
        if ($dir !== '/') {
            $dir = rtrim($dir, '/');
            if ($dir === '') {
                $dir = '/';
            }
        }
        return strpos($dir, '/') === 0 ? $dir : null;
    }

    /**
     * qf_conf 单键 upsert（存在则 update，不存在则 insert）
     */
    private function saveConfKey($key, $value, $title, $desc)
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
                'conf_title' => $title,
                'conf_desc' => $desc,
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
