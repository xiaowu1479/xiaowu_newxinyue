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
        if (input('is_type') !== '' && input('is_type') !== null) {
            $map[] = ['is_type', '=', input('is_type')];
        }
        if (!empty(input('account_name'))) {
            $map[] = ['account_name', '=', input('account_name')];
        }
        if (input('is_time') !== '' && input('is_time') !== null) {
            $map[] = ['is_time', '=', input('is_time')];
        } else {
            // 默认查询永久资源(0)和外部资源(2)，排除临时资源(1)
            $map[] = ['is_time', 'in', [0, 2]];
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
     * 解析Excel文件，返回识别到的记录列表（不入库）
     */
    public function parseExcel()
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

            try {
                validate(['file' => 'filesize:10485760|fileExt:xlsx,xls'])
                    ->check(['file' => $file]);
            } catch (\Exception $e) {
                return jerr('文件验证失败：' . $e->getMessage());
            }

            $saveName = Filesystem::putFile('excel', $file);
            if (!$saveName) {
                return jerr('文件保存失败，请检查 uploads 目录权限');
            }

            ini_set("memory_limit", -1);
            set_time_limit(0);

            $file_name = Filesystem::path($saveName);
            if (!file_exists($file_name)) {
                return jerr('文件不存在');
            }

            $extension = pathinfo($file_name, PATHINFO_EXTENSION);
            if ($extension == 'xlsx') {
                $PHPReader = new \PHPExcel_Reader_Excel2007();
            } elseif ($extension == 'xls') {
                $PHPReader = new \PHPExcel_Reader_Excel5();
            } else {
                Filesystem::delete($saveName);
                return jerr('不支持的文件类型');
            }

            $objExcel = $PHPReader->load($file_name);
            $excel_array = $objExcel->getSheet(0)->toArray();
            Filesystem::delete($saveName);

            array_shift($excel_array);
            array_shift($excel_array);

            $panTypes = config('pan_types');
            $panTypeMap = [];
            foreach ($panTypes as $type) {
                $panTypeMap[$type['id']] = $type['name'];
            }

            $list = [];
            foreach ($excel_array as $v) {
                $title = isset($v[0]) ? trim($v[0]) : '';
                $url = isset($v[1]) ? trim($v[1]) : '';
                if (empty($url)) {
                    continue;
                }
                $typeId = determineIsType($url);
                $list[] = [
                    'title' => $title,
                    'url' => $url,
                    'code' => '',
                    'type_id' => $typeId,
                    'type_name' => $panTypeMap[$typeId] ?? '未知',
                    'category_name' => isset($v[2]) ? trim($v[2]) : '',
                    'account_name' => isset($v[3]) ? trim($v[3]) : '',
                    'description' => isset($v[4]) ? trim($v[4]) : '',
                    'vod_content' => isset($v[5]) ? trim($v[5]) : '',
                ];
            }

            return jok('解析成功', $list);
        } catch (\Exception $error) {
            return jerr('解析失败，请检查文件格式');
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
            // 支持直接传入已解析的记录列表（跳过被用户删除的记录）
            $parsedData = input("parsed_data");
            $excel_array = null;

            if (!empty($parsedData)) {
                $decoded = json_decode($parsedData, true);
                if (!is_array($decoded)) {
                    return jerr('解析数据格式有误');
                }
                $excel_array = [];
                foreach ($decoded as $item) {
                    $excel_array[] = [
                        $item['title'] ?? '',
                        $item['url'] ?? '',
                        $item['category_name'] ?? '',
                        $item['account_name'] ?? '',
                        $item['description'] ?? '',
                        $item['vod_content'] ?? '',
                    ];
                }
            }

            if ($excel_array === null) {
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

                Filesystem::delete($saveName);
            }

            $data = [];
            $i = 0;
            $existing_data = [];

            $existing_records = $this->model->field('title, is_type')->select()->toArray();
            
            \think\facade\Log::info('现有记录数：' . count($existing_records));
            
            foreach ($existing_records as $record) {
                $existing_data[$record['title'] . '_' . $record['is_type']] = true;
            }

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
     * 解析输入文本，返回识别到的记录列表（不入库）
     */
    public function parseImport()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (empty(input("urls"))) {
            return jerr('请输入资源内容');
        }

        $allData = parsePanLinks(input("urls"));

        $panTypes = config('pan_types');
        $panTypeMap = [];
        foreach ($panTypes as $type) {
            $panTypeMap[$type['id']] = $type['name'];
        }

        $list = [];
        foreach ($allData as $item) {
            $typeId = determineIsType($item['url']);
            $list[] = [
                'title' => $item['title'],
                'url' => $item['url'],
                'code' => $item['code'] ?? '',
                'type_id' => $typeId,
                'type_name' => $panTypeMap[$typeId] ?? '未知',
            ];
        }

        return jok('解析成功', $list);
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
        if (empty(input("type"))) {
            return jerr('参数不能为空');
        }

        $source_category_id = input('source_category_id') ?? 0;
        $is_time = intval(input('is_time', 0));

        // 支持直接传入已解析的记录列表（跳过被用户删除的记录）
        $parsedData = input("parsed_data");
        if (!empty($parsedData)) {
            $allData = json_decode($parsedData, true);
            if (!is_array($allData)) {
                return jerr('解析数据格式有误');
            }
        } else {
            if (empty(input("urls"))) {
                return jerr('参数不能为空');
            }
            $allData = parsePanLinks(input("urls"));
        }

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
     * 全部转存 
     * @return void
     */
    public function transferAll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        if (empty(input('source_category_id'))) {
            return jerr('参数异常');
        }
        $quarkPlugin = new QuarkPlugin();
        $quarkPlugin->transferAll(input('source_category_id'));
        return jok('已提交任务，稍后查看结果');
    }

    /**
     * 获取夸克网盘文件夹
     *
     * @return void
     */
    public function getFiles()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $quarkPlugin = new QuarkPlugin();
        $result = $quarkPlugin->getFiles(input('type') ?? 0, input('pdir_fid') ?? 0);

        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok('获取成功', $result['data']);
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

    public function guangyaQrcode()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $urlData = [
                'scope' => 'user',
                'client_id' => 'aMe-8VSlkrbQXpUR',
            ];

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ];

            $res = curlHelper(
                'https://account.guangyapan.com/v1/auth/device/code',
                'POST',
                json_encode($urlData),
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('请求光鸭服务器失败: ' . $res['error']);
            }

            $data = json_decode($res['body'] ?? '', true);
            if (empty($data) || empty($data['device_code'])) {
                return jerr('获取光鸭二维码失败: ' . ($res['body'] ?? 'empty response'));
            }

            $verifyUrl = $data['verification_uri_complete'] ?? ($data['verification_url'] ?? '');

            require_once root_path('extend') . 'phpqrcode' . DIRECTORY_SEPARATOR . 'phpqrcode.php';
            ob_start();
            \QRcode::png($verifyUrl, false, QR_ECLEVEL_M, 6, 2);
            $imageData = ob_get_clean();
            $base64 = 'data:image/png;base64,' . base64_encode($imageData);

            cache('guangya_device_code', $data['device_code'], $data['expires_in'] ?? 600);

            return jok('获取成功', [
                'code_url' => $base64,
                'raw_url' => $verifyUrl,
                'expires_in' => $data['expires_in'] ?? 600,
                'interval' => $data['interval'] ?? 5,
            ]);
        } catch (\Exception $e) {
            return jerr('获取二维码异常: ' . $e->getMessage());
        }
    }

    public function guangyaQrcodePoll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $deviceCode = cache('guangya_device_code');
            if (empty($deviceCode)) {
                return jerr('二维码已过期，请重新获取');
            }

            $urlData = [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'device_code' => $deviceCode,
                'client_id' => 'aMe-8VSlkrbQXpUR',
            ];

            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ];

            $res = curlHelper(
                'https://account.guangyapan.com/v1/auth/token',
                'POST',
                json_encode($urlData),
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('请求光鸭服务器失败: ' . $res['error']);
            }

            $httpCode = 0;
            if (!empty($res['detail']) && !empty($res['detail']['http_code'])) {
                $httpCode = $res['detail']['http_code'];
            }

            $data = json_decode($res['body'] ?? '', true);

            if ($httpCode == 200 && !empty($data['access_token'])) {
                $authFile = app()->getConfigPath() . 'guangya_auth.json';
                $existingData = [];
                if (file_exists($authFile)) {
                    $existingData = json_decode(file_get_contents($authFile), true) ?: [];
                }

                $cookieJson = json_encode(array_merge($existingData, [
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? '',
                    'expires_in' => $data['expires_in'] ?? 3600,
                    'token_type' => $data['token_type'] ?? 'Bearer',
                    'scope' => $data['scope'] ?? 'user',
                ]));

                file_put_contents($authFile, $cookieJson);

                cache('guangya_device_code', null);

                return jok('登录成功', [
                    'status' => 'success',
                    'cookie' => $cookieJson,
                ]);
            }

            if (!empty($data['error'])) {
                $errorType = $data['error'];
                if ($errorType === 'authorization_pending') {
                    return jok('等待扫码', ['status' => 'waiting']);
                }
                if ($errorType === 'slow_down') {
                    return jok('请稍后重试', ['status' => 'waiting']);
                }
                if ($errorType === 'expired_token') {
                    cache('guangya_device_code', null);
                    return jerr('二维码已过期，请重新获取');
                }
                if ($errorType === 'access_denied') {
                    cache('guangya_device_code', null);
                    return jerr('用户拒绝授权');
                }
            }

            return jok('等待扫码', ['status' => 'waiting']);
        } catch (\Exception $e) {
            return jerr('轮询异常: ' . $e->getMessage());
        }
    }

    public function guangyaGetAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $authFile = app()->getConfigPath() . 'guangya_auth.json';
        $cookieJson = '';
        $hasAuth = false;
        if (file_exists($authFile)) {
            $cookieJson = file_get_contents($authFile);
            $decoded = json_decode($cookieJson, true);
            $hasAuth = !empty($decoded) && !empty($decoded['access_token']);
        }

        return jok('获取成功', [
            'cookie' => $cookieJson,
            'has_auth' => $hasAuth,
        ]);
    }

    public function guangyaSaveAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $cookieJson = input('cookie', '');
        if (empty($cookieJson)) {
            return jerr('凭证不能为空');
        }

        $decoded = json_decode($cookieJson, true);
        if (empty($decoded) || empty($decoded['access_token'])) {
            return jerr('凭证格式无效，需要包含 access_token');
        }

        $authFile = app()->getConfigPath() . 'guangya_auth.json';
        file_put_contents($authFile, $cookieJson);

        return jok('保存成功');
    }

    /**
     * 验证光鸭凭证是否有效
     */
    public function guangyaCheckAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $transfer = new \netdisk\Transfer();
            // type=10 是光鸭网盘，parent_id='' 表示根目录
            $result = $transfer->getFiles(10, '');
            if ($result['code'] != 200) {
                return jerr('凭证无效: ' . ($result['message'] ?? '文件列表获取失败'));
            }
            return jok('凭证有效，文件列表获取成功');
        } catch (\Exception $e) {
            return jerr('验证异常: ' . $e->getMessage());
        }
    }

    /**
     * 清空光鸭网盘凭证（退出登录）
     * 清除本地凭证文件及扫码会话缓存
     */
    public function guangyaClearAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $cleared = [];

        // 1️⃣ 删除主凭证文件 config/guangya_auth.json
        $authFile = app()->getConfigPath() . 'guangya_auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
            $cleared[] = 'guangya_auth.json';
        }

        // 2️⃣ 清除扫码会话缓存
        cache('guangya_device_code', null);
        $cleared[] = 'guangya_device_code(cache)';

        return jok('凭证已清空', ['cleared' => $cleared]);
    }

    // ─────────────────────────────────────────────
    // 迅雷云盘 - 扫码登录（PanBot OAuth 代理流）
    // ─────────────────────────────────────────────

    private $xunleiClientId = 'ad4urtDU3UbYHeg8';
    private $panbotBaseUrl = 'https://api.panbot.cn';
    private $panbotBackdoorKey = 'test-backdoor-2026';

    public function xunleiQrcode()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            // 调 PanBot 发起授权
            $headers = [
                'X-Backdoor-Key: ' . $this->panbotBackdoorKey,
                'Accept: application/json',
            ];

            $res = curlHelper(
                $this->panbotBaseUrl . '/api/v1/xunlei/oauth/start',
                'GET',
                null,
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('请求PanBot服务失败: ' . $res['error']);
            }

            $body = json_decode($res['body'] ?? '', true);
            if (empty($body) || ($body['code'] ?? 0) !== 200 || empty($body['data']['auth_url'])) {
                $msg = $body['message'] ?? '获取授权地址失败';
                return jerr($msg);
            }

            $sessionId = $body['data']['session_id'] ?? '';
            $authUrl = $body['data']['auth_url'] ?? '';
            $deviceId = $body['data']['device_id'] ?? '';

            if (empty($authUrl)) {
                return jerr('授权地址为空');
            }

            // 生成二维码
            $qrcodeFile = root_path('extend') . 'phpqrcode' . DIRECTORY_SEPARATOR . 'phpqrcode.php';
            $base64 = '';
            if (file_exists($qrcodeFile)) {
                require_once $qrcodeFile;
                ob_start();
                \QRcode::png($authUrl, false, QR_ECLEVEL_M, 6, 2);
                $imageData = ob_get_clean();
                $base64 = 'data:image/png;base64,' . base64_encode($imageData);
            }

            // 缓存 session_id 供轮询使用
            cache('xunlei_session_id', $sessionId, 300);
            cache('xunlei_device_id', $deviceId, 86400);

            return jok('获取成功', [
                'code_url' => $base64,
                'raw_url' => $authUrl,
                'expires_in' => 300,
                'session_id' => $sessionId,
            ]);
        } catch (\Exception $e) {
            return jerr('获取二维码异常: ' . $e->getMessage());
        }
    }

    /**
     * 轮询授权结果（2 秒间隔）
     */
    public function xunleiQrcodePoll()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $sessionId = cache('xunlei_session_id');
        if (empty($sessionId)) {
            return jerr('授权会话不存在，请重新扫码');
        }

        try {
            $headers = [
                'X-Backdoor-Key: ' . $this->panbotBackdoorKey,
                'Accept: application/json',
            ];

            $res = curlHelper(
                $this->panbotBaseUrl . '/api/v1/xunlei/oauth/poll?session_id=' . urlencode($sessionId),
                'GET',
                null,
                $headers
            );

            if (!empty($res['error'])) {
                return jerr('轮询请求失败: ' . $res['error']);
            }

            $body = json_decode($res['body'] ?? '', true);
            if (empty($body)) {
                return jok('等待响应', ['status' => 'waiting']);
            }

            $status = $body['data']['status'] ?? 'pending';

            if ($status === 'done') {
                // 授权成功，保存凭证
                $data = $body['data'];
                $authFile = app()->getConfigPath() . 'xunlei_auth.json';
                $cookieJson = json_encode([
                    'access_token'  => $data['access_token'] ?? '',
                    'refresh_token' => $data['refresh_token'] ?? '',
                    'expires_in'    => $data['expires_in'] ?? 7776000,
                    'device_id'     => $data['device_id'] ?? '',
                    'token_type'    => 'Bearer',
                    'scope'         => 'profile',
                    'sub'           => $data['user_info']['platform_user_id'] ?? '',
                    'user_id'       => $data['user_info']['platform_user_id'] ?? '',
                    'nickname'      => $data['user_info']['nickname'] ?? '',
                    'avatar'        => $data['user_info']['avatar'] ?? '',
                ]);
                file_put_contents($authFile, $cookieJson);

                // 保存 device_id 用于后续 API
                if (!empty($data['device_id'])) {
                    $deviceFile = app()->getConfigPath() . 'xunlei_device.json';
                    file_put_contents($deviceFile, json_encode([
                        'device_id' => $data['device_id'],
                    ]));
                }

                cache('xunlei_session_id', null);

                return jok('登录成功', [
                    'status' => 'success',
                    'cookie' => $cookieJson,
                ]);
            }

            if ($status === 'expired') {
                cache('xunlei_session_id', null);
                $msg = $body['data']['message'] ?? '授权已过期，请重新扫码';
                return jerr($msg);
            }

            // status === 'pending'
            return jok('等待扫码', ['status' => 'waiting']);
        } catch (\Exception $e) {
            return jerr('轮询异常: ' . $e->getMessage());
        }
    }

    public function xunleiGetAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $authFile = app()->getConfigPath() . 'xunlei_auth.json';
        $cookieJson = '';
        $hasAuth = false;
        if (file_exists($authFile)) {
            $cookieJson = file_get_contents($authFile);
            $decoded = json_decode($cookieJson, true);
            $hasAuth = !empty($decoded) && !empty($decoded['access_token']);
        }

        return jok('获取成功', [
            'cookie' => $cookieJson,
            'has_auth' => $hasAuth,
        ]);
    }

    /**
     * 验证迅雷凭证是否有效
     */
    public function xunleiCheckAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        try {
            $transfer = new \netdisk\Transfer();
            // type=4 是迅雷网盘，parent_id='' 表示根目录
            $result = $transfer->getFiles(4, '');
            if ($result['code'] != 200) {
                return jerr('凭证无效: ' . ($result['message'] ?? '文件列表获取失败'));
            }
            return jok('凭证有效，文件列表获取成功');
        } catch (\Exception $e) {
            return jerr('验证异常: ' . $e->getMessage());
        }
    }

    public function xunleiSaveAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $cookieJson = input('cookie', '');
        if (empty($cookieJson)) {
            return jerr('凭证不能为空');
        }

        $decoded = json_decode($cookieJson, true);
        if (empty($decoded) || empty($decoded['access_token'])) {
            return jerr('凭证格式无效，需要包含 access_token');
        }

        $authFile = app()->getConfigPath() . 'xunlei_auth.json';
        file_put_contents($authFile, $cookieJson);

        return jok('保存成功');
    }

    /**
     * 清空迅雷云盘凭证（退出登录）
     * 清除所有本地凭证文件、token缓存及数据库回退字段
     */
    public function xunleiClearAuth()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }

        $cleared = [];

        // 1️⃣ 删除主凭证文件 config/xunlei_auth.json
        $authFile = app()->getConfigPath() . 'xunlei_auth.json';
        if (file_exists($authFile)) {
            unlink($authFile);
            $cleared[] = 'xunlei_auth.json';
        }

        // 2️⃣ 删除设备 ID 文件 config/xunlei_device.json
        $deviceFile = app()->getConfigPath() . 'xunlei_device.json';
        if (file_exists($deviceFile)) {
            unlink($deviceFile);
            $cleared[] = 'xunlei_device.json';
        }

        // 3️⃣ 删除 access_token 缓存 extend/netdisk/pan/xunlei_token.json
        $tokenFile = root_path('extend') . 'netdisk' . DIRECTORY_SEPARATOR . 'pan' . DIRECTORY_SEPARATOR . 'xunlei_token.json';
        if (file_exists($tokenFile)) {
            unlink($tokenFile);
            $cleared[] = 'xunlei_token.json';
        }

        // 4️⃣ 删除 captcha 缓存 extend/netdisk/pan/xunlei_captcha.json
        $captchaFile = root_path('extend') . 'netdisk' . DIRECTORY_SEPARATOR . 'pan' . DIRECTORY_SEPARATOR . 'xunlei_captcha.json';
        if (file_exists($captchaFile)) {
            unlink($captchaFile);
            $cleared[] = 'xunlei_captcha.json';
        }

        // 5️⃣ 清空数据库回退字段 xunlei_cookie
        \think\facade\Db::name('conf')->where('conf_key', 'xunlei_cookie')->update([
            'conf_value' => ''
        ]);
        $cleared[] = 'xunlei_cookie';

        // 6️⃣ 清除扫码会话缓存
        cache('xunlei_session_id', null);
        cache('xunlei_device_id', null);

        return jok('凭证已清空', ['cleared' => $cleared]);
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
            // 注意：豆瓣已关闭影视/音乐的 subject_suggest 接口（返回空数组 []），
            // 仅图书 subject_suggest 仍可用。subject_suggest 返回空时回退到移动端搜索。
            $typeLabel = ['movie' => '', 'book' => '图书', 'music' => '音乐'];
            $apiBase = [
                'movie' => 'https://movie.douban.com/j/subject_suggest?q=',
                'book' => 'https://book.douban.com/j/subject_suggest?q=',
                'music' => 'https://music.douban.com/j/subject_suggest?q=',
            ];
            $url = ($apiBase[$searchType] ?? $apiBase['movie']) . urlencode($keyword);
            $response = curlHelper($url, 'GET', null, $headerApi, '', '', 15);
            $items = [];
            if (!isset($response['error']) && !empty($response['body'])) {
                $decoded = json_decode($response['body'], true);
                if (is_array($decoded)) {
                    $items = $decoded;
                }
            }
            // subject_suggest 返回空（影视/音乐接口已失效），回退到移动端搜索
            if (empty($items)) {
                $items = $this->searchDoubanMobile($keyword, $searchType, $headerMobile);
            }
            if (empty($items)) {
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
                // 豆瓣有时返回的 sub_title 与 title 完全相同（如"盘龙"），去重避免显示为"盘龙 盘龙"
                if ($subTitle !== '' && $subTitle === $title) {
                    $subTitle = '';
                }
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
     * 豆瓣移动端搜索（subject_suggest 接口失效时的回退方案）
     * 豆瓣已关闭影视/音乐的 subject_suggest 接口，改用 m.douban.com/search 移动端页面解析。
     * 返回与 subject_suggest 兼容的数组结构（含 id/title/img/pic/type/url 等字段）。
     * @param string $keyword 搜索关键词
     * @param string $searchType movie|book|music
     * @param array $headerMobile 移动端请求头
     * @return array
     */
    private function searchDoubanMobile($keyword, $searchType, $headerMobile)
    {
        // 移动端搜索 type 参数：movie/book/music 均可直接使用，返回综合搜索结果
        // 通过 href 前缀（/movie/subject/、/book/subject/、/music/subject/）过滤出对应类型
        $typeParamMap = ['movie' => 'movie', 'book' => 'book', 'music' => 'music'];
        $typeParam = $typeParamMap[$searchType] ?? 'movie';
        $hrefPrefix = $typeParam; // href 前缀与 type 参数一致

        $url = 'https://m.douban.com/search/?query=' . urlencode($keyword) . '&type=' . $typeParam;
        $res = curlHelper($url, 'GET', null, $headerMobile, '', '', 15);
        if (isset($res['error']) || empty($res['body'])) {
            return [];
        }
        $html = $res['body'];

        $items = [];
        // 匹配每个搜索结果条目：
        // <a href="/movie/subject/1652587/"><img src="..."/><div class="subject-info"><span class="subject-title">标题</span>...</div></a>
        $pattern = '/<a\s+href="\/(' . preg_quote($hrefPrefix, '/') . ')\/subject\/(\d+)\/"[^>]*>\s*'
            . '<img[^>]*src="([^"]*)"[^>]*>\s*'
            . '<div class="subject-info">\s*<span class="subject-title">([^<]*)<\/span>(.*?)<\/div>\s*<\/a>/is';
        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($matches as $m) {
                $itemType = $m[1];
                $id = $m[2];
                $img = $m[3];
                $title = trim(html_entity_decode($m[4], ENT_QUOTES, 'UTF-8'));
                $infoHtml = $m[5];

                if (isset($seen[$id]) || empty($img) || empty($title)) {
                    continue;
                }
                $seen[$id] = true;

                // 提取评分：rating-stars 的 data-rating 为 0-100，需除以 10 转为 0-10 分制
                $rate = '';
                if (preg_match('/data-rating="([\d.]+)"/i', $infoHtml, $rm)) {
                    $rate = sprintf('%.1f', floatval($rm[1]) / 10);
                }

                // subject_suggest 的 type 字段约定：movie=movie, book=b, music=music
                $normalizedType = $itemType === 'book' ? 'b' : $itemType;

                $items[] = [
                    'id' => $id,
                    'title' => $title,
                    'img' => $img,   // 影视用 img 字段
                    'pic' => $img,   // 图书/音乐用 pic 字段
                    'type' => $normalizedType,
                    'year' => '',
                    'episode' => '',
                    'sub_title' => '',
                    'author_name' => '',
                    'url' => 'https://m.douban.com/' . $itemType . '/subject/' . $id . '/',
                    'rate' => $rate,
                ];
            }
        }
        return $items;
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
