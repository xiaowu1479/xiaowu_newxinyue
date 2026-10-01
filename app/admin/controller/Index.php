<?php

namespace app\admin\controller;

use app\admin\QfShop;
use think\facade\Config;
use think\facade\Cache;
use think\facade\Db;
use util\Time;

class Index extends QfShop
{
    /**
     * 搜索量统计(今日、总量、最近7天每日)
     */
    public function searchStats()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        // 今日搜索量
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayEnd = strtotime(date('Y-m-d 23:59:59'));
        $todayCount = Db::name('feedback')->where('create_time', '>=', $todayStart)->where('create_time', '<=', $todayEnd)->count();
        // 总搜索量
        $totalCount = Db::name('feedback')->count();

        // 最近N天每日搜索量
        $days = intval(input('days', 7));
        if (!in_array($days, [7, 30])) {
            $days = 7;
        }
        $startTime = strtotime(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00');
        $data = Db::name('feedback')
            ->field("FROM_UNIXTIME(create_time, '%Y-%m-%d') as date, COUNT(*) as count")
            ->where('create_time', '>=', $startTime)
            ->group('date')
            ->order('date', 'asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($data as $item) {
            $map[$item['date']] = (int) $item['count'];
        }

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $daily[] = [
                'date' => substr($date, 5), // MM-DD
                'count' => $map[$date] ?? 0,
            ];
        }

        return jok('获取成功', [
            'today' => $todayCount,
            'total' => $totalCount,
            'daily' => $daily,
        ]);
    }

    /**
     * 搜索关键字排名(热门关键词Top10)
     */
    public function keywordRank()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        // 时间范围：0=总榜，1=最近1天，7=最近7天
        $days = intval(input('days', 0));
        if (!in_array($days, [0, 1, 7], true)) {
            $days = 0;
        }
        $query = Db::name('feedback')
            ->fieldRaw("TRIM(SUBSTRING(content, LOCATE(']', content) + 1)) as keyword, COUNT(*) as cnt")
            ->where('content', '<>', '');
        if ($days > 0) {
            $startTime = strtotime(date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')));
            $query = $query->where('create_time', '>=', $startTime);
        }
        $list = $query->group('keyword')
            ->order('cnt', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        foreach ($list as &$item) {
            $item['cnt'] = (int) $item['cnt'];
        }
        return jok('获取成功', $list);
    }

    /**
     * 资源统计(总数、今日新增、网盘类型分布)
     */
    public function sourceStats()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $total = Db::name('source')->where('status', 1)->where('is_delete', 0)->count();
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $todayNew = Db::name('source')->where('status', 1)->where('is_delete', 0)->where('create_time', '>=', $todayStart)->count();

        // 资源分类分布
        $catData = Db::name('source')
            ->fieldRaw('source_category_id, COUNT(*) as cnt')
            ->where('status', 1)->where('is_delete', 0)
            ->group('source_category_id')
            ->select()->toArray();
        $cats = Db::name('source_category')->where('status', 0)->select()->toArray();
        $catMap = [];
        foreach ($cats as $cat) {
            $catMap[$cat['source_category_id']] = $cat['name'];
        }
        $distribution = [];
        foreach ($catData as $item) {
            $catId = $item['source_category_id'];
            $distribution[] = [
                'name' => $catMap[$catId] ?? '未分类',
                'value' => (int) $item['cnt'],
            ];
        }
        usort($distribution, function ($a, $b) {
            return $b['value'] - $a['value'];
        });
        return jok('获取成功', [
            'total' => $total,
            'today_new' => $todayNew,
            'distribution' => $distribution,
        ]);
    }

    /**
     * 系统信息
     */
    public function systemInfo()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $mysqlVer = '';
        try {
            $r = Db::query('SELECT VERSION() as v');
            $mysqlVer = $r[0]['v'] ?? '';
        } catch (\Throwable $e) {
        }
        return jok('获取成功', [
            'php_version'   => phpversion(),
            'mysql_version' => $mysqlVer,
            'os'            => php_uname('s') . ' ' . php_uname('r'),
            'server_ip'     => $_SERVER['SERVER_ADDR'] ?? ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'),
            'disk_free'     => formatBytes(@disk_free_space('.')),
            'disk_total'    => formatBytes(@disk_total_space('.')),
            'max_upload'    => ini_get('upload_max_filesize'),
            'memory_limit'  => ini_get('memory_limit'),
            'server_time'   => date('Y-m-d H:i:s'),
        ]);
    }
}
