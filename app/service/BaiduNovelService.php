<?php

namespace app\service;

use RuntimeException;
use think\facade\Cache;
use think\facade\Log;
use app\model\Conf as ConfModel;

/**
 * 百度小说板块领域服务
 *
 * 负责「百度小说」板块的两类能力：
 * - 百度账号扫码绑定：生成二维码、轮询扫码状态、换取并保存 BDUSS/STOKEN 登录态
 * - 上游资源代理：把百度网盘小说资源接口（officialresource）归一化为对齐 lean9 的前台契约
 *
 * 凭据存储（qf_conf，conf_type=20 / conf_status=0，键带 baidu_novel_ 前缀，
 * ThemeService::mergeFrontendConfig 已剔除，不进前后台 qfshop 配置）：
 * - baidu_novel_bduss  百度登录态 BDUSS
 * - baidu_novel_stoken 百度登录态 STOKEN
 * - baidu_novel_user   绑定账号摘要 JSON（username/displayName/portraitUrl/bindtime）
 */
class BaiduNovelService
{
    /** conf 表键：百度 BDUSS */
    public const CONF_KEY_BDUSS = 'baidu_novel_bduss';
    /** conf 表键：百度 STOKEN */
    public const CONF_KEY_STOKEN = 'baidu_novel_stoken';
    /** conf 表键：绑定账号摘要 JSON */
    public const CONF_KEY_USER = 'baidu_novel_user';
    /** conf 表键：扫码换取响应的全量 Cookie（含 BAIDUID 等设备指纹，pan 风控需要） */
    public const CONF_KEY_COOKIE = 'baidu_novel_cookie';

    /** 分类缓存键（内容为 categories + tagMap，TTL 一天） */
    private const CACHE_KEY_TAGS = 'baidu_novel_tags';
    /** 列表/搜索缓存键前缀（TTL 5 分钟） */
    private const CACHE_KEY_SEARCH_PREFIX = 'baidu_novel_search_';
    /** 口令生成缓存键前缀（同一本书 10 分钟内复用，TTL 10 分钟） */
    private const CACHE_KEY_PCODE_PREFIX = 'baidu_novel_pcode_';
    /** 扫码会话 Cookie 环境缓存键前缀（getqrcode→unicast→qrbdusslogin 全链路共享，TTL 10 分钟） */
    private const CACHE_KEY_QRJAR_PREFIX = 'baidu_novel_qrjar_';

    /** 上游：小说分类与标签 */
    private const UP_TAGLIST_URL = 'https://pan.baidu.com/api/officialresource/taglist';
    /** 上游：列表/搜索 */
    private const UP_SEARCH_URL = 'https://pan.baidu.com/api/officialresource/search';
    /** 上游：扫码二维码生成 */
    private const UP_QRCODE_URL = 'https://passport.baidu.com/v2/api/getqrcode';
    /** 上游：二维码状态轮询（必须携带 callback 参数） */
    private const UP_UNICAST_URL = 'https://passport.baidu.com/channel/unicast';
    /** 上游：临时 bduss 换正式登录态 */
    private const UP_QRBDUSSLOGIN_URL = 'https://passport.baidu.com/v3/login/main/qrbdusslogin';
    /** 上游：网盘口令生成（卡片点击获取口令） */
    private const UP_GENCODE_URL = 'https://pan.baidu.com/api/mg/pcode/gencode';
    /** 上游 Referer（pan 侧接口必须） */
    private const UP_REFERER = 'https://pan.baidu.com/aipan/novelmaster';
    // 实测决定性因素：officialresource 接口对桌面 Chrome UA 风控拦截（-6 need login 为伪装提示），
    // 移动端 UA 下仅凭 BDUSS 即可通过；换桌面 UA 需全套浏览器指纹 Cookie 不可行
    private const UP_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.6 Mobile/15E148 Safari/604.1';

    /** 上游单页条数（novelmaster 页面代码使用 50） */
    private const UP_PAGE_SIZE = 50;
    /** 状态过滤切页时最多拉取的上游条目数（上限保护） */
    private const UP_MAX_ITEMS = 600;
    /** 状态过滤切页时最多翻的上游页数 */
    private const UP_MAX_PAGES = 20;

