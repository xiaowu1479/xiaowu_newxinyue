<?php

namespace app\admin\controller;

use think\App;
use think\facade\Db;
use app\admin\QfShop;
use app\service\PanBotService;

/**
 * PanBot 第三方开放平台同步（后台 API）
 *
 * 入口：资源管理 → panbot同步 按钮（/qfadmin/source/index 弹窗内）
 * API 路由：/admin/panbot/*
 *
 * 提供接口：
 *   getConfig()      - 读取当前 PanBot 配置（不含 api_key 全文）
 *   saveConfig()    - 保存/更新 PanBot 配置（url + api_key）
 *   testConnection()- 测试连通性（验证 url+key，统计资源库数）
 *   syncAll()       - 全量同步（拉资源库 + 分类 + 各分类资源首次全量）
 *   syncIncrement() - 增量同步（仅拉 last_sync_at > 0 的分类，按 updated_since）
 *   resetSyncTime() - 重置全部分类同步时间（强制下次全量）
 *   getLogs()       - 同步日志
 *   getCategoryMap()- 第三方分类 ↔ 本地分类映射
 *   getCategories() - 同步大类勾选列表（一级大类=资源库，is_sync 标记）
 *   saveCategories()- 保存同步大类勾选（仅同步勾选大类下的分类）
 */
class PanBot extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = null; // 配置型控制器，无单一模型
    }

    /**
     * 获取当前 PanBot 配置（api_key 全量返回仅限后台可信环境；前端展示时按需做脱敏）
     */
    public function getConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $row = PanBotService::getConfig();
        return jok('获取成功', $row ?: []);
    }

    /**
     * 保存 PanBot 配置
     * 保存成功后立刻触发一次全量同步（首次同步），用户可在弹窗里继续手动操作
     */
    public function saveConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $baseUrl = trim(strval(input('base_url', '')));
        $apiKey = trim(strval(input('api_key', '')));
        $intervalMinutes = intval(input('interval_minutes', 360));
        $status = input('status', 1) ? 1 : 0;
        try {
            $panbotId = PanBotService::saveConfig($baseUrl, $apiKey, $intervalMinutes, $status);
        } catch (\Exception $e) {
            return jerr($e->getMessage());
        }

        // 立即同步：保存后立即触发一次全量（后台手动触发，不阻塞返回）
        $syncResult = PanBotService::syncAll($panbotId);
        return jok('保存成功' . ($syncResult['code'] == 1 ? '，已立即开始同步' : '，但立即同步失败：' . $syncResult['message']), [
            'panbot_id' => $panbotId,
            'sync' => $syncResult,
        ]);
    }

    /**
     * 测试连通性
     */
    public function testConnection()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $baseUrl = trim(strval(input('base_url', '')));
        $apiKey = trim(strval(input('api_key', '')));
        $result = PanBotService::testConnection($baseUrl, $apiKey);
        if ($result['ok']) {
            return jok($result['message'], ['libraries_count' => $result['libraries_count']]);
        }
        return jerr($result['message']);
    }

    /**
     * 全量同步
     */
    public function syncAll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jerr('请先保存 PanBot 配置');
        }
        $result = PanBotService::syncAll($panbotId);
        if ($result['code'] == 1) {
            return jok($result['message'], $result['data']);
        }
        return jerr($result['message']);
    }

    /**
     * 增量同步
     */
    public function syncIncrement()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jerr('请先保存 PanBot 配置');
        }
        $result = PanBotService::syncIncrement($panbotId);
        if ($result['code'] == 1) {
            return jok($result['message'], $result['data']);
        }
        return jerr($result['message']);
    }

    /**
     * 同步分类勾选列表（弹窗「同步分类」区展示）
     */
    public function getCategories()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jok('未保存配置', ['list' => []]);
        }
        $result = PanBotService::getSyncCategories($panbotId);
        if ($result['code'] != 1) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
    }

    /**
     * 保存同步大类勾选：仅同步勾选大类（资源库）下的分类，未勾选的自动跳过
     */
    public function saveCategories()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jerr('请先保存 PanBot 配置');
        }
        // 兼容数组与逗号分隔字符串两种传参
        $idsInput = input('library_ids', []);
        if (is_string($idsInput)) {
            $idsInput = $idsInput === '' ? [] : explode(',', $idsInput);
        }
        if (!is_array($idsInput)) {
            $idsInput = [];
        }
        $result = PanBotService::saveSyncCategories($panbotId, $idsInput);
        if ($result['code'] != 1) {
            return jerr($result['message']);
        }
        return jok($result['message'], $result['data']);
    }

    /**
     * 重置同步时间：勾选分类（is_sync=1）的 last_sync_at 置 0，下次同步对该分类走全量
     * 未勾选分类不动（本就不参与拉取，保留其水位，重新勾选后可增量续传）
     * （PanBot 侧数据清空/恢复后，配合「全量重拉」使用）
     */
    public function resetSyncTime()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jerr('请先保存 PanBot 配置');
        }
        try {
            Db::name('panbot_category')
                ->where('panbot_id', $panbotId)
                ->where('is_sync', 1)
                ->update([
                    'last_sync_at' => 0,
                    'last_resource_id' => '',
                    'update_time' => time(),
                ]);
        } catch (\Throwable $e) {
            // 表尚未创建，静默忽略
        }
        return jok('已重置勾选分类的同步水位，下次同步将重新拉取勾选分类的全部资源');
    }

    /**
     * 最近实际同步到 qf_source 的资源
     * 返回本轮同步窗口（prev_sync_at 起）内 account_name='PanBot' 的资源，按 update_time 倒序
     */
    public function getRecentResources()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $panbotId = intval(input('panbot_id', 0));
        if ($panbotId <= 0) {
            $cfg = PanBotService::getConfig();
            $panbotId = intval($cfg['panbot_id'] ?? 0);
        }
        if ($panbotId <= 0) {
            return jok('获取成功', ['list' => [], 'since' => 0, 'total' => 0]);
        }
        $limit = max(1, min(200, intval(input('limit', 50))));
        $result = PanBotService::getRecentSyncedResources($panbotId, $limit);
        if ($result['code'] != 1) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
    }
}