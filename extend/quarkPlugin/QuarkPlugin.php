<?php

namespace QuarkPlugin;

use think\facade\Db;
use think\facade\Request;
use think\Exception;
use app\model\Source as SourceModel;
use app\model\SourceLog as SourceLogModel;

class QuarkPlugin
{
    protected $model;
    protected $SourceLogModel;

    public function __construct()
    {
        $this->model = new SourceModel();
        $this->SourceLogModel = new SourceLogModel();
        $this->source_category_id = 0;
    }

    public function import($allData, $source_category_id, $is_time = 0)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转入链接', $length);
        $result = ['total' => $length, 'success' => 0, 'skip' => 0, 'fail' => 0];

        foreach ($allData as $data) {
            $status = $this->processSingleData($data, $logId, $length, 1, $is_time);
            $result[$status]++;
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
        return $result;
    }

    public function transfer($allData, $source_category_id, $is_time = 0)
    {
        $this->source_category_id = $source_category_id;

        $length = count($allData);
        $logId = $this->SourceLogModel->addLog('批量转存他人链接', $length);
        $result = ['total' => $length, 'success' => 0, 'skip' => 0, 'fail' => 0];

        foreach ($allData as $data) {
            $status = $this->processSingleData($data, $logId, $length, 0, $is_time);
            $result[$status]++;
        }

        $this->SourceLogModel->editLog($logId, $length, '', '', 3);
        return $result;
    }

    function processSingleData($value, $logId = 0, $total_result = 0, $isType = 0, $is_time = 0)
    {
        $detail = $this->model->where('title', $value['title'])->where('is_type', determineIsType($value['url']))->find();
        if (!empty($detail)) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'skip_num', '重复跳过转存');
            }
            return 'skip';
        }

        $url = $value['url'];
        $substring = strstr($url, 's/');
        if ($substring === false) {
            if (!empty($logId)) {
                $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', '资源地址格式有误');
            }
            return 'fail';
        }

        $source_category_id = $value['source_category_id'] ?? $this->source_category_id;

        if ($isType == 1) {
            $data = [
                "title" => $value['title'] ?? '',
                "url" => $url,
                "is_type" => determineIsType($url),
                "code" => $value['code'] ?? '',
                "source_category_id" => $source_category_id,
                "is_time" => $is_time,
                "update_time" => time(),
                "create_time" => time(),
                "fid" => ''
            ];
        } else {
            $urlData = [
                'expired_type' => 1,  // 1正式资源 2临时资源
                'url' => $url,
                'code' => $value['code'] ?? '',
                'isType' => $isType
            ];

            $transfer = new \netdisk\Transfer();
            $res = $transfer->transfer($urlData);

            if ($res['code'] !== 200) {
                if (!empty($logId)) {
                    $this->SourceLogModel->editLog($logId, $total_result, 'fail_num', $res['message']);
                }
                return 'fail';
            }

            $title = empty($value['title']) ? preg_replace('/^\d+\./', '', $res['data']['title']) : $value['title'];
            $data = [
                "title" => $title,
                "url" => $res['data']['share_url'],
                "is_type" => determineIsType($res['data']['share_url']),
                "code" => $res['data']['code'] ?? $value['code'] ?? '',
                "source_category_id" => $source_category_id,
                "is_time" => $is_time,
                "update_time" => time(),
                "create_time" => time(),
                "fid" => is_array($res['data']['fid'] ?? '') ? json_encode($res['data']['fid']) : ($res['data']['fid'] ?? '')
            ];
        }

        $this->model->insertGetId($data);
        if (!empty($logId)) {
            $this->SourceLogModel->editLog($logId, $total_result, 'new_num', '');
        }
        return 'success';
    }
}
