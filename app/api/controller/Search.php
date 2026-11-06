<?php

namespace app\api\controller;

use app\api\QfShop;
use app\model\Source as SourceModel;
use app\model\SourceCategory as SourceCategoryModel;

class Search extends QfShop
{
    public function index()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getList(input(''));
        return jok('获取成功',$data);
    }
    
    public function getDetail()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getDetail(input(''));
        return jok('获取成功',$data);
    }
    
    /**
     * 增加资源浏览量
     * 用于前端点击资源列表时调用
     */
    public function incrementViews()
    {
        $id = input('id/d', 0);
        if (empty($id)) {
            return jerr('资源ID不能为空');
        }
        
        $SourceModel = new SourceModel();
        $result = $SourceModel->where('source_id', $id)->inc('page_views')->update();
        
        if ($result !== false) {
            return jok('浏览量增加成功');
        } else {
            return jerr('操作失败');
        }
    }
    
    public function getNew()
    {
        $SourceModel = new SourceModel();
        $data = input('');
        $data['page_size'] = $data['page_size']??20;
        $data = $SourceModel->getNew($data);
        return jok('获取成功',$data);
    }
    
    public function getHot()
    {
        $SourceModel = new SourceModel();
        $data = $SourceModel->getHot(input(''));
        return jok('获取成功',$data);
    }
    
    public function getCategory()
    {
        $SourceCategoryModel = new SourceCategoryModel();
        $data = $SourceCategoryModel->getList(input(''));
        return jok('获取成功',$data);
    }

    /**
     * 获取分类资源列表
     */
    public function getCategoryResources()
    {
        $categoryId = input('category_id', 0);
        $page = max(1, intval(input('page', 1)));
        $pageSize = max(1, min(100, intval(input('page_size', 20))));
        $sort = input('sort', 'time');
        $isTime = intval(input('is_time', 0));
        $isTime = in_array($isTime, [0, 1], true) ? $isTime : 0;
        $panType = input('pan_type', '');

        $map = [];
        $map[] = ['status', '=', 1];
        $map[] = ['is_delete', '=', 0];
        if ($isTime === 1) {
            $map[] = ['is_time', '=', 1];        // 仅查临时资源
        } else {
            $map[] = ['is_time', 'in', [0, 2]];  // 默认（0）：永久 + 外部资源
        }

        // 网盘类型筛选
        if ($panType !== '' && is_numeric($panType)) {
            $map[] = ['is_type', '=', intval($panType)];
        }

        if ($categoryId > 0) {
            $map[] = ['source_category_id', '=', $categoryId];
        }

        $SourceModel = new SourceModel();
        $result = $SourceModel->where($map)
            ->field('source_id as id,title,url,code,description,vod_content,create_time,update_time,is_type,source_category_id,vod_pic as src,page_views,is_time')
            ->when($sort === 'hot', function ($query) {
                $query->order(['page_views' => 'desc', 'create_time' => 'desc']);
            }, function ($query) {
                // 按时间排序时，优先用 update_time，为 0 则回退到 create_time
                $query->orderRaw('IF(update_time > 0, update_time, create_time) DESC');
            })
            ->paginate([
                'list_rows' => $pageSize,
                'page' => $page,
            ]);

        $list = $result->items();

        foreach ($list as &$item) {
            $rawTime = !empty($item['update_time']) && $item['update_time'] != '0' ? $item['update_time'] : $item['create_time'];
            if (is_numeric($rawTime)) {
                $timestamp = intval($rawTime);
            } else {
                $timestamp = strtotime($rawTime);
            }
            $item['times'] = $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '未知时间';
            // 简介优先用 description，为空则回退到 vod_content
            $item['description'] = !empty($item['description']) ? $item['description'] : (trim((string)$item['vod_content']) !== '' ? $item['vod_content'] : '');
            unset($item['create_time'], $item['update_time'], $item['vod_content']);
        }
        unset($item);

        // 加密外部资源(is_time=2)链接，前端通过 save_url 转存后显示
        encryptExternalUrls($list);

        return jok('获取成功', [
            'list' => $list,
            'total' => $result->total(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }
}
