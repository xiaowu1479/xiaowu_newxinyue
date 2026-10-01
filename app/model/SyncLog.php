<?php

namespace app\model;

use app\model\QfShop;

class SyncLog extends QfShop
{
    /**
     * 主键
     * 表 qf_sync_log 的主键为 sync_log_id（非默认的 id）
     * 必须显式指定，否则模型操作会用不存在的 id 字段
     *
     * @var string
     */
    protected $pk = 'sync_log_id';
}
