<?php

declare(strict_types=1);

namespace app\index;

use think\App;
use EasyWeChat\Factory;
use think\facade\View;
use app\model\Conf as ConfModel;
use app\service\ThemeService;
use app\service\BannerService;

/**
 * 控制器基础类
 */
abstract class QfShop
{
    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        // 前台配置装载：全量 conf + 当前主题覆盖合并（内部键剔除），规则统一在 ThemeService 维护
        $configs = (new ConfModel())->select()->toArray();
        config(ThemeService::mergeFrontendConfig($configs), 'qfshop');
        // simple 主题首页轮播数据注入（DB 读取、目录回退与缓存统一在 BannerService）
        View::assign('bannerListJson', BannerService::frontendJson());
    }

    /**
     * 主题注册表（config/themes.php）
     */
    protected function getFrontendThemes(): array
    {
        return ThemeService::registry()['themes'];
    }

    protected function getFrontendTemplateKey(): string
    {
        return ThemeService::currentThemeKey();
    }

    protected function getFrontendTemplateDirectory(): string
    {
        return ThemeService::themeDirectory(ThemeService::currentThemeKey());
    }

    protected function fetchFrontendView(string $view): string
    {
        $view = ltrim($view, '/');
        return '/' . $this->getFrontendTemplateDirectory() . '/' . $view;
    }
}
