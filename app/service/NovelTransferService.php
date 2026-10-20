<?php

namespace app\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 小说下载转存编排服务（原 no-novel-node /book-transfer 编排逻辑迁入 xinyue）
 *
 * 职责链（对齐原 runDownloadTask 语义）：
 *   submit()  秒回判定（三元组查 qf_novel_share → checkLinks 验证）→ 去重 → 并发槽 → 落任务表
 *   runTask() FPM 内联执行（fastcgi_finish_request 后）：
 *     POST node /book-fetch?async=1（纯下载，upload=0）→ 1s 轮询 /book-task-status
 *     合成 download-progress 事件 → 完成后 /book-download 取文件落临时目录
 *     → 逐账户：缓存复用 / pan-backend upload（XFERINFO 心跳）→ share
 *     → 事件表写 pan-upload-* → qf_novel_share 按账户全量合并落库
 *
 * 事件流：qf_novel_task_event append-only，payload 为前端契约 JSON 原文
 * （handleProgress 消费：download-progress/done/fail、pan-upload-start/progress/done/fail，
 *   事件必带 bookUrl，可选 clientId 隔离），novel_progress SSE 按 (client_id,id) 轮询输出。
 *
 * 缓存归属：qf_novel_share 存分享结果（UNIQUE 三元组 书名+作者+最新章节，跨源共享）；
 * no-novel-node books 表只存本地产物文件缓存（filename），两侧各管一层互不掺和。
 */
class NovelTransferService
{
    /** 轮询 node 任务状态总时长上限（秒） */
    const NODE_POLL_TIMEOUT = 1800;

    /** 轮询间隔（秒） */
    const NODE_POLL_INTERVAL = 1;

    /**
     * 读小说转存配置（qf_conf 现查，保证保存后立即可见）
     * @return array {enabled:bool, accounts:array, remoteDir:string, concurrency:int}
     */
    public static function getPanConfig()
    {
        $rows = Db::name('conf')->where('conf_key', 'in', [
            'novel_pan_enabled', 'novel_pan_accounts', 'novel_pan_remote_dir', 'novel_transfer_concurrency',
        ])->column('conf_value', 'conf_key');
        $accounts = [];
        $decoded = json_decode(strval($rows['novel_pan_accounts'] ?? '[]'), true);
        if (is_array($decoded)) {
            $accounts = array_values(array_filter(array_map('strval', $decoded), function ($v) {
                return $v !== '';
            }));
        }
        return [
            'enabled' => intval($rows['novel_pan_enabled'] ?? 0) === 1,
            'accounts' => $accounts,
            'remoteDir' => rtrim(strval($rows['novel_pan_remote_dir'] ?? '/novel'), '/') ?: '/novel',
            'concurrency' => max(1, intval($rows['novel_transfer_concurrency'] ?? 3) ?: 3),
        ];
    }