    /**
     * 读取绑定账号（凭据齐全返回，未绑定时返回 null）
     *
     * @return array|null {bduss, stoken, user: array|null}
     */
    public static function account(): ?array
    {
        $rows = (new ConfModel())->where('conf_key', 'in', [
            self::CONF_KEY_BDUSS,
            self::CONF_KEY_STOKEN,
            self::CONF_KEY_USER,
            self::CONF_KEY_COOKIE,
        ])->column('conf_value', 'conf_key');

        $bduss = trim((string) ($rows[self::CONF_KEY_BDUSS] ?? ''));
        if ($bduss === '') {
            return null;
        }
        $user = null;
        $decoded = json_decode((string) ($rows[self::CONF_KEY_USER] ?? ''), true);
        if (is_array($decoded)) {
            $user = $decoded;
        }
        return [
            'bduss' => $bduss,
            'stoken' => trim((string) ($rows[self::CONF_KEY_STOKEN] ?? '')),
            'cookie' => trim((string) ($rows[self::CONF_KEY_COOKIE] ?? '')),
            'user' => $user,
        ];
    }

    /**
     * 保存扫码绑定的账号凭据与摘要
     */
    public static function saveAccount(string $bduss, string $stoken, array $user, string $cookie = ''): void
    {
        $user = array_merge([
            'username' => '',
            'displayName' => '',
            'portraitUrl' => '',
            'bindtime' => time(),
        ], $user);
        self::upsertConf(self::CONF_KEY_BDUSS, $bduss, '百度小说绑定 BDUSS');
        self::upsertConf(self::CONF_KEY_STOKEN, $stoken, '百度小说绑定 STOKEN');
        self::upsertConf(self::CONF_KEY_USER, json_encode($user, JSON_UNESCAPED_UNICODE), '百度小说绑定账号摘要');
        if ($cookie !== '') {
            self::upsertConf(self::CONF_KEY_COOKIE, $cookie, '百度小说绑定完整Cookie');
        }
    }

    /**
     * 解除绑定（清空全部凭据）
     */
    public static function clearAccount(): void
    {
        (new ConfModel())->where('conf_key', 'in', [
            self::CONF_KEY_BDUSS,
            self::CONF_KEY_STOKEN,
            self::CONF_KEY_USER,
            self::CONF_KEY_COOKIE,
        ])->delete();
        Cache::delete(self::CACHE_KEY_TAGS);
    }

    /**
     * 绑定状态摘要（BDUSS 脱敏展示，供模板管理页使用）
     */
    public static function status(): array
    {
        $acc = self::account();
        if ($acc === null) {
            return ['bound' => false];
        }
        $user = is_array($acc['user']) ? $acc['user'] : [];
        $bduss = $acc['bduss'];
        return [
            'bound' => true,
            'username' => (string) ($user['displayName'] ?? $user['username'] ?? ''),
            'portrait' => (string) ($user['portraitUrl'] ?? ''),
            'bindtime' => (int) ($user['bindtime'] ?? 0),
            'bduss_preview' => substr($bduss, 0, 6) . '****' . substr($bduss, -4),
        ];
    }

    /**
     * 生成扫码登录二维码
     *
     * @return array {sign, imgurl}
     * @throws RuntimeException
     */
    public static function qrGenerate(): array
    {
        // 建立浏览器等价的 Cookie 环境（BAIDUID 等设备指纹），getqrcode→unicast→qrbdusslogin 全链路共享；
        // 裸环境换取的 BDUSS 会被 pan 域风控标记为不可信，导致列表接口"need login"
        $jar = self::initialJar();
        $res = curlHelper(self::UP_QRCODE_URL . '?lp=pc', 'GET', null, ['User-Agent: ' . self::UP_UA], '', self::jarCookie($jar), 15);
        if (isset($res['error']) || !isset($res['body'])) {
            throw new RuntimeException('生成二维码失败：网络异常，请稍后重试');
        }
        $body = json_decode((string) $res['body'], true);
        if (!is_array($body) || (int) ($body['errno'] ?? -1) !== 0 || empty($body['sign'])) {
            self::logUpstream('二维码生成', (string) ($res['body'] ?? ''));
            throw new RuntimeException('生成二维码失败，请稍后重试');
        }
        self::mergeSetCookies($jar, (string) ($res['header'] ?? ''));
        Cache::set(self::CACHE_KEY_QRJAR_PREFIX . $body['sign'], $jar, 600);

        $imgurl = (string) $body['imgurl'];
        if ($imgurl !== '' && !preg_match('#^https?://#i', $imgurl)) {
            // 上游返回无协议形式：实测为 "passport.baidu.com/xxx"（带域名），兼容 "/xxx"（仅路径）
            if (stripos($imgurl, 'passport.baidu.com') === 0) {
                $imgurl = 'https://' . $imgurl;
            } else {
                $imgurl = 'https://passport.baidu.com' . ($imgurl[0] === '/' ? $imgurl : '/' . $imgurl);
            }
        }
        return ['sign' => (string) $body['sign'], 'imgurl' => $imgurl];
    }

