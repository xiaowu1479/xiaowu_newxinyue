<?php

namespace app\api\controller;

use app\api\QfShop;

class Game extends QfShop
{
    private $cacheTime = 21600; // 6小时

    private function getCacheDir()
    {
        $dir = root_path('runtime/api/cache');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 游民星空游戏排行榜
     * GET /api/game/ranking
     */
    public function ranking()
    {
        $cacheFile = $this->getCacheDir() . 'gamersky_ranking.cache';
        $data = [];

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheTime)) {
            $data = json_decode(file_get_contents($cacheFile), true) ?: [];
        }

        if (empty($data)) {
            $data = $this->scrapeRanking();
            if (!empty($data)) {
                file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
            }
        }

        return jok('获取成功', $data);
    }

    /**
     * 游民星空图片代理
     * GET /api/game/proxy?url=xxx
     */
    public function proxy()
    {
        $url = input('url', '');
        if (empty($url) || strpos($url, 'gamersky.com') === false) {
            return jerr('无效的图片URL');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Referer: https://www.gamersky.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
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
     * 爬取游民星空排行榜
     */
    private function scrapeRanking()
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $html = $this->fetchUrl('https://www.gamersky.com/top/', $ua);
        if ($html === false) return [];

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $result = [];

        // 获取 Mid1ph / Mid2ph 容器下的所有排行榜
        $containers = $xpath->query('//div[contains(@class,"Mid1ph") or contains(@class,"Mid2ph")]');

        foreach ($containers as $container) {
            $childNode = $container->firstChild;
            $currentTitle = '';
            $rankings = [];

            while ($childNode) {
                if ($childNode->nodeType === XML_ELEMENT_NODE) {
                    $cls = $childNode->getAttribute('class');

                    // 排行榜标题
                    if (strpos($cls, 'Mtit') !== false) {
                        $titNode = $xpath->query('.//div[@class="tit"]', $childNode);
                        $currentTitle = $titNode->length > 0 ? trim($titNode->item(0)->textContent) : '';
                    }

                    // 排行榜列表
                    if (strpos($cls, 'PHB') !== false) {
                        $items = $this->parseList($xpath, $childNode);
                        if (!empty($items) && $currentTitle) {
                            $result[] = [
                                'title' => $currentTitle,
                                'items' => $items,
                            ];
                        }
                    }
                }
                $childNode = $childNode->nextSibling;
            }
        }

        return $result;
    }

    /**
     * 解析排行榜列表
     */
    private function parseList($xpath, $ulNode)
    {
        $items = [];
        $lis = $xpath->query('.//li[@class="txt"]', $ulNode);

        foreach ($lis as $li) {
            // 检查是否有封面图（决定字段映射）
            $imgNode = $xpath->query('.//img', $li);
            $hasCover = $imgNode->length > 0;

            // 排名
            $rankNode = $xpath->query('.//div[contains(@class,"tt1")]', $li);
            $ranking = $rankNode->length > 0 ? intval(trim($rankNode->item(0)->textContent)) : count($items) + 1;

            $cover = '';
            $title = '';
            $link = '';
            $genre = '';
            $date = '';
            $popularity = '';

            if ($hasCover) {
                // 有封面的排行榜（大作排行、热门总排行）
                $cover = $imgNode->item(0)->getAttribute('src') ?: $imgNode->item(0)->getAttribute('data-src');
                if ($cover && strpos($cover, '//') === 0) {
                    $cover = 'https:' . $cover;
                }

                $nameNode = $xpath->query('.//div[contains(@class,"tt3")]//a', $li);
                $title = $nameNode->length > 0 ? trim($nameNode->item(0)->textContent) : '';
                $link = $nameNode->length > 0 ? $nameNode->item(0)->getAttribute('href') : '';

                $typeNode = $xpath->query('.//div[contains(@class,"tt4")]', $li);
                $genre = $typeNode->length > 0 ? trim($typeNode->item(0)->textContent) : '';

                $timeNode = $xpath->query('.//div[contains(@class,"tt5")]', $li);
                $date = $timeNode->length > 0 ? trim($timeNode->item(0)->textContent) : '';

                $popNode = $xpath->query('.//div[contains(@class,"tt6")]', $li);
                $popularity = $popNode->length > 0 ? trim($popNode->item(0)->textContent) : '';
            } else {
                // 无封面的排行榜（射击、动作等）
                // tt2 = 游戏名
                $nameNode = $xpath->query('.//div[contains(@class,"tt2")]//a', $li);
                $title = $nameNode->length > 0 ? trim($nameNode->item(0)->textContent) : '';
                $link = $nameNode->length > 0 ? trim($nameNode->item(0)->getAttribute('href')) : '';

                // tt3 = 时间
                $timeNode = $xpath->query('.//div[contains(@class,"tt3")]', $li);
                $date = $timeNode->length > 0 ? trim($timeNode->item(0)->textContent) : '';
            }

            if (empty($title)) continue;

            $items[] = [
                'ranking' => $ranking,
                'title' => $title,
                'cover' => $cover,
                'genre' => $genre,
                'date' => $date,
                'popularity' => $popularity,
                'url' => $link,
            ];
        }

        return $items;
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200 ? $html : false;
    }
}