    /**
     * 从缓存记录中筛出本次请求账户的可用分享结果（对齐 node book.js pickReady）
     * @param array|null $rec qf_novel_share 行（含 share_results 解码后）
     * @param string[] $accounts
     * @return array[] {account, shareUrl, shareUrlWithPasscode, passcode}
     */
    public static function pickReady($rec, $accounts)
    {
        $results = (is_array($rec) && isset($rec['results']) && is_array($rec['results'])) ? $rec['results'] : [];
        $wanted = array_flip($accounts);
        $seen = [];
        $out = [];
        foreach ($results as $r) {
            if (!is_array($r) || empty($r['account']) || !isset($wanted[$r['account']])) {
                continue;
            }
            if (isset($seen[$r['account']])) {
                continue;
            }
            if (empty($r['shareUrl']) && empty($r['shareUrlWithPasscode'])) {
                continue;
            }
            if (!empty($r['error'])) {
                continue;
            }
            $seen[$r['account']] = true;
            $out[] = [
                'account' => strval($r['account']),
                'shareUrl' => strval($r['shareUrl'] ?? ''),
                'shareUrlWithPasscode' => strval($r['shareUrlWithPasscode'] ?? ''),
                'passcode' => strval($r['passcode'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * 判断既有分享链接是否仍可用（对齐 node book.js linksUsable）：
     * 仅当全部链接被判失效（invalid）才返回 false 触发重新生成；
     * pending（限流）/检测异常均按可用处理，避免误重下大文件
     */
    public static function linksUsable($ready)
    {
        $links = [];
        foreach ($ready as $r) {
            $links[] = $r['shareUrlWithPasscode'] !== '' ? $r['shareUrlWithPasscode'] : $r['shareUrl'];
        }
        $links = array_values(array_filter($links));
        if (empty($links)) {
            return false;
        }
        $res = PanBackendService::checkLinks($links);
        if (!$res['ok']) {
            // 检测服务异常按可用处理
            return true;
        }
        $items = is_array($res['data']) && isset($res['data']['items']) && is_array($res['data']['items']) ? $res['data']['items'] : [];
        $statusByUrl = [];
        foreach ($items as $it) {
            if (is_array($it) && !empty($it['url'])) {
                $statusByUrl[$it['url']] = is_array($it['result'] ?? null) ? strval($it['result']['status'] ?? 'unknown') : 'unknown';
            }
        }
        foreach ($links as $url) {
            if (($statusByUrl[$url] ?? 'unknown') !== 'invalid') {
                return true; // 任一非 invalid 即可用
            }
        }
        return false;
    }

    /**
     * 按三元组查分享缓存
     * @return array|null {title,author,last_chapter,book_url,filename,results(已解码)}
     */
    public static function findShare($bookName, $author, $latestChapter)
    {
        if ($bookName === '' || $author === '' || $latestChapter === '') {
            return null;
        }
        $row = Db::name('novel_share')->where([
            ['title', '=', $bookName],
            ['author', '=', $author],
            ['last_chapter', '=', $latestChapter],
        ])->find();
        if (!$row) {
            return null;
        }
        $results = json_decode(strval($row['share_results'] ?? '[]'), true);
        $row['results'] = is_array($results) ? $results : [];
        return $row;
    }

    /**
     * 追加事件（payload 与前端契约一致；秒回事件 task_id=0 仅挂 client_id）
     */
    public static function appendEvent($taskId, $clientId, $type, array $payload)
    {
        $payload['type'] = $type;
        $now = time();
        Db::name('novel_task_event')->insert([
            'task_id' => intval($taskId),
            'client_id' => strval($clientId),
            'type' => strval($type),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
        ]);
        if (intval($taskId) > 0) {
            Db::name('novel_task')->where('id', intval($taskId))->update(['last_event_at' => $now]);
        }
    }

    /**
     * 提交任务（novel_transfer 调用）
     * @param string $bookUrl
     * @param string[] $accounts 目标账户（mode=transfer 时非空）
     * @param string $clientId
     * @param string $bookName
     * @param string $author
     * @param string $latestChapter
     * @return array {kind:'instant',result:array}|{kind:'duplicate'}|{kind:'queued',task_id:int}
     */
    public static function submit($bookUrl, $accounts, $clientId, $bookName, $author, $latestChapter)
    {
        $accounts = array_values(array_filter(array_map('strval', (array)$accounts), function ($v) {
            return $v !== '';
        }));
        $now = time();

        // ① 秒回判定（三元组齐全才查；缺失时任务体内 node 会补齐，xinyue 侧不重复解析）
        if (!empty($accounts)) {
            $rec = self::findShare($bookName, $author, $latestChapter);
            if ($rec !== null) {
                $ready = self::pickReady($rec, $accounts);
                if (count($ready) === count($accounts) && self::linksUsable($ready)) {
                    // 命中：写事件（与响应同步写，保证前端「响应带 result + SSE 事件」竞态前提）
                    $filename = strval($rec['filename'] ?? '');
                    self::appendEvent(0, $clientId, 'download-done', [
                        'bookUrl' => $bookUrl,
                        'filename' => $filename,
                        'bookName' => $bookName,
                        'author' => $author,
                        'reused' => 1,
                        'message' => '已复用本地资源，跳过下载与上传',
                    ]);
                    $results = [];
                    foreach ($ready as $r) {
                        self::appendEvent(0, $clientId, 'pan-upload-done', [
                            'account' => $r['account'],
                            'filename' => $filename,
                            'shareUrl' => $r['shareUrl'],
                            'shareUrlWithPasscode' => $r['shareUrlWithPasscode'],
                            'passcode' => $r['passcode'],
                            'bookUrl' => $bookUrl,
                            'clientId' => $clientId,
                            'reused' => 1,
                            'message' => '已为你复用历史分享链接',
                        ]);
                        $results[] = $r;
                    }
                    return ['kind' => 'instant', 'result' => [
                        'cached' => true,
                        'bookName' => $bookName,
                        'author' => $author,
                        'latestChapter' => $latestChapter,
                        'filename' => $filename,
                        'results' => $results,
                    ]];
                }
            }
        }

        // ② 同书去重（book_url + 未完成态）
        $dup = Db::name('novel_task')->where('book_url', $bookUrl)
            ->where('status', 'in', ['queued', 'running'])->count();
        if (intval($dup) > 0) {
            return ['kind' => 'duplicate'];
        }

        // ③ 并发槽（running 计数，FPM 多进程下以 DB 为准）
        $conf = self::getPanConfig();
        if (!empty($accounts)) {
            $running = Db::name('novel_task')->where('status', 'running')->count();
            if (intval($running) >= $conf['concurrency']) {
                return ['kind' => 'busy', 'concurrency' => $conf['concurrency']];
            }
        }

        // ④ 落任务表
        $taskId = Db::name('novel_task')->insertGetId([
            'client_id' => strval($clientId),
            'book_url' => strval($bookUrl),
            'title' => mb_substr(strval($bookName), 0, 255),
            'author' => mb_substr(strval($author), 0, 255),
            'last_chapter' => mb_substr(strval($latestChapter), 0, 255),
            'mode' => empty($accounts) ? 'download' : 'transfer',
            'status' => 'queued',
            'stage' => 'queued',
            'accounts' => json_encode($accounts, JSON_UNESCAPED_UNICODE),
            'error' => '',
            'last_event_at' => $now,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        return ['kind' => 'queued', 'task_id' => intval($taskId)];
    }

    /**
     * 执行任务（FPM 内联：register_shutdown_function 内 fastcgi_finish_request 后调用）
     * 全程异常兜底标 fail，不向外抛
     */
    public static function runTask($taskId)
    {
        set_time_limit(0);
        ignore_user_abort(true);
        $taskId = intval($taskId);
        $task = Db::name('novel_task')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['queued', 'running'])) {
            return;
        }
        $accounts = json_decode(strval($task['accounts'] ?? '[]'), true);
        $accounts = is_array($accounts) ? $accounts : [];
        $tmpPath = '';
        try {
            Db::name('novel_task')->where('id', $taskId)->update([
                'status' => 'running',
                'stage' => 'downloading',
                'last_event_at' => time(),
                'update_time' => time(),
            ]);

            // ===== 1. 调 node /book-fetch 纯下载（任务化） =====
            $line = self::getNovelLine();
            if ($line === null) {
                throw new \Exception('暂无可用小说线路');
            }
            $query = [
                'url' => $task['book_url'],
                'async' => '1',
                'upload' => '0', // 上传统一由 xinyue 编排，node 只做纯下载
                'clientId' => strval($task['client_id']),
                'bookName' => strval($task['title']),
                'author' => strval($task['author']),
                'latestChapter' => strval($task['last_chapter']),
            ];
            $startResp = curlHelper($line['baseUrl'] . '/book-fetch?' . http_build_query($query), 'POST', null, $line['headers'], '', '', 30);
            $startBody = self::decodeNodeResp($startResp, '[start]');
            if ($startBody === null) {
                $startHttp = intval($startResp['detail']['http_code'] ?? 0);
                if ($startHttp === 409) {
                $p409 = json_decode(strval($startResp['body'] ?? ''), true);
                throw new \Exception('node任务冲突: ' . strval($p409['message'] ?? ''));
            }
                if ($startHttp === 429) {
                    throw new \Exception('小说服务下载并发已满');
                }
                throw new \Exception('小说服务不可达或响应异常（HTTP ' . $startHttp . '）');
            }
            $nodeTaskId = strval($startBody['taskId'] ?? '');
            if ($nodeTaskId === '') {
                throw new \Exception('小说服务任务创建失败');
            }

            // ===== 2. 轮询任务状态，合成 download-progress 事件 =====
            $snap = self::pollNodeTask($taskId, $task, $nodeTaskId, $line);

            $bookName = strval($snap['bookName'] ?? $task['title']);
            $author = strval($snap['author'] ?? $task['author']);
            $latestChapter = strval($snap['latestChapter'] ?? $task['last_chapter']);
            $filename = strval($snap['filename'] ?? '');
            // 元数据补齐回写任务行（入口仅传链接时 node 侧解析）
            Db::name('novel_task')->where('id', $taskId)->update([
                'title' => mb_substr($bookName, 0, 255),
                'author' => mb_substr($author, 0, 255),
                'last_chapter' => mb_substr($latestChapter, 0, 255),
                'update_time' => time(),
            ]);

            if (empty($accounts)) {
                // 纯下载：download-done 已由轮询合成，直接完成
                self::finishTask($taskId);
                return;
            }

            // ===== 3. 取产物文件到临时目录 =====
            $tmpPath = self::fetchBookFile($line, $filename, $taskId);
            if ($tmpPath === '') {
                throw new \Exception('产物文件获取失败');
            }

            // ===== 4. 逐账户：缓存复用 / 上传 / 分享 =====
            self::uploadAndMerge($taskId, $task, $accounts, compact('bookName', 'author', 'latestChapter', 'filename'), $tmpPath);

            self::finishTask($taskId);
        } catch (\Throwable $e) {
            Log::error('[NovelTransferService] runTask #' . $taskId . ' failed: ' . $e->getMessage());
            self::markFail($taskId, $e->getMessage());
        } finally {
            // 临时文件清理
            if ($tmpPath !== '' && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    /** 标记任务失败（写 download-fail/pan-upload-fail 事件由调用方决定，这里只落状态） */
    public static function markFail($taskId, $message)
    {
        $taskId = intval($taskId);
        if ($taskId <= 0) {
            return;
        }
        Db::name('novel_task')->where('id', $taskId)->update([
            'status' => 'fail',
            'stage' => 'fail',
            'error' => mb_substr(strval($message), 0, 500),
            'last_event_at' => time(),
            'update_time' => time(),
        ]);
    }

    /** 小说线路（与 Other::getNovelLine 同规则，pantype=11） */
    private static function getNovelLine()
    {
        $line = \app\model\ApiList::where('status', 1)->where('pantype', 11)->order('weight desc')->find();
        if (empty($line)) {
            return null;
        }
        $baseUrl = rtrim(trim(strval($line['url'] ?? '')), '/');
        if ($baseUrl === '') {
            return null;
        }
        $headers = [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; xinyue-novel-proxy/1.0)',
        ];
        if (!empty($line['auth_enabled']) && !empty($line['auth_username'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($line['auth_username'] . ':' . ($line['auth_password'] ?? ''));
        }
        return ['baseUrl' => $baseUrl, 'headers' => $headers];
    }

    /** 解析 node 响应（success 包络 code==200 → data）；失败时记录原始响应便于排查 */
    private static function decodeNodeResp($resp, $tag = '')
    {
        if (!empty($resp['error']) || empty($resp['body'])) {
            Log::error("[NovelTransferService]{$tag} node resp error: " . strval($resp['error'] ?? 'body为空'));
            return null;
        }
        $payload = json_decode(strval($resp['body']), true);
        $http = intval($resp['detail']['http_code'] ?? 0);
        if (!is_array($payload) || intval($payload['code'] ?? 0) != 200) {
            Log::error("[NovelTransferService]{$tag} node resp http={$http} body=" . mb_substr(strval($resp['body']), 0, 300));
            return null;
        }
        return is_array($payload['data'] ?? null) ? $payload['data'] : null;
    }

    /**
     * 轮询 node /book-task-status 直至终态
     * downloading 期间把 progress 变化合成为 download-progress 事件（字段与前端契约一致）
     * @return array 终态快照（stage=done）
     */
    private static function pollNodeTask($taskId, $task, $nodeTaskId, $line)
    {
        $clientId = strval($task['client_id']);
        $bookUrl = strval($task['book_url']);
        $startedAt = time();
        $lastProgress = '';
        $lastTouch = time();
        while (true) {
            if (time() - $startedAt > self::NODE_POLL_TIMEOUT) {
                throw new \Exception('下载超时（超过 ' . self::NODE_POLL_TIMEOUT . ' 秒）');
            }
            $resp = curlHelper($line['baseUrl'] . '/book-task-status?taskId=' . urlencode($nodeTaskId), 'GET', null, $line['headers'], '', '', 10);
            $snap = self::decodeNodeResp($resp, '[poll]');
            if ($snap === null) {
                $http = intval($resp['detail']['http_code'] ?? 0);
                if ($http === 404) {
                    // node 重启/任务被逐出
                    throw new \Exception('小说服务任务丢失（服务可能已重启）');
                }
                // 瞬时不可达：继续轮询（node 短暂抖动不直接判失败）
                if (time() - $lastTouch >= 30) {
                    Db::name('novel_task')->where('id', intval($taskId))->update(['last_event_at' => time()]);
                    $lastTouch = time();
                }
                sleep(self::NODE_POLL_INTERVAL);
                continue;
            }
            $stage = strval($snap['stage'] ?? '');
            if ($stage === 'fail') {
                throw new \Exception(strval($snap['error'] ?? '下载失败'));
            }
            if ($stage === 'done') {
                // 完成事件：纯下载/转存均需要（前端以 download-done 推进阶段）
                self::appendEvent($taskId, $clientId, 'download-done', [
                    'bookUrl' => $bookUrl,
                    'filename' => strval($snap['filename'] ?? ''),
                    'bookName' => strval($snap['bookName'] ?? ''),
                    'author' => strval($snap['author'] ?? ''),
                    'clientId' => $clientId,
                    'message' => '下载完成',
                ]);
                return $snap;
            }
            // downloading：进度变化才写事件（progress 由 node 侧节流，这里再按内容去重）
            $index = intval($snap['progress']['index'] ?? 0);
            $total = intval($snap['progress']['total'] ?? 0);
            $sig = $index . '/' . $total;
            if ($sig !== $lastProgress && ($index > 0 || $total > 0)) {
                $lastProgress = $sig;
                self::appendEvent($taskId, $clientId, 'download-progress', [
                    'bookUrl' => $bookUrl,
                    'bookName' => strval($snap['bookName'] ?? ''),
                    'author' => strval($snap['author'] ?? ''),
                    'index' => $index,
                    'total' => $total,
                    'clientId' => $clientId,
                ]);
            }
            if (time() - $lastTouch >= 30) {
                // 无进度长爬取时保持心跳（防僵尸回收误杀）
                Db::name('novel_task')->where('id', intval($taskId))->update(['last_event_at' => time()]);
                $lastTouch = time();
            }
            sleep(self::NODE_POLL_INTERVAL);
        }
    }

    /**
     * 取产物文件到临时目录（runtime/novel_tmp/task{id}_{filename}）
     * @return string 临时文件绝对路径（失败返回 ''）
     */
    private static function fetchBookFile($line, $filename, $taskId)
    {
        if ($filename === '') {
            return '';
        }
        $dir = runtime_path() . 'novel_tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // 临时文件名必须纯 ASCII：Windows 版 libcurl 以本地编码打开本地文件，
        // 含中文的路径会直接读取失败（CURLE_READ_ERROR=26，curl_error 为空）；
        // 产物名仅作为 multipart 的 filename 传给服务端，本地路径用哈希名即可
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $tmpPath = $dir . DIRECTORY_SEPARATOR . 'task' . intval($taskId) . '_' . md5($filename) . ($ext !== '' ? '.' . $ext : '.tmp');
        $url = $line['baseUrl'] . '/book-download?filename=' . urlencode($filename);
        $fp = @fopen($tmpPath, 'wb');
        if (!$fp) {
            return '';
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $line['headers']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_exec($ch);
        $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if ($err !== '' || $http !== 200 || !is_file($tmpPath) || filesize($tmpPath) <= 0) {
            @unlink($tmpPath);
            return '';
        }
        return $tmpPath;
    }

    /**
     * 逐账户转存 + 按账户全量合并落库（对齐 node book.js ④ 段语义）：
     * 库中已有结果的账户走复用（emit pan-upload-done reused）；
     * 缺失账户 upload→share；合并基底取库中"全量"（不限本次账户），
     * 防止换盘时抹掉其他网盘历史链接。
     */
    private static function uploadAndMerge($taskId, $task, $accounts, $meta, $tmpPath)
    {
        $clientId = strval($task['client_id']);
        $bookUrl = strval($task['book_url']);
        $conf = self::getPanConfig();

        $bookName = strval($meta['bookName']);
        $author = strval($meta['author']);
        $latestChapter = strval($meta['latestChapter']);
        $filename = strval($meta['filename']);

        // 补查库内已有分享（任务提交时三元组可能不全，此处已补齐）
        $ready = [];
        $rec = self::findShare($bookName, $author, $latestChapter);
        if ($rec !== null) {
            $ready = self::pickReady($rec, $accounts);
        }
        $readySet = [];
        foreach ($ready as $r) {
            $readySet[$r['account']] = true;
            self::appendEvent($taskId, $clientId, 'pan-upload-done', [
                'account' => $r['account'],
                'filename' => $filename,
                'shareUrl' => $r['shareUrl'],
                'shareUrlWithPasscode' => $r['shareUrlWithPasscode'],
                'passcode' => $r['passcode'],
                'bookUrl' => $bookUrl,
                'clientId' => $clientId,
                'reused' => 1,
                'message' => '已为你复用历史分享链接',
            ]);
        }
        $toUpload = array_values(array_filter($accounts, function ($a) use ($readySet) {
            return !isset($readySet[$a]);
        }));

        // 只上传缺失账户；失败不掩盖已有复用结果
        $fresh = [];
        $shareError = '';
        if (!empty($toUpload)) {
            Db::name('novel_task')->where('id', intval($taskId))->update(['stage' => 'uploading', 'update_time' => time()]);
            foreach ($toUpload as $account) {
                self::appendEvent($taskId, $clientId, 'pan-upload-start', [
                    'account' => $account,
                    'filename' => $filename,
                    'bookUrl' => $bookUrl,
                    'clientId' => $clientId,
                    'message' => '正在上传到网盘…',
                ]);
                $destDir = '/' . $account . $conf['remoteDir'];
                // 上传进度心跳：真实字节进度节流约 15s 上报一次（首帧立即），
                // 防止长上传期间无事件被前端 120s 兜底扫描误判失败
                $uploaded = PanBackendService::upload($tmpPath, $destDir, $filename, function ($done, $total) use ($taskId, $clientId, $account, $filename, $bookUrl) {
                    $pct = $total > 0 ? intval(round($done * 100 / $total)) : 0;
                    self::appendEvent($taskId, $clientId, 'pan-upload-progress', [
                        'account' => $account,
                        'filename' => $filename,
                        'bookUrl' => $bookUrl,
                        'clientId' => $clientId,
                        'message' => '已上传 ' . $pct . '%',
                    ]);
                });
                if (!$uploaded['ok']) {
                    self::appendEvent($taskId, $clientId, 'pan-upload-fail', [
                        'account' => $account,
                        'filename' => $filename,
                        'bookUrl' => $bookUrl,
                        'clientId' => $clientId,
                        'message' => '资源准备失败: ' . $uploaded['message'],
                    ]);
                    $fresh[] = ['account' => $account, 'error' => $uploaded['message']];
                    $shareError = $shareError !== '' ? $shareError : $uploaded['message'];
                    continue;
                }
                $shared = PanBackendService::share($destDir . '/' . $filename, $bookName);
                if (!$shared['ok']) {
                    self::appendEvent($taskId, $clientId, 'pan-upload-fail', [
                        'account' => $account,
                        'filename' => $filename,
                        'bookUrl' => $bookUrl,
                        'clientId' => $clientId,
                        'message' => '资源准备失败: ' . $shared['message'],
                    ]);
                    $fresh[] = ['account' => $account, 'error' => $shared['message']];
                    $shareError = $shareError !== '' ? $shareError : $shared['message'];
                    continue;
                }
                $item = [
                    'account' => $account,
                    'shareUrl' => $shared['data']['shareUrl'],
                    'shareUrlWithPasscode' => $shared['data']['shareUrlWithPasscode'],
                    'passcode' => $shared['data']['passcode'],
                    'destPath' => $destDir . '/' . $filename,
                ];
                $fresh[] = $item;
                self::appendEvent($taskId, $clientId, 'pan-upload-done', [
                    'account' => $account,
                    'filename' => $filename,
                    'shareUrl' => $item['shareUrl'],
                    'shareUrlWithPasscode' => $item['shareUrlWithPasscode'],
                    'passcode' => $item['passcode'],
                    'bookUrl' => $bookUrl,
                    'clientId' => $clientId,
                    'message' => '资源链接已就绪',
                ]);
            }
        }

        // ===== 唯一落库点：以账户为键合并库中全量结果 =====
        if (!empty(array_filter($fresh, function ($r) {
            return !empty($r['shareUrl']) && empty($r['error']);
        }))) {
            $byAccount = [];
            if ($rec !== null) {
                foreach ($rec['results'] as $r) {
                    if (is_array($r) && !empty($r['account'])) {
                        $byAccount[$r['account']] = $r;
                    }
                }
            }
            foreach ($fresh as $r) {
                if (!empty($r['account'])) {
                    $byAccount[$r['account']] = $r;
                }
            }
            self::saveShare($bookName, $author, $latestChapter, $bookUrl, $filename, array_values($byAccount));
        }

        // 失败汇总（仅全失败且无复用时抛出，让任务落 fail；有复用结果则任务仍算完成）
        $okCount = count($ready) + count(array_filter($fresh, function ($r) {
            return !empty($r['shareUrl']) && empty($r['error']);
        }));
        if ($okCount === 0 && $shareError !== '') {
            throw new \Exception($shareError);
        }
    }

    /** 分享缓存落库（三元组 upsert） */
    private static function saveShare($bookName, $author, $latestChapter, $bookUrl, $filename, $results)
    {
        if ($bookName === '' || $author === '' || $latestChapter === '') {
            return; // 三元组不齐无法建唯一键（与 node saveBook 必须 filename 同理），放弃缓存
        }
        $now = time();
        $row = Db::name('novel_share')->where([
            ['title', '=', $bookName],
            ['author', '=', $author],
            ['last_chapter', '=', $latestChapter],
        ])->find();
        $data = [
            'book_url' => mb_substr(strval($bookUrl), 0, 500),
            'filename' => mb_substr(strval($filename), 0, 255),
            'share_results' => json_encode($results, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ];
        if ($row) {
            Db::name('novel_share')->where('id', intval($row['id']))->update($data);
        } else {
            $data['title'] = mb_substr(strval($bookName), 0, 255);
            $data['author'] = mb_substr(strval($author), 0, 255);
            $data['last_chapter'] = mb_substr(strval($latestChapter), 0, 255);
            $data['create_time'] = $now;
            Db::name('novel_share')->insert($data);
        }
    }

    private static function finishTask($taskId)
    {
        Db::name('novel_task')->where('id', intval($taskId))->update([
            'status' => 'done',
            'stage' => 'done',
            'error' => '',
            'last_event_at' => time(),
            'update_time' => time(),
        ]);
    }
}