    /**
     * 轮询扫码状态
     *
     * 返回 status 取值：
     * - waiting  等待扫码
     * - scanned  已扫码，等待手机端确认
     * - success  登录成功（已保存凭据，携带 user 摘要）
     * - expired  二维码过期，需要重新生成
     *
     * @throws RuntimeException 登录成功但换取凭据失败等异常场景
     */
    public static function qrQuery(string $sign): array
    {
        $sign = trim($sign);
        if ($sign === '') {
            throw new RuntimeException('二维码标识不能为空');
        }
        $jar = Cache::get(self::CACHE_KEY_QRJAR_PREFIX . $sign);
        if (!is_array($jar)) {
            $jar = [];
        }
        $url = self::UP_UNICAST_URL . '?' . http_build_query([
            'channel_id' => $sign,
            'callback' => 'bd__cbs__xinyue',
            'tpl' => 'netdisk',
        ]);
        $res = curlHelper($url, 'GET', null, ['User-Agent: ' . self::UP_UA], '', self::jarCookie($jar), 15);
        // 单次轮询的网络抖动不报错，让前端继续下一轮
        if (isset($res['error']) || !isset($res['body'])) {
            return ['status' => 'waiting'];
        }
        self::mergeSetCookies($jar, (string) $res['header']);
        $payload = self::parseJsonpCallback((string) $res['body']);
        if ($payload === null || (int) ($payload['errno'] ?? -1) !== 0) {
            Cache::delete(self::CACHE_KEY_QRJAR_PREFIX . $sign);
            return ['status' => 'expired'];
        }
        $channel = [];
        if (isset($payload['channel_v']) && is_string($payload['channel_v'])) {
            $decoded = json_decode($payload['channel_v'], true);
            if (is_array($decoded)) {
                $channel = $decoded;
            }
        }
        $status = (int) ($channel['status'] ?? -1);
        if ($status === 1) {
            return ['status' => 'scanned'];
        }
        if ($status !== 0 || empty($channel['v'])) {
            return ['status' => 'waiting'];
        }

        try {
            $user = self::exchangeLogin((string) $channel['v'], $jar);
        } catch (RuntimeException $e) {
            Cache::delete(self::CACHE_KEY_QRJAR_PREFIX . $sign);
            throw $e;
        }
        Cache::delete(self::CACHE_KEY_QRJAR_PREFIX . $sign);
        return ['status' => 'success', 'user' => $user];
    }

    /**
     * 小说分类列表（对齐 lean9 /api/novel/categories 契约：data 为字符串数组）
     *
     * @return string[]
     * @throws RuntimeException
     */
    public static function fetchTaglist(): array
    {
        $cached = Cache::get(self::CACHE_KEY_TAGS);
        if (is_array($cached) && isset($cached['categories']) && is_array($cached['categories'])) {
            return $cached['categories'];
        }
        $tagInfo = self::fetchTaglistCache(true);
        return $tagInfo['categories'];
    }

