<?php

namespace app\api\controller;

use think\App;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Request;
use think\facade\Log;
use app\api\QfShop;
use app\model\Source as SourceModel;
use app\model\Days as DaysModel;
use app\model\ApiList as ApiListModel;
use Lizhichao\Word\VicWord;
use app\service\NovelTransferService;
use app\service\PanBackendService;

class Other extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new SourceModel();
        $this->ApiListModel = new ApiListModel();
    }

    /**
     * 全网搜索 该接口用户网页端使用
     * 
     * @return void
     */
    public function web_search()
    {
        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 防止 Nginx 缓冲

        $title = input('title', '');

        // 被屏蔽的关键词，用逗号分隔
        $banKeywords = explode(',', Config('qfshop.ban_keywords'));
        // 检查$name是否包含屏蔽关键词
        $blocked = false;
        foreach ($banKeywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword !== '' && mb_strpos($title, $keyword) !== false) {
                $blocked = true;
                break;
            }
        }

        $is_type_input = input('is_type', 0); //0夸克 1阿里云盘 2百度 3Uc 4迅雷，支持数组或逗号分隔
        $is_show = input('is_show', 0); //0加密网址  1显示网址

        // 处理 is_type，支持数组或逗号分隔字符串
        $is_type_list = [];
        if (is_array($is_type_input)) {
            $is_type_list = array_values(array_unique(array_map('intval', $is_type_input)));
        } elseif (is_string($is_type_input) && strpos($is_type_input, ',') !== false) {
            $is_type_list = array_values(array_unique(array_map('intval', explode(',', $is_type_input))));
        } elseif ($is_type_input !== '' && is_numeric($is_type_input)) {
            $is_type_list = [intval($is_type_input)];
        }
        if (empty($is_type_list)) {
            $is_type_list = [0];
        }
        $is_type_str = implode(',', $is_type_list);

        // 小说聚合搜索最慢（no-novel-node 多书源并发，兜底 35s），调整到其他线路之后处理，
        // 避免 SSE 前段输出被阻塞（前端先到先显示；缓存 key 用 $is_type_str 不受顺序影响）
        $novelKey = array_search(11, $is_type_list);
        if ($novelKey !== false) {
            unset($is_type_list[$novelKey]);
            $is_type_list[] = 11;
        }

        // 生成缓存 key（10 分钟）
        $cacheKey = 'web_search_' . md5($title . '|' . $is_type_str . '|' . $is_show);
        $cachedLines = Cache::get($cacheKey);
        if (is_array($cachedLines) && !empty($cachedLines)) {
            foreach ($cachedLines as $line) {
                echo $line;
            }
            ob_flush();
            flush();
            exit;
        }

        // 输出并收集到缓存
        $outputLines = [];
        $sendLine = function($line) use (&$outputLines) {
            echo $line;
            ob_flush();
            flush();
            $outputLines[] = $line;
        };

        if (empty($title) || $blocked) {
            $sendLine("data: [DONE] 无搜索词\n\n");
            Cache::set($cacheKey, $outputLines, 600);
            exit;
        }

        // 记录搜索统计到 qf_feedback 表
        if (!empty($title)) {
            $panTypeMap = config('pan_types');
            $panTypeNames = [];
            foreach ($is_type_list as $type) {
                $panTypeNames[] = $panTypeMap[$type]['name'] ?? '未知';
            }
            $panTypeName = implode(',', $panTypeNames);
            
            $FeedbackModel = new \app\model\Feedback();
            $FeedbackModel->save([
                'content' => '[' . $panTypeName . '] ' . $title,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }

        $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        $logMsg = "[web_search] is_type=$is_type_str, title=$title";
        Log::info($logMsg);
        // 确保日志目录存在
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $logMsg . "\n", FILE_APPEND);

        // 定义输出单条数据的回调函数
        $outputCallback = function($item, $lineName) use ($is_show, $logFile, $sendLine) {
            // 小说线路已经预置 is_type=11，不要被 determineIsType 覆盖
            if (empty($item['is_type']) || $item['is_type'] != 11) {
                $item['is_type'] = determineIsType($item['url']);
            }
            $logMsg = date('Y-m-d H:i:s') . " [web_search] Processing item: title=" . $item['title'] . ", url=" . substr($item['url'], 0, 50) . "..., is_type=" . $item['is_type'] . "\n";
            file_put_contents($logFile, $logMsg, FILE_APPEND);
            
            if (Config('qfshop.is_quan_zc') == 1 && $item['is_type'] != 11) {
                //检测是否有效 - 支持夸克和阿里网盘（小说为普通网页链接，跳过网盘有效性验证）
                $infoData = verificationUrl($item['url'], $item['is_type']);
                if (!empty($infoData['stoken'])) {
                    $item['stoken'] = $infoData['stoken'];
                }
                if ($infoData === 0) {
                    $logMsg = date('Y-m-d H:i:s') . " [web_search] Item verification failed but still outputting: " . $item['url'] . "\n";
                    file_put_contents($logFile, $logMsg, FILE_APPEND);
                    // 测试模式下，即使验证失败也输出数据
                    $item['verification_status'] = 'failed';
                } else {
                    $item['verification_status'] = 'success';
                }
            }
            if (config('qfshop.is_quan_type') != 1 && $is_show != 1 && $this->hasPanAccount($item['is_type'])) {
                $item['url'] = encryptObject($item['url']);
            }
            $sendLine("data: " . str_replace(["\n", "\r"], '', json_encode($item, JSON_UNESCAPED_UNICODE)) . "\n\n");
            return 1;
        };

        // 遍历每种选中的网盘类型
        $hasAnyLine = false;
        foreach ($is_type_list as $is_type) {
            // 查找该类型的可用线路
            $lines = $this->ApiListModel->where('status', 1)->where('pantype', $is_type)->order('weight desc')->select()->toArray();

            Log::info("[web_search] Found " . count($lines) . " lines for pantype=$is_type");

            // 获取自定义线路并合并到线路列表前面
            $lines = array_merge($this->getCustomLines(), $lines);

            if (!$lines || count($lines) == 0) {
                Log::error("[web_search] No available lines for pantype=$is_type");
                continue;
            }
            $hasAnyLine = true;

            foreach ($lines as $line) {
                $sendLine("线路：" . $line['name'] . "\n\n");
                $type = $line['type'] ?? 'api';
                $logMsg = date('Y-m-d H:i:s') . " [web_search] Processing line: " . $line['name'] . ", type=$type\n";
                file_put_contents($logFile, $logMsg, FILE_APPEND);
                
                $outputCount = 0;
                if ($type === 'tg') {
                    $outputCount = $this->handleTg($line, $title, $outputCallback);
                } else if ($type === 'api') {
                    $outputCount = $this->handleApi($line, $title, $outputCallback);
                } else if ($type === 'html') {
                    $outputCount = $this->handleWeb($line, $title, $outputCallback);
                } else if ($type === 'kk') {
                    $outputCount = $this->handleKk($line, $title, $line['num'], $outputCallback);
                } else if ($type === 'novel') {
                    $outputCount = $this->handleNovel($line, $title, $outputCallback);
                }
                
                $logMsg = date('Y-m-d H:i:s') . " [web_search] Output $outputCount items for line: " . $line['name'] . "\n";
                file_put_contents($logFile, $logMsg, FILE_APPEND);
            }
        }

        if (!$hasAnyLine) {
            $sendLine("data: [DONE] 暂无可用线路\n\n");
        } else {
            $sendLine("data: [DONE]\n\n");
        }
        Cache::set($cacheKey, $outputLines, 600);
        exit;
    }

    /**
     * 检查本地资源是否存在
     * 用于首页搜索时优先展示本地资源
     * 
     * @return \think\Response
     */
    public function local_search_check()
    {
        try {
            $title = input('title', '');
            $isTypeInput = input('is_type', '');
            
            if (empty($title)) {
                return jok('检查完成', ['hasData' => false, 'count' => 0, 'items' => []]);
            }

            // 处理 is_type，支持数组或逗号分隔字符串
            $isTypeList = [];
            if (is_array($isTypeInput)) {
                $isTypeList = array_values(array_unique(array_map('intval', $isTypeInput)));
            } elseif (is_string($isTypeInput) && strpos($isTypeInput, ',') !== false) {
                $isTypeList = array_values(array_unique(array_map('intval', explode(',', $isTypeInput))));
            } elseif ($isTypeInput !== '' && is_numeric($isTypeInput)) {
                $isTypeList = [intval($isTypeInput)];
            }

            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0]; // 只查询永久资源，不查询临时资源
            
            // 简单模糊查询（description 字段才是用于搜索的副标题字段）
            $map[] = ['title|description', 'like', '%' . trim($title) . '%'];
            $query = $this->model->where($map);

            // 如果指定了网盘类型，添加类型过滤
            if (!empty($isTypeList)) {
                $query->whereIn('is_type', $isTypeList);
            }

            $items = $query->field('source_id, title, description, url, is_type, code, update_time, status')
                ->order('source_id', 'desc')
                ->limit(20)
                ->select()
                ->toArray();

            // 格式化返回数据，与全网搜格式保持一致
            $formattedItems = [];
            $panTypeMap = config('pan_types');
            
            foreach ($items as $item) {
                $updateTime = isset($item['update_time']) && is_numeric($item['update_time']) ? intval($item['update_time']) : time();
                $formattedItems[] = [
                    'title' => $item['title'],
                    'desc' => $item['description'] ?? '',
                    'url' => $item['url'],
                    'is_type' => intval($item['is_type'] ?? 0),
                    'code' => $item['code'] ?? '',
                    'datetime' => date('Y-m-d H:i:s', $updateTime),
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源',
                    'source' => '本地资源'
                ];
            }

            return jok('检查完成', [
                'hasData' => count($formattedItems) > 0,
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('查询失败: ' . $e->getMessage() . ' | 文件: ' . $e->getFile() . ' | 行: ' . $e->getLine());
        }
    }

    /**
     * 获取最近更新的本地资源
     * 
     * @return void
     */
    public function get_latest_sources()
    {
        try {
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];

            $list = $this->model->where($map)
                ->field('source_id as id, title, url, create_time as time, is_type')
                ->order('source_id', 'desc')
                ->limit(9)
                ->select()
                ->toArray();

            $formattedItems = [];
            foreach ($list as $item) {
                // 处理时间字段，可能是时间戳或字符串
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源'
                ];
            }

            return jok('获取成功', [
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取热门搜索关键词（从 feedback 表统计搜索次数最高的关键词）
     *
     * @return void
     */
    public function get_hot_keywords()
    {
        try {
            $limit = (int)input('limit', 10);
            if ($limit <= 0 || $limit > 50) $limit = 10;

            $FeedbackModel = new \app\model\Feedback();
            // feedback 表的 content 格式为 "[网盘类型] 关键词"，需要提取关键词部分后聚合
            $list = $FeedbackModel
                ->fieldRaw("TRIM(SUBSTRING(content, LOCATE(']', content) + 1)) as keyword, COUNT(*) as cnt")
                ->where('content', '<>', '')
                ->group('keyword')
                ->order('cnt', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();

            $items = [];
            foreach ($list as $row) {
                if (!empty($row['keyword'])) {
                    $items[] = [
                        'keyword' => $row['keyword'],
                        'count' => (int)$row['cnt']
                    ];
                }
            }

            return jok('获取成功', ['items' => $items]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取热门搜索资源（按浏览量排序，浏览量为0时按时间排序）
     * 
     * @return void
     */
    public function get_hot_sources()
    {
        try {
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];
            
            // 先尝试获取有浏览量的资源（按浏览量降序）
            $hotList = $this->model->where($map)
                ->where('page_views', '>', 0)
                ->field('source_id as id, title, url, create_time as time, is_type, page_views')
                ->order('page_views', 'desc')
                ->limit(9)
                ->select()
                ->toArray();
            
            $formattedItems = [];
            
            // 如果有浏览量的资源不足9条，补充最新的资源
            if (count($hotList) < 9) {
                // 获取已有资源的ID列表
                $existingIds = array_column($hotList, 'id');
                
                // 补充最新资源
                $additionalMap = $map;
                if (!empty($existingIds)) {
                    $additionalMap[] = ['source_id', 'not in', $existingIds];
                }
                
                $additionalList = $this->model->where($additionalMap)
                    ->field('source_id as id, title, url, create_time as time, is_type, page_views')
                    ->order('source_id', 'desc')
                    ->limit(9 - count($hotList))
                    ->select()
                    ->toArray();
                
                $hotList = array_merge($hotList, $additionalList);
            }
            
            foreach ($hotList as $item) {
                // 处理时间字段
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源',
                    'page_views' => $item['page_views'] ?? 0
                ];
            }
            
            return jok('获取成功', [
                'count' => count($formattedItems),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取最近更新的本地资源（分页）
     * 
     * @return void
     */
    public function get_latest_sources_page()
    {
        try {
            $page = input('page', 1);
            $pageSize = input('page_size', 20);
            $panType = input('pan_type', '');
            $categoryId = input('category_id', '');
            
            $panTypeMap = config('pan_types');
            
            $map = [];
            $map[] = ['status', '=', 1];
            $map[] = ['is_delete', '=', 0];
            $map[] = ['is_time', '=', 0];
            
            // 如果指定了网盘类型，添加筛选条件
            if ($panType !== '' && is_numeric($panType)) {
                $map[] = ['is_type', '=', intval($panType)];
            }
            
            // 如果指定了分类，添加筛选条件
            if ($categoryId !== '' && is_numeric($categoryId)) {
                $map[] = ['source_category_id', '=', intval($categoryId)];
            }

            // 获取总数
            $total = $this->model->where($map)->count();

            // 获取分页数据
            $list = $this->model->where($map)
                ->field('source_id as id, title, url, create_time as time, is_type, source_category_id')
                ->order('create_time', 'desc')
                ->page($page, $pageSize)
                ->select()
                ->toArray();

            $formattedItems = [];
            foreach ($list as $item) {
                // 处理时间字段，可能是时间戳或字符串
                $time = $item['time'];
                if (is_numeric($time)) {
                    $datetime = date('Y-m-d H:i:s', $time);
                } else {
                    $datetime = $time;
                }
                
                $formattedItems[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'datetime' => $datetime,
                    'is_type' => $item['is_type'],
                    'pantype' => $panTypeMap[$item['is_type']]['name'] ?? '网盘资源'
                ];
            }

            return jok('获取成功', [
                'total' => $total,
                'page' => intval($page),
                'page_size' => intval($pageSize),
                'items' => $formattedItems
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 全网搜索 该接口仅用于机器人和微信对话时使用
     * 
     * @return void
     */
    public function all_search($param = '')
    {
        $title = $param ?: input('title', '');
        if (empty($title)) {
            return jerr("请输入要看的内容");
        }
        $is_type = 0; //0夸克  2百度

        $map[] = ['status', '=', 1];
        $map[] = ['is_delete', '=', 0];
        $map[] = ['is_time', '=', 1];
        $map[] = ['title|description', 'like', '%' . trim($title) . '%'];

        $urls = $this->model->where($map)->field('source_id as id, title, url,is_time')->order('update_time', 'desc')->limit(5)->select()->toArray();
        if (!empty($urls)) {
            $ids = array_column($urls, 'id');
            $this->model->whereIn('source_id', $ids)->update(['update_time' => time()]);
            return !empty($param) ? $urls : jok('临时资源获取成功', $urls);
        }

        //同一个搜索内容锁机
        if (Cache::has($title)) {
            // 检查缓存中是否已有结果
            return !empty($param) ? Cache::get($title) : jok('临时资源获取成功', Cache::get($title));
        }

        // 检查是否有正在处理的请求
        if (Cache::has($title . '_processing')) {
            // 如果当前正在处理相同关键词的请求，等待结果
            $startTime = time(); // 记录开始时间
            while (Cache::has($title . '_processing')) {
                usleep(1000000); // 暂停1秒

                // 检查是否超过60秒
                if (time() - $startTime > 60) {
                    return !empty($param) ? [] : jok('临时资源获取成功', []);
                }
            }
            return !empty($param) ? Cache::get($title) : jok('临时资源获取成功', Cache::get($title));
        }

        // 设置处理状态为正在处理
        Cache::set($title . '_processing', true, 60); // 锁定60秒


        $typeV = input('type', 0);

        $searchList = []; //查询的结果集
        $datas = []; //最终转存后的数据
        $num_total = 2; //最多想要几条转存后的结果
        $num_success = 0;

        $datas_zc = []; //最终未转存的数据
        $num_total_zc = $typeV == 1 ? 3 : 0; //最多想要几条未转存的结果
        $num_success_zc = 0;

        // 查找一条可用线路
        $lines = $this->ApiListModel->where('status', 1)->where('pantype', $is_type)->order('weight desc')->select()->toArray();;

        // 获取自定义线路并合并到线路列表前面
        $lines = array_merge($this->getCustomLines(), $lines);

        if (!$lines || count($lines) == 0) {
            Cache::set($title, $datas, 60); // 缓存结果60秒
            Cache::delete($title . '_processing'); // 解锁
            return !empty($param) ? $datas : jok('临时资源获取成功', $datas);
        }

        foreach ($lines as $line) {
            if ($num_success >= $num_total && $num_success_zc >= $num_total_zc) {
                break;
            }
            $result = [];
            $type = $line['type'] ?? 'api';
            if ($type === 'tg') {
                $result = $this->handleTg($line, $title);
            } else if ($type === 'api') {
                $result = $this->handleApi($line, $title);
            } else if ($type === 'html') {
                $result = $this->handleWeb($line, $title);
            } else if ($type === 'kk') {
                $result = $this->handleKk($line, $title, $line['num']);
            } else if ($type === 'novel') {
                $result = $this->handleNovel($line, $title);
            }

            foreach ($result as $item) {
                // 确定网盘类型
                $item['is_type'] = determineIsType($item['url']);
                
                if ($num_success < $num_total) {
                    //检测是否有效 - 支持夸克和阿里网盘
                    $infoData = verificationUrl($item['url'], $item['is_type']);
                    if (!empty($infoData['stoken'])) {
                        $item['stoken'] = $infoData['stoken'];
                    }
                    if ($infoData !== 0) {
                        if (!$this->urlExists($searchList, $item['url'])) {
                            $searchList[] = $item;
                            $this->processUrl($item, $num_success, $datas);
                        }
                    }
                } else if ($num_success_zc < $num_total_zc) {
                    //检测是否有效 - 支持夸克和阿里网盘
                    $infoData = verificationUrl($item['url'], $item['is_type']);
                    if (!empty($infoData['stoken'])) {
                        $item['stoken'] = $infoData['stoken'];
                    }
                    if ($infoData !== 0) {
                        if (!$this->urlExists($searchList, $item['url'])) {
                            $titles = array_column($searchList, 'title');
                            if (!in_array($item['title'], $titles)) {
                                $searchList[] = $item;
                                $datas_zc[] = $item;
                                $num_success_zc++;
                            }
                        }
                    }
                }
            }
        }
        Cache::set($title, $datas, 60); // 缓存结果60秒
        Cache::delete($title . '_processing'); // 解锁

        if ($typeV == 1) {
            $datas = array_merge($datas, $datas_zc);
        }

        return !empty($param) ? $datas : jok('临时资源获取成功', $datas);
    }

    /**
     * 获取自定义线路配置
     * @return array 自定义线路数组
     */
    private function getCustomLines()
    {
        // 自定义线路 - 线路一
        // $customLines = array_map(function ($i) {
        //     return [
        //         'name' => '自定义线路一',
        //         'pantype' => 0,
        //         'type' => 'kk',
        //         'count' => 5,
        //         'num' => $i,
        //     ];
        // }, range(1, 6));

        // 可以在这里添加更多自定义线路
        // 例如：
        /*
        $customLines[] = [
            'name' => '自定义线路二',
            'pantype' => 0,
            'type' => 'GG',
            'count' => 5,
        ];
        */
        return $customLines ?? [];
    }

    /**
     * 接口类型处理
     */
    private function handleApi($line, $title, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        // 从配置文件读取网盘类型
        $panTypes = config('pan_types');
        $panType = [];
        foreach ($panTypes as $config) {
            $panType[$config['id']] = $config['pan_type'] ?? '';
        }

        if (!isset($panType[$type]) || $maxCount <= 0) {
            return [];
        }

        $url     = $line['url'];
        $method  = strtoupper($line['method']);
        $headers = json_decode($line['headers'], true) ?? [];
        $params  = json_decode($line['fixed_params'], true) ?? [];

        // 替换 {keyword}
        foreach ($params as &$val) {
            $val = str_replace('{keyword}', $title, $val);
        }

        // headers 转为 curl 格式
        $headerArr = [];
        foreach ($headers as $k => $v) {
            $headerArr[] = "$k: $v";
        }

        // 确保POST请求有正确的Content-Type
        if ($method === 'POST' && !isset($headers['Content-Type'])) {
            $headerArr[] = "Content-Type: application/x-www-form-urlencoded";
        }

        // 简化参数处理
        $queryParams = $method === 'GET' ? $params : [];

        // 处理POST数据
        if ($method === 'POST' && !empty($params)) {
            $postData = http_build_query($params);
            $result = curlHelper($url, $method, $postData, $headerArr, $queryParams);
        } else {
            $result = curlHelper($url, $method, $method === 'GET' ? null : $params, $headerArr, $queryParams);
        }

        $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        
        if (empty($result['body'])) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] Empty response body from: " . $url . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
            return [];
        }

        $fieldMap = json_decode($line['field_map'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] field_map JSON decode error: " . json_last_error_msg() . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }

        $response = json_decode($result['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errMsg = date('Y-m-d H:i:s') . " [handleApi] Response JSON decode error: " . json_last_error_msg() . "\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
            return [];
        }

        // 调试日志：记录响应结构
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] type=$type, Response keys: " . json_encode(array_keys($response)) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] field_map: " . json_encode($fieldMap) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Response body preview: " . substr($result['body'], 0, 500) . "\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);

        $results = $this->extractList($response, $fieldMap, $type, $logFile);
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Extracted " . count($results) . " results, maxCount=$maxCount\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);
        
        $returnResults = array_slice($results, 0, $maxCount);
        
        // 如果提供了回调函数，逐条输出
        if ($outputCallback && is_callable($outputCallback)) {
            $outputCount = 0;
            foreach ($returnResults as $item) {
                $outputCount += $outputCallback($item, $line['name'] ?? 'API线路');
            }
            return $outputCount;
        }
        
        $logMsg = date('Y-m-d H:i:s') . " [handleApi] Returning " . count($returnResults) . " results\n";
        file_put_contents($logFile, $logMsg, FILE_APPEND);

        return $returnResults;
    }

    /**
     * 提取字段
     */
    protected function extractList($response, $fieldMap, $type, $logFile = '')
    {
        if (empty($logFile)) {
            $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        }
        
        // 调试日志
        if (empty($fieldMap['list_path'])) {
            $errMsg = date('Y-m-d H:i:s') . " [extractList] field_map.list_path is empty\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }
        if (empty($fieldMap['fields'])) {
            $errMsg = date('Y-m-d H:i:s') . " [extractList] field_map.fields is empty\n";
            file_put_contents($logFile, $errMsg, FILE_APPEND);
        }
        
        $listPath = explode('.', $fieldMap['list_path'] ?? '');
        $listData = $response;
        foreach ($listPath as $key) {
            if (empty($key)) continue; // 跳过空键
            if (isset($listData[$key])) {
                $listData = $listData[$key];
            } else {
                $errMsg = date('Y-m-d H:i:s') . " [extractList] list_path key not found: " . $key . ", available keys: " . json_encode(array_keys($listData)) . "\n";
                file_put_contents($logFile, $errMsg, FILE_APPEND);
                return [];
            }
        }

        $fields = $fieldMap['fields'] ?? [];
        $result = [];
        foreach ($listData as $item) {
            $row = [];
            foreach ($fields as $targetKey => $sourcePath) {
                $value = $item;
                foreach (explode('.', $sourcePath) as $p) {
                    $value = $value[$p] ?? null;
                }
                $row[$targetKey] = $value;

                if ($targetKey == 'url') {
                    // 将任何类型的值转换为字符串
                    $stringValue = '';

                    if (is_array($value)) {
                        // 原始数组转字符串
                        $stringValue = json_encode($value, JSON_UNESCAPED_UNICODE);
                        // JSON 中链接中的 / 会变成 \/，替换回来
                        $stringValue = str_replace('\/', '/', $stringValue);
                    } else {
                        $stringValue = (string)$value;
                    }

                    $urlPattern = getPanUrlPattern($type);
                    $logMsg = date('Y-m-d H:i:s') . " [extractList] type=$type, urlPattern=$urlPattern, stringValue=" . substr($stringValue, 0, 200) . "\n";
                    file_put_contents($logFile, $logMsg, FILE_APPEND);

                    if (!empty($urlPattern) && preg_match($urlPattern, $stringValue, $urlMatch)) {
                        $row['url'] = trim($urlMatch[0]);
                        $logMsg = date('Y-m-d H:i:s') . " [extractList] URL matched: " . $row['url'] . "\n";
                        file_put_contents($logFile, $logMsg, FILE_APPEND);

                        if (in_array($type, [2, 4])) {
                            if (!strpos($row['url'], '?pwd=') && preg_match('/["\'](pwd|code)["\']\s*:\s*["\']([^"\']+)["\']/', $stringValue, $pwdMatches)) {
                                $row['url'] .= '?pwd=' . $pwdMatches[2];
                            }
                        } else if ($type == 5) {
                            if (!strpos($row['url'], '?提取码:') && preg_match('/["\']?提取码["\']?\s*[:：]\s*["\']?([a-zA-Z0-9]+)["\']?/', $stringValue, $pwdMatches)) {
                                $row['url'] .= '?提取码:' . $pwdMatches[1];
                            }
                        }
                    } else {
                        $logMsg = date('Y-m-d H:i:s') . " [extractList] URL not matched for type=$type\n";
                        file_put_contents($logFile, $logMsg, FILE_APPEND);
                        $row['url'] = '';
                    }
                }
            }
            if (!empty($row['url'])) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * TG频道类型处理
     */
    private function handleTg($line, $title, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        // 根据类型选择搜索参数
        $panType = [
            0 => 'quark',   // 夸克
            1 => 'alipan',  // 阿里云盘
            2 => 'baidu',    // 百度
            3 => 'uc',       // UC
            4 => 'xunlei',   // 迅雷
            5 => 'pan123',   // 123网盘
            6 => 'pan115',   // 115网盘
        ];

        if (!isset($panType[$type]) || $maxCount <= 0) {
            return $outputCallback ? 0 : [];
        }

        $results = [];
        $outputCount = 0;
        $url = 'https://t.me/s/' . $line['url'] . '?q=' . urlencode($title);
        $dom = getDom($url);
        $finder = new \DomXPath($dom);

        $nodes = $finder->query('//div[contains(@class, "tgme_widget_message_text")]');

        foreach ($nodes as $node) {
            // 获取 HTML 内容
            $htmlContent = $dom->saveHTML($node);

            if (preg_match('/名称：(.+?)<br/i', $htmlContent, $titleMatch)) {
                $parsedItem['title'] = trim(html_entity_decode(strip_tags($titleMatch[1]), ENT_QUOTES, 'UTF-8'));
            } else {
                $parsedItem['title'] = $title;
            }

            // 提取夸克链接（可支持百度扩展）
            $parsedItem['url'] = '';
            $urlPattern = getPanUrlPattern($type);
            if (!empty($urlPattern) && preg_match($urlPattern, $htmlContent, $urlMatch)) {
                $parsedItem['url'] = trim($urlMatch[0]);
            }

            // 过滤不合法或无效链接
            if ($parsedItem['title'] && $parsedItem['url']) {
                if ($outputCallback && is_callable($outputCallback)) {
                    $outputCount += $outputCallback($parsedItem, $line['name'] ?? 'TG线路');
                } else {
                    $results[] = $parsedItem;
                }
            }

            if ($outputCallback) {
                if ($outputCount >= $maxCount) {
                    return $outputCount;
                }
            } else {
                if (count($results) >= $maxCount) {
                    return $results;
                }
            }
        }
        return $outputCallback ? $outputCount : $results;
    }


    /**
     * 网页类型处理
     */
    private function handleWeb($line, $title, $outputCallback = null)
    {
        $results = [];
        $outputCount = 0;

        // 替换搜索关键词并获取配置参数
        $url = str_replace('{keyword}', urlencode($title), $line['url']);

        $parts = explode('+', $line['html_item'], 2);
        $tag = $parts[0] ?? '';
        $classString = $parts[1] ?? '';

        $partsTitle = explode('+', $line['html_title'], 2);
        $tagTitle = $partsTitle[0] ?? '';
        $classStringTitle = $partsTitle[1] ?? '';

        $partsUrl = explode('+', $line['html_url2'], 2);
        $tagUrl = $partsUrl[0] ?? '';
        $classStringUrl = $partsUrl[1] ?? '';

        $maxCount = $line['count'] ?? 10;
        $type = $line['pantype'];

        // 定义网盘链接匹配规则
        $panPatterns = [
            0 => '/https:\/\/pan\.quark\.cn\/s\/[a-zA-Z0-9]+/', // 夸克
            1 => '/https:\/\/(www\.alipan\.com|www\.aliyundrive\.com)\/s\/[a-zA-Z0-9]+/', // 阿里云盘
            2 => '/https:\/\/pan\.baidu\.com\/s\/[a-zA-Z0-9_-]+/', // 百度
            3 => '/https:\/\/drive\.uc\.cn\/s\/[a-zA-Z0-9]+/', // UC
            4 => '/https:\/\/pan\.xunlei\.com\/s\/[a-zA-Z0-9_-]+/', // 迅雷
            5 => '/https:\/\/123\d{3}\.com\/s\/[a-zA-Z0-9-]+/', // 123网盘
        ];

        // 获取DOM并设置XPath查询
        $dom = getDom($url);
        if (!$dom) {
            return $results;
        }

        $finder = new \DomXPath($dom);
        $xpath = $this->buildXPathQuery($tag, $classString);
        $nodes = $finder->query($xpath);

        foreach ($nodes as $node) {
            if ($outputCallback) {
                if ($outputCount >= $maxCount) {
                    break;
                }
            } else {
                if (count($results) >= $maxCount) {
                    break;
                }
            }

            $html = $dom->saveHTML($node);
            $item = [
                'title' => '',
                'url'   => '',
            ];

            // 提取资源标题
            $item['title'] = $this->extractTitle($html, $tagTitle, $classStringTitle);

            // 尝试直接从当前HTML中提取网盘链接
            if (preg_match($panPatterns[$type], $html, $match)) {
                $item['url'] = trim($match[0]);
            } else {
                // 根据配置决定是否需要进入详情页
                if ($line['html_type'] == 1) {
                    $item['url'] = $this->extractUrlFromDetailPage($html, $line, $url, $tagUrl, $classStringUrl, $panPatterns[$type]);
                } else {
                    $item['url'] = $this->extractUrlFromListPage($html, $tagUrl, $classStringUrl, $panPatterns[$type]);
                }
            }

            // 只添加同时有标题和URL的结果
            if ($item['title'] && $item['url']) {
                if ($outputCallback && is_callable($outputCallback)) {
                    $outputCount += $outputCallback($item, $line['name'] ?? '网页线路');
                } else {
                    $results[] = $item;
                }
            }
        }

        return $outputCallback ? $outputCount : $results;
    }

    /**
     * 构建XPath查询语句
     * 
     * @param string $tag 标签名
     * @param string $classString 类名字符串
     * @return string XPath查询语句
     */
    private function buildXPathQuery($tag, $classString)
    {
        $classArray = explode(' ', trim($classString));
        $xpathConditions = [];
        foreach ($classArray as $cls) {
            if (!empty($cls)) {
                $xpathConditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
            }
        }

        return "//{$tag}" . (empty($xpathConditions) ? "" : "[" . implode(' and ', $xpathConditions) . "]");
    }

    /**
     * 从HTML中提取标题
     * 
     * @param string $html HTML内容
     * @param string $tagTitle 标题标签
     * @param string $classStringTitle 标题类名
     * @return string 提取的标题
     */
    private function extractTitle($html, $tagTitle, $classStringTitle)
    {
        // 尝试匹配"名称：xxx 描述："格式
        if (preg_match('/名称：(.*?)\n\n描述：/s', $html, $match)) {
            return trim(strip_tags($match[1]));
        }

        // 尝试根据标签和类名匹配
        $escapedClass = preg_quote($classStringTitle, '#');
        $escapedTag = preg_quote($tagTitle, '#');
        $pattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

        if (preg_match($pattern, $html, $titleMatch)) {
            return trim(strip_tags($titleMatch[1]));
        }

        return '';
    }

    /**
     * 从详情页提取URL
     * 
     * @param string $html 列表页HTML
     * @param array $line 配置信息
     * @param string $baseUrl 基础URL
     * @param string $tagUrl URL标签
     * @param string $classStringUrl URL类名
     * @param string $panPattern 网盘链接匹配模式
     * @return string 提取的URL
     */
    private function extractUrlFromDetailPage($html, $line, $baseUrl, $tagUrl, $classStringUrl, $panPattern)
    {
        list($tagD, $classStringD) = explode('+', $line['html_url'], 2);

        // 构建匹配详情页链接的正则表达式
        $detailUrlPattern = $this->buildHrefPattern($tagD, $classStringD);

        if (!preg_match($detailUrlPattern, $html, $match)) {
            return '';
        }

        // 处理相对URL
        $detailUrl = trim($match[1]);
        $fullDetailUrl = $this->buildFullUrl($detailUrl, $baseUrl);

        // 获取详情页内容
        $dom2 = getDom($fullDetailUrl);
        if (!$dom2) {
            return '';
        }

        $finder2 = new \DomXPath($dom2);
        $xpath2 = $this->buildXPathQuery($tagUrl, $classStringUrl);
        $nodes2 = $finder2->query($xpath2);

        // 遍历详情页节点查找网盘链接
        foreach ($nodes2 as $node2) {
            $html2 = $dom2->saveHTML($node2);

            // 尝试从内容中提取
            $escapedClass = preg_quote($classStringUrl, '#');
            $escapedTag = preg_quote($tagUrl, '#');
            $contentPattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

            if (preg_match($contentPattern, $html2, $titleMatch)) {
                $extractedUrl = trim(strip_tags($titleMatch[1]));
                if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                    return trim($urlMatch[0]);
                }
            }

            // 尝试从href属性中提取
            $hrefPattern = $this->buildHrefPattern($tagUrl, $classStringUrl);
            if (preg_match($hrefPattern, $html2, $match)) {
                $extractedUrl = trim($match[1]);
                if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                    return trim($urlMatch[0]);
                }
            }
        }

        return '';
    }

    /**
     * 从列表页直接提取URL
     * 
     * @param string $html HTML内容
     * @param string $tagUrl URL标签
     * @param string $classStringUrl URL类名
     * @param string $panPattern 网盘链接匹配模式
     * @return string 提取的URL
     */
    private function extractUrlFromListPage($html, $tagUrl, $classStringUrl, $panPattern)
    {
        // 尝试从内容中提取
        $escapedClass = preg_quote($classStringUrl, '#');
        $escapedTag = preg_quote($tagUrl, '#');
        $contentPattern = '#<' . $escapedTag . '[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'][^>]*>(.*?)</' . $escapedTag . '>#s';

        if (preg_match($contentPattern, $html, $titleMatch)) {
            $extractedUrl = trim(strip_tags($titleMatch[1]));
            if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                return trim($urlMatch[0]);
            }
        }

        // 尝试从href属性中提取
        $hrefPattern = $this->buildHrefPattern($tagUrl, $classStringUrl);
        if (preg_match($hrefPattern, $html, $match)) {
            $extractedUrl = trim($match[1]);
            if (preg_match($panPattern, $extractedUrl, $urlMatch)) {
                return trim($urlMatch[0]);
            }
        }

        return '';
    }

    /**
     * 构建 href 属性匹配模式，支持 class 和 href 顺序不固定
     *
     * @param string $tag 标签名
     * @param string $classString 类名（可为空）
     * @return string 正则表达式
     */
    private function buildHrefPattern($tag, $classString)
    {
        $escapedClass = preg_quote($classString, '#');
        $escapedTag = preg_quote($tag, '#');

        if (empty($escapedClass)) {
            // 没有类名要求，只匹配标签中包含 href 的内容
            return '#<' . $escapedTag . '\b[^>]*href=["\']([^"\']+)["\'][^>]*>#i';
        } else {
            // 匹配包含指定 class 的标签，不要求 href 和 class 的顺序
            return '#<' . $escapedTag . '\b(?=[^>]*class=["\'][^"\']*' . $escapedClass . '[^"\']*["\'])(?=[^>]*href=["\']([^"\']+)["\'])[^>]*>#i';
        }
    }


    /**
     * 构建完整URL
     * 
     * @param string $url 可能是相对URL
     * @param string $baseUrl 基础URL
     * @return string 完整URL
     */
    private function buildFullUrl($url, $baseUrl)
    {
        if (strpos($url, 'http') !== 0) {
            $parsed = parse_url($baseUrl);
            $base = $parsed['scheme'] . '://' . $parsed['host'];
            return $base . $url;
        }
        return $url;
    }

    /**
     * 自定义接口
     */
    private function handleKk($line, $title, $apiType = 0, $outputCallback = null)
    {
        $type = $line['pantype'];
        $maxCount = $line['count'];

        $url2 = [];
        $outputCount = 0;
        $urlDefault = "https://m.kkkba.com";

        // 网盘链接匹配正则
        $pattern = [
            0 => '/https:\/\/pan\.quark\.cn\/[^\s]+/',   // 夸克
            2 => '/https:\/\/pan\.baidu\.com\/[^\s]+/',  // 百度
        ];

        if (!isset($pattern[$type])) {
            return $outputCallback ? 0 : [];
        }

        try {
            $res = curlHelper($urlDefault . "/v/api/getToken", "GET", null, [], "", "", 5)['body'] ?? null;
            if (!$res) return $url2;
        } catch (Exception $err) {
            return $url2;
        }

        $res = json_decode($res, true);
        $token = $res['token'] ?? '';
        if (empty($token)) {
            return $url2;
        }

        // 所有接口列表
        $allApiList = [
            1 => "/v/api/getJuzi",
            2 => "/v/api/search",
            // 3 => "/v/api/getXiaoyu",
            // 4 => "/v/api/getDJ",
            // 5 => "/v/api/getKK"
        ];

        // 根据 apiType 确定要调用的接口列表
        if ($apiType == 0) {
            // 全部接口
            $apiList = array_values($allApiList);
        } elseif (isset($allApiList[$apiType])) {
            // 指定某个接口
            $apiList = [$allApiList[$apiType]];
        } else {
            // 错误类型，直接返回空
            return $outputCallback ? 0 : [];
        }

        // 请求头
        $urlData = array(
            'name' => $title,
            'token' => $token
        );
        $headers = ['Content-Type: application/json'];

        foreach ($apiList as $apiUrl) {
            try {
                $response = curlHelper($urlDefault . $apiUrl, "POST", json_encode($urlData), $headers, "", "", 5);
                $res = isset($response['body']) ? json_decode($response['body'], true) : null;
            } catch (Exception $err) {
                continue;
            }

            if (empty($res['list']) || !is_array($res['list'])) {
                continue;
            }
            foreach ($res['list'] as $value) {
                if (preg_match($pattern[$type], $value['answer'], $matches)) {
                    $link = $matches[0];
                    if (preg_match('/提取码[:：]?\s*([a-zA-Z0-9]{4})/', $value['answer'], $codeMatch)) {
                        $link .= '?pwd=' . $codeMatch[1];
                    }
                    $titleText = preg_replace('/\s*[\(（]?(夸克|百度)?[\)）]?\s*/u', '', $value['answer'] ?? '');
                    $item = [
                        'title' => $titleText,
                        'url' => $link
                    ];
                    if ($outputCallback && is_callable($outputCallback)) {
                        $outputCount += $outputCallback($item, $line['name'] ?? 'KK线路');
                        if ($outputCount >= $maxCount) {
                            return $outputCount;
                        }
                    } else {
                        $url2[] = $item;
                        if (count($url2) >= $maxCount) {
                            return $url2;
                        }
                    }
                }
            }
        }

        return $outputCallback ? $outputCount : $url2;
    }

    /**
     * 小说线路处理（调用 no-novel-node 的 /search/aggregated 聚合搜索）
     * no-novel-node 响应结构：
     *   { code:200, message:'OK', data: { books: [{ name, author, cover, url, intro, latestChapter, ... }, ...] } }
     * 统一返回：
     *   title   - 小说名（来自 name / title / bookName）
     *   url     - 详情页 URL（用于打开查看 / 下载）
     *   cover   - 封面图绝对地址
     *   author  - 作者
     *   latestChapter - 最新章节
     *   desc    - 简介
     *   datetime - 当前时间（小说无统一时间字段）
     *   is_type - 11（小说类型）
     *
     * @param array $line 线路配置
     * @param string $title 搜索关键字
     * @param callable|null $outputCallback
     * @return int|array
     */
    private function handleNovel($line, $title, $outputCallback = null)
    {
        $maxCount = (int)($line['count'] ?? 20);
        if ($maxCount <= 0) {
            return $outputCallback ? 0 : [];
        }

        $baseUrl = trim((string)($line['url'] ?? ''));
        if ($baseUrl === '') {
            return $outputCallback ? 0 : [];
        }
        // 去掉尾斜杠，避免拼接时产生 //
        $baseUrl = rtrim($baseUrl, '/');

        $searchUrl = $baseUrl . '/search/aggregated?kw=' . urlencode($title) . '&searchLimit=' . $maxCount;

        $headers = [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; xinyue-novel-search/1.0)',
        ];
        // 兼容鉴权：管理员如在 url 中以 "?token=xxx" 形式携带，或 fields.auth_enabled 开启
        if (!empty($line['auth_enabled']) && !empty($line['auth_username'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($line['auth_username'] . ':' . ($line['auth_password'] ?? ''));
        }

        // no-novel-node 聚合搜索：并发扫描多个书源，每个书源兜底超时 30s（见 plugin/index.js#SOURCE_TIMEOUT），
        // 1~2 个慢书源就会把聚合耗时推到 15s 以上。PHP 这边 15s 超时极易误杀。
        // 提到 35s 覆盖单书源兜底 + 网络多结果余量，超时仍失败时降级返回空（前端 SSE 流式输出已有 [DONE] 收尾）。
        $response = curlHelper($searchUrl, 'GET', null, $headers, '', '', 35);
        $logFile = app()->getRuntimePath() . 'api/log/' . date('Ym') . '/' . date('d') . '_debug.log';
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }

        if (isset($response['error']) || empty($response['body'])) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " [handleNovel] request failed: " . ($response['error'] ?? 'empty body') . " url=$searchUrl\n", FILE_APPEND);
            return $outputCallback ? 0 : [];
        }

        $payload = json_decode($response['body'], true);
        if (!is_array($payload) || ($payload['code'] ?? 0) != 200) {
            file_put_contents($logFile, date('Y-m-d H:i:s') . " [handleNovel] bad payload: " . substr($response['body'], 0, 200) . "\n", FILE_APPEND);
            return $outputCallback ? 0 : [];
        }

        // 兼容两种结构：data.books（数组）或 data 直接为数组
        $books = isset($payload['data']['books']) && is_array($payload['data']['books'])
            ? $payload['data']['books']
            : (isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : []);
        if (empty($books)) {
            return $outputCallback ? 0 : [];
        }

        $nowTime = date('Y-m-d H:i:s');
        $results = [];
        foreach ($books as $book) {
            if (!is_array($book)) continue;
            $bookUrl = (string)($book['url'] ?? $book['bookUrl'] ?? '');
            $cover = (string)($book['cover'] ?? $book['coverUrl'] ?? '');
            if ($cover !== '' && $bookUrl !== '') {
                // 小说服务返回的封面链接指向内网地址，浏览器不可达，
                // 统一重写为本站代理（以书籍详情页链接为参数，由 novel_cover 转发）
                $cover = '/api/other/novel_cover?url=' . urlencode($bookUrl);
            }
            $item = [
                'title'   => (string)($book['name'] ?? $book['title'] ?? $book['bookName'] ?? ''),
                'url'     => $bookUrl,
                'desc'    => (string)($book['intro'] ?? $book['desc'] ?? $book['description'] ?? ''),
                'author'  => (string)($book['author'] ?? $book['authorName'] ?? ''),
                'cover'   => $cover,
                'latestChapter' => (string)($book['latestChapter'] ?? $book['lastChapter'] ?? ''),
                'source'  => $book['source'] ?? ($book['bookSourceName'] ?? ($book['sourceName'] ?? '')),
                'category' => $book['category'] ?? ($book['kind'] ?? ''),
                'datetime' => $nowTime,
                'is_type' => 11,
            ];
            if ($item['title'] === '' || $item['url'] === '') {
                continue;
            }
            $results[] = $item;
            if ($outputCallback && is_callable($outputCallback)) {
                $outputCallback($item, $line['name'] ?? '小说线路');
                if (count($results) >= $maxCount) break;
            } else {
                if (count($results) >= $maxCount) break;
            }
        }

        return $outputCallback ? count($results) : $results;
    }

    /**
     * 获取可用小说线路（pantype=11 且启用，按权重取最高）
     * @return array|null {baseUrl, headers}，无可用线路时返回 null
     */
    private function getNovelLine()
    {
        $line = $this->ApiListModel->where('status', 1)->where('pantype', 11)->order('weight desc')->find();
        if (!$line) {
            return null;
        }
        $baseUrl = rtrim(trim((string)($line['url'] ?? '')), '/');
        if ($baseUrl === '') {
            return null;
        }
        $headers = [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (compatible; xinyue-novel-proxy/1.0)',
        ];
        // 兼容鉴权：与 handleNovel/novel_rank 保持一致
        if (!empty($line['auth_enabled']) && !empty($line['auth_username'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode($line['auth_username'] . ':' . ($line['auth_password'] ?? ''));
        }
        return ['baseUrl' => $baseUrl, 'headers' => $headers];
    }

    /**
     * 小说搜索（GET 同步代理）：xinyue 只做转发，不掺任何业务逻辑（无 SSE、无缓存、无反馈统计、无 URL 加密）。
     * 数据源完全来自 no-novel-node /search/aggregated；前端拿到结果直接渲染。
     * 设计原则：xinyue 是纯代理/转发层，小说业务规则与命中逻辑一律在 no-novel-node 内闭环。
     *
     * 入参：title（必填）、searchLimit（可选）
     * 返回：no-novel-node 原始 { code, message, data: [...] }
     *
     * @return \think\Response
     */
    public function novel_search()
    {
        $title = trim((string)input('title', ''));
        if ($title === '') {
            return jerr('缺少搜索关键字');
        }
        $line = $this->getNovelLine();
        if ($line === null) {
            return jerr('暂无可用小说线路');
        }

        $searchLimit = (int)input('searchLimit', 20);
        if ($searchLimit <= 0 || $searchLimit > 100) $searchLimit = 20;

        // 透传到 no-novel-node /search/aggregated；超时 35s 与之前 handleNovel 保持一致
        $url = $line['baseUrl'] . '/search/aggregated?kw=' . urlencode($title)
             . '&searchLimit=' . $searchLimit;
        $response = curlHelper($url, 'GET', null, $line['headers'], '', '', 35);

        if (isset($response['error']) || empty($response['body'])) {
            Log::error('[novel_search] upstream unreachable: ' . ($response['error'] ?? 'empty body'));
            return jerr('小说服务暂时不可用');
        }
        $payload = json_decode($response['body'], true);
        if (!is_array($payload)) {
            return jerr('小说服务返回数据格式异常');
        }
        // 把 no-novel-node 返回的相对封面地址重写为本站代理（同 novel_cover 处理思路）：
        // 浏览器不能直达内网 no-novel-node，统一走 /api/other/novel_cover 转发
        $data = $payload['data'] ?? [];
        if (is_array($data)) {
            $list = isset($data['books']) && is_array($data['books']) ? $data['books'] : $data;
            if (is_array($list)) {
                foreach ($list as &$item) {
                    if (is_array($item) && !empty($item['url']) && empty($item['cover'])) {
                        $item['cover'] = '/api/other/novel_cover?url=' . urlencode($item['url']);
                    }
                }
                unset($item);
            }
        }
        // 透传 code/message；data 已就地改写
        jok($payload['message'] ?? 'OK', $data);
    }

    /**
     * 小说封面代理：转发小说服务 /search/cover（磁盘缓存），供前端 <img> 直接引用
     * @return void
     */
    public function novel_cover()
    {
        $bookUrl = input('url', '');
        $line = $this->getNovelLine();
        if ($line === null || $bookUrl === '') {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        $apiUrl = $line['baseUrl'] . '/search/cover?url=' . urlencode($bookUrl);
        $response = curlHelper($apiUrl, 'GET', null, $line['headers'], '', '', 20);
        if (isset($response['error']) || empty($response['body'])) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        // 透传内容类型并允许浏览器本地缓存一天（封面基本不变）
        $contentType = (string)($response['detail']['content_type'] ?? 'image/jpeg');
        if ($contentType === '' || stripos($contentType, 'image') === false) {
            $contentType = 'image/jpeg';
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=86400');
        echo $response['body'];
        exit;
    }

    /**
     * 小说下载模式配置下发：向下游前端透出下载方式与目标网盘（读 xinyue 自己的 qf_conf）
     *   - 开启转存且已配置账户 → mode=transfer，pans=按网盘类型分组的可选项
     *   - 未开启 → mode=download（仅下载，不上传网盘）
     * 前端据此在结果列表展示"下载"或逐盘"存入XX网盘"按钮（多盘时可选择），
     * 点击后直接入队，无需用户手动选择网盘账户
     */
    public function novel_download_config()
    {
        // 转存配置已迁入 xinyue（qf_conf），不再读小说服务 /config
        $conf = NovelTransferService::getPanConfig();
        $mode = 'download';
        $accounts = [];
        if ($conf['enabled'] && !empty($conf['accounts'])) {
            $mode = 'transfer';
            $accounts = $conf['accounts'];
        }
        // 账户标识格式 {drive_type}_{userId}，按网盘类型分组（同类多账户合并），
        // 供前端逐盘展示"存入XX网盘"按钮；panName 为兼容保留的合并中文名
        $pans = [];
        if ($mode === 'transfer') {
            $grouped = [];
            foreach ($accounts as $acc) {
                $type = trim(explode('_', (string)$acc)[0]);
                if ($type === '') {
                    continue;
                }
                $grouped[$type][] = $acc;
            }
            foreach ($grouped as $type => $accs) {
                $pans[] = ['type' => $type, 'name' => $this->driveTypeName($type), 'accounts' => $accs];
            }
        }
        $panName = implode('、', array_filter(array_column($pans, 'name')));
        jok('success', ['mode' => $mode, 'panName' => $panName, 'accounts' => $accounts, 'pans' => $pans]);
    }

    /**
     * pan-backend 网盘驱动标识 → 中文名
     * 键为各驱动 Adapter 的 DRIVE_TYPE 常量（账户 ID 前缀），与目录名不完全一致
     * （如 123云盘目录 pan123 但 DRIVE_TYPE=123pan；115网盘目录 cloud115 但 DRIVE_TYPE=115）
     */
    private function driveTypeName($driveType)
    {
        $map = [
            'quark'     => '夸克网盘',
            'aliyun'    => '阿里云盘',
            'baidu'     => '百度网盘',
            'uc'        => 'UC网盘',
            'xunlei'    => '迅雷网盘',
            '115'       => '115网盘',
            'cloud115'  => '115网盘',
            '123pan'    => '123云盘',
            'pan123'    => '123云盘',
            'cloud189'  => '天翼云盘',
            'cloud139'  => '移动云盘',
            'guangya'   => '光鸭网盘',
        ];
        $key = strtolower(trim((string)$driveType));
        return $map[$key] ?? '';
    }

    /**
     * 小说资源获取（任务化，立即返回不等待）：
     * 编排已从 no-novel-node 迁入 xinyue（NovelTransferService）：
     *   - 后台开启转存（novel_pan_enabled 且已配置目标账户）→ mode=transfer，下载完成后
     *     由 xinyue 直连 pan-backend 上传并生成分享链接
     *   - 未开启或前端指定 __none__ → mode=download 仅服务端下载
     * 两种模式均写任务表后在 shutdown 阶段（fastcgi_finish_request 后）FPM 内联执行，
     * 进度与分享链接经 /api/other/novel_progress（SSE）推送，前端「下载中」面板实时展示
     * 参数：url（书籍详情页链接，必填）；可选 accounts（逗号分隔，转存时覆盖后台默认账户）；
     *           clientId（浏览器客户端标识，任务进度 SSE 仅推送给该客户端，实现多用户隔离）；
     *           bookName/author/latestChapter（三元组尽力透传：秒回判定与缓存键用，缺失属常态）
     */
    public function novel_transfer()
    {
        $bookUrl = trim((string)input('url', ''));
        $accounts = trim((string)input('accounts', ''));
        $clientId = trim((string)input('clientId', ''));
        if ($bookUrl === '') {
            jerr('缺少书籍链接');
        }

        // 读 xinyue 自己的转存配置（qf_conf：novel_pan_enabled / novel_pan_accounts）
        $conf = NovelTransferService::getPanConfig();
        $mode = 'download';
        $defaultAccounts = [];
        if ($conf['enabled'] && !empty($conf['accounts'])) {
            $mode = 'transfer';
            $defaultAccounts = $conf['accounts'];
        }

        // 前端指定账户时覆盖后台配置；指定为空表示强制纯下载
        if ($mode === 'transfer') {
            if ($accounts === '__none__') {
                $mode = 'download';
                $defaultAccounts = [];
            } elseif ($accounts !== '') {
                $defaultAccounts = array_values(array_filter(array_map('trim', explode(',', $accounts))));
                if (empty($defaultAccounts)) {
                    jerr('未选择网盘账户');
                }
            }
        }

        $bookName = trim((string)input('bookName', ''));
        $author = trim((string)input('author', ''));
        $latestChapter = trim((string)input('latestChapter', ''));

        $submitted = NovelTransferService::submit($bookUrl, $defaultAccounts, $clientId, $bookName, $author, $latestChapter);
        switch ($submitted['kind']) {
            case 'instant':
                // 缓存全命中：结果随响应直达（事件表同步写入，SSE 侧同样能收到重放）
                return jok('success', ['mode' => $mode, 'started' => true, 'accounts' => $defaultAccounts, 'result' => $submitted['result']]);
            case 'duplicate':
                return jok('success', ['mode' => $mode, 'started' => true, 'duplicate' => true, 'accounts' => $defaultAccounts]);
            case 'busy':
                return jerr('当前下载任务较多，请稍后再试');
        }

        $taskId = intval($submitted['task_id']);
        // FPM 内联执行：响应先行落盘，之后在本 FPM 进程内跑完整编排
        // （fastcgi_finish_request 只能在响应输出后调用一次；ignore_user_abort 防客户端断开中止 shutdown）
        ignore_user_abort(true);
        register_shutdown_function(function () use ($taskId) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            NovelTransferService::runTask($taskId);
        });
        return jok('success', ['mode' => $mode, 'started' => true, 'accounts' => $defaultAccounts, 'taskId' => $taskId]);
    }

    /**
     * 小说任务进度 SSE：本地面板事件流（编排迁入 xinyue 后不再透传 node）
     * 数据源：qf_novel_task_event append-only 事件表，按 (client_id,id) 轮询输出；
     * payload 即前端契约 JSON 原文（事件必带 bookUrl，可选 clientId 隔离）
     * 重连重放：游标初始化取 10 分钟窗口内该 client_id 的最早事件前一位——
     * 页面刷新/断网重连后 download-done/pan-upload-done 会重放，前端任务状态可恢复
     */
    public function novel_progress()
    {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        set_time_limit(0); // SSE 长连接，避免被 max_execution_time 中断
        // 关闭所有输出缓冲层（含 php.ini output_buffering 的 SAPI 缓冲）：
        // 攒批会导致 php-cgi 长时间无字节输出，触发 nginx fastcgi_read_timeout(60s)
        // 静默超时断连，浏览器重连后关键事件（download-done/pan-upload-*）不重放 → 前端误报下载失败
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        ob_implicit_flush(true);

        $clientId = trim((string)input('clientId', ''));
        if ($clientId === '') {
            // 无 clientId 无法隔离事件（node 时代为广播），保持最小响应即断开
            echo "data: {\"type\":\"client-id-required\"}\n\n";
            exit;
        }

        // 重连重放：10 分钟窗口内最早事件的前一位作为游标起点
        $windowStart = time() - 600;
        $oldest = Db::name('novel_task_event')
            ->where('client_id', $clientId)
            ->where('create_time', '>', $windowStart)
            ->order('id asc')->value('id');
        $lastId = $oldest ? intval($oldest) - 1 : 0;

        $lastBeat = time();
        while (true) {
            if (connection_aborted()) {
                exit;
            }
            // 注意：不可用 column('payload,id')——TP6 在"两字段且未传 key 参数"时会返回整行数组
            // （数字索引），导致输出索引而非 payload、游标永不推进（无限重放同批事件）
            $events = Db::name('novel_task_event')
                ->where('client_id', $clientId)
                ->where('id', '>', $lastId)
                ->order('id asc')
                ->limit(50)
                ->field('id,payload')
                ->select()
                ->toArray();
            foreach ($events as $ev) {
                echo 'data: ' . $ev['payload'] . "\n\n";
                $lastId = intval($ev['id']);
            }
            if (!empty($events)) {
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
                $lastBeat = time();
            } elseif (time() - $lastBeat >= 15) {
                // SSE 保活心跳（nginx fastcgi_read_timeout 防静默断连）
                echo ": ping\n\n";
                flush();
                $lastBeat = time();
            }
            sleep(1);
        }
    }

    /**
     * 小说文件下载代理：流式转发小说服务 /book-download（服务器上已下载完成的书籍文件）
     */
    public function novel_file()
    {
        $filename = input('filename', '');
        $line = $this->getNovelLine();
        if ($line === null || $filename === '') {
            jerr('参数错误');
        }
        $apiUrl = $line['baseUrl'] . '/book-download?filename=' . urlencode($filename);
        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($filename));
        header('Cache-Control: no-cache');
        set_time_limit(0);
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $line['headers']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 0);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) {
            echo $chunk;
            if (ob_get_level() > 0) {
                @ob_flush();
            }
            flush();
            return strlen($chunk);
        });
        curl_exec($ch);
        curl_close($ch);
        exit;
    }

    /**
     * 本地书列表代理：透传小说服务 /local-books（已下载完成的书籍，含文件存在状态）
     * 供前端按书名匹配下载产物文件名
     */
    public function novel_local_books()
    {
        $line = $this->getNovelLine();
        if ($line === null) {
            jerr('暂无可用小说线路');
        }
        $page = max(1, intval(input('page', 1)));
        $pageSize = min(100, max(1, intval(input('pageSize', 50))));
        $apiUrl = $line['baseUrl'] . '/local-books?page=' . $page . '&pageSize=' . $pageSize;
        $response = curlHelper($apiUrl, 'GET', null, $line['headers'], '', '', 15);
        if (isset($response['error']) || (int)($response['detail']['http_code'] ?? 0) != 200 || empty($response['body'])) {
            jerr('本地书列表查询失败');
        }
        $payload = json_decode($response['body'], true);
        if (!is_array($payload) || ($payload['code'] ?? 0) != 200) {
            jerr('本地书列表查询失败');
        }
        jok('success', $payload['data'] ?? []);
    }

    /**
     * 番茄小说榜单代理接口（不走 SSE，普通 JSON）
     * action=categories 获取男频/女频分类列表
     * action=books 获取榜单书籍，参数：
     *   gender   - 1男频 0女频
     *   rankMold - 2阅读榜 1新书榜
     *   categoryId  - 番茄分类 ID
     *   offset/limit - 分页
     *
     * @return \think\Response
     */
    public function novel_rank()
    {
        $action = input('action', 'books');
        try {
            // 查找可用小说线路（与 handleNovel 相同的选取规则）
            $line = $this->ApiListModel->where('status', 1)->where('pantype', 11)->order('weight desc')->find();
            if (!$line) {
                return jerr('暂无可用小说线路');
            }
            $baseUrl = rtrim(trim((string)($line['url'] ?? '')), '/');
            if ($baseUrl === '') {
                return jerr('小说线路未配置');
            }

            $headers = [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (compatible; xinyue-novel-rank/1.0)',
            ];
            // 兼容鉴权：与 handleNovel 保持一致
            if (!empty($line['auth_enabled']) && !empty($line['auth_username'])) {
                $headers[] = 'Authorization: Basic ' . base64_encode($line['auth_username'] . ':' . ($line['auth_password'] ?? ''));
            }

            if ($action === 'categories') {
                $apiUrl = $baseUrl . '/rank/categories';
            } else {
                $gender = input('gender', 1) == 0 ? 0 : 1;
                $rankMold = input('rankMold', 2) == 1 ? 1 : 2;
                $categoryId = preg_replace('/\D/', '', (string)input('categoryId', '1141'));
                if ($categoryId === '') {
                    $categoryId = '1141';
                }
                $offset = max(0, intval(input('offset', 0)));
                $limit = min(50, max(1, intval(input('limit', 20))));
                $apiUrl = $baseUrl . '/rank/books?gender=' . $gender . '&rankMold=' . $rankMold
                    . '&categoryId=' . $categoryId . '&offset=' . $offset . '&limit=' . $limit;
            }

            $response = curlHelper($apiUrl, 'GET', null, $headers, '', '', 15);
            if (isset($response['error']) || empty($response['body'])) {
                Log::error("[novel_rank] request failed: " . ($response['error'] ?? 'empty body') . " url=$apiUrl");
                return jerr('榜单服务暂时不可用');
            }
            $payload = json_decode($response['body'], true);
            if (!is_array($payload) || ($payload['code'] ?? 0) != 200) {
                Log::error("[novel_rank] bad payload: " . substr($response['body'], 0, 200));
                return jerr('榜单数据获取失败');
            }
            return jok('OK', $payload['data'] ?? '');
        } catch (\Throwable $e) {
            Log::error("[novel_rank] exception: " . $e->getMessage());
            return jerr('榜单数据获取失败');
        }
    }

    /**
     * 小说模板首页推荐位下发（公开接口，读 qf_novel_recommend 启用项，与 qf_banner 完全独立）
     */
    public function novel_recommend()
    {
        $limit = min(20, max(1, intval(input('limit', 10))));
        $list = Db::name('novel_recommend')
            ->where('novel_recommend_status', 0)
            ->field('novel_recommend_title, novel_recommend_cover, novel_recommend_link')
            ->order('novel_recommend_sort', 'asc')
            ->order('novel_recommend_id', 'asc')
            ->limit($limit)
            ->select()
            ->toArray();
        return jok('OK', $list);
    }

    /**
     * 解密url并转存
     * @return void
     */
    public function save_url()
    {
        $rawUrl = input('url', '');
        $decodedUrl = rawurldecode($rawUrl);

        $value = [
            'title'  => input('title', ''),
            'url'    => $decodedUrl,
            'stoken' => input('stoken', ''),
        ];
        $decrypted = decryptObject($value['url']);

        // 如果解密结果是数组，取其中的 url 字段
        if (is_array($decrypted) && isset($decrypted['url'])) {
            $value['url'] = $decrypted['url'];
        } else {
            $value['url'] = $decrypted;
        }

        if (empty($value['url'])) {
            return jerr("参数不对");
        }

        // 如果只是解密链接（title为空），直接返回解密后的链接
        if (empty($value['title'])) {
            return jok('链接解密成功', ['url' => $value['url']]);
        }

        $map[] = ['status', '=', 1];
        $map[] = ['is_delete', '=', 0];
        $map[] = ['is_time', '=', 1];
        $map[] = ['content', '=', $value['url']];

        $url = $this->model->where($map)->field('source_id as id, title, url')->find();
        if (!empty($url)) {
            $this->model->where('source_id', $url['id'])->update(['update_time' => time()]);
            unset($url['id']);
            return jok('临时资源获取成功', $url);
        }

        //同一个搜索内容锁机
        $keys = $value['url'] . 'ACAA';
        if (Cache::has($keys)) {
            // 检查缓存中是否已有结果
            return jok('临时资源获取成功', Cache::get($keys));
        }

        // 检查是否有正在处理的请求
        if (Cache::has($keys . '_processing')) {
            // 如果当前正在处理相同关键词的请求，等待结果
            $startTime = time(); // 记录开始时间
            while (Cache::has($keys . '_processing')) {
                usleep(1000000); // 暂停1秒

                // 检查是否超过60秒
                if (time() - $startTime > 60) {
                    return jok('临时资源获取成功', []);
                }
            }
            return jok('临时资源获取成功', Cache::get($keys));
        }

        // 设置处理状态为正在处理
        Cache::set($keys . '_processing', true, 60); // 锁定60秒

        $datas = [];
        $num_total = 1;
        $num_success = 0;
        $res = $this->processUrl($value, $num_success, $datas, true);

        Cache::delete($keys . '_processing'); // 解锁

        if ($res['code'] !== 200) {
            return jerr($res['message']);
        } else {
            $result['title'] = $res['data']['title'];
            $result['url'] = $res['data']['url'];
            Cache::set($keys, $result, 60); // 缓存结果60秒
            return jok('临时资源获取成功', $result);
        }
    }

    // 检查 URL 是否已存在（忽略查询参数）
    public function urlExists($searchList, $urlToCheck)
    {
        // 解析待检查的 URL
        $parsedUrlToCheck = parse_url($urlToCheck);

        foreach ($searchList as $item) {
            $parsedUrl = parse_url($item['url']);

            // 比较 scheme, host 和 path
            if (
                $parsedUrlToCheck['scheme'] === $parsedUrl['scheme'] &&
                $parsedUrlToCheck['host'] === $parsedUrl['host'] &&
                $parsedUrlToCheck['path'] === $parsedUrl['path']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 临时资源转存
     * 
     * @return void
     */
    public function processUrl($value, &$num_success, &$datas, $type = false)
    {
        // 检查是否为磁力链接
        if (preg_match('/^magnet:\?xt=urn:btih:/i', $value['url'])) {
            // 磁力链接直接处理，不需要转存
            $patterns = '/^\d+\./';
            $title = preg_replace($patterns, '', $value['title']);
            
            // 添加资源到系统中
            $data["title"] = $title;
            $data["url"] = $value['url'];
            $data["is_type"] = 9; // 磁力链接类型
            $data["content"] = $value['url'];
            $data["fid"] = '';
            $data["is_time"] = 1;
            $data["update_time"] = time();
            $data["create_time"] = time();
            $data["id"] = $this->model->insertGetId($data);
            $datas[] = $data;
            $num_success++;

            if ($type) {
                return jok2('转存成功', ['title' => $title, 'url' => $value['url'], 'fid' => '']);
            } else {
                return;
            }
        }

        // 网盘链接处理
        $substring = strstr($value['url'], 's/');
        if ($substring === false) {
            if ($type) {
                return jerr2("资源地址格式有误");
            } else {
                return; // 模拟 continue 行为
            }
        }

        $code = '';
        if (preg_match('/\?pwd=([^,\s&]+)/', $value['url'], $pwdMatch)) {
            $code = trim($pwdMatch[1]);
        }

        $urlData = array(
            'url' => $value['url'],
            'code' => $code,
            'expired_type' => 2,
        );

        $transfer = new \netdisk\Transfer();
        $res = $transfer->transfer($urlData);

        if ($res['code'] !== 200) {
            if ($type) {
                return jerr2($res['message']);
            } else {
                return; // 模拟 continue 行为
            }
        }

        $patterns = '/^\d+\./';
        $title = preg_replace($patterns, '', $value['title']);
        // 添加资源到系统中
        $data["title"] = $title;
        $data["url"] = $res['data']['share_url'];
        $data["is_type"] = determineIsType($data["url"]);
        $data["content"] = $value['url'];
        $dataFid = $res['data']['fid'] ?? '';
        $data["fid"] = is_array($dataFid) ? json_encode($dataFid) : $dataFid;
        $data["is_time"] = 1;
        $data["update_time"] = time();
        $data["create_time"] = time();
        $data["id"] = $this->model->insertGetId($data);
        $datas[] = $data;
        $num_success++;

        if ($type) {
            return jok2('转存成功', $data);
        }
    }


    /**
     * 30分钟后清除临时资源
     * 
     * @return void
     */
    public function delete_search()
    {
        // 搜索条件
        $map[] = ['is_time', '=', 1];
        $map[] = ['update_time', '<=', time() - (30 * 60)];
        $abc = $this->model->where($map)->select();


        $transfer = new \netdisk\Transfer();
        $this->model->where($map)->chunk(100, function ($order) use ($transfer) {
            foreach ($order as $value) {
                $deles = $value->toArray();

                $fid = $deles['fid'];

                // 尝试解码，如果是有效的 JSON 数组则使用，否则转为单元素数组
                $filelist = (is_string($fid) && ($decodedFid = json_decode($fid, true)) && is_array($decodedFid)) ? $decodedFid : (array)$fid;

                $this->model->where('fid', $deles['fid'])->delete();
                $transfer->deletepdirFid($filelist);
            }
        });

        return jok('临时资源删除成功', $abc);
    }

    /**
     * 检查对应网盘账号是否已配置（查 pan-backend 账户列表）
     * @param int $isType 网盘类型 0夸克 1阿里 2百度 3UC 4迅雷 5:123 6:115 10:光雅
     * @return bool
     */
    private function hasPanAccount($isType)
    {
        $driveType = \netdisk\Transfer::DRIVE_TYPE_MAP[intval($isType)] ?? '';
        if ($driveType === '') {
            return false;
        }
        return PanBackendService::firstAccount($driveType) !== null;
    }

    /**
     * 获取搜索统计数据
     * 用于前端实时更新今日搜索和总搜索量
     * 
     * @return \think\response\Json
     */
    public function get_search_stats()
    {
        try {
            $stats = getSearchStats();
            
            return jok('获取成功', [
                'today' => $stats['today'],
                'total' => $stats['total'],
                'today_formatted' => formatNumberToK($stats['today']),
                'total_formatted' => formatNumberToK($stats['total'])
            ]);
        } catch (\Exception $e) {
            return jerr('获取失败: ' . $e->getMessage());
        }
    }
}
