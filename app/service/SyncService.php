<?php

namespace app\service;

use think\facade\Db;
use think\facade\Cache;
use app\model\SyncServer as SyncServerModel;
use app\model\SyncLog as SyncLogModel;

/**
 * 资源同步服务（核心逻辑）
 *
 * 从 SyncServer 控制器提取，供 HTTP 接口和 CLI 定时命令共用。
 * 不依赖 access() 鉴权，调用方需自行确保身份验证。
 */
class SyncService
{
    /**
     * 执行单台服务器的资源同步
     *
     * @param int $serverId 同步服务器 ID
     * @return array 同步结果 [code, message, data]
     */
    public static function run($serverId)
    {
        $id = intval($serverId);
        $lockKey = 'sync_server_lock_' . $id;
        $startTime = time();

        try {
            $model = new SyncServerModel();
            $server = $model->find($id);
            if (empty($server)) {
                return ['code' => 0, 'message' => '服务器不存在', 'data' => []];
            }
            if ($server['status'] != 1) {
                return ['code' => 0, 'message' => '该服务器已禁用', 'data' => []];
            }

            // 并发锁
            if (Cache::get($lockKey)) {
                return ['code' => 0, 'message' => '该服务器正在同步中，请稍后再试', 'data' => []];
            }
            Cache::set($lockKey, 1, 600);

            @set_time_limit(0);
            @ini_set('memory_limit', -1);

            $baseUrl = rtrim($server['domain'], '/');
            $apiKey = $server['api_key'];
            $sinceTime = intval($server['last_sync_time']);

            $queryParams = ['api_key' => $apiKey];
            if ($sinceTime > 0) {
                $queryParams['since_time'] = $sinceTime;
            }
            if (!empty($server['sync_is_type'])) {
                $queryParams['is_type'] = $server['sync_is_type'];
            }

            $newAdded = 0;
            $updated = 0;
            $skipped = 0;
            $failed = 0;
            $failReason = '';
            $total = 0;

            try {
                // 1. 连通性测试
                $pingRes = self::callRemote($baseUrl . '/api/sync/ping', ['api_key' => $apiKey]);
                if ($pingRes === false || ($pingRes['code'] ?? 0) != 200) {
                    throw new \Exception($pingRes['message'] ?? '连通性测试失败，请检查域名和密钥');
                }

                // 2. 获取总数
                $countRes = self::callRemote($baseUrl . '/api/sync/count', $queryParams);
                if ($countRes === false || ($countRes['code'] ?? 0) != 200) {
                    throw new \Exception($countRes['message'] ?? '获取资源总数失败');
                }
                $total = intval($countRes['data']['total'] ?? 0);
                if ($total <= 0) {
                    Cache::delete($lockKey);
                    self::writeLog($id, $server['name'], 0, 0, 0, 0, 0, '没有需要同步的新资源', $startTime, time(), 1);
                    $model->where('sync_server_id', $id)->update(['update_time' => time()]);
                    return ['code' => 1, 'message' => '没有需要同步的新资源', 'data' => ['total' => 0, 'new_added' => 0, 'updated' => 0]];
                }

                // 3. 预加载已有 URL 集合（用于去重）
                $existingUrls = self::getExistingUrls();

                // 4. 预加载分类映射
                $categoryMap = self::parseCategoryMap($server['category_map']);
                $defaultCategoryId = intval($server['default_category_id']);
                $localCategories = null;

                // 5. 分页拉取
                $pageSize = 50;
                $totalPages = ceil($total / $pageSize);

                for ($page = 1; $page <= $totalPages; $page++) {
                    $queryParams['page'] = $page;
                    $queryParams['page_size'] = $pageSize;

                    $listRes = self::callRemote($baseUrl . '/api/sync/lists', $queryParams);
                    if ($listRes === false || ($listRes['code'] ?? 0) != 200) {
                        $failed += $pageSize;
                        $failReason = '第' . $page . '页拉取失败: ' . ($listRes['message'] ?? '未知错误');
                        break;
                    }

                    $items = $listRes['data']['items'] ?? [];
                    foreach ($items as $item) {
                        $url = trim($item['url'] ?? '');
                        $title = trim($item['title'] ?? '');

                        if (empty($url) || empty($title)) {
                            $skipped++;
                            continue;
                        }

                        try {
                            $isType = determineIsType($url);

                            $localCategoryId = self::mapCategory(
                                intval($item['source_category_id'] ?? 0),
                                trim($item['category_name'] ?? ''),
                                $categoryMap,
                                $defaultCategoryId,
                                $localCategories
                            );

                            $localVodPic = self::downloadRemoteCover($baseUrl, $item['vod_pic'] ?? '');

                            $data = [
                                'title'    => mb_substr($title, 0, 255),
                                'url'      => mb_substr($url, 0, 255),
                                'description' => mb_substr(trim($item['description'] ?? ''), 0, 255),
                                'vod_content' => mb_substr(trim($item['vod_content'] ?? ''), 0, 255),
                                'vod_pic'  => $localVodPic,
                                'is_type'  => $isType,
                                'code'     => mb_substr(trim($item['code'] ?? ''), 0, 50),
                                'source_category_id' => $localCategoryId,
                                'is_time'  => 2,
                                'update_time' => time(),
                            ];

                            if (isset($existingUrls[$url])) {
                                Db::name('source')->where('url', $url)->update($data);
                                $updated++;
                            } else {
                                $data['create_time'] = time();
                                $data['status'] = 1;
                                $data['is_delete'] = 0;
                                $data['is_user'] = 0;
                                $data['page_views'] = 0;
                                $data['sort'] = 0;
                                $data['is_top'] = 0;
                                $data['content'] = '';
                                $data['fid'] = '';
                                Db::name('source')->insertGetId($data);
                                $existingUrls[$url] = true;
                                $newAdded++;
                            }
                        } catch (\Exception $e) {
                            $failed++;
                            $failReason = $e->getMessage();
                        }
                    }
                }
            } catch (\Exception $e) {
                $failed++;
                $failReason = $e->getMessage();
            }

            $endTime = time();

            // 更新服务器同步状态
            $model->where('sync_server_id', $id)->update([
                'last_sync_time' => $endTime,
                'last_sync_count' => $newAdded + $updated,
                'total_synced' => $server['total_synced'] + $newAdded,
                'update_time' => $endTime,
            ]);

            // 写入同步日志
            $status = ($failed > 0 && $newAdded + $updated > 0) ? 2 : (($failed > 0) ? 0 : 1);
            self::writeLog($id, $server['name'], $total, $newAdded, $updated, $skipped, $failed, $failReason, $startTime, $endTime, $status);

            Cache::delete($lockKey);

            return [
                'code' => 1,
                'message' => '同步完成',
                'data' => [
                    'total' => $total,
                    'new_added' => $newAdded,
                    'updated' => $updated,
                    'skipped' => $skipped,
                    'failed' => $failed,
                ],
            ];
        } catch (\Exception $e) {
            Cache::delete($lockKey);
            return ['code' => 0, 'message' => '同步失败：' . $e->getMessage(), 'data' => []];
        }
    }

