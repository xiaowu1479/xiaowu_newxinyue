<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;
use app\model\SourceSubscribe as SourceSubscribeModel;
use quarkPlugin\QuarkPlugin;

/**
 * 资源订阅拉取服务（PanBot 第三方应用对接版）
 *
 * 适配 doc/third-party-app-integration-complete(1)(1).md §4.3 订阅查询接口：
 *   GET {url}/api/v1/third-party/subscription-codes/{code}?page=N&page_size=30
 *     + JSON 请求体：{"client_id":"...","client_secret":"..."}
 *   响应：{"code":200,"data":{"meta":{...},"list":[{"id","title","share_url","passcode","drive_type",
 *   "category_id","category_name","cover_url","description","file_count","total_size","created_at","updated_at"}],
 *   "total","page","page_size"},"timestamp":...}
 *
 * 触发入口（共用同一把 Cache 锁互斥）：
 *   - HTTP：GET /api/sync/autoPull?api_key=xxx（宝塔 cron，见 Sync::autoPull）
 *   - CLI：php think subscribe:pull
 *   - 后台手动：/admin/source_subscribe/pullNow
 *
 * 入库方式按订阅可配置：1=直接入库（写 source 表）2=转存后入库（QuarkPlugin::transfer）。
 * 去重：按 share_url 跨批去重（已入库则跳过），status != active 的分享直接 skip
 */
class SubscribeService
{
    // 单次拉取的响应体大小上限（30MB）
    const MAX_BODY_BYTES = 31457280;
    // 4.3 接口 page_size 上限 50；单次拉取最多 50 页（2500 条）防 total 异常时死循环
    const PAGE_SIZE = 50;
    const MAX_PAGES = 50;
    // 转存模式每批条数（QuarkPlugin::transfer 逐条转存耗时，分批防超时）
    const TRANSFER_BATCH_SIZE = 50;

    // SSRF 校验开关：仅供自动化测试对本地 mock 服务跑端到端时置 true，生产恒为 false
    public static $skipGuard = false;

    /**
     * 执行拉取
     * @param int $subscribeId 指定订阅 ID；0=拉取所有已到期且启用的订阅
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>['results'=>[...每条订阅结果]]]
     */
    public static function run($subscribeId = 0)
    {
        $subscribeId = intval($subscribeId);

        if ($subscribeId > 0) {
            $model = new SourceSubscribeModel();
            $subscribe = $model->find($subscribeId);
            if (empty($subscribe)) {
                return ['code' => 0, 'message' => '订阅不存在', 'data' => []];
            }
            $result = self::pullOne($subscribe);
            return [
                'code' => $result['code'],
                'message' => $result['message'],
                'data' => ['results' => [self::normalizeResult($subscribe, $result)]],
            ];
        }

        // 到期判断：last_pull_at=0 视为立即到期
        $now = time();
        $list = Db::name('source_subscribe')
            ->where('status', 1)
            ->whereRaw('(last_pull_at = 0 OR last_pull_at + interval_minutes * 60 <= ' . $now . ')')
            ->select()
            ->toArray();
        if (empty($list)) {
            return ['code' => 1, 'message' => '没有到期的订阅', 'data' => ['results' => []]];
        }

        $results = [];
        foreach ($list as $subscribe) {
            $results[] = self::normalizeResult($subscribe, self::pullOne($subscribe));
        }
        return ['code' => 1, 'message' => '订阅拉取完成', 'data' => ['results' => $results]];
    }

    /**
     * 归一化单条订阅结果：携带 code/message + 统计，供 HTTP/CLI/后台统一消费
     */
    private static function normalizeResult($subscribe, $pullResult)
    {
        return array_merge([
            'subscribe_id' => intval($subscribe['subscribe_id']),
            'name' => $subscribe['name'],
            'code' => $pullResult['code'],
            'message' => $pullResult['message'],
        ], $pullResult['data']);
    }

