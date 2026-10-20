<?php

namespace app\admin\controller;

use think\App;
use think\facade\View;
use think\facade\Filesystem;
use think\facade\Db;
use think\exception\ValidateException;
use app\admin\QfShop;
use app\model\Source as SourceModel;
use app\model\SourceLog as SourceLogModel;
use app\model\SourceCategory;
use quarkPlugin\QuarkPlugin;

class Source extends QfShop
{
    public function __construct(App $app)
    {
        parent::__construct($app);
        $panTypes = config('pan_types');
        $panTypeMap = [];
        foreach ($panTypes as $type) {
            $panTypeMap[$type['id']] = $type['name'];
        }
        View::assign('panTypeMap', $panTypeMap);
        //查询列表时允许的字段
        $this->selectList = "*";
        //查询详情时允许的字段
        $this->selectDetail = "*";
        //筛选字段
        $this->searchFilter = [];
        $this->insertFields = [
            //允许添加的字段列表
            "source_category_id",
            "title",
            "description",
            "url",
            "status",
            "is_delete",
            "sort",
            "is_top",
            "vod_content",
            "is_type",
            "account_name",
            "vod_pic",
            "is_time"
        ];
        $this->updateFields = [
            //允许更新的字段列表
            "source_category_id",
            "title",
            "description",
            "url",
            "status",
            "is_delete",
            "sort",
            "is_top",
            "vod_content",
            "is_type",
            "account_name",
            "vod_pic",
            "is_time"
        ];
        $this->insertRequire = [
            //添加时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "title" => "资源名称必须填写",
            "url" => "资源地址必须填写",
        ];
        $this->updateRequire = [
            //修改时必须填写的字段
            // "字段名称"=>"该字段不能为空"
            "source_id" => "资源ID必须填写",
            "title" => "资源名称必须填写",
            "url" => "资源地址必须填写",
        ];
        $this->model = new SourceModel();
    }

    public function __call($name, $arguments) {
        if ($name == 'SourceLogModel') {
            return new SourceLogModel();
        }
        return parent::__call($name, $arguments);
    }

