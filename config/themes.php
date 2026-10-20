<?php
// 前台模板（主题）注册表：前台视图解析与后台「模板管理」板块共用的唯一数据源。
// 新增主题：在 themes 中追加一项即可，无需改动其他代码。
//
// themes.*.directory  视图目录 public/views/index/{directory}
// themes.*.nav_items   该主题支持后台配置显隐的导航/板块 key（未列出的项由模板写死，不提供开关）
// themes.*.sections    该主题的专属可配置板块（模板管理页主题配置抽屉内渲染，如 banner=首页轮播图）
// nav_items            板块 key => 显示名（后台渲染开关与 conf_content 下拉项均由此生成）
//
// 列表第一项为默认主题（frontend_template 配置缺失或非法时回退到它）。
return [
    // 全部板块定义（与前台模板消费的 conf key 保持一致，勿随意改名）
    'nav_items' => [
        'home'        => '首页',
        'ranking'     => '夸克榜单',
        'top250'      => 'Top250',
        'game'        => '游戏排行',
        'category'    => '最近更新',
        'novel'       => '小说',
        'baidu_novel' => '百度小说',
        'contact'     => '联系我们',
        'demand'      => '提交需求',
    ],
    'themes' => [
        'classic' => [
            'name'      => '经典模板',
            'directory' => 'news',
            // 经典模板导航为固定布局，仅「小说」入口支持显隐配置
            'nav_items' => ['novel'],
        ],
        'simple' => [
            'name'      => '简约模板',
            'directory' => 'simple',
            'nav_items' => ['home', 'ranking', 'top250', 'game', 'category', 'novel', 'baidu_novel', 'contact', 'demand'],
            // 首页轮播图管理（数据表 qf_banner，无启用记录时前台回退扫描 banner 目录）
            // 百度小说扫码绑定（凭据存 qf_conf，BaiduNovelService 收口读写）
            'sections'  => ['banner', 'baidu_novel'],
        ],
        'novel' => [
            'name' => '小说模板', 'directory' => 'novel',
            // 小说模板为纯小说站固定布局，仅开放首页推荐位管理（数据表 qf_novel_recommend）
            'nav_items' => [],
            'sections'  => ['novel_recommend'],
        ],
    ],
];
