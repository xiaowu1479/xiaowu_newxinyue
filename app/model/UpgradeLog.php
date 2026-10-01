<?php

namespace app\model;

class UpgradeLog extends QfShop
{
    // 对应 qf_upgrade_log 表
    protected $name = 'upgrade_log';
    protected $pk = 'log_id';
    protected $autoWriteTimestamp = false;
}