    /**
     * 小说列表/搜索（对齐 lean9 /api/novel/list 与 /search 契约）
     *
     * @param int    $page       页码（从 1 起）
     * @param int    $limit      每页条数
     * @param string $keyword    搜索关键词（空为普通列表）
     * @param string $category   分类名（all 或空为全部分类）
     * @param int    $bookStatus 状态过滤：-1 全部 / 0 连载 / 1 完结
     * @return array {list, page, limit, category, keyword, book_status, has_more}
     * @throws RuntimeException
     */
    public static function fetchSearch(int $page, int $limit, string $keyword = '', string $category = '', int $bookStatus = -1): array
    {
        $page = max(1, $page);
        $limit = min(max(1, $limit), self::UP_PAGE_SIZE);
        $bookStatus = in_array($bookStatus, [-1, 0, 1], true) ? $bookStatus : -1;

        $cacheKey = self::CACHE_KEY_SEARCH_PREFIX . md5(json_encode([$page, $limit, $keyword, $category, $bookStatus]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['list'])) {
            return $cached;
        }

        // 分类命中标签组时按 tag_filters 过滤；未命中时降级为关键词搜索
        $tagFilters = [];
        if ($category !== '' && $category !== 'all') {
            $tagInfo = self::fetchTaglistCache(false);
            $hit = $tagInfo['tagMap'][$category] ?? null;
            if (is_array($hit)) {
                $tagFilters = [['name' => (string) $hit['group'], 'values' => [(string) $hit['val']]]];
            } else {
                $keyword = $keyword !== '' ? $keyword : $category;
            }
        }
        $hasKeyword = $keyword !== '';
        if ($hasKeyword) {
            $tagFilters = [];
        }

        $acc = self::requireAccount();
        // 上游不支持完结状态过滤：多拉若干条归一化后在本地过滤切页
        $need = $page * $limit;
        $cap = min(max($need * 2, $need + $limit), self::UP_MAX_ITEMS);
        $all = [];
        $upstreamHasMore = false;
        for ($p = 1; $p <= self::UP_MAX_PAGES; $p++) {
            $resp = self::upstreamSearch($acc, $p, $hasKeyword ? $keyword : '', $tagFilters);
            foreach ($resp['list'] as $item) {
                $all[] = self::normalizeResource($item);
            }
            $upstreamHasMore = $resp['has_more'];
            if (!$upstreamHasMore || count($resp['list']) < self::UP_PAGE_SIZE || count($all) >= $cap) {
                break;
            }
        }

        if ($bookStatus !== -1) {
            $all = array_values(array_filter($all, function ($item) use ($bookStatus) {
                return (int) $item['book_status'] === $bookStatus;
            }));
        }

        $total = count($all);
        $offset = ($page - 1) * $limit;
        $list = array_slice($all, $offset, $limit);
        $result = [
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'category' => $category !== '' ? $category : 'all',
            'keyword' => $keyword,
            'book_status' => $bookStatus,
            'has_more' => ($offset + count($list)) < $total || $upstreamHasMore,
        ];
        Cache::set($cacheKey, $result, 300);
        return $result;
    }