    /**
     * 拉取单条订阅（含锁、翻页拉取、解析、入库、统计回写）
     * @param array $subscribe qf_source_subscribe 行
     * @return array ['code'=>1|0, 'message'=>string, 'data'=>[统计]]
     */
    private static function pullOne($subscribe)
    {
        $id = intval($subscribe['subscribe_id']);
        $lockKey = 'subscribe_pull_lock_' . $id;
        $startTime = time();

        // 并发锁：HTTP/CLI/手动拉取共用，天然互斥
        if (Cache::get($lockKey)) {
            return ['code' => 0, 'message' => '该订阅正在拉取中，请稍后再试', 'data' => ['name' => $subscribe['name']]];
        }
        Cache::set($lockKey, 1, 600);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $pullCount = 0;
        $newCount = 0;
        $skipped = 0;
        $failed = 0;
        $failReason = '';

        try {
            $baseUrl = trim(strval($subscribe['url']));
            $code = trim(strval($subscribe['code']));
            $clientId = trim(strval($subscribe['client_id']));
            $clientSecret = trim(strval($subscribe['client_secret']));
            if ($baseUrl === '' || $code === '' || $clientId === '' || $clientSecret === '') {
                throw new \Exception('订阅配置不完整（PanBot 地址/订阅码/client_id/client_secret 均必填）');
            }

            // 1. SSRF 防护：仅 http/https + 拒绝内网/回环/链路本地（防止 baseUrl 指向内网服务）
            if (!self::$skipGuard) {
                $guardError = self::guardUrl($baseUrl);
                if ($guardError !== '') {
                    throw new \Exception('PanBot 地址被拒绝：' . $guardError);
                }
            }

            // 2. 按 4.3 分页拉取（page_size=50 上限），循环直到读完或达到页数上限
            // 凭证通过 query 传递（文档 §3.2：GET 请求可用同名 query 参数携带凭证，
            // curlHelper 的 GET 分支不支持请求体）
            $existingUrls = self::getExistingUrls();
            $page = 1;
            while (true) {
                $queryUrl = rtrim($baseUrl, '/') . '/api/v1/third-party/subscription-codes/'
                    . rawurlencode($code) . '?page=' . $page . '&page_size=' . self::PAGE_SIZE
                    . '&client_id=' . rawurlencode($clientId) . '&client_secret=' . rawurlencode($clientSecret);
                $res = curlHelper($queryUrl, 'GET', null, [
                    'Accept: application/json',
                    'User-Agent: Mozilla/5.0 (compatible; XinyueSubscribe/1.0)',
                ], '', '', 20);
                if (!empty($res['error'])) {
                    throw new \Exception('拉取失败：' . $res['error']);
                }
                $body = $res['body'] ?? '';
                if (empty($body)) {
                    throw new \Exception('拉取失败：响应为空');
                }
                if (strlen($body) > self::MAX_BODY_BYTES) {
                    throw new \Exception('响应超过 30MB，已拒绝解析');
                }
                $httpCode = intval($res['detail']['http_code'] ?? 0);
                if ($httpCode >= 400) {
                    // 文档 §3.1 约定：失败时 HTTP 非 200，code 为错误码，message 中文说明
                    $hint = json_decode($body, true);
                    throw new \Exception('拉取失败 HTTP ' . $httpCode . '：' . strval($hint['message'] ?? '上游异常'));
                }

                $payload = json_decode($body, true);
                if (!is_array($payload) || intval($payload['code'] ?? 0) !== 200) {
                    throw new \Exception('上游返回非成功响应：' . strval($payload['message'] ?? 'code != 200'));
                }
                $data = $payload['data'] ?? null;
                if (!is_array($data)) {
                    throw new \Exception('上游响应缺少 data 字段');
                }
                $list = is_array($data['list'] ?? null) ? $data['list'] : [];
                $total = intval($data['total'] ?? 0);

                // 3. 按文档固定字段映射；status != active（expired/not_found/invalid）直接跳过
                $mapped = [];
                foreach ($list as $item) {
                    if (!is_array($item)) continue;
                    $status = strval($item['status'] ?? '');
                    if ($status !== '' && $status !== 'active') {
                        $skipped++;
                        continue;
                    }
                    $title = trim(strval($item['title'] ?? ''));
                    $url = trim(strval($item['share_url'] ?? ''));
                    if ($title === '' || $url === '' || !preg_match('/^https?:\/\//i', $url)) {
                        $skipped++;
                        continue;
                    }
                    $mapped[] = [
                        'title' => $title,
                        'url' => $url,
                        'description' => trim(strval($item['description'] ?? '')),
                        // PanBot 未配置对象存储时 cover_url 可能是不带协议头的对象 key（相对路径），无法直接展示，按无图处理
                        'vod_pic' => preg_match('/^https?:\/\//i', strval($item['cover_url'] ?? '')) ? trim(strval($item['cover_url'])) : '',
                        'code' => trim(strval($item['passcode'] ?? '')),
                    ];
                }

                // 4. URL 去重（全局预载 + 批内增量）+ 分流入库
                $fresh = [];
                foreach ($mapped as $item) {
                    if (isset($existingUrls[$item['url']])) {
                        $skipped++;
                        continue;
                    }
                    $existingUrls[$item['url']] = true;
                    $fresh[] = $item;
                }
                if (!empty($fresh)) {
                    if (intval($subscribe['pull_mode']) === 2) {
                        list($n, $s, $f, $r) = self::importByTransfer($fresh, $subscribe);
                    } else {
                        list($n, $s, $f, $r) = self::importDirect($fresh, $subscribe);
                    }
                    $newCount += $n;
                    $skipped += $s;
                    $failed += $f;
                    if ($r !== '') $failReason = $r;
                }
                $pullCount += count($list);

                // 5. 翻页：list 拉满一页且未到 total 才继续；达到 MAX_PAGES 安全截断
                if (count($list) < self::PAGE_SIZE || $pullCount >= $total) {
                    break;
                }
                $page++;
                if ($page > self::MAX_PAGES) {
                    $failReason = '已达单次拉取页数上限 ' . self::MAX_PAGES . ' 页，剩余 ' . max(0, $total - $pullCount) . ' 条未拉取';
                    break;
                }
            }

            $endTime = time();
            $message = "拉取 {$pullCount} 条，新增 {$newCount}，跳过 {$skipped}，失败 {$failed}";
            self::finishPull($id, $subscribe, $pullCount, $newCount, $skipped, $failed, $failReason);
            Cache::delete($lockKey);
            return ['code' => 1, 'message' => $message, 'data' => [
                'name' => $subscribe['name'],
                'pull_count' => $pullCount,
                'new_count' => $newCount,
                'skipped' => $skipped,
                'failed' => $failed,
                'last_error' => $failReason,
                'cost' => $endTime - $startTime,
            ]];
        } catch (\Exception $e) {
            self::finishPull($id, $subscribe, $pullCount, 0, $skipped, $failed + 1, $e->getMessage());
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => '拉取失败：' . $e->getMessage(), 'data' => ['name' => $subscribe['name']]];
        }
    }

    /**
     * SSRF 最低防护：仅 http/https；解析 IP 拒绝内网/回环/链路本地地址
     * @return string ''=通过，否则拒绝原因
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
            // IPv6：回环 ::1、未指定 ::
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
     * 直接入库（pull_mode=1）：字段语义同 Excel 导入。
     * 每批两条 DB 去重查询：url 命中（跨批/同批重复发布）+ title+is_type 命中（同题不同链）
     */
    private static function importDirect($fresh, $subscribe)
    {
        $newCount = 0;
        $skipped = 0;
        $failed = 0;
        $failReason = '';
        if (empty($fresh)) {
            return [0, 0, 0, ''];
        }
        $batches = array_chunk($fresh, 50);
        foreach ($batches as $batch) {
            $urls = array_column($batch, 'url');
            $titles = array_values(array_unique(array_column($batch, 'title')));
            $urlSet = array_flip(Db::name('source')->where('is_delete', 0)
                ->whereIn('url', $urls)->column('url'));
            $pairs = Db::name('source')->where('is_delete', 0)
                ->whereIn('title', $titles)
                ->field("CONCAT(title,'_',is_type) AS pair")
                ->select()
                ->toArray();
            $pairSet = array_flip(array_column($pairs, 'pair'));

            $insert = [];
            foreach ($batch as $item) {
                if (isset($urlSet[$item['url']])) {
                    $skipped++;
                    continue;
                }
                $isType = determineIsType($item['url']);
                if (isset($pairSet[$item['title'] . '_' . $isType])) {
                    $skipped++;
                    continue;
                }
                $insert[] = [
                    'title' => mb_substr($item['title'], 0, 255),
                    'url' => mb_substr($item['url'], 0, 255),
                    'description' => mb_substr($item['description'], 0, 255),
                    'vod_content' => '',
                    // 1000：PanBot 封面为对象存储签名直链（约 300+ 字符），255 会截掉签名参数导致 403
                    'vod_pic' => mb_substr($item['vod_pic'], 0, 1000),
                    'is_type' => $isType,
                    'code' => mb_substr($item['code'], 0, 50),
                    'source_category_id' => intval($subscribe['source_category_id']),
                    'is_time' => intval($subscribe['is_time']),
                    'status' => 1,
                    'is_delete' => 0,
                    'is_user' => 0,
                    'page_views' => 0,
                    'sort' => 0,
                    'is_top' => 0,
                    'content' => '',
                    'fid' => '',
                    'account_name' => '订阅:' . mb_substr($subscribe['name'], 0, 240),
                    'create_time' => time(),
                    'update_time' => time(),
                ];
                $urlSet[$item['url']] = true;
                $pairSet[$item['title'] . '_' . $isType] = true;
            }
            if (empty($insert)) {
                continue;
            }
            try {
                Db::name('source')->insertAll($insert);
                $newCount += count($insert);
            } catch (\Exception $e) {
                $failed += count($insert);
                $failReason = '入库失败：' . $e->getMessage();
                break;
            }
        }
        return [$newCount, $skipped, $failed, $failReason];
    }

    /**
     * 转存后入库（pull_mode=2）：QuarkPlugin::transfer 内部按 title+is_type 去重；
     * 非分享链接（url 不含 s/）直接按跳过处理；整批失败时中止后续批次
     */
    private static function importByTransfer($fresh, $subscribe)
    {
        $newCount = 0;
        $skipped = 0;
        $failed = 0;
        $failReason = '';
        if (empty($fresh)) {
            return [0, 0, 0, ''];
        }
        // 转存插件只接受含 s/ 的分享链接
        $shareLinks = [];
        foreach ($fresh as $item) {
            if (strpos($item['url'], 's/') === false) {
                $skipped++;
                continue;
            }
            $shareLinks[] = [
                'title' => $item['title'],
                'url' => $item['url'],
                'code' => $item['code'],
            ];
        }
        if (empty($shareLinks)) {
            return [0, $skipped, 0, '无可转存的分享链接'];
        }

        $quarkPlugin = new QuarkPlugin();
        $batches = array_chunk($shareLinks, self::TRANSFER_BATCH_SIZE);
        foreach ($batches as $index => $batch) {
            try {
                $result = $quarkPlugin->transfer($batch, intval($subscribe['source_category_id']), intval($subscribe['is_time']));
            } catch (\Exception $e) {
                $failed += count($batch);
                $failReason = '转存异常：' . $e->getMessage();
                break;
            }
            $newCount += intval($result['success'] ?? 0);
            $skipped += intval($result['skip'] ?? 0);
            $failed += intval($result['fail'] ?? 0);
            // 整批失败（无一条成功）→ 视为凭证失效/风控，中止后续批次
            if (($result['success'] ?? 0) == 0 && ($result['fail'] ?? 0) > 0) {
                $failReason = "第" . ($index + 1) . "批全部转存失败（" . ($result['fail'] ?? 0) . " 条），请检查网盘 Cookie，已中止后续批次";
                break;
            }
        }
        if ($failed > 0 && $failReason === '') {
            $failReason = "{$failed} 条转存失败，请检查网盘 Cookie";
        }
        return [$newCount, $skipped, $failed, $failReason];
    }

    /**
     * 统计回写（成功与失败都更新 last_pull_at/last_error，失败时新增数为 0 不动累计值）
     */
    private static function finishPull($id, $subscribe, $pullCount, $newCount, $skipped, $failed, $failReason)
    {
        Db::name('source_subscribe')->where('subscribe_id', $id)->update([
            'last_pull_at' => time(),
            'last_pull_count' => intval($pullCount),
            'last_new_count' => intval($newCount),
            'total_count' => intval($subscribe['total_count'] ?? 0) + intval($newCount),
            'last_error' => mb_substr(trim(strval($failReason)), 0, 500),
            'update_time' => time(),
        ]);
    }

    private static function getExistingUrls()
    {
        $urls = Db::name('source')->where('is_delete', 0)->column('url');
        return array_flip($urls);
    }
}
