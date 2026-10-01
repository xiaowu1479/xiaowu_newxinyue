<?php

namespace app\api\controller;

use app\api\QfShop;

class Douban extends QfShop
{
    private $cacheTime = 86400; // 24小时

    private function getCacheDir()
    {
        $dir = root_path('runtime/api/cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 豆瓣图片代理（绕过防盗链）
     * GET /api/douban/proxy?url=xxx
     */
    public function proxy()
    {
        $url = input('url', '');
        if (empty($url) || strpos($url, 'doubanio.com') === false) {
            return jerr('无效的图片URL');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Referer: https://movie.douban.com/',
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $data = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $data) {
            header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
            header('Cache-Control: public, max-age=86400');
            echo $data;
            exit;
        }
        return jerr('图片获取失败');
    }

    /**
     * 豆瓣Top250榜单
     * GET /api/douban/top250?page=1&limit=25
     */
    public function top250()
    {
        $page = max(1, intval(input('page', 1)));
        $limit = max(1, min(100, intval(input('limit', 25))));

        $cacheFile = $this->getCacheDir() . 'douban_top250.cache';
        $items = [];

        // 检查缓存
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheTime)) {
            $items = json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        // 缓存过期或为空，重新爬取
        if (empty($items)) {
            $items = $this->scrapeTop250();
            if (!empty($items)) {
                file_put_contents($cacheFile, json_encode($items, JSON_UNESCAPED_UNICODE));
            }
        }

        $total = count($items);
        $start = ($page - 1) * $limit;
        $pageItems = array_slice($items, $start, $limit);

        return jok('获取成功', [
            'items' => $pageItems,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'hasMore' => ($start + $limit) < $total,
        ]);
    }

    /**
     * 爬取豆瓣Top250全部250条
     */
    private function scrapeTop250()
    {
        $allItems = [];
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15';

        for ($page = 0; $page < 10; $page++) {
            $start = $page * 25;
            $url = $start === 0
                ? 'https://movie.douban.com/top250'
                : "https://movie.douban.com/top250?start={$start}";

            $html = $this->fetchUrl($url, $ua);
            if ($html === false) {
                // 首页失败则放弃
                if ($page === 0) break;
                continue;
            }

            $items = $this->parseTop250Html($html);
            $allItems = array_merge($allItems, $items);

            // 限速：每页间隔1.5s，最后一页不等
            if ($page < 9) {
                usleep(1500000);
            }
        }

        return $allItems;
    }

    /**
     * cURL获取URL内容
     */
    private function fetchUrl($url, $ua)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: ' . $ua,
            'Accept-Language: zh-CN,zh;q=0.9',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $html : false;
    }

    /**
     * 解析豆瓣Top250 HTML
     */
    private function parseTop250Html($html)
    {
        $items = [];

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $lis = $xpath->query('//ol[@class="grid_view"]/li');
        $rank = 1;

        foreach ($lis as $li) {
            // 链接
            $linkNode = $xpath->query('.//div[@class="pic"]/a', $li);
            $href = $linkNode->length > 0 ? $linkNode->item(0)->getAttribute('href') : '';
            $id = 0;
            if (preg_match('/(\d+)/', $href, $m)) {
                $id = intval($m[1]);
            }

            // 片名
            $titleNode = $xpath->query('.//div[@class="info"]//span[@class="title"][1]', $li);
            $title = $titleNode->length > 0 ? trim($titleNode->item(0)->textContent) : '';

            // 评分
            $scoreNode = $xpath->query('.//span[@class="rating_num"]', $li);
            $score = $scoreNode->length > 0 ? trim($scoreNode->item(0)->textContent) : '0.0';

            if (empty($title)) {
                $rank++;
                continue;
            }

            // 封面
            $imgNode = $xpath->query('.//img', $li);
            $cover = '';
            if ($imgNode->length > 0) {
                $cover = $imgNode->item(0)->getAttribute('data-src') ?: $imgNode->item(0)->getAttribute('src');
                if ($cover && strpos($cover, '//') === 0) {
                    $cover = 'https:' . $cover;
                }
            }

            // 短评
            $inqNode = $xpath->query('.//span[@class="inq"]', $li);
            $desc = $inqNode->length > 0 ? trim($inqNode->item(0)->textContent) : '';

            // 年份/地区/类型
            $bdNode = $xpath->query('.//div[@class="bd"]/p[1]', $li);
            $meta = $bdNode->length > 0 ? trim($bdNode->item(0)->textContent) : '';
            $metaParts = array_map('trim', explode('/', $meta));
            $year = '';
            $area = '';
            $genre = '';
            if (count($metaParts) >= 3) {
                $year = trim($metaParts[count($metaParts) - 3] ?? '');
                $area = trim($metaParts[count($metaParts) - 2] ?? '');
                $genre = trim($metaParts[count($metaParts) - 1] ?? '');
            }

            $items[] = [
                'id' => $id,
                'title' => $title,
                'score' => $score,
                'cover' => $cover,
                'desc' => $desc,
                'year' => $year,
                'area' => $area,
                'genre' => $genre,
                'url' => $href ?: "https://movie.douban.com/subject/{$id}/",
                'ranking' => $rank,
            ];
            $rank++;
        }

        return $items;
    }
}
