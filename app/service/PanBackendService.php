<?php

namespace app\service;

use think\facade\Cache;
use think\facade\Db;

/**
 * pan-backend 网盘服务客户端（xinyue 直连）
 *
 * 凭证：qf_conf 独立配置键 pan_backend_base_url（服务根地址）+ pan_backend_token
 *       （API token，均在网盘管理页手动填写，填什么用什么——不做登录签发、不做缓存；
 *       令牌兼容读取历史手动令牌键 novel_pan_backend_token），与「接口配置/线路」无关。
 *
 * 响应双层结构（与 no-novel-node panClient.unwrap 对齐）：
 *   HTTP 层 {code:200, data, msg, success} → 业务 CloudResult {code:0, message, data}
 *   data 含 code 键时视为 CloudResult：code==0 取内层 data，否则失败。
 *
 * 上传目录语义：path = /{driveType}_{userId}/目录（首个下划线前为账户标识，
 * 父目录自动创建）；调用方传 destDir = '/{account}' . remoteDir。
 */
class PanBackendService
{
    /** qf_conf：网盘服务地址 / 服务令牌（网盘管理页手动维护，填什么用什么） */
    const CONF_KEY_BASE_URL = 'pan_backend_base_url';
    const CONF_KEY_TOKEN = 'pan_backend_token';

    /**
     * 解析网盘服务连接配置（qf_conf 独立键，与接口配置/线路无关）
     * @return array|null {base_url(根地址，无尾斜杠), api_base(含 /api), token}
     */
    public static function resolveService()
    {
        $conf = Db::name('conf')->where('conf_key', 'in', [
            self::CONF_KEY_BASE_URL, self::CONF_KEY_TOKEN, 'novel_pan_backend_token',
        ])->column('conf_value', 'conf_key');
        $token = trim(strval($conf[self::CONF_KEY_TOKEN] ?? ''));
        if ($token === '') {
            // 兼容历史手动令牌键
            $token = trim(strval($conf['novel_pan_backend_token'] ?? ''));
        }
        // 兼容存量数据粘贴时带的 "Bearer " 前缀
        $token = trim(preg_replace('#^Bearer\s+#i', '', $token));
        $baseUrl = rtrim(trim(strval($conf[self::CONF_KEY_BASE_URL] ?? '')), '/');
        if ($baseUrl === '') {
            // 升级顺序兜底：新代码生效而迁移 SQL 未执行时，回落读旧 qf_api_list pantype=12 行（仅地址）
            $legacy = \app\model\ApiList::where('pantype', 12)->order('weight desc')->find();
            if (empty($legacy)) {
                return null;
            }
            $legacyUrl = rtrim(trim(strval($legacy['url'] ?? '')), '/');
            if ($legacyUrl === '') {
                return null;
            }
            return [
                'base_url' => $legacyUrl,
                'api_base' => preg_match('#/api$#', $legacyUrl) ? $legacyUrl : $legacyUrl . '/api',
                'token' => $token,
            ];
        }
        return [
            'base_url' => $baseUrl,
            'api_base' => preg_match('#/api$#', $baseUrl) ? $baseUrl : $baseUrl . '/api',
            'token' => $token,
        ];
    }

