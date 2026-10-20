<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * PanBot 第三方开放平台同步服务
 *
 * 适配 https://test.openapi.pars.panbot.cn/docs 开放接口：
 *   GET {base_url}/api/v1/libraries        分页查询资源库列表
 *   GET {base_url}/api/v1/categories       分页查询分类列表（按 library_id 过滤）
 *   GET {base_url}/api/v1/shares           分页查询资源数据（按 category_id / library_id 筛选）
 *
 * 鉴权：所有数据接口均需在请求头携带 X-API-Key: pbak_xxx
 *
 * 同步流程（全量）：
 *   1. 拉取所有资源库（不传参，资源库总数小，单次拉完）
 *   2. 拉取所有分类（不传参，单次拉完），按 library_id 关联资源库
 *   3. 本地缺失的分类按「资源库名 + 分类名」逐个创建/匹配，本地分类作为父级映射
 *   4. 按分类逐个拉取资源列表（首次全量，二次按 last_sync_at 增量）
 *   5. 资源写入 qf_source 表，url 去重，update_time / category_id 同步更新
 *
 * 同步流程（增量）：
 *   仅步骤 4 改为 time_field=updated_at + time_start=该分类上次同步时间-300s + sort=updated_at_asc
 */
class PanBotService
{
    // 单次 HTTP 超时（秒）
    const TIMEOUT = 30;
    // 单次 HTTP 响应体上限（5MB）
    const MAX_BODY_BYTES = 5242880;
    // 资源库 / 分类列表的最大页数（资源库和分类数量很少，做硬上限防失控）
    const MAX_LIST_PAGES = 20;
    // 资源列表的最大页数（按分类同步，单分类上限）
    const MAX_RESOURCE_PAGES = 200;
    // 资源接口默认 page_size
    const DEFAULT_PAGE_SIZE = 100;

    // SSRF 校验开关：仅供自动化测试对本地 mock 服务跑端到端时置 true，生产恒为 false
    public static $skipGuard = false;

    /**
     * 自愈建表：检测 panbot_* 三张表是否存在，缺失则用 CREATE TABLE IF NOT EXISTS 幂等创建
     * 用于部署后未执行 upgrade.sql 的环境，用户首次进弹窗点保存即可触发
     */
    private static function ensureTables()
    {
        $sqls = [
            "CREATE TABLE IF NOT EXISTS `qf_panbot_config` (
              `panbot_id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(100) NOT NULL DEFAULT '',
              `base_url` varchar(255) NOT NULL DEFAULT '',
              `api_key` varchar(255) NOT NULL DEFAULT '',
              `status` tinyint(1) NOT NULL DEFAULT 1,
              `interval_minutes` int(11) NOT NULL DEFAULT 360,
              `last_sync_at` int(11) NOT NULL DEFAULT 0,
              `prev_sync_at` int(11) NOT NULL DEFAULT 0,
              `last_sync_msg` varchar(500) NOT NULL DEFAULT '',
              `last_auto_at` int(11) NOT NULL DEFAULT 0,
              `create_time` int(11) NOT NULL DEFAULT 0,
              `update_time` int(11) NOT NULL DEFAULT 0,
              PRIMARY KEY (`panbot_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `qf_panbot_category` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `panbot_id` int(11) NOT NULL DEFAULT 0,
              `library_id` varchar(64) NOT NULL DEFAULT '',
              `library_name` varchar(100) NOT NULL DEFAULT '',
              `remote_category_id` varchar(64) NOT NULL DEFAULT '',
              `remote_category_name` varchar(100) NOT NULL DEFAULT '',
              `local_category_id` int(11) NOT NULL DEFAULT 0,
              `is_sync` tinyint(1) NOT NULL DEFAULT 1,
              `last_sync_at` int(11) NOT NULL DEFAULT 0,
              `last_resource_id` varchar(64) NOT NULL DEFAULT '',
              `create_time` int(11) NOT NULL DEFAULT 0,
              `update_time` int(11) NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uk_panbot_remote` (`panbot_id`,`remote_category_id`),
              KEY `idx_panbot_id` (`panbot_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
        foreach ($sqls as $sql) {
            try {
                Db::execute($sql);
            } catch (\Throwable $e) {
                // 单条失败不抛出（让其他表继续创建）；最后由调用方重试
            }
        }

        // 列级自愈：旧版本创建的表补齐后加的列（幂等），避免同步回写时 Unknown column
        try {
            $fields = array_column(Db::query("SHOW COLUMNS FROM `qf_panbot_config`"), 'Field');
            $alters = [
                'interval_minutes' => "ALTER TABLE `qf_panbot_config` ADD COLUMN `interval_minutes` int(11) NOT NULL DEFAULT 360 COMMENT '自动同步间隔（分钟，最小5；0=禁用自动同步）' AFTER `status`",
                'prev_sync_at' => "ALTER TABLE `qf_panbot_config` ADD COLUMN `prev_sync_at` int(11) NOT NULL DEFAULT 0 COMMENT '本轮同步窗口起点（上次同步开始前时间）' AFTER `last_sync_at`",
                'last_auto_at' => "ALTER TABLE `qf_panbot_config` ADD COLUMN `last_auto_at` int(11) NOT NULL DEFAULT 0 COMMENT '最近一次自动同步检查时间' AFTER `last_sync_msg`",
            ];
            foreach ($alters as $name => $ddl) {
                if (!in_array($name, $fields)) {
                    Db::execute($ddl);
                }
            }
        } catch (\Throwable $e) {
            // 自愈失败不阻断主流程
        }

        // 列级自愈：旧库的 qf_panbot_category 补 is_sync 列（勾选分类同步，幂等）
        try {
            $catFields = array_column(Db::query("SHOW COLUMNS FROM `qf_panbot_category`"), 'Field');
            if (!empty($catFields) && !in_array('is_sync', $catFields)) {
                Db::execute("ALTER TABLE `qf_panbot_category` ADD COLUMN `is_sync` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否同步该分类（1=同步 0=跳过）' AFTER `local_category_id`");
            }
        } catch (\Throwable $e) {
            // 自愈失败不阻断主流程
        }

        // 列宽自愈：vod_pic 旧为 varchar(255)，PanBot 封面为对象存储签名直链（约 300+ 字符），
        // 255 会截掉签名参数导致图片 403，扩到 varchar(1000)（幂等，仅旧宽度时执行）
        try {
            $col = Db::query("SHOW COLUMNS FROM `qf_source` LIKE 'vod_pic'");
            if (!empty($col) && str_contains(strval($col[0]['Type'] ?? ''), 'varchar(255)')) {
                Db::execute("ALTER TABLE `qf_source` MODIFY `vod_pic` varchar(1000) NOT NULL DEFAULT '' COMMENT '资源图片'");
            }
        } catch (\Throwable $e) {
            // 自愈失败不阻断主流程
        }
    }

    /**
     * 检测是否触发过自愈建表（每次请求一次，避免重复探测三张表）
     */
    private static function ensureTablesChecked()
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        self::ensureTables();
    }

