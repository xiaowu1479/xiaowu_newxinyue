<?php

namespace app\model;

use app\model\QfShop;

class Server extends QfShop
{
    protected $name = 'install_server';
    protected $pk = 'server_id';
    protected $autoWriteTimestamp = false;
}
