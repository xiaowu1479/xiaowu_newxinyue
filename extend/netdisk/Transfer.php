<?php

namespace netdisk;

use app\service\PanBackendService;

/**
 * 网盘转存编排器（薄代理）
 *
 * 具体网盘能力全部委托 pan-backend 统一服务（PanBackendService），
 * 这里只负责业务编排：链接识别 → 预检 → 选号 → 转存 → 广告清理 → 分享。
 *
 * transfer() 签名与响应契约保持不变（调用方零改动）：
 *   - isType=1 只验证：{code:200, data:{title, share_url(原链接), stoken}}
 *   - isType=0 转存并分享：{code:200, data:{title, share_url(新分享), code(提取码), fid(转存目录统一路径)}}
 *   - 失败 jerr2；fid 存统一路径（/{driveType}_{userId}/目录/指纹），
 *     临时资源 30 分钟后 delete_search 按该路径整目录回收。
 */
class Transfer
{
    /** is_type（config/pan_types.php）→ pan-backend driveType（7 天翼/8 移动服务端未启用，暂不支持） */
    const DRIVE_TYPE_MAP = [
        0 => 'quark',
        1 => 'aliyun',
        2 => 'baidu',
        3 => 'uc',
        4 => 'xunlei',
        5 => '123pan',
        6 => '115',
        10 => 'guangya',
    ];

    public function __construct()
    {
        $this->url = ""; // 资源地址
        $this->expired_type = 1; //有效期 1分享永久 2临时
        $this->code = ""; //分享码
        $this->isType = 0; //0 转存并分享后的资源信息  1直接获取资源信息
    }

    public function transfer($urlData = [])
    {
        $url = $urlData['url'] ?? '';

        if (empty($url)) {
            return jerr2('资源地址为空');
        }

        // 剥离 ?entry= 跟踪参数
        if (strpos($url, '?entry=') !== false) {
            $url = preg_match('/.*(?=\?entry=)/', $url, $matches) ? $matches[0] : '';
        }

        // 确保 URL 包含协议前缀
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        if (strstr($url, 's/') === false) {
            return jerr2('资源地址格式有误，缺少 s/ 标识');
        }

        // 按域名匹配网盘类型（config/pan_types.php）
        $url_type = -1;
        foreach (config('pan_types') as $panConfig) {
            $domains = $panConfig['domain'] ?? [];
            if (!is_array($domains)) {
                $domains = [$domains];
            }
            foreach ($domains as $domain) {
                if (!empty($domain) && strpos($url, $domain) !== false) {
                    $url_type = intval($panConfig['id']);
                    break 2;
                }
            }
        }
        if ($url_type === -1) {
            return jerr2('资源地址格式有误，不支持的网盘类型');
        }
        $driveType = self::DRIVE_TYPE_MAP[$url_type] ?? '';
        if ($driveType === '') {
            return jerr2('该网盘类型暂不支持转存');
        }

        $this->url = $url;
        $this->code = strval($urlData['code'] ?? input('code') ?? '');
        $this->isType = intval($urlData['isType'] ?? input('isType') ?? 0);
        $this->expired_type = intval($urlData['expired_type'] ?? input('expired_type') ?? 1);

        // 只验证：返回资源信息，share_url 回原链接（与旧实现一致）
        if ($this->isType == 1) {
            $res = PanBackendService::shareInfo($url, $this->code);
            if (!$res['ok']) {
                return jerr2($res['message'] !== '' ? $res['message'] : '资源地址无效');
            }
            $info = is_array($res['data']) ? $res['data'] : [];
            return jok2('获取成功', [
                'title' => strval($info['title'] ?? ''),
                'share_url' => $url,
                'stoken' => strval($info['stoken'] ?? ''),
            ]);
        }

        return $this->saveAndShare($url, $driveType);
    }