    /**
     * 获取（或创建）单条 PanBot 配置
     * @return array|null 配置行；无有效 base_url/api_key 返回 null
     */
    public static function getConfig()
    {
        try {
            $row = Db::name('panbot_config')->order('panbot_id', 'desc')->find();
        } catch (\Throwable $e) {
            self::ensureTables();
            try {
                $row = Db::name('panbot_config')->order('panbot_id', 'desc')->find();
            } catch (\Throwable $e2) {
                return null;
            }
        }
        if (empty($row)) {
            return null;
        }
        return $row;
    }

    /**
     * 测试连通性：调一次 libraries 接口，验证 url + key
     * @param string $baseUrl
     * @param string $apiKey
     * @return array ['ok'=>bool, 'message'=>string, 'libraries_count'=>int]
     */
    public static function testConnection($baseUrl, $apiKey)
    {
        $baseUrl = rtrim(trim(strval($baseUrl)), '/');
        $apiKey = trim(strval($apiKey));
        if ($baseUrl === '' || $apiKey === '') {
            return ['ok' => false, 'message' => '服务地址和 API Key 均必填', 'libraries_count' => 0];
        }
        if (!self::$skipGuard) {
            $guardError = self::guardUrl($baseUrl);
            if ($guardError !== '') {
                return ['ok' => false, 'message' => '服务地址被拒绝：' . $guardError, 'libraries_count' => 0];
            }
        }
        try {
            $res = self::callApi($baseUrl, '/api/v1/libraries', $apiKey, [], 1, 1);
            if ($res === false) {
                return ['ok' => false, 'message' => '无法连接第三方开放平台，请检查 URL 与网络', 'libraries_count' => 0];
            }
            if (intval($res['code'] ?? 0) !== 200) {
                return ['ok' => false, 'message' => strval($res['message'] ?? '第三方返回非成功响应'), 'libraries_count' => 0];
            }
            $count = intval($res['data']['total'] ?? 0);

            // 顺带探测资源列表接口（shares），避免只测 libraries 造成的「测试通过但同步 404」假阳性
            $res2 = self::callApi($baseUrl, '/api/v1/shares', $apiKey, ['page' => 1, 'page_size' => 1], 1, 1);
            if ($res2 === false) {
                return ['ok' => false, 'message' => '资源库连接正常，但资源数据接口无响应，请检查网络', 'libraries_count' => $count];
            }
            if (intval($res2['code'] ?? 0) !== 200) {
                return ['ok' => false, 'message' => '资源数据接口异常：' . strval($res2['message'] ?? '非成功响应'), 'libraries_count' => $count];
            }
            $sharesTotal = intval($res2['data']['total'] ?? 0);
            return ['ok' => true, 'message' => '连接成功，共 ' . $count . ' 个资源库、' . $sharesTotal . ' 条资源', 'libraries_count' => $count];
        } catch (\Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'libraries_count' => 0];
        }
    }

    /**
     * 保存配置（upsert，按 panbot_id；单条配置语义，不存在则新建）
     * @param int $intervalMinutes 自动同步间隔（分钟，默认 360=6小时，最小 5，0=禁用）
     * @return int 配置 panbot_id
     */
    public static function saveConfig($baseUrl, $apiKey, $intervalMinutes = 360, $status = 1)
    {
        $baseUrl = rtrim(trim(strval($baseUrl)), '/');
        $apiKey = trim(strval($apiKey));
        $intervalMinutes = max(0, intval($intervalMinutes));
        if ($intervalMinutes > 0 && $intervalMinutes < 5) {
            // 0 表示禁用；其它值不允许小于 5（避免过于频繁打第三方接口）
            $intervalMinutes = 5;
        }
        $status = $status ? 1 : 0;

        if ($baseUrl === '' || $apiKey === '') {
            throw new \Exception('服务地址和 API Key 均必填');
        }
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new \Exception('服务地址必须以 http:// 或 https:// 开头');
        }
        if (!preg_match('/^pbak_[A-Za-z0-9]{8,}$/', $apiKey)) {
            throw new \Exception('API Key 格式无效，应以 pbak_ 开头');
        }
        if (!self::$skipGuard) {
            $guardError = self::guardUrl($baseUrl);
            if ($guardError !== '') {
                throw new \Exception('服务地址被拒绝：' . $guardError);
            }
        }

        $now = time();
        self::ensureTablesChecked();
        try {
            $existing = Db::name('panbot_config')->order('panbot_id', 'desc')->find();
        } catch (\Throwable $e) {
            self::ensureTables();
            $existing = Db::name('panbot_config')->order('panbot_id', 'desc')->find();
        }
        if ($existing) {
            Db::name('panbot_config')->where('panbot_id', $existing['panbot_id'])->update([
                'base_url' => $baseUrl,
                'api_key' => $apiKey,
                'status' => $status,
                'interval_minutes' => $intervalMinutes,
                'update_time' => $now,
            ]);
            return intval($existing['panbot_id']);
        }
        return Db::name('panbot_config')->insertGetId([
            'name' => 'PanBot',
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'status' => $status,
            'interval_minutes' => $intervalMinutes,
            'last_sync_at' => 0,
            'last_sync_msg' => '',
            'last_auto_at' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);
    }

    /**
     * 自动同步入口：扫描所有启用的停用记录，对到期的执行增量同步
     * （CLI 命令 /api/sync/panbotAuto 调用）
     * @param int $panbotId 指定 panbot_id；0=扫描所有启用的配置
     * @return array
     */
    public static function syncAuto($panbotId = 0)
    {
        $now = time();
        $results = [];

        self::ensureTablesChecked();
        try {
            $query = Db::name('panbot_config')->where('status', 1);
            if ($panbotId > 0) {
                $query->where('panbot_id', $panbotId);
            }
            $configs = $query->select()->toArray();
        } catch (\Throwable $e) {
            self::ensureTables();
            try {
                $query = Db::name('panbot_config')->where('status', 1);
                if ($panbotId > 0) {
                    $query->where('panbot_id', $panbotId);
                }
                $configs = $query->select()->toArray();
            } catch (\Throwable $e2) {
                return ['code' => 0, 'message' => '表不存在，请先执行 upgrade.sql', 'data' => ['results' => []]];
            }
        }

        foreach ($configs as $cfg) {
            $id = intval($cfg['panbot_id']);
            $interval = intval($cfg['interval_minutes']);
            $lastAuto = intval($cfg['last_auto_at']);

            // 间隔为 0 视为禁用自动同步
            if ($interval <= 0) {
                $results[] = self::makeAutoResult($cfg, 0, '已禁用自动同步（间隔=0）');
                continue;
            }

            // 未到期：上次检查 + 间隔（分钟）> 当前时间，跳过
            if ($lastAuto > 0 && $lastAuto + $interval * 60 > $now) {
                $results[] = self::makeAutoResult($cfg, 0, '未到期');
                continue;
            }

            // 标记本次检查时间（先于 sync 内部成功失败）
            Db::name('panbot_config')->where('panbot_id', $id)->update([
                'last_auto_at' => $now,
                'update_time' => $now,
            ]);

            // 从未同步过：跑一次全量；否则跑增量
            $lastSync = intval($cfg['last_sync_at']);
            if ($lastSync <= 0) {
                $syncResult = self::syncAll($id);
                $mode = 'all';
            } else {
                $syncResult = self::syncIncrement($id);
                $mode = 'incr';
            }
            $results[] = self::makeAutoResult($cfg, $syncResult['code'], $syncResult['message'], $mode, $syncResult['data'] ?? []);
        }

        return ['code' => 1, 'message' => '自动同步检查完毕', 'data' => ['results' => $results]];
    }

    /**
     * 归一化自动同步结果
     */
    private static function makeAutoResult($cfg, $code, $message, $mode = '-', $data = [])
    {
        return [
            'panbot_id' => intval($cfg['panbot_id']),
            'name' => $cfg['name'] ?: 'PanBot',
            'mode' => $mode,
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * 最近实际同步到 qf_source 的资源（只显示本轮同步新增/变更的）
     * 窗口 = [prev_sync_at, now]（prev_sync_at=上一轮同步完成时间），且仅取 account_name='PanBot' 写入的记录，按 update_time 倒序
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>['list'=>[], 'since'=>int, 'total'=>int]]
     */
    public static function getRecentSyncedResources($panbotId, $limit = 50)
    {
        self::ensureTablesChecked();
        try {
            $config = Db::name('panbot_config')->find(intval($panbotId));
        } catch (\Throwable $e) {
            self::ensureTables();
            $config = Db::name('panbot_config')->find(intval($panbotId));
        }
        if (empty($config)) {
            return ['code' => 0, 'message' => 'PanBot 配置不存在', 'data' => ['list' => [], 'since' => 0, 'total' => 0]];
        }

        // 窗口起点 = 上一轮同步开始前的时间；从未同步过则回溯 30 天做预览
        $since = intval($config['prev_sync_at'] ?? 0);
        if ($since <= 0) {
            $since = time() - 86400 * 30;
        }

        try {
            $total = (int) Db::name('source')
                ->where('status', 1)
                ->where('is_delete', 0)
                ->where('account_name', 'PanBot')
                ->where('update_time', '>=', $since)
                ->where('url', '<>', '')
                ->count();
            $list = Db::name('source')
                ->where('status', 1)
                ->where('is_delete', 0)
                ->where('account_name', 'PanBot')
                ->where('update_time', '>=', $since)
                ->where('url', '<>', '')
                ->order('update_time', 'desc')
                ->limit($limit)
                ->field('source_id, source_category_id, title, url, code, is_type, is_time, vod_pic, update_time')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return ['code' => 0, 'message' => '查询资源失败：' . $e->getMessage(), 'data' => ['list' => [], 'since' => $since, 'total' => 0]];
        }

        return ['code' => 1, 'message' => 'ok', 'data' => [
            'list' => $list,
            'since' => $since,
            'total' => $total,
            'limit' => $limit,
        ]];
    }

    /**
     * 勾选同步的第三方分类集合（qf_panbot_category.is_sync=1），remote_category_id => 1
     * @return array|null 表异常时返回 null（调用方不过滤，保持旧行为）
     */
    private static function getEnabledCategoryMap($panbotId)
    {
        try {
            $rows = Db::name('panbot_category')
                ->where('panbot_id', $panbotId)
                ->where('is_sync', 1)
                ->column('remote_category_id');
        } catch (\Throwable $e) {
            return null;
        }
        $map = [];
        foreach ($rows as $rid) {
            $rid = strval($rid);
            if ($rid !== '') {
                $map[$rid] = 1;
            }
        }
        return $map;
    }

    /**
     * 获取同步大类勾选列表（弹窗「同步分类」区展示用，一级大类=远程资源库粒度）
     * 表为空（尚未同步过）时先拉一次远程资源库+分类建立映射，再返回（不拉资源）
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>['list'=>[]]] list 每项：
     *               ['library_id','library_name','is_sync','category_total']
     */
    public static function getSyncCategories($panbotId)
    {
        self::ensureTablesChecked();
        try {
            $config = Db::name('panbot_config')->find(intval($panbotId));
        } catch (\Throwable $e) {
            self::ensureTables();
            $config = Db::name('panbot_config')->find(intval($panbotId));
        }
        if (empty($config)) {
            return ['code' => 0, 'message' => 'PanBot 配置不存在，请先保存配置', 'data' => ['list' => []]];
        }

        // 按大类（library_id）聚合：is_sync 取该大类下任一行勾选状态，附分类数量
        $aggregate = function () use ($panbotId) {
            $rows = Db::name('panbot_category')
                ->where('panbot_id', intval($panbotId))
                ->order('id', 'asc')
                ->field('library_id, library_name, is_sync')
                ->select()->toArray();
            $list = [];
            $idx = [];
            foreach ($rows as $r) {
                $libId = strval($r['library_id'] ?? '');
                if (!isset($idx[$libId])) {
                    $idx[$libId] = count($list);
                    $list[] = [
                        'library_id' => $libId,
                        'library_name' => strval($r['library_name'] ?? ''),
                        'is_sync' => 0,
                        'category_total' => 0,
                    ];
                }
                $list[$idx[$libId]]['category_total']++;
                if (intval($r['is_sync']) === 1) {
                    $list[$idx[$libId]]['is_sync'] = 1;
                }
            }
            return $list;
        };

        try {
            $list = $aggregate();
        } catch (\Throwable $e) {
            $list = [];
        }

        // 尚未同步过（映射表为空）：先拉远程分类建映射，让勾选列表有数据可显示
        if (empty($list)) {
            try {
                $baseUrl = rtrim(trim(strval($config['base_url'])), '/');
                $apiKey = trim(strval($config['api_key']));
                $libraries = self::fetchAll($baseUrl, '/api/v1/libraries', $apiKey, [], self::MAX_LIST_PAGES, 100);
                if ($libraries === false) {
                    return ['code' => 0, 'message' => '拉取资源库列表失败：网络异常', 'data' => ['list' => []]];
                }
                $libMap = [];
                foreach ($libraries as $lib) {
                    $id = strval($lib['id'] ?? '');
                    if ($id !== '') {
                        $libMap[$id] = $lib;
                    }
                }
                $categories = self::fetchAll($baseUrl, '/api/v1/categories', $apiKey, [], self::MAX_LIST_PAGES, 100);
                if ($categories === false) {
                    return ['code' => 0, 'message' => '拉取分类列表失败：网络异常', 'data' => ['list' => []]];
                }
                self::ensureCategoryMapping(intval($panbotId), $categories, $libMap);
                $list = $aggregate();
            } catch (\Throwable $e) {
                return ['code' => 0, 'message' => '拉取分类失败：' . $e->getMessage(), 'data' => ['list' => []]];
            }
        }

        return ['code' => 1, 'message' => 'ok', 'data' => ['list' => $list]];
    }

    /**
     * 保存同步大类勾选（一级大类=远程资源库粒度）：传入勾选的 library_id 列表，
     * 该大类下所有分类行 is_sync 置 1，未勾选大类下所有行置 0
     * @param int $panbotId
     * @param array $libraryIds 勾选的大类 id（字符串）
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>['enabled'=>int]]
     */
    public static function saveSyncCategories($panbotId, array $libraryIds)
    {
        self::ensureTablesChecked();
        $panbotId = intval($panbotId);
        // 归一化：去空、去重、字符串化
        $enabledLibs = [];
        foreach ($libraryIds as $lid) {
            $lid = trim(strval($lid));
            if ($lid !== '') {
                $enabledLibs[$lid] = true;
            }
        }
        $now = time();
        try {
            Db::startTrans();
            Db::name('panbot_category')->where('panbot_id', $panbotId)->update([
                'is_sync' => 0,
                'update_time' => $now,
            ]);
            if (!empty($enabledLibs)) {
                Db::name('panbot_category')
                    ->where('panbot_id', $panbotId)
                    ->where('library_id', 'in', array_keys($enabledLibs))
                    ->update([
                        'is_sync' => 1,
                        'update_time' => $now,
                    ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return ['code' => 0, 'message' => '保存失败：' . $e->getMessage(), 'data' => ['enabled' => 0]];
        }
        $enabled = count($enabledLibs);
        return ['code' => 1, 'message' => '已保存，勾选 ' . $enabled . ' 个大类', 'data' => ['enabled' => $enabled]];
    }

    /**
     * 全量同步：拉资源库 → 拉分类 → 重建本地分类映射 → 按分类拉资源（首次全量）
     * @param int $panbotId
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>['libraries_total','categories_total','resources_total', ...]]
     */
    public static function syncAll($panbotId)
    {
        $panbotId = intval($panbotId);
        $lockKey = 'panbot_sync_lock_' . $panbotId;
        if (Cache::get($lockKey)) {
            return ['code' => 0, 'message' => '该 PanBot 同步任务正在执行中，请稍后再试', 'data' => []];
        }
        Cache::set($lockKey, 1, 1800);

        self::ensureTablesChecked();
        $startTime = time();
        try {
            $config = Db::name('panbot_config')->find($panbotId);
        } catch (\Throwable $e) {
            self::ensureTables();
            $config = Db::name('panbot_config')->find($panbotId);
        }
        if (empty($config)) {
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => 'PanBot 配置不存在', 'data' => []];
        }
        if (intval($config['status']) !== 1) {
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => '该 PanBot 配置已停用', 'data' => []];
        }

        // 本轮同步窗口起点：上次同步完成时间；首次同步则用本次开始时间
        $prevSyncAt = intval($config['last_sync_at'] ?? 0);
        if ($prevSyncAt <= 0) {
            $prevSyncAt = $startTime;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $stats = [
            'libraries_total' => 0,
            'categories_total' => 0,
            'resources_total' => 0,
            'resources_new' => 0,
            'resources_updated' => 0,
            'resources_skipped' => 0,
            'resources_failed' => 0,
        ];
        $failReason = '';

        try {
            $baseUrl = rtrim(trim(strval($config['base_url'])), '/');
            $apiKey = trim(strval($config['api_key']));

            // 1. 拉资源库（不传参，一次性拉完所有）
            $libraries = self::fetchAll($baseUrl, '/api/v1/libraries', $apiKey, [], self::MAX_LIST_PAGES, 100);
            if ($libraries === false) {
                throw new \Exception('拉取资源库列表失败：网络异常');
            }
            $stats['libraries_total'] = count($libraries);
            $libMap = [];
            foreach ($libraries as $lib) {
                $id = strval($lib['id'] ?? '');
                if ($id === '') continue;
                $libMap[$id] = $lib;
            }

            // 2. 拉分类（不传参，一次性拉完所有）
            $categories = self::fetchAll($baseUrl, '/api/v1/categories', $apiKey, [], self::MAX_LIST_PAGES, 100);
            if ($categories === false) {
                throw new \Exception('拉取分类列表失败：网络异常');
            }
            $stats['categories_total'] = count($categories);

            // 3. 重建本地分类映射：以资源库名匹配/创建（cat=分类映射，lib=资源库映射）
            $mapping = self::ensureCategoryMapping($panbotId, $categories, $libMap);
            $localCatMap = $mapping['cat'];
            $localLibMap = $mapping['lib'];

            // 仅同步勾选的分类（qf_panbot_category.is_sync=1）
            $enabledCatMap = self::getEnabledCategoryMap($panbotId);

            // 4. 按分类逐个拉资源（首次全量：传 category_id，不传 since_time）
            $existingUrls = self::getExistingUrls();
            foreach ($categories as $cat) {
                $remoteCatId = strval($cat['id'] ?? '');
                if ($remoteCatId === '') continue;
                if (is_array($enabledCatMap) && !isset($enabledCatMap[$remoteCatId])) {
                    continue; // 未勾选的分类跳过
                }
                $localCatId = intval($localCatMap[$remoteCatId] ?? 0);
                $syncRow = Db::name('panbot_category')
                    ->where('panbot_id', $panbotId)
                    ->where('remote_category_id', $remoteCatId)
                    ->find();
                $since = intval($syncRow['last_sync_at'] ?? 0); // 首次=0 全量

                $catStats = self::syncCategoryResources(
                    $panbotId, $baseUrl, $apiKey,
                    $cat, $libMap[strval($cat['library_id'] ?? '')] ?? [],
                    $localCatId, $since, $existingUrls
                );
                $stats['resources_total'] += $catStats['total'];
                $stats['resources_new'] += $catStats['new'];
                $stats['resources_updated'] += $catStats['updated'];
                $stats['resources_skipped'] += $catStats['skipped'];
                $stats['resources_failed'] += $catStats['failed'];
                if ($catStats['failed'] > 0 && $catStats['fail_reason'] !== '') {
                    $failReason = $catStats['fail_reason'];
                }

                // 单分类完成后立刻回写 last_sync_at，下次同步此分类走增量
                self::updateCategorySyncTime($panbotId, $remoteCatId, $catStats['last_resource_id'] ?? '');
            }

            // 5. 全局兜底：不带分类过滤拉一遍，覆盖已删除分类/无分类的资源（全量语义，重复项由内容比对跳过）
            self::syncGlobalResources($baseUrl, $apiKey, $localCatMap, $localLibMap, 0, $existingUrls, $stats, $failReason, $enabledCatMap);

            $endTime = time();
            $enabledCount = is_array($enabledCatMap) ? count($enabledCatMap) : $stats['categories_total'];
            $msg = sprintf(
                '同步完成：勾选分类 %d/%d，资源 %d（新增 %d / 更新 %d / 跳过 %d / 失败 %d）',
                $enabledCount, $stats['categories_total'], $stats['resources_total'],
                $stats['resources_new'], $stats['resources_updated'],
                $stats['resources_skipped'], $stats['resources_failed']
            );
            if ($failReason !== '') {
                $msg .= '；失败说明：' . $failReason;
            }

            self::writeSyncState($panbotId, $endTime, $msg, $prevSyncAt, $stats['resources_new'] + $stats['resources_updated']);

            Cache::delete($lockKey);
            return ['code' => 1, 'message' => $msg, 'data' => $stats];
        } catch (\Exception $e) {
            $endTime = time();
            $msg = '同步失败：' . $e->getMessage();
            try {
                self::writeSyncState($panbotId, $endTime, $msg, $prevSyncAt, $stats['resources_new'] + $stats['resources_updated']);
            } catch (\Throwable $e2) {
                // 状态回写失败不吞掉原始异常，仍走正常失败返回
            }
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => $msg, 'data' => $stats];
        }
    }

    /**
     * 增量同步：只拉取 last_sync_at > 0 的分类，其余跳过
     * @return array 同 syncAll
     */
    public static function syncIncrement($panbotId)
    {
        $panbotId = intval($panbotId);
        $lockKey = 'panbot_sync_lock_' . $panbotId;
        if (Cache::get($lockKey)) {
            return ['code' => 0, 'message' => '该 PanBot 同步任务正在执行中，请稍后再试', 'data' => []];
        }
        Cache::set($lockKey, 1, 1800);

        self::ensureTablesChecked();
        $startTime = time();
        try {
            $config = Db::name('panbot_config')->find($panbotId);
        } catch (\Throwable $e) {
            self::ensureTables();
            $config = Db::name('panbot_config')->find($panbotId);
        }
        if (empty($config)) {
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => 'PanBot 配置不存在', 'data' => []];
        }
        if (intval($config['status']) !== 1) {
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => '该 PanBot 配置已停用', 'data' => []];
        }

        // 本轮同步窗口起点：上次同步完成时间；首次同步则用本次开始时间
        $prevSyncAt = intval($config['last_sync_at'] ?? 0);
        if ($prevSyncAt <= 0) {
            $prevSyncAt = $startTime;
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $stats = [
            'libraries_total' => 0,
            'categories_total' => 0,
            'resources_total' => 0,
            'resources_new' => 0,
            'resources_updated' => 0,
            'resources_skipped' => 0,
            'resources_failed' => 0,
        ];
        $failReason = '';

        try {
            $baseUrl = rtrim(trim(strval($config['base_url'])), '/');
            $apiKey = trim(strval($config['api_key']));

            // 1. 重新拉资源库 + 分类（数据量小，用于刷新映射；按需拿最新）
            $libraries = self::fetchAll($baseUrl, '/api/v1/libraries', $apiKey, [], self::MAX_LIST_PAGES, 100);
            if ($libraries === false) {
                throw new \Exception('拉取资源库列表失败：网络异常');
            }
            $stats['libraries_total'] = count($libraries);
            $libMap = [];
            foreach ($libraries as $lib) {
                $id = strval($lib['id'] ?? '');
                if ($id === '') continue;
                $libMap[$id] = $lib;
            }
            $categories = self::fetchAll($baseUrl, '/api/v1/categories', $apiKey, [], self::MAX_LIST_PAGES, 100);
            if ($categories === false) {
                throw new \Exception('拉取分类列表失败：网络异常');
            }
            $stats['categories_total'] = count($categories);

            // 2. 重建本地分类映射（第三方分类若新增，本地对应创建/匹配）
            $mapping = self::ensureCategoryMapping($panbotId, $categories, $libMap);
            $localCatMap = $mapping['cat'];
            $localLibMap = $mapping['lib'];

            // 3. 增量：仅遍历已同步过（last_sync_at>0）且勾选同步（is_sync=1）的分类
            $syncedRows = Db::name('panbot_category')
                ->where('panbot_id', $panbotId)
                ->where('last_sync_at', '>', 0)
                ->where('is_sync', 1)
                ->column('remote_category_id');
            $remoteCategoryMap = [];
            foreach ($categories as $cat) {
                $remoteCategoryMap[strval($cat['id'] ?? '')] = $cat;
            }
            $existingUrls = self::getExistingUrls();

            foreach ($syncedRows as $remoteCatId) {
                if (!isset($remoteCategoryMap[$remoteCatId])) {
                    continue; // 第三方已删除的分类，本地保留不动
                }
                $cat = $remoteCategoryMap[$remoteCatId];
                $localCatId = intval($localCatMap[$remoteCatId] ?? 0);
                $since = intval(Db::name('panbot_category')
                    ->where('panbot_id', $panbotId)
                    ->where('remote_category_id', $remoteCatId)
                    ->value('last_sync_at'));
                $catStats = self::syncCategoryResources(
                    $panbotId, $baseUrl, $apiKey,
                    $cat, $libMap[strval($cat['library_id'] ?? '')] ?? [],
                    $localCatId, $since, $existingUrls
                );
                $stats['resources_total'] += $catStats['total'];
                $stats['resources_new'] += $catStats['new'];
                $stats['resources_updated'] += $catStats['updated'];
                $stats['resources_skipped'] += $catStats['skipped'];
                $stats['resources_failed'] += $catStats['failed'];
                if ($catStats['failed'] > 0 && $catStats['fail_reason'] !== '') {
                    $failReason = $catStats['fail_reason'];
                }

                self::updateCategorySyncTime($panbotId, $remoteCatId, $catStats['last_resource_id'] ?? '');
            }

            // 4. 全局兜底：不带分类过滤拉一遍，覆盖已删除分类/无分类的资源（水位取各分类最小值，保守防漏）
            $globalSince = intval(Db::name('panbot_category')
                ->where('panbot_id', $panbotId)
                ->where('last_sync_at', '>', 0)
                ->min('last_sync_at'));
            $enabledCatMap = self::getEnabledCategoryMap($panbotId);
            self::syncGlobalResources($baseUrl, $apiKey, $localCatMap, $localLibMap, $globalSince, $existingUrls, $stats, $failReason, $enabledCatMap);

            $endTime = time();
            $msg = sprintf(
                '增量同步完成：处理分类 %d，资源 %d（新增 %d / 更新 %d / 跳过 %d / 失败 %d）',
                count($syncedRows), $stats['resources_total'],
                $stats['resources_new'], $stats['resources_updated'],
                $stats['resources_skipped'], $stats['resources_failed']
            );
            if ($failReason !== '') {
                $msg .= '；失败说明：' . $failReason;
            }

            self::writeSyncState($panbotId, $endTime, $msg, $prevSyncAt, $stats['resources_new'] + $stats['resources_updated']);

            Cache::delete($lockKey);
            return ['code' => 1, 'message' => $msg, 'data' => $stats];
        } catch (\Exception $e) {
            $endTime = time();
            $msg = '增量同步失败：' . $e->getMessage();
            try {
                self::writeSyncState($panbotId, $endTime, $msg, $prevSyncAt, $stats['resources_new'] + $stats['resources_updated']);
            } catch (\Throwable $e2) {
                // 状态回写失败不吞掉原始异常，仍走正常失败返回
            }
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => $msg, 'data' => $stats];
        }
    }

    /**
     * 回写同步状态到 panbot_config
     * prev_sync_at 每轮同步（含 0 写入的空跑/失败）都推进为本轮窗口起点，
     * 保证弹窗「本次同步更新的资源」只反映最近一轮的产出，不残留更早轮次的旧批次
     */
    private static function writeSyncState($panbotId, $endTime, $msg, $prevSyncAt, $written)
    {
        $data = [
            'last_sync_at' => $endTime,
            'last_sync_msg' => mb_substr($msg, 0, 500),
            'update_time' => $endTime,
        ];
        // prev_sync_at 每次（含 0 写入的空跑）都要推进：它是弹窗「本次同步更新的资源」的窗口起点，
        // 不推进的话窗口一直停留在上一次有产出的同步，旧批次资源会持续展示，看起来像刚拉了一遍
        $data['prev_sync_at'] = $prevSyncAt;
        Db::name('panbot_config')->where('panbot_id', $panbotId)->update($data);
    }

    /**
     * 拉取单个列表型接口的全部数据（自动分页，直到 list.length < page_size 或达到 max_pages）
     * @return array|false 失败返回 false
     */
    public static function fetchAll($baseUrl, $path, $apiKey, $extraParams, $maxPages, $pageSize)
    {
        $list = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $params = array_merge($extraParams, ['page' => $page, 'page_size' => $pageSize]);
            $res = self::callApi($baseUrl, $path, $apiKey, $params, $page, 1);
            if ($res === false) {
                return false;
            }
            if (intval($res['code'] ?? 0) !== 200) {
                throw new \Exception(strval($res['message'] ?? '第三方接口返回非成功响应') . '（路径：' . $path . '）');
            }
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $rows = is_array($data['list'] ?? null) ? $data['list'] : [];
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                $list[] = $row;
            }
            if (count($rows) < $pageSize) {
                break;
            }
        }
        return $list;
    }

    /**
     * 按分类拉取资源列表并写入本地资源表
     * 接口为 GET /api/v1/shares（文档「资源数据」），增量按 updated_at 水位（time_start 前退 5 分钟防漏）
     * @param int $since 0=全量，>0=该分类上次同步时间戳（增量水位）
     * @return array 统计 [total,new,updated,skipped,failed,fail_reason,last_resource_id]
     */
    private static function syncCategoryResources($panbotId, $baseUrl, $apiKey, $cat, $lib, $localCatId, $since, &$existingUrls)
    {
        $result = [
            'total' => 0, 'new' => 0, 'updated' => 0,
            'skipped' => 0, 'failed' => 0,
            'fail_reason' => '', 'last_resource_id' => '',
        ];

        $remoteCatId = strval($cat['id'] ?? '');
        if ($remoteCatId === '' || $localCatId <= 0) {
            // 本地无对应分类（用户未启用分类管理或分类被禁用），跳过
            return $result;
        }

        $params = ['category_id' => $remoteCatId, 'page_size' => self::DEFAULT_PAGE_SIZE, 'status' => 'active'];
        if ($since > 0) {
            // 增量：按 updated_at 过滤 + 升序翻页（水位前退 5 分钟，防边界漏数据）
            $params['time_field'] = 'updated_at';
            $params['time_start'] = max(0, $since - 300);
            $params['sort'] = 'updated_at_asc';
        }

        $page = 1;
        $lastResourceId = '';
        while ($page <= self::MAX_RESOURCE_PAGES) {
            $params['page'] = $page;
            $res = self::callApi($baseUrl, '/api/v1/shares', $apiKey, $params, $page, 1);
            if ($res === false) {
                $result['failed']++;
                $result['fail_reason'] = '分类「' . strval($cat['name'] ?? '') . '」第 ' . $page . ' 页拉取失败：网络异常';
                break;
            }
            if (intval($res['code'] ?? 0) !== 200) {
                $result['failed']++;
                $result['fail_reason'] = '分类「' . strval($cat['name'] ?? '') . '」第 ' . $page . ' 页拉取失败：' . strval($res['message'] ?? '');
                break;
            }
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $rows = is_array($data['list'] ?? null) ? $data['list'] : [];
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $stats = self::writeResourceRow($row, $localCatId, $existingUrls);
                $result['total']++;
                $result['new'] += $stats['new'];
                $result['updated'] += $stats['updated'];
                $result['skipped'] += $stats['skipped'];
                $result['failed'] += $stats['failed'];
                if ($stats['failed'] > 0 && $stats['fail_reason'] !== '') {
                    $result['fail_reason'] = $stats['fail_reason'];
                }
                $rid = strval($row['id'] ?? '');
                if ($rid !== '') {
                    $lastResourceId = $rid;
                }
            }
            if (count($rows) < self::DEFAULT_PAGE_SIZE) {
                break;
            }
            $page++;
        }

        $result['last_resource_id'] = $lastResourceId;
        return $result;
    }

    /**
     * 全局兜底拉取：不带 category_id 拉取全部分享，覆盖按分类遍历够不到的资源
     * （第三方已删除分类下的分享、无分类分享）；行内 category_id 映射本地分类，
     * 映射不到时回退行内 library_id 对应的资源库分类，仍无则按 0（未分类）入库
     * @param int $since 0=全量，>0=全局增量水位（各分类水位的最小值-300）
     * @param array|null $enabledCatMap 勾选同步的 remote_category_id 集合；null=不过滤（旧行为），
     *                                  非 null 时仅保留勾选分类的行（无分类/未勾选的行跳过）
     */
    private static function syncGlobalResources($baseUrl, $apiKey, $localCatMap, $localLibMap, $since, &$existingUrls, array &$stats, string &$failReason, $enabledCatMap = null)
    {
        $params = ['page_size' => self::DEFAULT_PAGE_SIZE, 'status' => 'active'];
        if ($since > 0) {
            $params['time_field'] = 'updated_at';
            $params['time_start'] = max(0, $since - 300);
            $params['sort'] = 'updated_at_asc';
        }

        $page = 1;
        while ($page <= self::MAX_RESOURCE_PAGES) {
            $params['page'] = $page;
            $res = self::callApi($baseUrl, '/api/v1/shares', $apiKey, $params, $page, 1);
            if ($res === false) {
                $stats['resources_failed']++;
                if ($failReason === '') {
                    $failReason = '全局兜底拉取第 ' . $page . ' 页失败：网络异常';
                }
                break;
            }
            if (intval($res['code'] ?? 0) !== 200) {
                $stats['resources_failed']++;
                if ($failReason === '') {
                    $failReason = '全局兜底拉取失败：' . strval($res['message'] ?? '');
                }
                break;
            }
            $data = is_array($res['data'] ?? null) ? $res['data'] : [];
            $rows = is_array($data['list'] ?? null) ? $data['list'] : [];
            if (empty($rows)) {
                break;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $remoteCatId = strval($row['category_id'] ?? '');
                // 仅同步勾选分类：无分类/未勾选的行跳过
                if (is_array($enabledCatMap) && !isset($enabledCatMap[$remoteCatId])) {
                    continue;
                }
                if ($remoteCatId !== '' && isset($localCatMap[$remoteCatId])) {
                    $localCatId = intval($localCatMap[$remoteCatId]);
                } else {
                    // 分类缺失或映射不到 → 回退资源库（library_id）对应的本地分类
                    $remoteLibId = strval($row['library_id'] ?? '');
                    $localCatId = ($remoteLibId !== '' && isset($localLibMap[$remoteLibId]))
                        ? intval($localLibMap[$remoteLibId]) : 0;
                }
                $one = self::writeResourceRow($row, $localCatId, $existingUrls);
                $stats['resources_total']++;
                $stats['resources_new'] += $one['new'];
                $stats['resources_updated'] += $one['updated'];
                $stats['resources_skipped'] += $one['skipped'];
                $stats['resources_failed'] += $one['failed'];
                if ($one['failed'] > 0 && $one['fail_reason'] !== '' && $failReason === '') {
                    $failReason = $one['fail_reason'];
                }
            }
            if (count($rows) < self::DEFAULT_PAGE_SIZE) {
                break;
            }
            $page++;
        }
    }

    /**
     * 写入单条资源到 qf_source 表
     * 字段映射：title, url, description, vod_pic, code, is_type（按 url 推断）
     * 第三方字段参考：
     *   id, name/title, share_url/url, description, cover_url/vod_pic, code, pan_type/drive_type, updated_at
     */
    private static function writeResourceRow($row, $localCatId, &$existingUrls)
    {
        $stats = ['new' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'fail_reason' => ''];

        $title = trim(strval($row['title'] ?? ($row['name'] ?? '')));
        $url = trim(strval($row['url'] ?? ($row['share_url'] ?? '')));
        if ($title === '' || $url === '' || !preg_match('/^https?:\/\//i', $url)) {
            $stats['skipped']++;
            return $stats;
        }

        try {
            $description = trim(strval($row['description'] ?? ''));
            $vodPic = trim(strval($row['vod_pic'] ?? ($row['cover_url'] ?? '')));
            // PanBot 未配置对象存储时 cover_url 可能是不带协议头的对象 key（相对路径），无法直接展示，按无图处理
            if (!preg_match('/^https?:\/\//i', $vodPic)) {
                $vodPic = '';
            }
            $code = trim(strval($row['code'] ?? ($row['passcode'] ?? '')));
            $isType = determineIsType($url);

            $data = [
                'source_category_id' => $localCatId,
                'title' => mb_substr($title, 0, 255),
                'url' => mb_substr($url, 0, 255),
                'description' => mb_substr($description, 0, 255),
                // 1000：PanBot 封面为对象存储签名直链（约 300+ 字符），255 会截掉签名参数导致 403
                'vod_pic' => mb_substr($vodPic, 0, 1000),
                'code' => mb_substr($code, 0, 50),
                'is_type' => $isType,
                'is_time' => 0, // 永久资源
                'status' => 1,
                'is_delete' => 0,
                'is_user' => 0,
                'page_views' => 0,
                'sort' => 0,
                'is_top' => 0,
                'account_name' => 'PanBot',
                'content' => '',
                'fid' => '',
                'update_time' => time(),
            ];

            if (isset($existingUrls[$url])) {
                $existing = $existingUrls[$url];
                // 内容未变化：跳过写入，不刷 update_time（否则「本次更新」列表会被历史数据刷屏）
                if (is_array($existing)
                    && intval($existing['source_category_id']) === intval($localCatId)
                    && strval($existing['title']) === $data['title']
                    && strval($existing['description']) === $data['description']
                    && strval($existing['vod_pic']) === $data['vod_pic']
                    && strval($existing['code']) === $data['code']
                    && intval($existing['is_type']) === intval($isType)) {
                    $stats['skipped']++;
                    return $stats;
                }
                Db::name('source')->where('url', $url)->update($data);
                $existingUrls[$url] = $data;
                $stats['updated']++;
            } else {
                $data['create_time'] = time();
                Db::name('source')->insertGetId($data);
                $existingUrls[$url] = $data;
                $stats['new']++;
            }
        } catch (\Exception $e) {
            $stats['failed']++;
            $stats['fail_reason'] = '入库失败：' . $e->getMessage();
        }
        return $stats;
    }

    /**
     * 重建本地分类映射：以资源库名（library_name）作为本地分类名在 qf_source_category 中查找/创建
     * 例如「影视资源 - 剧集」「影视资源 - 电影」均归入本地分类「影视资源」
     * @param array $categories 第三方分类列表
     * @param array $libMap 第三方资源库 id => 行
     * @return array ['cat' => remote_category_id => local_category_id, 'lib' => remote_library_id => local_category_id]
     */
    private static function ensureCategoryMapping($panbotId, $categories, $libMap)
    {
        // 1. 预加载本地分类（按 name => id 缓存，便于快速查找）
        $localRows = Db::name('source_category')
            ->where('status', 0)
            ->column('source_category_id', 'name');
        $localMap = [];
        foreach ($localRows as $name => $id) {
            $localMap[trim(strval($name))] = intval($id);
        }

        $remoteToLocal = [];
        $libToLocal = [];
        $now = time();
        foreach ($categories as $cat) {
            $remoteId = strval($cat['id'] ?? '');
            if ($remoteId === '') continue;
            $remoteName = trim(strval($cat['name'] ?? ''));
            $libId = strval($cat['library_id'] ?? '');
            $libName = trim(strval($libMap[$libId]['name'] ?? ''));
            // 以资源库名作为本地分类名（不再用「资源库名 - 分类名」组合名）

            $localId = 0;
            if ($libName !== '') {
                if (isset($localMap[$libName])) {
                    $localId = $localMap[$libName];
                } else {
                    // 本地不存在 → 以资源库名自动创建
                    $localId = Db::name('source_category')->insertGetId([
                        'name' => mb_substr($libName, 0, 100),
                        'image' => '',
                        'sort' => 0,
                        'status' => 0,
                        'is_sys' => 0,
                        'is_update' => 1,
                        'is_type' => 0,
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    $localMap[$libName] = $localId;
                }
                if ($libId !== '') {
                    $libToLocal[$libId] = $localId;
                }
            }
            $remoteToLocal[$remoteId] = $localId;

            // upsert qf_panbot_category
            $exists = Db::name('panbot_category')
                ->where('panbot_id', $panbotId)
                ->where('remote_category_id', $remoteId)
                ->find();
            if ($exists) {
                Db::name('panbot_category')->where('id', $exists['id'])->update([
                    'library_id' => $libId,
                    'library_name' => mb_substr($libName, 0, 100),
                    'remote_category_name' => mb_substr($remoteName, 0, 100),
                    'local_category_id' => $localId,
                    'update_time' => $now,
                ]);
            } else {
                // 新分类默认跟随所属大类（资源库）的勾选状态；该大类无记录时默认同步
                $libSync = 1;
                if ($libId !== '') {
                    $libSyncVal = Db::name('panbot_category')
                        ->where('panbot_id', $panbotId)
                        ->where('library_id', $libId)
                        ->order('is_sync', 'desc')
                        ->value('is_sync');
                    if ($libSyncVal !== null) {
                        $libSync = intval($libSyncVal);
                    }
                }
                Db::name('panbot_category')->insert([
                    'panbot_id' => $panbotId,
                    'library_id' => $libId,
                    'library_name' => mb_substr($libName, 0, 100),
                    'remote_category_id' => $remoteId,
                    'remote_category_name' => mb_substr($remoteName, 0, 100),
                    'local_category_id' => $localId,
                    'is_sync' => $libSync,
                    'last_sync_at' => 0,
                    'last_resource_id' => '',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }
        return ['cat' => $remoteToLocal, 'lib' => $libToLocal];
    }

    /**
     * 同步完成时回写分类同步时间
     */
    private static function updateCategorySyncTime($panbotId, $remoteCatId, $lastResourceId)
    {
        Db::name('panbot_category')
            ->where('panbot_id', $panbotId)
            ->where('remote_category_id', $remoteCatId)
            ->update([
                'last_sync_at' => time(),
                'last_resource_id' => mb_substr($lastResourceId, 0, 64),
                'update_time' => time(),
            ]);
    }

    // ========== 私有 HTTP 与日志辅助 ==========

    /**
     * 调用第三方开放接口（统一封装 curlHelper，自动加 X-API-Key）
     * @param string $baseUrl
     * @param string $path 如 /api/v1/libraries
     * @param string $apiKey
     * @param array $params query 参数
     * @param int $page 当前页（仅日志用）
     * @param int $pageSize 每页条数（仅日志用）
     * @return array|false 失败 false；成功返回原始 JSON 解码
     */
    private static function callApi($baseUrl, $path, $apiKey, $params, $page = 1, $pageSize = 20)
    {
        $fullUrl = $baseUrl . $path . '?' . http_build_query($params);
        $headers = [
            'X-API-Key: ' . $apiKey,
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; XinyuePanBot/1.0)',
        ];
        $res = curlHelper($fullUrl, 'GET', null, $headers, '', '', self::TIMEOUT);
        if (!empty($res['error'])) {
            return false;
        }
        $body = strval($res['body'] ?? '');
        if ($body === '') {
            return false;
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new \Exception('第三方响应超过 ' . (self::MAX_BODY_BYTES / 1024 / 1024) . 'MB，已拒绝解析');
        }
        $httpCode = intval($res['detail']['http_code'] ?? 0);
        if ($httpCode >= 400) {
            $payload = json_decode($body, true);
            throw new \Exception('第三方返回 HTTP ' . $httpCode . '：' . strval(is_array($payload) ? ($payload['message'] ?? '') : '') . '（路径：' . $path . '）');
        }
        return json_decode($body, true);
    }

    /**
     * SSRF 最低防护：仅 http/https；解析 IP 拒绝内网/回环/链路本地地址
     */
    private static function guardUrl($url)
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '地址格式无效';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '仅允许 http/https 协议';
        }
        $host = $parts['host'];
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
            return '域名解析失败';
        }
        $isV6 = strpos($ip, ':') !== false;
        $long = $isV6 ? false : ip2long($ip);
        $blocked = false;
        if ($isV6) {
            $compact = strtolower(inet_ntop(inet_pton($ip)) ?: $ip);
            $blocked = in_array($compact, ['::1', '::'], true);
        } elseif ($long !== false) {
            $blocked =
                ($long >= ip2long('10.0.0.0') && $long <= ip2long('10.255.255.255')) ||
                ($long >= ip2long('172.16.0.0') && $long <= ip2long('172.31.255.255')) ||
                ($long >= ip2long('192.168.0.0') && $long <= ip2long('192.168.255.255')) ||
                ($long >= ip2long('127.0.0.0') && $long <= ip2long('127.255.255.255')) ||
                ($long >= ip2long('169.254.0.0') && $long <= ip2long('169.254.255.255')) ||
                $ip === '0.0.0.0';
        }
        if ($blocked) {
            return '禁止访问内网/保留地址';
        }
        return '';
    }

    /**
     * 现有资源映射：url => 行（仅取 writeResourceRow 写入的比对字段）
     * 供 writeResourceRow 做内容比对：内容未变化的行跳过写入，避免刷 update_time
     */
    private static function getExistingUrls()
    {
        $rows = Db::name('source')->where('is_delete', 0)
            ->field('url, source_category_id, title, description, vod_pic, code, is_type')
            ->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[strval($r['url'])] = $r;
        }
        return $map;
    }
}