    /**
     * 临时 bduss 换取正式登录态并保存，返回账号摘要
     *
     * @param string $tmpBduss unicast 下发的临时凭据
     * @param array  $jar      扫码会话共享的 Cookie 环境（getqrcode/unicast 阶段积累）
     * @throws RuntimeException
     */
    private static function exchangeLogin(string $tmpBduss, array $jar = []): array
    {
        $params = [
            'bduss' => $tmpBduss,
            'tpl' => 'netdisk',
            'apiver' => 'v3',
            'tt' => (string) (int) (microtime(true) * 1000),
            'traceid' => (string) mt_rand(100000, 999999),
            'time' => (string) time(),
            'alg' => 'v3',
            'force_login' => '1',
            'qrcode' => '1',
            'loginVersion' => 'v4',
            // 浏览器走 jsonp 分支（callback）+ namedtype，缺省时服务端可能走简化分支导致 Cookie 颁发不全
            'callback' => 'bd__cbs__bn' . mt_rand(1000, 9999),
            'namedtype' => 'hideLogin',
        ];
        $res = curlHelper(
            self::UP_QRBDUSSLOGIN_URL . '?' . http_build_query($params),
            'GET',
            null,
            ['User-Agent: ' . self::UP_UA, 'Referer: https://pan.baidu.com/'],
            '',
            self::jarCookie($jar),
            20
        );
        if (isset($res['error']) || !isset($res['header'])) {
            self::logUpstream('扫码登录换取', '网络异常');
            throw new RuntimeException('扫码登录失败：网络异常，请重试');
        }

        // 合并换取响应的 set-cookie 到会话 jar（跳过删除指令）
        $cookieLines = [];
        foreach (preg_split('/\r?\n/', (string) $res['header']) as $line) {
            if (stripos($line, 'set-cookie') !== false) {
                $cookieLines[] = trim($line);
            }
        }
        self::mergeSetCookies($jar, (string) $res['header']);

        // 登录态来源：cookie jar 优先，body JSON 的 session 字段兜底
        $payload = self::parseQrBdussBody((string) ($res['body'] ?? ''));
        $session = is_array($payload['data']['session'] ?? null) ? $payload['data']['session'] : [];
        $bduss = trim((string) ($jar['BDUSS'] ?? ''));
        $stoken = trim((string) ($jar['STOKEN'] ?? ''));
        $bodyBduss = (string) ($session['bduss'] ?? '');
        $bodyStoken = (string) ($session['stoken'] ?? '');
        if ($bduss === '') {
            $bduss = $bodyBduss;
        }
        if ($stoken === '') {
            $stoken = $bodyStoken;
        }
        // session.stokenLogin 是 pan 域专用 STOKEN（pan API 真正校验的凭据之一），进 jar 供 pan 请求使用
        $stokenLogin = trim((string) ($session['stokenLogin'] ?? ''));
        if ($stokenLogin !== '') {
            $jar['STOKEN_LOGIN'] = $stokenLogin;
        }
        $fullCookie = self::jarCookie($jar);

        // 诊断日志：原始 set-cookie（BDUSS 脱敏）与解析结果（直写独立文件，不依赖框架日志通道）
        $masked = preg_replace('/(BDUSS=)(\S{12})\S*(\S{8})/i', '$1$2...$3', $cookieLines) ?? $cookieLines;
        self::diagLog('登录换取dump', "SET-COOKIE行:\n" . implode("\n", $masked)
            . "\njar_cookies: " . implode(', ', array_keys($jar))
            . "\n双来源: bduss_len=" . strlen($bduss)
            . ' body_bduss_len=' . strlen($bodyBduss)
            . ' stokenLogin_len=' . strlen($stokenLogin)
            . ' same=' . var_export($bduss === $bodyBduss, true)
            . "\nBODY片段: " . mb_substr((string) ($res['body'] ?? ''), 0, 1200));

        if ($bduss === '') {
            self::logUpstream('扫码登录换取', mb_substr((string) ($res['body'] ?? ''), 0, 300));
            throw new RuntimeException('扫码登录失败：未获取到登录凭据，请重新扫码');
        }

        $bareCookie = 'BDUSS=' . $bduss . ($stoken !== '' ? '; STOKEN=' . $stoken : '');

        // 探测有效性：全量 cookie 优先，裸 BDUSS+STOKEN 对照（移动端 UA 下 BDUSS 即有效）
        $fullOk = self::quotaOk($fullCookie);
        $bareOk = $fullOk ? false : self::quotaOk($bareCookie);
        self::diagLog('登录换取探测', 'full_jar_ok=' . var_export($fullOk, true)
            . ' bare_ok=' . var_export($bareOk, true)
            . ' bduss_len=' . strlen($bduss)
            . ' jar_names=' . implode(',', array_keys($jar)));
        if (!$fullOk && !$bareOk) {
            throw new RuntimeException('扫码登录失败：凭据校验未通过，请重新扫码（多次失败请查看服务器日志）');
        }

        $rawUser = is_array($payload['data']['user'] ?? null) ? $payload['data']['user'] : [];
        $user = [
            'username' => (string) ($rawUser['username'] ?? ''),
            'displayName' => (string) ($rawUser['displayName'] ?? ''),
            'portraitUrl' => (string) ($rawUser['portraitUrl'] ?? ''),
            'bindtime' => time(),
        ];
        self::saveAccount($bduss, $stoken, $user, $fullOk ? $fullCookie : $bareCookie);
        return $user;
    }

