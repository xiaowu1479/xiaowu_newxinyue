<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'reset:today-search' => 'app\command\ResetTodaySearch',
        'sync:auto' => 'app\command\SyncAuto',
        'subscribe:pull' => 'app\command\SubscribePull',
        'panbot:auto' => 'app\command\PanBotAuto',
        'novel:reap' => 'app\command\NovelReap',
    ],
];
