<?php

namespace app\api\controller;

use RuntimeException;
use app\api\QfShop;
use app\service\BaiduNovelService;

/**
 * 百度小说板块接口（代理百度网盘小说资源，需后台扫码绑定百度账号）
 *
 * - GET /api/novel/categories  分类列表（data 为字符串数组）
 * - GET /api/novel/list        列表（page/page_size/category/book_status）
 * - GET /api/novel/search      搜索（keyword/page/page_size）
 * - GET /api/novel/code        生成网盘口令（book_id/title/author）
 */
class Novel extends QfShop
{
    /**
     * 分类列表（对齐 lean9 /api/novel/categories：data 直接为字符串数组）
     */
    public function categories()
    {
        try {
            $list = BaiduNovelService::fetchTaglist();
        } catch (RuntimeException $e) {
            return jerr($e->getMessage());
        }
        return jok('数据获取成功', $list);
    }

    /**
     * 小说列表
     * GET /api/novel/list?page=1&page_size=24&category=all&book_status=-1
     */
    public function list()
    {
        $page = max(1, intval(input('page', 1)));
        $pageSize = max(1, min(50, intval(input('page_size', 24))));
        $category = trim((string) input('category', 'all'));
        $bookStatus = intval(input('book_status', -1));

        try {
            $data = BaiduNovelService::fetchSearch($page, $pageSize, '', $category, $bookStatus);
        } catch (RuntimeException $e) {
            return jerr($e->getMessage());
        }
        // 对齐 lean9 契约：分页字段名为 page_size
        $data['page_size'] = $data['limit'];
        return jok('数据获取成功', $data);
    }

    /**
     * 小说搜索
     * GET /api/novel/search?keyword=xxx&page=1&page_size=24
     */
    public function search()
    {
        $keyword = trim((string) input('keyword', input('wd', '')));
        if ($keyword === '') {
            return jerr('搜索关键词不能为空');
        }
        $page = max(1, intval(input('page', 1)));
        $pageSize = max(1, min(50, intval(input('page_size', 24))));

        try {
            $data = BaiduNovelService::fetchSearch($page, $pageSize, $keyword, 'all', -1);
        } catch (RuntimeException $e) {
            return jerr($e->getMessage());
        }
        $data['page_size'] = $data['limit'];
        return jok('数据获取成功', $data);
    }

    /**
     * 生成百度网盘口令（对齐 lean9 novels 页：点击卡片弹出口令，复制后到网盘 App 保存）
     * GET /api/novel/code?book_id=xxx&title=xxx&author=xxx
     */
    public function code()
    {
        $bookId = trim((string) input('book_id', ''));
        $title = trim((string) input('title', ''));
        $author = trim((string) input('author', ''));
        if ($bookId === '') {
            return jerr('缺少书籍标识');
        }
        try {
            $pcode = BaiduNovelService::genCode($bookId, $title, $author);
        } catch (RuntimeException $e) {
            return jerr($e->getMessage());
        }
        return jok('数据获取成功', ['pcode' => $pcode]);
    }
}
