<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * 首页轮播 Banner（simple 主题 sections.banner）前台数据服务
 *
 * 前台轮播数据统一从这里取：优先 qf_banner 启用行（banner_status=0），
 * 异常或无启用记录时回退扫描 banner 目录；结果缓存 10 分钟，
 * 后台 Banner 增删改后调用 clearCache() 立即生效。
 */
class BannerService
{
    /** 前台轮播数据缓存键（存 JSON 字符串，空列表 '[]' 也可命中） */
    public const CACHE_KEY = 'frontend_banner_list';
    /** 缓存时长（秒），与首页其它列表缓存一致 */
    public const CACHE_TTL = 600;

    /**
     * 前台轮播数据（JSON 字符串，视图注入 window.__BANNER_LIST）
     */
    public static function frontendJson(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $json = json_encode(self::buildItems());
        Cache::set(self::CACHE_KEY, $json, self::CACHE_TTL);
        return $json;
    }

    /**
     * 清除前台轮播缓存（后台 Banner 数据变更后调用）
     */
    public static function clearCache(): void
    {
        Cache::delete(self::CACHE_KEY);
    }

    /**
     * 构建轮播数据：qf_banner 启用行；查询异常或为空时回退扫描 banner 目录
     */
    private static function buildItems(): array
    {
        $items = [];
        try {
            $rows = Db::name('banner')
                ->where('banner_status', 0)
                ->field('banner_title, banner_image, banner_link')
                ->order('banner_sort asc, banner_id asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            $rows = [];
        }
        foreach ($rows as $row) {
            $img = trim((string) ($row['banner_image'] ?? ''));
            if ($img === '') continue;
            $items[] = [
                'url'  => $img,
                'name' => (string) ($row['banner_title'] ?? ''),
                'link' => (string) ($row['banner_link'] ?? ''),
            ];
        }
        if (empty($items)) {
            $items = self::scanDirectory();
        }
        return $items;
    }

    /**
     * 回退：扫描 simple 主题静态 banner 目录（目录约定与 simple 主题视图结构一致）
     */
    private static function scanDirectory(): array
    {
        $dir = public_path() . 'views/index/simple/common/static/banner/';
        $allFiles = is_dir($dir) ? glob($dir . '*') : [];
        $names = [];
        $nameFile = $dir . 'name.txt';
        if (is_file($nameFile)) {
            foreach (file($nameFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $names[] = trim($line);
            }
        }
        $items = [];
        $imgIdx = 0;
        foreach ($allFiles as $f) {
            if (!preg_match('/\.(jpe?g|png|webp|avif|gif)$/i', $f)) continue;
            $items[] = [
                'url'  => '/views/index/simple/common/static/banner/' . rawurlencode(basename($f)),
                'name' => $names[$imgIdx] ?? '',
                'link' => '',
            ];
            $imgIdx++;
        }
        return $items;
    }
}