    /**
     * 原始 HTTP 调用（薄封装 curlHelper）
     * @return array curlHelper 响应 {header,body,detail} 或 {error}
     */
    private static function rawCall($url, $method, $body, $bearerToken, $timeout)
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($bearerToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }
        return curlHelper(
            $url,
            $method,
            $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
            $headers,
            '',
            '',
            $timeout
        );
    }

    /**
     * 统一业务调用：填写的令牌直用（401 不重签，提示人工更新）+ 双层剥壳
     * @param string $method HTTP 方法
     * @param string $path   以 / 开头的路径（如 /cloud-drive/accounts/list）
     * @param mixed  $body   JSON 请求体（GET 传 null）
     * @param int    $timeout 秒
     * @return array ['ok'=>bool, 'data'=>mixed, 'message'=>string]
     */
    public static function call($method, $path, $body = null, $timeout = 40)
    {
        $service = self::resolveService();
        if ($service === null) {
            return ['ok' => false, 'data' => null, 'message' => '网盘服务未配置，请在「网盘管理」填写服务地址'];
        }
        if ($service['token'] === '') {
            return ['ok' => false, 'data' => null, 'message' => '网盘服务令牌未配置，请在「网盘管理」填写服务令牌'];
        }
        $resp = self::rawCall($service['api_base'] . $path, $method, $body, $service['token'], $timeout);
        if (!empty($resp['error']) || empty($resp['body'])) {
            $err = strval($resp['error'] ?? '响应为空');
            // HTTPS 请求打到纯 HTTP 端口的典型报错：TLS 握手收到明文响应
            if (strpos($err, 'wrong version number') !== false) {
                $err .= '（该端口未启用 HTTPS，请将服务地址改为 http:// 开头）';
            }
            return ['ok' => false, 'data' => null, 'message' => '网盘服务不可达：' . $err];
        }
        $http = intval($resp['detail']['http_code'] ?? 0);
        $payload = json_decode($resp['body'], true);
        if ($http === 401) {
            // 令牌填什么用什么，失效即提示人工更新；透传 pan-backend 具体原因
            $reason = strval($payload['message'] ?? $payload['msg'] ?? '');
            return ['ok' => false, 'data' => null, 'message' => '网盘服务令牌已失效，请在「网盘管理」更新服务令牌' . ($reason !== '' ? '：' . $reason : '')];
        }
        if ($http !== 200) {
            return ['ok' => false, 'data' => null, 'message' => strval($payload['msg'] ?? $payload['message'] ?? ('HTTP ' . $http))];
        }
        if (!is_array($payload) || intval($payload['code'] ?? 0) != 200) {
            return ['ok' => false, 'data' => null, 'message' => strval($payload['msg'] ?? '网盘服务返回异常')];
        }
        $data = $payload['data'] ?? null;
        // 内层 CloudResult 剥壳：{code:0, message, data}
        if (is_array($data) && array_key_exists('code', $data)) {
            if (intval($data['code']) !== 0) {
                return ['ok' => false, 'data' => null, 'message' => strval($data['message'] ?? '网盘操作失败')];
            }
            $data = $data['data'] ?? null;
        }
        return ['ok' => true, 'data' => $data, 'message' => ''];
    }

    /**
     * 上传本地文件到网盘账户目录（multipart，CURLFile 流式不占内存）
     * @param string   $localPath 本地临时文件绝对路径
     * @param string   $destDir   目标目录 /{account}{remoteDir}
     * @param string   $remoteName 上传后保存的文件名（产物名；默认取临时文件 basename——
     *                            编排层临时文件名带 task 前缀，必须显式传产物名）
     * @param callable|null $onProgress function(int $uploaded, int $totalUpload)（节流后调用，约 15s 一次）
     * @return array ['ok'=>bool, 'data'=>mixed, 'message'=>string]
     */
    public static function upload($localPath, $destDir, $remoteName = null, $onProgress = null)
    {
        $service = self::resolveService();
        if ($service === null) {
            return ['ok' => false, 'data' => null, 'message' => '网盘服务未配置，请在「网盘管理」填写服务地址'];
        }
        if ($service['token'] === '') {
            return ['ok' => false, 'data' => null, 'message' => '网盘服务令牌未配置，请在「网盘管理」填写服务令牌'];
        }

        $filename = $remoteName !== null && $remoteName !== '' ? $remoteName : basename($localPath);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $service['api_base'] . '/cloud-drive/upload');
        curl_setopt($ch, CURLOPT_POST, true);
        // CURLFile 让 curl 自动生成 multipart 边界与 Content-Type，勿手设 Content-Type
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'path' => $destDir,
            'file' => new \CURLFile($localPath, 'application/octet-stream', $filename),
        ]);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $service['token'],
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // 上传进度心跳：真实字节进度节流 15s 上报（防止长上传无事件被前端 120s 兜底误杀）
        $lastTick = 0;
        $doneReported = false;
        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            // 兼容老 libcurl：CURLOPT_XFERINFOFUNCTION 缺失时退回同签名的 CURLOPT_PROGRESSFUNCTION
            $progressOpt = defined('CURLOPT_XFERINFOFUNCTION') ? CURLOPT_XFERINFOFUNCTION : CURLOPT_PROGRESSFUNCTION;
            curl_setopt($ch, $progressOpt, function ($ch, $dlTotal, $dlDone, $ulTotal, $ulDone) use ($onProgress, &$lastTick, &$doneReported) {
                $now = time();
                $isComplete = ($ulTotal > 0 && $ulDone >= $ulTotal);
                // 首帧立即上报；完成帧只上报一次（服务端处理期间 libcurl 会持续以
                // ulDone==ulTotal 回调，不加一次性标记会刷出大量重复的 100% 事件）；
                // 其余按 15s 节流
                if (($isComplete && !$doneReported) || $lastTick === 0 || ($now - $lastTick) >= 15) {
                    if ($isComplete) {
                        $doneReported = true;
                    }
                    $lastTick = $now;
                    try {
                        call_user_func($onProgress, intval($ulDone), intval($ulTotal));
                    } catch (\Throwable $e) {
                        // 进度回调异常不影响上传
                    }
                }
                return 0; // 继续传输
            });
        }

        $respBody = curl_exec($ch);
        $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $curlErr = curl_error($ch);
        if ($respBody === false || $curlErr !== '') {
            // 传输层失败。libcurl 部分错误（如 Windows 下本地文件读取失败）curl_error 为空，
            // 此时回退用 errno 描述，避免前端只看到"上传失败："而无从排查
            $errno = curl_errno($ch);
            curl_close($ch);
            $reason = $curlErr !== '' ? $curlErr : ('传输错误 errno ' . $errno);
            return ['ok' => false, 'data' => null, 'message' => '上传失败：' . $reason];
        }
        curl_close($ch);
        $payload = json_decode(strval($respBody), true);
        if ($http === 401) {
            $reason = strval($payload['message'] ?? $payload['msg'] ?? '');
            return ['ok' => false, 'data' => null, 'message' => '网盘服务令牌已失效，请在「网盘管理」更新服务令牌' . ($reason !== '' ? '：' . $reason : '')];
        }
        if ($http !== 200 || !is_array($payload) || intval($payload['code'] ?? 0) != 200) {
            return ['ok' => false, 'data' => null, 'message' => strval($payload['msg'] ?? ('上传失败 HTTP ' . $http))];
        }
        $data = $payload['data'] ?? null;
        if (is_array($data) && array_key_exists('code', $data)) {
            if (intval($data['code']) !== 0) {
                return ['ok' => false, 'data' => null, 'message' => strval($data['message'] ?? '上传失败')];
            }
            $data = $data['data'] ?? null;
        }
        return ['ok' => true, 'data' => $data, 'message' => ''];
    }

    /**
     * 创建分享链接（对齐 node pan.js normalizeShare：补全 shareUrlWithPasscode）
     * @param string $remotePath 网盘文件完整路径 /{account}{remoteDir}/{filename}
     * @param string $title      分享标题（可空）
     * @param int    $expireDays 有效天数（0=永久或网盘默认）
     * @param string $passcode   提取码（空则部分网盘自动生成并回传）
     * @return array ['ok'=>bool, 'data'=>['shareUrl','shareUrlWithPasscode','passcode'], 'message'=>string]
     */
    public static function share($remotePath, $title = '', $expireDays = 0, $passcode = '')
    {
        $body = ['path' => $remotePath];
        if ($title !== '') {
            $body['title'] = $title;
        }
        if ($passcode !== '') {
            $body['passcode'] = strval($passcode);
        }
        $body['expireDays'] = intval($expireDays);
        $res = self::call('POST', '/cloud-drive/share', $body, 60);
        if (!$res['ok']) {
            return $res;
        }
        $data = is_array($res['data']) ? $res['data'] : [];
        $shareUrl = strval($data['shareUrl'] ?? '');
        $passcode = strval($data['passcode'] ?? '');
        $withCode = strval($data['shareUrlWithPasscode'] ?? '');
        if ($withCode === '' && $shareUrl !== '' && $passcode !== '') {
            $withCode = $shareUrl . '?pwd=' . $passcode;
        }
        if ($shareUrl === '') {
            return ['ok' => false, 'data' => null, 'message' => '分享创建失败：未返回链接'];
        }
        return ['ok' => true, 'data' => [
            'shareUrl' => $shareUrl,
            'shareUrlWithPasscode' => $withCode,
            'passcode' => $passcode,
        ], 'message' => ''];
    }

    /**
     * 批量校验分享链接有效性（对齐 node linksUsable 语义由调用方判定）
     * @param array $links 分享链接列表
     * @return array ['ok'=>bool, 'data'=>['items'=>[['url','result'=>['status'=>...]]]], 'message'=>string]
     */
    public static function checkLinks($links)
    {
        $links = array_values(array_filter(array_map('strval', (array)$links), function ($v) {
            return $v !== '';
        }));
        if (empty($links)) {
            // 空列表短路：pan-backend 对空 links 会报参数错
            return ['ok' => true, 'data' => ['items' => []], 'message' => ''];
        }
        return self::call('POST', '/cloud-drive/check-links', [
            'links' => implode("\n", $links),
            'useCache' => true,
        ], 90);
    }

    /** 网盘账户列表（含 disabled，由调用方过滤） */
    public static function accountsList()
    {
        return self::call('GET', '/cloud-drive/accounts/list', null, 15);
    }

    /** 驱动列表（各网盘品牌 + 登录方式定义） */
    public static function drivers()
    {
        return self::call('GET', '/cloud-drive/drivers', null, 15);
    }

    /** 添加网盘账户（透传 pan-backend /accounts/login 的 action 流程体） */
    public static function accountLogin($body)
    {
        return self::call('POST', '/cloud-drive/accounts/login', is_array($body) ? $body : [], 40);
    }

    /** 删除网盘账户（解绑；body: {account: "{driveType}_{userId}"}） */
    public static function accountDelete($account)
    {
        return self::call('POST', '/cloud-drive/accounts/delete', ['account' => strval($account)], 15);
    }

    /**
     * 列目录（统一文件浏览入口）
     * @param string $path     绝对路径 /{account}/子目录
     * @param int    $page     页码
     * @param int    $pageSize 每页条数（0=不分页）
     * @param bool   $refresh  true=跳过缓存强制拉取
     * @return array ['ok'=>bool, 'data'=>['list'=>[CloudFileInfo], 'total',...], 'message'=>string]
     */
    public static function ls($path, $page = 1, $pageSize = 0, $refresh = false)
    {
        return self::call('GET', '/cloud-drive/ls?path=' . rawurlencode(strval($path))
            . '&page=' . intval($page) . '&pageSize=' . intval($pageSize)
            . '&refresh=' . ($refresh ? 'true' : 'false'), null, 30);
    }

    /**
     * 查询分享链接信息（标题/stoken/文件清单，不落盘）
     * @param string $shareUrl 分享链接
     * @param string $passcode 提取码（可空）
     * @return array ['ok'=>bool, 'data'=>['driveType','title','stoken','fileCount','files'=>[...]], 'message'=>string]
     */
    public static function shareInfo($shareUrl, $passcode = '')
    {
        $body = ['shareUrl' => strval($shareUrl)];
        if ($passcode !== '') {
            $body['passcode'] = strval($passcode);
        }
        return self::call('POST', '/cloud-drive/share-info', $body, 30);
    }

    /**
     * 分享链接转存到指定目录（服务端等待落盘任务终态后才返回）
     * @param string $shareUrl 分享链接
     * @param string $destPath 目标目录 /{account}/子目录
     * @param string $passcode 提取码（可空）
     * @return array ['ok'=>bool, 'data'=>mixed, 'message'=>string]
     */
    public static function save($shareUrl, $destPath, $passcode = '')
    {
        $body = ['shareUrl' => strval($shareUrl), 'destPath' => strval($destPath)];
        if ($passcode !== '') {
            $body['passcode'] = strval($passcode);
        }
        return self::call('POST', '/cloud-drive/save', $body, 120);
    }

    /** 删除单个文件/目录（body: {path}，单路径单次调用） */
    public static function deleteFile($path)
    {
        return self::call('POST', '/cloud-drive/delete', ['path' => strval($path)], 30);
    }

    /**
     * 取指定网盘类型的首个可用账户（转存编排选号）
     * @param string $driveType pan-backend 驱动标识（quark/aliyun/baidu/uc/xunlei/123pan/115/guangya）
     * @return array|null {userId,name}（null=无可用账户；结果缓存 5 分钟，
     *         无账户缓存 60 秒，新登录账户最多 1 分钟后生效）
     */
    public static function firstAccount($driveType)
    {
        $driveType = strval($driveType);
        $cacheKey = 'pan_backend_first_account_' . $driveType;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return is_array($cached) && !empty($cached['userId']) ? $cached : null;
        }
        $res = self::accountsList();
        $account = null;
        if ($res['ok'] && is_array($res['data'])) {
            foreach ($res['data'] as $item) {
                if (!empty($item['disabled']) || strval($item['drive_type'] ?? '') !== $driveType) {
                    continue;
                }
                $account = [
                    'userId' => strval($item['userId'] ?? ''),
                    'name' => strval($item['name'] ?? ''),
                ];
                break;
            }
        }
        Cache::set($cacheKey, $account !== null ? $account : 0, $account !== null ? 300 : 60);
        return $account;
    }
}
