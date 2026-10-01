<?php

declare(strict_types=1);

namespace app\index;

use think\App;
use EasyWeChat\Factory;
use app\model\Conf as ConfModel;
use app\model\User as UserModel;

/**
 * 控制器基础类
 */
abstract class QfShop
{
    protected const FRONTEND_TEMPLATE_MAP = [
        'classic' => 'news',
        'simple' => 'simple',
    ];

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
        $this->confModel = new ConfModel();

        $configs = $this->confModel->select()->toArray();
        $c = array_column($configs, 'conf_value', 'conf_key');
        config($c, 'qfshop');
    }

    protected function getFrontendTemplateKey(): string
    {
        $template = (string) config('qfshop.frontend_template', 'simple');

        if (!isset(self::FRONTEND_TEMPLATE_MAP[$template])) {
            return 'simple';
        }

        return $template;
    }

    protected function getFrontendTemplateDirectory(): string
    {
        return self::FRONTEND_TEMPLATE_MAP[$this->getFrontendTemplateKey()];
    }

    protected function fetchFrontendView(string $view): string
    {
        $view = ltrim($view, '/');
        return '/' . $this->getFrontendTemplateDirectory() . '/' . $view;
    }
}