    /**
     * 用 pan quota 接口探测 Cookie 登录态有效性（errno=0 即有效）
     */
    private static function quotaOk(string $cookie): bool
    {
        $res = curlHelper('https://pan.baidu.com/api/quota?checkfree=1&checkexpire=1', 'GET', null, [
            'User-Agent: ' . self::UP_UA,
            'Referer: https://pan.baidu.com/disk/main',
        ], '', $cookie, 10);
        if (isset($res['error']) || !isset($res['body'])) {
            return false;
        }
        $body = json_decode((string) $res['body'], true);
        return is_array($body) && (int) ($body['errno'] ?? -1) === 0;
    }

    /**
     * 访问 passport 登录页获取初始 Cookie 环境（BAIDUID 等设备指纹）
     */
    private static function initialJar(): array
    {
        $jar = [];
        $res = curlHelper('https://passport.baidu.com/v2/?m=login&tpl=netdisk', 'GET', null, ['User-Agent: ' . self::UP_UA], '', '', 15);
        if (!isset($res['error']) && isset($res['header'])) {
            self::mergeSetCookies($jar, (string) $res['header']);
        }
        return $jar;
    }

    /**
     * 把响应头中的 set-cookie 合并进会话 jar（expires 为过去时间的视为删除指令）
     */
    private static function mergeSetCookies(array &$jar, string $header): void
    {
        foreach (preg_split('/\r?\n/', $header) as $line) {
            if (stripos($line, 'set-cookie') === false) {
                continue;
            }
            $pair = trim(substr(trim($line), strlen('Set-Cookie:')));
            $eq = strpos($pair, '=');
            if ($eq <= 0) {
                continue;
            }
            $name = trim(substr($pair, 0, $eq));
            $rest = substr($pair, $eq + 1);
            $value = trim(explode(';', $rest)[0]);
            if (preg_match('/expires=([^;]+)/i', $rest, $em) && ($ts = strtotime(trim($em[1]))) !== false && $ts < time()) {
                unset($jar[$name]);
                continue;
            }
            if ($name === '' || $value === '') {
                continue;
            }
            $jar[$name] = $value;
        }
    }

    /**
     * 会话 jar 转 Cookie 请求串
     */
    private static function jarCookie(array $jar): string
    {
        $parts = [];
        foreach ($jar as $name => $value) {
            $parts[] = $name . '=' . $value;
        }
        return implode('; ', $parts);
    }

    /**
     * 读取（必要时拉取）分类与标签映射缓存
     *
     * @param bool $force true 时跳过缓存强制拉取
     * @return array {categories: string[], tagMap: array<string, {group, val}>}
     * @throws RuntimeException
     */
    private static function fetchTaglistCache(bool $force): array
    {
        if (!$force) {
            $cached = Cache::get(self::CACHE_KEY_TAGS);
            if (is_array($cached) && isset($cached['categories'], $cached['tagMap'])) {
                return $cached;
            }
        }

        $acc = self::requireAccount();
        $res = curlHelper(self::UP_TAGLIST_URL, 'GET', null, self::panHeaders(), ['scene' => 0], self::panCookie($acc), 15);
        $body = self::parsePanResponse('获取分类', $res);
        $data = $body['data'] ?? [];

        $groups = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            $groups = $data['tags'];
        } elseif (is_array($data)) {
            $groups = $data;
        }

