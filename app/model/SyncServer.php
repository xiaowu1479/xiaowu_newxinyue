<?php

namespace app\model;

use app\model\QfShop;

class SyncServer extends QfShop
{
    /**
     * 主键
     * 表 qf_sync_server 的主键为 sync_server_id（非默认的 id）
     * 必须显式指定，否则 find($id) 会用 WHERE id = ? 查询不存在的字段
     *
     * @var string
     */
    protected $pk = 'sync_server_id';
}