    // ========== 私有辅助方法 ==========

    private static function callRemote($url, $params)
    {
        $fullUrl = $url . '?' . http_build_query($params);
        $res = curlHelper($fullUrl, 'GET', null, [
            'User-Agent: Mozilla/5.0 (compatible; XinyueSync/1.0)',
            'Accept: application/json',
        ], '', '', 30);
        if (!empty($res['error'])) {
            return false;
        }
        $body = $res['body'] ?? '';
        if (empty($body)) {
            return false;
        }
        return json_decode($body, true);
    }

    private static function downloadRemoteCover($baseUrl, $vodPic)
    {
        $vodPic = trim($vodPic ?? '');
        if (empty($vodPic)) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $vodPic)) {
            $imageUrl = $vodPic;
        } else {
            $imageUrl = $baseUrl . '/' . ltrim($vodPic, '/');
        }

        $res = curlHelper($imageUrl, 'GET', null, [
            'User-Agent: Mozilla/5.0 (compatible; XinyueSync/1.0)',
        ], '', '', 20);
        if (!empty($res['error'])) {
            return '';
        }
        $body = $res['body'] ?? '';
        if (empty($body) || strlen($body) < 100) {
            return '';
        }

        $ext = 'jpg';
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($body);
            $mimeMap = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
            ];
            if (isset($mimeMap[$mime])) {
                $ext = $mimeMap[$mime];
            }
        } catch (\Exception $e) {
        }

        $saveDir = public_path() . 'uploads/image/' . date('Ymd');
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $fileName = 'sync_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $filePath = $saveDir . '/' . $fileName;
        file_put_contents($filePath, $body);
        return '/uploads/image/' . date('Ymd') . '/' . $fileName;
    }

    private static function getExistingUrls()
    {
        $urls = Db::name('source')->where('is_delete', 0)->column('url');
        return array_flip($urls);
    }

    private static function parseCategoryMap($json)
    {
        if (empty($json)) {
            return [];
        }
        $map = json_decode($json, true);
        if (!is_array($map)) {
            return [];
        }
        $result = [];
        foreach ($map as $k => $v) {
            $result[strval($k)] = intval($v);
        }
        return $result;
    }

    private static function mapCategory($remoteCategoryId, $remoteCategoryName, $categoryMap, $defaultCategoryId, &$localCategories)
    {
        if (!empty($categoryMap) && isset($categoryMap[strval($remoteCategoryId)])) {
            return $categoryMap[strval($remoteCategoryId)];
        }
        if (!empty($remoteCategoryName)) {
            if ($localCategories === null) {
                $localCategories = Db::name('source_category')
                    ->where('status', 0)
                    ->column('source_category_id', 'name');
            }
            if (isset($localCategories[$remoteCategoryName])) {
                return intval($localCategories[$remoteCategoryName]);
            }
        }
        return $defaultCategoryId;
    }

    private static function writeLog($serverId, $serverName, $total, $newAdded, $updated, $skipped, $failed, $failReason, $startTime, $endTime, $status)
    {
        $log = new SyncLogModel();
        $log->insert([
            'sync_server_id' => $serverId,
            'server_name' => $serverName,
            'total_fetched' => $total,
            'new_added' => $newAdded,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'fail_reason' => mb_substr($failReason, 0, 1000),
            'status' => $status,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'create_time' => time(),
        ]);
    }
}