        $categories = [];
        $tagMap = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupName = (string) ($group['name'] ?? '');
            $values = is_array($group['values'] ?? null) ? $group['values'] : [];
            foreach ($values as $v) {
                if (is_array($v)) {
                    $display = (string) ($v['display'] ?? $v['val'] ?? '');
                    $val = (string) ($v['val'] ?? $display);
                } else {
                    $val = $display = (string) $v;
                }
                if ($display === '') {
                    continue;
                }
                if (!in_array($display, $categories, true)) {
                    $categories[] = $display;
                }
                $tagMap[$display] = ['group' => $groupName, 'val' => $val];
            }
        }
        if ($categories === []) {
            self::logUpstream('获取分类', json_encode($data, JSON_UNESCAPED_UNICODE));
            throw new RuntimeException('获取分类失败：上游返回为空，若已绑定账号可能登录态失效，请重新扫码绑定');
        }

        $tagInfo = ['categories' => $categories, 'tagMap' => $tagMap];
        Cache::set(self::CACHE_KEY_TAGS, $tagInfo, 86400);
        return $tagInfo;
    }

    /**
     * 调用上游列表/搜索接口并解析
     *
     * @param array $acc        绑定账号
     * @param int   $page       上游页码
     * @param int   $limit      上游单页条数
     * @param array $tagFilters 分类过滤条件（keyword 非空时传空数组）
     * @return array {list: array[], has_more: bool}
     * @throws RuntimeException
     */
    private static function upstreamSearch(array $acc, int $page, string $keyword, array $tagFilters): array
    {
        $payload = [
            'scene' => 0,
            'page' => $page,
            'limit' => self::UP_PAGE_SIZE,
            'keyword' => $keyword,
            'tag_filters' => array_values($tagFilters),
        ];
        $res = curlHelper(
            self::UP_SEARCH_URL,
            'POST',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            array_merge(self::panHeaders(), ['Content-Type: application/json']),
            '',
            self::panCookie($acc),
            15
        );
        $body = self::parsePanResponse('获取列表', $res);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $list = is_array($data['list'] ?? null) ? $data['list'] : [];
        return ['list' => $list, 'has_more' => (int) ($data['has_more'] ?? 0) > 0];
    }

    /**
     * 生成百度网盘口令（卡片点击获取，对齐 lean9 novels 页交互）
     * 同一本书 10 分钟内复用同一口令，避免频繁请求上游
     *
     * @throws RuntimeException
     */
    public static function genCode(string $bookId, string $title, string $author): string
    {
        $bookId = trim($bookId);
        if ($bookId === '') {
            throw new RuntimeException('缺少书籍标识，无法生成口令');
        }
        $cacheKey = self::CACHE_KEY_PCODE_PREFIX . md5($bookId . '|' . $title);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $acc = self::requireAccount();
        $pdata = json_encode([
            'content_id' => $bookId,
            'content_title' => mb_substr(trim($title), 0, 50),
            'content_author' => mb_substr(trim($author), 0, 30),
            'content_type' => 'novel',
            'is_custom_code' => 0,
        ], JSON_UNESCAPED_UNICODE);
        $res = curlHelper(
            self::UP_GENCODE_URL . '?' . http_build_query(['clienttype' => '0', 'app_id' => '250528', 'web' => '1']),
            'POST',
            http_build_query([
                'mg_type' => '201',
                'mg_appid' => '10010',
                'mg_ak' => 'wap',
                'pdata_format' => 'json',
                'clienttype' => '0',
                'pdata' => $pdata,
            ]),
            array_merge(self::panHeaders(), ['Content-Type: application/x-www-form-urlencoded']),
            '',
            self::panCookie($acc),
            15
        );
        $body = self::parsePanResponse('生成口令', $res);
        $pcode = trim((string) ($body['pcode'] ?? ''));
        if ($pcode === '') {
            self::logUpstream('生成口令', mb_substr((string) ($res['body'] ?? ''), 0, 300));
            throw new RuntimeException('生成口令失败：上游未返回口令，请稍后重试');
        }
        Cache::set($cacheKey, $pcode, 600);
        return $pcode;
    }

    /**
     * 解析 pan 侧 JSON 响应并校验 errno（errno=±6 视为登录态失效）
     *
     * @return array
     * @throws RuntimeException
     */
    private static function parsePanResponse(string $action, array $res): array
    {
        if (isset($res['error']) || !isset($res['body'])) {
            self::logUpstream($action, '网络异常：' . (string) ($res['error'] ?? ''));
            throw new RuntimeException($action . '失败：网络异常，请稍后重试');
        }
        $body = json_decode((string) $res['body'], true);
        if (!is_array($body)) {
            self::logUpstream($action, mb_substr((string) $res['body'], 0, 300));
            throw new RuntimeException($action . '失败：上游响应异常，请稍后重试');
        }
        $errno = (int) ($body['errno'] ?? -1);
        if ($errno === 6 || $errno === -6) {
            throw new RuntimeException('百度登录态已失效，请在模板管理中重新扫码绑定');
        }
        if ($errno !== 0) {
            self::logUpstream($action, mb_substr((string) $res['body'], 0, 300));
            throw new RuntimeException($action . '失败：上游返回错误（errno=' . $errno . '），请稍后重试');
        }
        return $body;
    }

    /**
     * 资源条目归一化（对齐 lean9 列表字段：book_id/title/author/category/cover/word_count/book_status/description）
     */
    private static function normalizeResource(array $item): array
    {
        $extra = is_array($item['extra'] ?? null) ? $item['extra'] : [];
        $completed = (int) ($extra['book_status'] ?? 0) === 1
            || strtolower((string) ($extra['completionStatus'] ?? '')) === 'completed';
        return [
            'book_id' => (string) ($extra['book_id'] ?? $item['resource_id'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'author' => (string) ($extra['cp_name'] ?? ''),
            'category' => (string) ($extra['category'] ?? ''),
            'cover' => (string) ($item['cover'] ?? ''),
            'word_count' => 0,
            'book_status' => $completed ? '1' : '0',
            'description' => (string) ($item['desc'] ?? ''),
            'resource_link' => (string) ($item['resource_link'] ?? ''),
        ];
    }

    /**
     * 要求已绑定账号，未绑定时抛出明确异常
     *
     * @throws RuntimeException
     */
    private static function requireAccount(): array
    {
        $acc = self::account();
        if ($acc === null) {
            throw new RuntimeException('百度账号未绑定，请先在模板管理中扫码登录');
        }
        return $acc;
    }

    /**
     * pan 侧通用请求头
     */
    private static function panHeaders(): array
    {
        return [
            'User-Agent: ' . self::UP_UA,
            'Referer: ' . self::UP_REFERER,
            'Origin: https://pan.baidu.com',
            'Accept: application/json, text/plain, */*',
        ];
    }

    /**
     * pan 侧 Cookie 串（优先使用扫码换取时保存的全量 Cookie，兼容旧数据回退 BDUSS+STOKEN）
     */
    private static function panCookie(array $acc): string
    {
        if (!empty($acc['cookie'])) {
            return $acc['cookie'];
        }
        $cookie = 'BDUSS=' . $acc['bduss'];
        if ($acc['stoken'] !== '') {
            $cookie .= '; STOKEN=' . $acc['stoken'];
        }
        return $cookie;
    }

    /**
     * 解析 jsonp 响应（callback({...}) → 关联数组；无 callback 包裹时按纯 JSON 解析）
     */
    private static function parseJsonpCallback(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        $start = strpos($body, '(');
        $end = strrpos($body, ')');
        if ($start !== false && $end !== false && $end > $start) {
            $body = substr($body, $start + 1, $end - $start - 1);
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 解析 qrbdusslogin 响应体（单引号 JSON 风格的 jsonp）
     */
    private static function parseQrBdussBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        // 百度该接口响应为单引号 JSON，且 & 会被转义成 \&，先还原再统一为双引号
        $str = str_replace('\\&', '&', $body);
        $str = preg_replace("/'([^']*)'/", '"$1"', $str) ?? $body;
        return self::parseJsonpCallback($str);
    }

    /**
     * 写入凭据 conf 行（conf_type=20 / conf_status=0，不在系统设置页展示）
     */
    private static function upsertConf(string $key, string $value, string $title): void
    {
        ThemeService::upsertConfByKey($key, [
            'conf_value' => $value,
            'conf_title' => $title,
            'conf_desc' => '百度小说板块百度账号扫码绑定凭据（模板管理域内部键）',
            'conf_spec' => 0,
            'conf_content' => '',
            'conf_type' => ThemeService::THEME_OVERRIDE_TYPE,
            'conf_status' => 0,
            'conf_sort' => 0,
            'conf_system' => 1,
        ]);
    }

    /**
     * 记录上游异常响应片段
     */
    private static function logUpstream(string $action, string $detail): void
    {
        Log::error('[BaiduNovel] ' . $action . ' 失败：' . $detail);
    }

    /**
     * 诊断日志：直写独立文件（runtime/log/baidu_novel_diag.log），不依赖框架日志通道
     */
    private static function diagLog(string $title, string $content): void
    {
        try {
            $dir = root_path('runtime') . 'log' . DIRECTORY_SEPARATOR;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir . 'baidu_novel_diag.log', '[' . date('Y-m-d H:i:s') . '][' . $title . '] ' . $content . "\n\n", FILE_APPEND);
        } catch (\Throwable $e) {
            // 诊断日志失败不影响主流程
        }
    }
}
