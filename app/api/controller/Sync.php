<?php

namespace app\api\controller;

use think\App;
use think\facade\Db;
use app\api\QfShop;
use app\service\SyncService;

/**
 * 资源同步 API（源站端 Provider）
 *
 * 暴露资源列表供其他 xinyue-search 部署实例拉取
 * 鉴权方式：通过 sync_key 参数校验（对应源站后台「系统设置 → 资源同步密钥」配置项）
 *
 * 菜单位置：系统设置 → 资源同步
 */
class Sync extends QfShop
{
    /**
     * 鉴权校验
     * 校验源站是否开启同步功能，以及 api_key 是否匹配
     *
     * @return void 失败时直接 jerr 终止
     */
    private function checkAuth()
    {
        $syncKey = Config('qfshop.sync_key');
        if (empty($syncKey)) {
            jerr('源站未开启同步功能，请先在后台设置 sync_key');
        }
        if ($syncKey !== input('api_key')) {
            jerr('sync_key 错误，无权访问');
        }
    }

    /**
     * 连通性测试
     * 目的站保存配置前调用，验证域名+密钥是否正确
     *
     * @return void
     */
    public function ping()
    {
        $this->checkAuth();
        $appName = Config('qfshop.app_name') ?: 'xinyue-search';
        jok('success', [
            'site_name' => $appName,
            'version'   => '1.0',
        ]);
    }

    /**
     * 资源总数
     * 返回源站可同步的资源总数，用于目的站预检
     *
     * @return void
     */
    public function count()
    {
        $this->checkAuth();

        $query = $this->buildSyncQuery();
        $total = $query->count();
        jok('success', ['total' => $total]);
    }

    /**
     * 资源列表（核心拉取接口）
     * 返回分页资源列表，供目的站同步入库
     * 注意：方法名 lists 避免 PHP 保留字 list 冲突
     *
     * 请求参数：
     *   api_key      string  必填  鉴权密钥
     *   page         int     选填  页码，默认1
     *   page_size    int     选填  每页数量，默认50，上限200
     *   since_time   int     选填  增量同步：只返回 update_time > since_time 的资源
     *   is_type      string  选填  网盘类型过滤，逗号分隔，如 "0,1"
     *   category_id  int     选填  按源站分类过滤
     *
     * @return void
     */
    public function lists()
    {
        $this->checkAuth();

        $page      = max(1, intval(input('page', 1)));
        $pageSize  = min(200, max(1, intval(input('page_size', 50))));
        $sinceTime = intval(input('since_time', 0));

        $query = $this->buildSyncQuery();

        // 增量同步：只返回指定时间之后更新的资源
        if ($sinceTime > 0) {
            $query->where('update_time', '>', $sinceTime);
        }

        // 网盘类型过滤
        $isType = input('is_type', '');
        if (!empty($isType)) {
            $typeIds = array_filter(explode(',', $isType), function ($v) {
                return is_numeric($v);
            });
            if (!empty($typeIds)) {
                $query->where('is_type', 'in', $typeIds);
            }
        }

        // 分类过滤
        $categoryId = intval(input('category_id', 0));
        if ($categoryId > 0) {
            $query->where('source_category_id', $categoryId);
        }

        $total = $query->count();
        $items = [];
        if ($total > 0) {
            // 关联分类表获取分类名称
            $items = $query
                ->field('title, url, description, vod_content, vod_pic, is_type, code, source_category_id, update_time')
                ->page($page, $pageSize)
                ->order('source_id', 'asc')
                ->select()
                ->toArray();

            // 批量获取分类名称
            $categoryIds = array_unique(array_filter(array_column($items, 'source_category_id')));
            $categoryNames = [];
            if (!empty($categoryIds)) {
                $cats = Db::name('source_category')
                    ->where('source_category_id', 'in', $categoryIds)
                    ->column('name', 'source_category_id');
                $categoryNames = $cats;
            }
            foreach ($items as &$item) {
                $catId = $item['source_category_id'] ?? 0;
                $item['category_name'] = $categoryNames[$catId] ?? '';
            }
            unset($item);
        }

        jok('success', [
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
            'items'     => $items,
        ]);
    }

    /**
     * 定时自动同步触发接口
     *
     * 供宝塔面板等定时任务通过"访问URL"方式调用，无需 CLI 环境。
     * 鉴权方式：api_key 参数需匹配后台配置的 sync_key。
     * 可选参数：server_id=N 只同步指定服务器（跳过时间检查）
     *
     * 调用示例：
     *   GET https://www.site1.com/api/sync/autoSync?api_key=你的sync_key
     *   GET https://www.site1.com/api/sync/autoSync?api_key=你的sync_key&server_id=1
     */
    public function autoSync()
    {
        $this->checkAuth();

        $serverId = intval(input('server_id', 0));
        $servers = Db::name('sync_server')->where('status', 1);

        if ($serverId > 0) {
            $servers = $servers->where('sync_server_id', $serverId)->select();
        } else {
            $servers = $servers->where('auto_sync', 1)->select();
        }

        if (empty($servers) || count($servers) == 0) {
            jok('没有需要定时同步的服务器');
        }

        $currentHour = intval(date('G'));
        $results = [];

        foreach ($servers as $server) {
            $id = $server['sync_server_id'];
            $name = $server['name'];

            // 指定服务器模式跳过时间检查
            if ($serverId <= 0) {
                if (intval($server['sync_hour']) !== $currentHour) {
                    $results[] = ['name' => $name, 'status' => 'skip', 'reason' => "设定{$server['sync_hour']}点，当前{$currentHour}点"];
                    continue;
                }
                // 防重复：今天已同步过则跳过
                $todayStart = strtotime(date('Y-m-d'));
                if (intval($server['last_sync_time']) >= $todayStart) {
                    $results[] = ['name' => $name, 'status' => 'skip', 'reason' => '今天已同步过'];
                    continue;
                }
            }

            $result = SyncService::run($id);
            $results[] = [
                'name' => $name,
                'status' => $result['code'] == 1 ? 'success' : 'fail',
                'message' => $result['message'],
                'data' => $result['data'],
            ];
        }

        jok('同步完毕', ['hour' => $currentHour, 'results' => $results]);
    }

    /**
     * 构建同步查询的基础条件
     * 只返回永久资源(0)和外部资源(2)，不返回临时资源(1)
     *
     * @return \think\db\Query
     */
    private function buildSyncQuery()
    {
        return Db::name('source')
            ->where('status', 1)
            ->where('is_delete', 0)
            ->where('is_time', 'in', [0, 2]);
    }
}