    /**
     * 资源统计
     */
    public function stats()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));
        $total = Db::name('source')->count();
        $today = Db::name('source')->where('create_time', '>=', $todayStart)->count();
        $month = Db::name('source')->where('create_time', '>=', $monthStart)->count();
        $category = Db::name('source_category')->count();
        return jok('获取成功', [
            'total' => $total,
            'today' => $today,
            'month' => $month,
            'category' => $category,
        ]);
    }


    /**
     * 获取列表接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function getList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $map = $this->getDataFilterFromRequest();
        $map[] = ['is_delete', '=', 0];
        if (!empty(input('source_category_id'))) {
            $map[] = ['source_category_id', '=', input('source_category_id')];
        }
        if (!empty(input('is_type')) && input('is_type') !== '') {
            $map[] = ['is_type', '=', input('is_type')];
        }
        if (!empty(input('account_name'))) {
            $map[] = ['account_name', '=', input('account_name')];
        }
        if (input('is_time') !== '' && input('is_time') !== null) {
            $map[] = ['is_time', '=', input('is_time')];
        }
        if (input('is_top') !== '' && input('is_top') !== null) {
            $map[] = ['is_top', '=', input('is_top')];
        }
        empty(input('keyword')) ?: $map[] = ['title|description', 'like', '%' . input('keyword') . '%'];
        $order = $this->getorderfromRequest();
        $this->setGetListPerPage();
        $dataList = $this->model->getListByPage($map, $order, $this->selectList);
        return jok('数据获取成功', $dataList);
    }


    /**
     * 添加接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function add()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        //校验Insert字段是否填写
        $error = $this->validateInsertFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Insert数据
        $data = $this->getInsertDataFromRequest();
        //清理并截断易超长的字段
        $data = $this->sanitizeSourceData($data);
        //添加这行数据
        $data["update_time"] = time();
        $data["create_time"] = time();
        $data["is_type"] = determineIsType($data["url"]);
        $this->model->insertGetId($data);
        return jok('添加成功');
    }

    /**
     * 修改接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function update()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "参数必须填写", 400);
        }
        //根据主键获取一行数据
        $item = $this->getRowByPk();
        if (empty($item)) {
            return jerr("数据查询失败", 404);
        }
        //校验Update字段是否填写
        $error  = $this->validateUpdateFields();
        if ($error) {
            return $error;
        }
        //从请求中获取Update数据
        $data = $this->getUpdateDataFromRequest();
        //清理并截断易超长的字段
        $data = $this->sanitizeSourceData($data);
        //根据主键更新这条数据
        $data["update_time"] = time();
        $data["is_type"] = determineIsType($data["url"]);
        $this->model->where($this->pk, $this->pk_value)->update($data);
        return jok('修改成功');
    }

    /**
     * 清理并截断资源字段，防止过长内容导致数据库插入失败
     * @param array $data
     * @return array
     */
    protected function sanitizeSourceData($data)
    {
        $limits = [
            'title' => 255,
            'url' => 255,
            'description' => 255,
            'vod_content' => 255,
            'vod_pic' => 255,
            'account_name' => 255,
            'code' => 50,
        ];
        foreach ($limits as $field => $limit) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = mb_substr(trim($data[$field]), 0, $limit);
            }
        }
        return $data;
    }



    /**
     * 删除接口基类 子类自动继承 如有特殊需求 可重写到子类 请勿修改父类方法
     *
     * @return void
     */
    public function delete()
    {
        //校验Access与RBAC
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (!$this->pk_value) {
            return jerr($this->pk . "必须填写", 400);
        }

        if (isInteger($this->pk_value)) {
            //根据主键获取一行数据
            $item = $this->getRowByPk();
            if (empty($item)) {
                return jerr("数据查询失败", 404);
            }
            $this->model->where($this->pk, $this->pk_value)->delete();
        } else {
            $list = explode(',', $this->pk_value);
            $this->model->where($this->pk, 'in', $list)->delete();
        }
        return jok('删除成功');
    }

    // 判断文件编码
    function detectFileEncoding($filename)
    {
        $handle = fopen($filename, 'r');
        $firstLine = fread($handle, 1024); // 读取文件的开头一部分内容
        fclose($handle);
        // 尝试使用不同的编码进行解码，并检查是否成功
        if (mb_check_encoding($firstLine, 'UTF-8')) {
            return 'UTF-8';
        } elseif (mb_check_encoding($firstLine, 'GBK')) {
            return 'GBK';
        } else {
            // 如果无法确定编码，则返回默认编码
            return 'UTF-8'; // 或者根据需要返回其他默认编码
        }
    }


    /**
     * Excel导入
     *
     * @return void
     */
    public function imports()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        try {
            $file = request()->file('file');
            if (!$file) {
                return jerr('请选择文件');
            }
            
            $fileInfo = [
                'originalName' => $file->getOriginalName(),
                'extension' => $file->extension(),
                'size' => $file->getSize(),
            ];
            
            \think\facade\Log::info('上传文件信息：' . json_encode($fileInfo, JSON_UNESCAPED_UNICODE));
            
            try {
                validate(['file' => 'filesize:10485760|fileExt:xlsx,xls'])
                    ->check(['file' => $file]);
            } catch (\Exception $e) {
                \think\facade\Log::error('文件验证失败：' . $e->getMessage());
                return jerr('文件验证失败：' . $e->getMessage());
            }
            
            $saveName = Filesystem::putFile('excel', $file);
            
            if (!$saveName) {
                \think\facade\Log::error('文件保存失败：uploads 目录可能不存在或无写入权限');
                return jerr('文件保存失败，请检查 uploads 目录权限');
            }
            
            \think\facade\Log::info('文件保存成功：' . $saveName);

            ini_set("memory_limit", -1);
            set_time_limit(0);

            $file_name = Filesystem::path($saveName);
            
            \think\facade\Log::info('完整文件路径：' . $file_name);
            
            if (!file_exists($file_name)) {
                \think\facade\Log::error('文件不存在：' . $file_name);
                \think\facade\Log::error('尝试查找的路径：' . Filesystem::path(''));
                return jerr('文件不存在');
            }

            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            if ($extension == 'csv') {
                return jerr('转成xlsx格式吧');
                $PHPReader = new \PHPExcel_Reader_CSV();
                $encoding = $this->detectFileEncoding($file_name);
                $PHPReader->setInputEncoding($encoding);
                $PHPReader->setDelimiter(',');
            } elseif ($extension == 'xlsx') {
                $PHPReader = new \PHPExcel_Reader_Excel2007();
            } elseif ($extension == 'xls') {
                $PHPReader = new \PHPExcel_Reader_Excel5();
            } else {
                return jerr('不支持的文件类型');
            }

            $objExcel = $PHPReader->load($file_name);
            $excel_array = $objExcel->getSheet(0)->toArray();
            
            \think\facade\Log::info('Excel 数据行数：' . count($excel_array));
            
            array_shift($excel_array);
            array_shift($excel_array);
            
            \think\facade\Log::info('处理后数据行数：' . count($excel_array));
            
            $data = [];
            $i = 0;
            $existing_data = [];

            $existing_records = $this->model->field('title, is_type')->select()->toArray();
            
            \think\facade\Log::info('现有记录数：' . count($existing_records));
            
            foreach ($existing_records as $record) {
                $existing_data[$record['title'] . '_' . $record['is_type']] = true;
            }

            Filesystem::delete($saveName);

            // 获取前端传来的默认分类和资源类型
            $default_category_id = input('source_category_id', 0);
            $default_is_time = intval(input('is_time', 0));
            \think\facade\Log::info('前端传来的默认分类ID：' . $default_category_id);
            
            // 加载所有分类到内存，方便快速查找
            $categoryModel = new \app\model\SourceCategory();
            $allCategories = $categoryModel->field('source_category_id, name')->where('status', 0)->select()->toArray();
            $categoryMap = []; // 名称 => ID 的映射
            foreach ($allCategories as $cat) {
                $categoryMap[trim($cat['name'])] = $cat['source_category_id'];
            }
            \think\facade\Log::info('已加载分类数量：' . count($categoryMap));

            foreach ($excel_array as $k => $v) {
                $title = isset($v[0]) ? trim($v[0]) : '';
                $url = isset($v[1]) ? trim($v[1]) : '';
                $excel_category_name = isset($v[2]) ? trim($v[2]) : '';
                $account_name = isset($v[3]) ? trim($v[3]) : '';
                $description = isset($v[4]) ? trim($v[4]) : '';
                $vod_content = isset($v[5]) ? trim($v[5]) : '';

                $is_type = $url ? determineIsType($url) : 0;

                $key = $title . '_' . $is_type;

                if (!isset($existing_data[$key]) && $url) {
                    $record = [];
                    $record['title'] = $title;
                    $record['url'] = $url;
                    $record['is_type'] = $is_type;
                    
                    // 处理分类：优先使用Excel中的分类名称，支持自动创建
                    $source_category_id = 0;
                    if (!empty($excel_category_name)) {
                        // 根据名称查找分类ID
                        if (isset($categoryMap[$excel_category_name])) {
                            // 分类已存在
                            $source_category_id = $categoryMap[$excel_category_name];
                            \think\facade\Log::info('找到已有分类：' . $excel_category_name . ' => ID:' . $source_category_id);
                        } else {
                            // 分类不存在，自动创建
                            $newCategory = [
                                'name' => $excel_category_name,
                                'image' => '',
                                'sort' => 0,
                                'status' => 0,
                                'is_sys' => 0,
                                'is_update' => 1,
                                'is_type' => 0,
                                'create_time' => time(),
                                'update_time' => time(),
                            ];
                            $source_category_id = $categoryModel->insertGetId($newCategory);
                            // 更新缓存
                            $categoryMap[$excel_category_name] = $source_category_id;
                            \think\facade\Log::info('自动创建分类：' . $excel_category_name . ' => ID:' . $source_category_id);
                        }
                    } elseif (!empty($default_category_id)) {
                        // 使用前端传来的默认分类
                        $source_category_id = intval($default_category_id);
                    }
                    $record['source_category_id'] = $source_category_id;
                    
                    $record['account_name'] = $account_name;

                    $record['description'] = $description;
                    $record['vod_content'] = $vod_content;
                    $record['update_time'] = time();
                    $record['create_time'] = time();
                    $record['status'] = 1;
                    $record['is_delete'] = 0;
                    $record['is_time'] = $default_is_time;
                    $record['is_user'] = 0;
                    $record['page_views'] = 0;
                    $record['vod_pic'] = '';
                    $record['sort'] = 0;
                    $record['is_top'] = 0;
                    $record['content'] = '';
                    $record['code'] = '';
                    $record['fid'] = '';

                    $data[] = $record;
                    $existing_data[$key] = true;
                    $i++;
                }
            }
            
            \think\facade\Log::info('准备插入数据数：' . count($data));
            
            if (empty($data)) {
                \think\facade\Log::error('没有可导入的数据');
                return jok('无可导入的资源，请检查表格格式');
            }

            $this->model->autoWriteTimestamp = false;
            
            try {
                $result = $this->model->insertAll($data);
                \think\facade\Log::info('插入结果：' . $result);
            } catch (\Exception $e) {
                \think\facade\Log::error('插入失败：' . $e->getMessage());
                \think\facade\Log::error('错误堆栈：' . $e->getTraceAsString());
                return jerr('数据库插入失败：' . $e->getMessage());
            }
            
            $this->model->autoWriteTimestamp = true;
            if ($i == 0) {
                return jok('无可导入的资源，请检查表格格式');
            }
            return jok('导入成功' . $i . '个资源');
        } catch (ValidateException $e) {
            \think\facade\Log::error('验证异常：' . $e->getMessage());
            \think\facade\Log::error('验证异常堆栈：' . $e->getTraceAsString());
            return jerr($e->getMessage());
        } catch (\Exception $error) {
            \think\facade\Log::error('上传文件异常：' . $error->getMessage());
            \think\facade\Log::error('异常堆栈：' . $error->getTraceAsString());
            return jerr('上传文件失败，请检查你的文件！');
        }
    }



    /**
     * 导出
     *
     * @return void
     */
    public function excel()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        //查询数据
        $map = [];
        $filter = input('');
        foreach ($filter as $k => $v) {
            if ($k == 'filter') {
                $k = input('filter');
                $v = input('keyword');
            }
            if ($v === '' || $v === null) {
                continue;
            }
            if (array_key_exists($k, $this->searchFilter)) {
                switch ($this->searchFilter[$k]) {
                    case "like":
                        array_push($map, [$k, 'like', "%" . $v . "%"]);
                        break;
                    case "=":
                        array_push($map, [$k, '=', $v]);
                        break;
                    default:
                }
            }
        }

        // 获取所有分类，建立ID到名称的映射
        $categoryModel = new \app\model\SourceCategory();
        $categories = $categoryModel->field('source_category_id, name')->select()->toArray();
        $categoryMap = [];
        foreach ($categories as $cat) {
            $categoryMap[$cat['source_category_id']] = $cat['name'];
        }

        $field = 'title,url,source_category_id,account_name,description,vod_content';
        $dataList = $this->model->field($field)->where($map)->select();
        // 处理数据，将分类ID转换为分类名称
        $data = [];
        foreach ($dataList as $item) {
            $item['source_category_name'] = $categoryMap[$item['source_category_id']] ?? '';
            $data[] = $item;
        }
        $excelField = [
            "title" => "资源名称",
            "url" => "资源地址",
            "source_category_name" => "资源分类",
            "account_name" => "添加人",
            "description" => "关键字搜索",
            "vod_content" => "资源介绍",
        ];

        $this->excelField = $excelField;
        // 转换为集合对象，以便调用toArray()方法
        $dataCollection = collect($data);
        $this->exportExcelData($dataCollection);
    }

    /**
     * 一键转存并分享夸克资源
     *
     * @return void
     */
    public function transfer()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (empty(input("type")) || empty(input("urls"))) {
            return jerr('参数不能为空');
        }

        $source_category_id = input('source_category_id') ?? 0;
        $is_time = intval(input('is_time', 0));

        $allData = parsePanLinks(input("urls"));

        $quarkPlugin = new QuarkPlugin();
        if (input("type") == 2) {
            //转存分享导入
            $res = $quarkPlugin->transfer($allData, $source_category_id, $is_time);
        } else {
            // 直接导入
            $res = $quarkPlugin->import($allData, $source_category_id, $is_time);
        }

        return jok('导入完成', $res);
    }

    /**
     * 批量修改分类
     *
     * @return void
     */
    public function batchUpdateCategory()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        
        $source_ids = input('source_ids');
        $source_category_id = input('source_category_id');
        
        if (empty($source_ids) || empty($source_category_id)) {
            return jerr('参数不能为空');
        }
        
        $ids = explode(',', $source_ids);
        
        try {
            $this->model->where('source_id', 'in', $ids)->update([
                'source_category_id' => $source_category_id,
                'update_time' => time()
            ]);
            return jok('批量修改分类成功');
        } catch (\Exception $e) {
            return jerr('批量修改分类失败');
        }
    }

    // ─────────────────────────────────────────────
    // 封面图搜索与下载
    // ─────────────────────────────────────────────

    /**
     * 预热封面搜索（获取百度图片 cookie 并缓存）
     */
    public function preheatCover()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $source = input('source', 'baidu');
        if ($source === 'baidu') {
            $cookies = $this->getBaiduImageCookies(true);
            if ($cookies) {
                return json(['code' => 0, 'msg' => '预热成功']);
            }
            return json(['code' => 1, 'msg' => '预热失败']);
        }
        return json(['code' => 0, 'msg' => '无需预热']);
    }

    /**
     * 获取百度图片 cookie（带缓存，30分钟有效）
     */
    private function getBaiduImageCookies($force = false)
    {
        $cacheKey = 'baidu_image_cookies';
        if (!$force) {
            $cached = cache($cacheKey);
            if ($cached) return $cached;
        }
        $bdHeader = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://image.baidu.com/');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $bdHeader);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
        $cookies = '';
        if ($resp) {
            preg_match_all('/Set-Cookie:\s*([^;]+)/i', $resp, $ckMatches);
            if (!empty($ckMatches[1])) $cookies = implode('; ', $ckMatches[1]);
        }
        if ($cookies) {
            cache($cacheKey, $cookies, 1800); // 缓存30分钟
        }
        return $cookies;
    }

    /**
     * 搜索封面图（豆瓣搜索）
     */
    public function searchCover()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $keyword = input('keyword', '');
        $searchType = input('type', 'movie');
        if (empty($keyword)) {
            return jerr('请输入资源名称');
        }

        $headerApi = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept: application/json',
            'Referer: ' . 'https://www.douban.com/',
        ];
        $headerMobile = [
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            'Accept: text/html',
            'Referer: ' . 'https://m.douban.com/',
        ];
        $results = [];

        if ($searchType === 'bing') {
            // Bing 图片搜索（cn.bing.com 会 302 跳转到 www.bing.com，需跟随重定向）
            $bingUrl = 'https://www.bing.com/images/search?q=' . urlencode($keyword . ' 封面') . '&form=HDRSC2&first=1&mkt=zh-CN&setlang=zh-CN';
            $headerBing = [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Referer: https://www.bing.com/',
            ];
            $bingCh = curl_init();
            curl_setopt($bingCh, CURLOPT_URL, $bingUrl);
            curl_setopt($bingCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($bingCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($bingCh, CURLOPT_HTTPHEADER, $headerBing);
            curl_setopt($bingCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($bingCh, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($bingCh, CURLOPT_TIMEOUT, 10);
            curl_setopt($bingCh, CURLOPT_CONNECTTIMEOUT, 10);
            $bingHtml = curl_exec($bingCh);
            curl_close($bingCh);
            if ($bingHtml !== false && !empty($bingHtml)) {
                // 正则用非贪婪匹配到 &quot; 为止，避免 URL 中的 &amp; 导致截断
                if (preg_match_all('/murl&quot;:&quot;(https?:\/\/.+?)&quot;/i', $bingHtml, $bingMatches)) {
                    $seen = [];
                    foreach (array_slice($bingMatches[1], 0, 20) as $bingImg) {
                        // 还原 HTML 实体
                        $bingImg = html_entity_decode($bingImg, ENT_QUOTES, 'UTF-8');
                        if (isset($seen[$bingImg])) continue;
                        $seen[$bingImg] = true;
                        // 放宽后缀判断：允许 URL 带参数（xxx.jpg?w=500）
                        if (!preg_match('/\.(jpg|jpeg|png|webp|gif|bmp)(\?|$)/i', $bingImg)) continue;
                        if (count($seen) > 12) break;
                        $results[] = [
                            'title' => $keyword,
                            'year' => '',
                            'type' => '图片',
                            'episode' => '',
                            'sub_title' => '',
                            'cover' => $bingImg,
                            'cover_proxy' => '/admin/source/proxyImage?url=' . urlencode($bingImg),
                            'url' => '',
                            'rate' => '',
                            'abstract' => '',
                            'abstract_2' => '',
                        ];
                    }
                }
            }
        } elseif ($searchType === 'game') {
            // 豆瓣游戏搜索
            $gameUrl = 'https://www.douban.com/j/ilmen/game/search?q=' . urlencode($keyword) . '&sort=rating&more=1';
            $gameRes = curlHelper($gameUrl, 'GET', null, $headerApi, '', '', 10);
            if (!isset($gameRes['error']) && !empty($gameRes['body'])) {
                $gameData = json_decode($gameRes['body'], true);
                if (!empty($gameData['games'])) {
                    foreach (array_slice($gameData['games'], 0, 8) as $gItem) {
                        $gCover = $gItem['cover'] ?? '';
                        if (!$gCover) continue;
                        $gParts = [];
                        if (!empty($gItem['platforms'])) $gParts[] = '平台: ' . $gItem['platforms'];
                        if (!empty($gItem['genres'])) $gParts[] = $gItem['genres'];
                        $results[] = [
                            'title' => $gItem['title'] ?? '',
                            'year' => '',
                            'type' => '游戏',
                            'episode' => '',
                            'sub_title' => '',
                            'cover' => $gCover,
                            'cover_proxy' => '/admin/source/proxyImage?url=' . urlencode($gCover),
                            'url' => $gItem['url'] ?? '',
                            'rate' => $gItem['rating'] ?? '',
                            'abstract' => implode(' / ', $gParts),
                            'abstract_2' => $gItem['review']['content'] ?? '',
                        ];
                    }
                }
            }
        } elseif ($searchType === 'gamersky') {
            // 游民星空搜索（需强制 TLS 1.2，curlHelper 默认协商会失败）
            $gsUrl = 'https://so.gamersky.com/?s=' . urlencode($keyword);
            $headerGs = [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html',
                'Accept-Language: zh-CN,zh;q=0.9',
            ];
            $gsCh = curl_init();
            curl_setopt($gsCh, CURLOPT_URL, $gsUrl);
            curl_setopt($gsCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($gsCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($gsCh, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($gsCh, CURLOPT_HTTPHEADER, $headerGs);
            curl_setopt($gsCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($gsCh, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($gsCh, CURLOPT_TIMEOUT, 10);
            curl_setopt($gsCh, CURLOPT_CONNECTTIMEOUT, 10);
            $gsBody = curl_exec($gsCh);
            curl_close($gsCh);
            if ($gsBody !== false && !empty($gsBody)) {
                $gsHtml = $gsBody;
                $seen = [];
                if (preg_match_all('/<ul class="ImgY">(.*?)<\/ul>/is', $gsHtml, $ulMatches)) {
                    foreach ($ulMatches[1] as $ulContent) {
                        if (preg_match_all('/<li>\s*<a href="([^"]*)"[^>]*>(.*?)<\/a>\s*<\/li>/is', $ulContent, $liMatches, PREG_SET_ORDER)) {
                            foreach ($liMatches as $li) {
                                $gsLink = $li[1];
                                $liInner = $li[2];
                                if (!preg_match('/<img[^>]*>/i', $liInner, $imgTag)) continue;
                                $imgHtml = $imgTag[0];
                                if (!preg_match('/src="(https?:\/\/[^"]*imgs\.gamersky\.com[^"]*)"/i', $imgHtml, $srcMatch)) continue;
                                $gsImg = $srcMatch[1];
                                $gsTitle = '';
                                if (preg_match('/title="([^"]*)"/i', $imgHtml, $titleMatch)) {
                                    $gsTitle = $titleMatch[1];
                                } elseif (preg_match('/alt="([^"]*)"/i', $imgHtml, $altMatch)) {
                                    $gsTitle = $altMatch[1];
                                }
                                if (isset($seen[$gsImg])) continue;
                                $seen[$gsImg] = true;
                                $results[] = [
                                    'title' => $gsTitle ?: $keyword,
                                    'year' => '',
                                    'type' => '游戏',
                                    'episode' => '',
                                    'sub_title' => '',
                                    'cover' => $gsImg,
                                    'cover_proxy' => '/admin/source/proxyImage?url=' . urlencode($gsImg),
                                    'url' => $gsLink,
                                    'rate' => '',
                                    'abstract' => '',
                                    'abstract_2' => '',
                                ];
                            }
                        }
                    }
                }
            }
        } elseif ($searchType === 'baidu') {
            // 百度图片搜索（带缓存 cookie + 完整 Referer 链，模拟真实浏览器）
            $bdCookies = $this->getBaiduImageCookies();
            $bdUrl = 'https://image.baidu.com/search/flip?tn=baiduimage&ie=utf-8&word=' . urlencode($keyword) . '&pn=0&rn=15';
            $bdHeader = [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Referer: https://image.baidu.com/search/index?tn=baiduimage&word=' . urlencode($keyword),
            ];
            $bdCh = curl_init();
            curl_setopt($bdCh, CURLOPT_URL, $bdUrl);
            curl_setopt($bdCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($bdCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($bdCh, CURLOPT_HTTPHEADER, $bdHeader);
            curl_setopt($bdCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($bdCh, CURLOPT_FOLLOWLOCATION, true);
            if ($bdCookies) curl_setopt($bdCh, CURLOPT_COOKIE, $bdCookies);
            curl_setopt($bdCh, CURLOPT_TIMEOUT, 10);
            curl_setopt($bdCh, CURLOPT_CONNECTTIMEOUT, 10);
            $bdHtml = curl_exec($bdCh);
            $bdHttpCode = curl_getinfo($bdCh, CURLINFO_HTTP_CODE);
            curl_close($bdCh);
            // 检测风控：HTTP异常或验证码
            $bdBlocked = ($bdHtml === false)
                || ($bdHttpCode != 200)
                || strpos($bdHtml, 'captcha') !== false
                || strpos($bdHtml, '安全验证') !== false
                || strpos($bdHtml, 'wappass.baidu.com') !== false;
            // 如果被风控，清除 cookie 缓存，下次重新获取
            if ($bdBlocked) {
                cache('baidu_image_cookies', null);
            }
            if (!$bdBlocked && !empty($bdHtml)) {
                // 多字段正则兜底：thumbURL → middleURL → objURL → hoverURL
                $bdImages = [];
                foreach (['thumbURL', 'middleURL', 'objURL', 'hoverURL'] as $field) {
                    if (preg_match_all('/"' . $field . '":"(https?:[^"]+)"/', $bdHtml, $m)) {
                        foreach ($m[1] as $img) {
                            $img = stripslashes($img);
                            if (!isset($bdImages[$img])) $bdImages[$img] = true;
                        }
                    }
                }
                $seen = [];
                foreach (array_keys($bdImages) as $bdImg) {
                    if (isset($seen[$bdImg])) continue;
                    $seen[$bdImg] = true;
                    if (count($seen) > 12) break;
                    $results[] = [
                        'title' => $keyword,
                        'year' => '',
                        'type' => '图片',
                        'episode' => '',
                        'sub_title' => '',
                        'cover' => $bdImg,
                        'cover_proxy' => '/admin/source/proxyImage?url=' . urlencode($bdImg),
                        'url' => '',
                        'rate' => '',
                        'abstract' => '',
                        'abstract_2' => '',
                    ];
                }
            }
        } else {
            // 豆瓣影视/图书/音乐搜索
            $typeMap = ['movie' => 'movie', 'book' => 'book', 'music' => 'music'];
            $typeLabel = ['movie' => '', 'book' => '图书', 'music' => '音乐'];
            $apiBase = [
                'movie' => 'https://movie.douban.com/j/subject_suggest?q=',
                'book' => 'https://book.douban.com/j/subject_suggest?q=',
                'music' => 'https://music.douban.com/j/subject_suggest?q=',
            ];
            $url = ($apiBase[$searchType] ?? $apiBase['movie']) . urlencode($keyword);
            $response = curlHelper($url, 'GET', null, $headerApi, '', '', 15);
            if (isset($response['error'])) {
                return jerr('搜索请求失败：' . $response['error']);
            }
            $items = json_decode($response['body'] ?? '', true);
            if (!is_array($items) || empty($items)) {
                return jerr('未找到封面图，请手动上传');
            }
            $doubanTypeMap = ['movie' => '电影', 'tv' => '剧集', 'book' => '图书', 'music' => '音乐'];
            foreach ($items as $item) {
                // 影视用 img，图书/音乐用 pic
                $img = $item['img'] ?? ($item['pic'] ?? '');
                $title = $item['title'] ?? '';
                $year = $item['year'] ?? '';
                $id = $item['id'] ?? '';
                $itemType = $item['type'] ?? '';
                $episode = $item['episode'] ?? '';
                $subTitle = $item['sub_title'] ?? '';
                $author = $item['author_name'] ?? '';
                $itemUrl = $item['url'] ?? '';
                if (!$img) continue;

                $row = [
                    'title' => $title,
                    'year' => $year,
                    'type' => $doubanTypeMap[$itemType] ?? ($typeLabel[$searchType] ?? ''),
                    'episode' => $episode,
                    'sub_title' => $subTitle ?: $author,
                    'cover' => $img,
                    'cover_proxy' => '/admin/source/proxyImage?url=' . urlencode($img),
                    'url' => $itemUrl ?: ($id ? 'https://movie.douban.com/subject/' . $id . '/' : ''),
                    'rate' => '',
                    'abstract' => '',
                    'abstract_2' => '',
                ];

                // 图书显示作者信息
                if ($author && $searchType === 'book') {
                    $row['abstract'] = '作者: ' . $author;
                }

                // 影视获取详细信息
                if ($id && $searchType === 'movie') {
                    $detailUrl = 'https://movie.douban.com/j/subject_abstract?subject_id=' . $id;
                    $detailRes = curlHelper($detailUrl, 'GET', null, $headerApi, '', '', 10);
                    if (!isset($detailRes['error']) && !empty($detailRes['body'])) {
                        $detail = json_decode($detailRes['body'], true);
                        if (!empty($detail['subject'])) {
                            $s = $detail['subject'];
                            $row['rate'] = $s['rate'] ?? '';
                            $directors = !empty($s['directors']) ? implode(' / ', $s['directors']) : '';
                            $actors = !empty($s['actors']) ? implode(' / ', array_slice($s['actors'], 0, 3)) : '';
                            $duration = $s['duration'] ?? '';
                            $region = $s['region'] ?? '';
                            $types = !empty($s['types']) ? implode(' / ', $s['types']) : '';
                            $parts = [];
                            if ($directors) $parts[] = '导演: ' . $directors;
                            if ($actors) $parts[] = '主演: ' . $actors;
                            if ($types) $parts[] = $types;
                            if ($duration) $parts[] = $duration;
                            if ($region) $parts[] = $region;
                            $row['abstract'] = implode(' / ', $parts);
                        }
                    }
                    // 移动端详情页获取简介
                    $mUrl = 'https://m.douban.com/movie/subject/' . $id . '/';
                    $mRes = curlHelper($mUrl, 'GET', null, $headerMobile, '', '', 10);
                    if (!isset($mRes['error']) && !empty($mRes['body'])) {
                        $mHtml = $mRes['body'];
                        if (preg_match('/<meta\s+name="description"\s+content="([^"]+)"/i', $mHtml, $dm)) {
                            $desc = $dm[1];
                            if (preg_match('/简介[：:]\s*(.*)/u', $desc, $sm)) {
                                $row['abstract_2'] = trim($sm[1]);
                            } else {
                                $row['abstract_2'] = $desc;
                            }
                        }
                    }
                }
                $results[] = $row;
            }
        }

        if (empty($results)) {
            return jerr('未找到封面图，请手动上传');
        }
        return jok('success', $results);
    }

    /**
     * 代理图片（解决防盗链）
     */
    public function proxyImage()
    {
        $imageUrl = input('url', '');
        if (empty($imageUrl) || !preg_match('/^https?:\/\//i', $imageUrl)) {
            header('HTTP/1.1 403 Forbidden');
            die;
        }
        $referer = 'https://movie.douban.com/';
        if (preg_match('/steamstatic\.com/i', $imageUrl)) {
            $referer = 'https://store.steampowered.com/';
        }
        if (preg_match('/gamersky\.com/i', $imageUrl)) {
            $referer = 'https://www.gamersky.com/';
        }
        if (preg_match('/baidu\.com/i', $imageUrl)) {
            $referer = 'https://image.baidu.com/';
        }
        $header = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: ' . $referer,
        ];
        // 游民星空 HTTPS 需强制 TLS 1.2
        if (preg_match('/^https:\/\/.*gamersky\.com/i', $imageUrl)) {
            $proxyCh = curl_init();
            curl_setopt($proxyCh, CURLOPT_URL, $imageUrl);
            curl_setopt($proxyCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($proxyCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($proxyCh, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($proxyCh, CURLOPT_HTTPHEADER, $header);
            curl_setopt($proxyCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($proxyCh, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($proxyCh, CURLOPT_TIMEOUT, 15);
            curl_setopt($proxyCh, CURLOPT_CONNECTTIMEOUT, 15);
            $proxyBody = curl_exec($proxyCh);
            curl_close($proxyCh);
            if ($proxyBody === false || empty($proxyBody)) {
                header('HTTP/1.1 502 Bad Gateway');
                die;
            }
            $body = $proxyBody;
        } else {
            $response = curlHelper($imageUrl, 'GET', null, $header, '', '', 15);
            if (isset($response['error']) || empty($response['body'])) {
                header('HTTP/1.1 502 Bad Gateway');
                die;
            }
            $body = $response['body'];
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($body) ?: 'image/jpeg';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: public, max-age=86400');
        echo $body;
        die;
    }

    /**
     * 下载远程图片到本地
     */
    public function downloadCover()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $imageUrl = input('url', '');
        if (empty($imageUrl)) {
            return jerr('图片地址不能为空');
        }
        if (!preg_match('/^https?:\/\//i', $imageUrl)) {
            return jerr('图片地址无效');
        }
        $header = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.douban.com/',
        ];
        if (preg_match('/gamersky\.com/i', $imageUrl)) {
            $header[1] = 'Referer: https://www.gamersky.com/';
        }
        if (preg_match('/baidu\.com/i', $imageUrl)) {
            $header[1] = 'Referer: https://image.baidu.com/';
        }
        // 游民星空 HTTPS 需强制 TLS 1.2
        if (preg_match('/^https:\/\/.*gamersky\.com/i', $imageUrl)) {
            $imgCh = curl_init();
            curl_setopt($imgCh, CURLOPT_URL, $imageUrl);
            curl_setopt($imgCh, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($imgCh, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($imgCh, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            curl_setopt($imgCh, CURLOPT_HTTPHEADER, $header);
            curl_setopt($imgCh, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($imgCh, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($imgCh, CURLOPT_TIMEOUT, 20);
            curl_setopt($imgCh, CURLOPT_CONNECTTIMEOUT, 20);
            $imgBody = curl_exec($imgCh);
            $imgErr = ($imgBody === false) ? curl_error($imgCh) : null;
            curl_close($imgCh);
            if ($imgErr) {
                return jerr('图片下载失败：' . $imgErr);
            }
            $body = $imgBody ?: '';
        } else {
            $imgData = curlHelper($imageUrl, 'GET', null, $header, '', '', 20);
            if (isset($imgData['error'])) {
                return jerr('图片下载失败：' . $imgData['error']);
            }
            $body = $imgData['body'] ?? '';
        }
        if (empty($body) || strlen($body) < 100) {
            return jerr('图片下载失败');
        }
        // 判断图片类型
        $ext = 'jpg';
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($body);
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (isset($mimeMap[$mime])) {
            $ext = $mimeMap[$mime];
        }
        $saveDir = public_path() . 'uploads/image/' . date('Ymd');
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $fileName = 'cover_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $filePath = $saveDir . '/' . $fileName;
        file_put_contents($filePath, $body);
        $attachPath = '/uploads/image/' . date('Ymd') . '/' . $fileName;
        return jok('下载成功', ['attach_path' => $attachPath]);
    }

}
