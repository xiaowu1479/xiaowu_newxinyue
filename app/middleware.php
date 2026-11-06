<?php
// 全局中间件定义文件
return [
    // PHP 8 兼容：忽略旧依赖的弃用提示，避免被转换成异常
    \app\middleware\Php8Compat::class,
    // 跨域请求支持
    \think\middleware\AllowCrossDomain::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    \think\middleware\SessionInit::class
];