    /**
     * 转存并分享编排
     */
    private function saveAndShare($url, $driveType)
    {
        // 1. 预检分享信息：链接失效提前返回，顺带取原资源标题
        $info = PanBackendService::shareInfo($url, $this->code);
        if (!$info['ok']) {
            return jerr2($info['message'] !== '' ? $info['message'] : '资源地址无效');
        }
        $infoData = is_array($info['data']) ? $info['data'] : [];
        $title = strval($infoData['title'] ?? '');

        // 2. 选取该类型首个可用账户
        $account = PanBackendService::firstAccount($driveType);
        if ($account === null) {
            return jerr2('暂无该网盘类型的可用转存账户');
        }

        // 3. 子目录隔离转存：/{account}/{保存目录}/{url指纹}
        $accountPrefix = '/' . $driveType . '_' . $account['userId'];
        $destPath = $accountPrefix . '/' . $this->saveDir() . '/' . substr(md5($url), 0, 12);
        $save = PanBackendService::save($url, $destPath, $this->code);
        if (!$save['ok']) {
            return jerr2($save['message'] !== '' ? $save['message'] : '转存失败');
        }

        // 4. 清理广告文件（全部为广告时视作空资源终止）
        if (!$this->cleanAds($destPath, $accountPrefix)) {
            return jerr2('资源内容为空');
        }

        // 5. 创建分享（临时资源 1 天有效，正式资源用网盘默认/永久）
        $expireDays = $this->expired_type == 2 ? 1 : 0;
        $share = PanBackendService::share($destPath, $title, $expireDays);
        if (!$share['ok']) {
            return jerr2($share['message'] !== '' ? $share['message'] : '分享创建失败');
        }

        return jok2('获取成功', [
            'title' => $title,
            'share_url' => strval($share['data']['shareUrl'] ?? ''),
            'code' => strval($share['data']['passcode'] ?? ''),
            'fid' => $destPath,
        ]);
    }

    /** 转存根目录名（qfshop.pan_backend_save_dir，缺省「转存」） */
    private function saveDir()
    {
        $dir = trim(strval(Config('qfshop.pan_backend_save_dir') ?? ''));
        return $dir !== '' ? $dir : '转存';
    }

    /**
     * 清理转存目录中的广告文件
     * @param string $destPath 转存目录统一路径
     * @param string $accountPrefix 账户前缀 /{driveType}_{userId}
     * @return bool true=有保留文件可继续分享；false=全部为广告或空，目录已删除
     */
    private function cleanAds($destPath, $accountPrefix)
    {
        $banned = trim(strval(Config('qfshop.pan_backend_banned') ?? Config('qfshop.quark_banned') ?? ''));
        $ls = PanBackendService::ls($destPath, 1, 0, true);
        $list = is_array($ls['data']['list'] ?? null) ? $ls['data']['list'] : [];
        if (empty($list)) {
            PanBackendService::deleteFile($destPath);
            return false;
        }

        $bannedList = $banned !== '' ? explode(',', $banned) : [];
        $keep = 0;
        foreach ($list as $file) {
            // file_path 为账户内路径，兼容服务端已返回完整路径的形态
            $filePath = strval($file['file_path'] ?? '');
            if ($filePath === '') {
                continue;
            }
            $fullPath = strpos($filePath, $accountPrefix . '/') === 0 ? $filePath : $accountPrefix . $filePath;

            $name = strval($file['file_name'] ?? '');
            foreach ($bannedList as $word) {
                $word = trim(strval($word));
                if ($word !== '' && strpos($name, $word) !== false) {
                    PanBackendService::deleteFile($fullPath);
                    continue 2;
                }
            }
            $keep++;
        }

        if ($keep <= 0) {
            PanBackendService::deleteFile($destPath);
            return false;
        }
        return true;
    }

    /**
     * 删除转存内容（fid 已存统一路径，按路径逐个删除）
     * @param array $filelist 路径列表
     */
    public function deletepdirFid($filelist)
    {
        foreach ((array)$filelist as $path) {
            $path = trim(strval($path));
            if ($path !== '') {
                PanBackendService::deleteFile($path);
            }
        }
    }
}
