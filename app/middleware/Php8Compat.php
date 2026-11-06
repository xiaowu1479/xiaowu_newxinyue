<?php

declare(strict_types=1);

namespace app\middleware;

use Closure;

/**
 * PHP 8 兼容中间件
 *
 * 旧版依赖（如 PHPExcel）在 PHP 8.1/8.2 下会产生大量弃用提示。
 * ThinkPHP 默认会把所有错误转换成异常，导致导出/导入直接 500。
 * 这里只忽略弃用级别，其他错误仍按原逻辑处理。
 */
class Php8Compat
{
    public function handle($request, Closure $next)
    {
        error_reporting(error_reporting() & ~E_DEPRECATED & ~E_USER_DEPRECATED);

        return $next($request);
    }
}
