<?php

namespace app\admin\controller;

use RuntimeException;
use think\App;
use app\admin\QfShop;
use app\model\Conf as ConfModel;
use app\service\BaiduNovelService;
use app\service\ThemeService;

/**
 * 模板管理：前台主题切换 + 各模板独立的板块显隐与外观配置
 * 数据源：config/themes.php 主题注册表
 *
 * 键名规则、主题覆盖的读写与前台合并统一由 app\service\ThemeService 收口，本控制器只做编排与校验。
 *
 * 存储（qf_conf）：
 * - frontend_template        当前启用主题
 * - nav_menu_items           全局生效值（前台模板统一读取的键，始终与当前主题配置同步）
 * - nav_menu_items_{theme}   各主题独立导航配置（conf_status=0，不在「系统参数」页展示）
 * - theme_{theme}_{conf_key} 各主题外观覆盖值（conf_type=20；前台读取时覆盖到原键，缺行回退全局值）
 *
 * Banner（simple 主题首页轮播）：数据表 qf_banner，前台无启用记录时回退扫描 banner 目录
 */
class Template extends QfShop
{
    /**
     * 前台外观设置分组定义
     * site: 站点信息（conf_type=0 全部）；appearance: 页面外观（conf_type=3 非主题键 + 搜索空态两项）；seo: SEO 与统计（conf_type=9 全部）
     */
    private const APPEARANCE_GROUPS = [
        ['key' => 'site', 'title' => '站点信息', 'desc' => '站点品牌、LOGO 与页脚展示内容', 'types' => [0], 'keys' => []],
        ['key' => 'appearance', 'title' => '页面外观', 'desc' => '展示开关、榜单、图片素材与配色', 'types' => [3], 'keys' => ['search_tips', 'search_bg']],
        ['key' => 'seo', 'title' => 'SEO 与统计', 'desc' => '页面标题关键词描述与统计代码', 'types' => [9], 'keys' => []],
    ];

    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new ConfModel();
    }

    /**
     * 主题列表 + 当前主题 + 各主题板块显隐配置
     */
    public function getThemes()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $registry = ThemeService::registry();
        if (empty($registry['themes'])) {
            return jerr("主题注册表为空，请检查 config/themes.php", 500);
        }

        $currentKey = ThemeService::currentThemeKey($registry);

        // 全局生效键缺行时自动补齐
        ThemeService::upsertConfByKey(ThemeService::NAV_MENU_ITEMS_KEY, $this->getNavMenuItemsDefaults(ThemeService::NAV_MENU_ITEMS_KEY, ThemeService::globalNavValue()));

        $themes = [];
        foreach ($registry['themes'] as $key => $theme) {
            // 该主题支持的板块 key（按注册表全局顺序）
            $supported = array_values(array_intersect(
                array_keys($registry['nav_items']),
                (array) ($theme['nav_items'] ?? [])
            ));
            $enabled = array_map('trim', explode(',', ThemeService::themeNavValue((string) $key, $registry)));

            $items = [];
            foreach ($supported as $navKey) {
                $items[] = [
                    'key' => $navKey,
                    'label' => (string) ($registry['nav_items'][$navKey] ?? $navKey),
                    'checked' => in_array($navKey, $enabled, true),
                ];
            }

            $themes[] = [
                'key' => (string) $key,
                'name' => (string) ($theme['name'] ?? $key),
                'directory' => (string) ($theme['directory'] ?? ''),
                'is_current' => $key === $currentKey,
                'configurable' => !empty($supported),
                // 主题专属可配置板块（如 banner），前端据此在抽屉中渲染对应管理区
                'sections' => array_values((array) ($theme['sections'] ?? [])),
                'items' => $items,
            ];
        }

        return jok('数据获取成功', [
            'current' => $currentKey,
            'themes' => $themes,
        ]);
    }

    /**
     * 切换主题：写入 frontend_template，并把该主题的板块配置发布到全局生效键
     */
    public function switchTheme()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $registry = ThemeService::registry();
        $key = (string) input('theme', '');
        if ($key === '' || !isset($registry['themes'][$key])) {
            return jerr("主题不存在", 400);
        }

        ThemeService::upsertConfByKey(ThemeService::FRONTEND_TEMPLATE_KEY, $this->getFrontendTemplateDefaults($key));
        // 该主题的板块配置发布为全局生效值（前台模板统一读取全局键）
        ThemeService::upsertConfByKey(ThemeService::NAV_MENU_ITEMS_KEY, $this->getNavMenuItemsDefaults(ThemeService::NAV_MENU_ITEMS_KEY, ThemeService::themeNavValue($key, $registry)));

        return jok('主题切换成功，请刷新前台查看效果', ['current' => $key]);
    }

    /**
     * 保存指定主题的板块显隐配置
     * 各主题独立存储（nav_menu_items_{theme}），互不影响；
     * 若保存的是当前启用主题，同步发布到全局生效键，前台立即生效
     */
    public function updateNavConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $registry = ThemeService::registry();
        $key = (string) input('theme', '');
        if ($key === '' || !isset($registry['themes'][$key])) {
            return jerr("主题不存在", 400);
        }

        $supported = array_values(array_intersect(
            array_keys($registry['nav_items']),
            (array) ($registry['themes'][$key]['nav_items'] ?? [])
        ));
        if (empty($supported)) {
            return jerr("该模板没有可配置的板块", 400);
        }

        $items = input('post.items');
        if (!is_array($items)) {
            return jerr("参数格式错误", 400);
        }

        // 仅收集该主题支持的板块，按注册表全局顺序稳定输出
        $checked = [];
        foreach ($registry['nav_items'] as $navKey => $label) {
            if (in_array($navKey, $supported, true) && !empty($items[$navKey])) {
                $checked[] = (string) $navKey;
            }
        }
        $value = implode(',', $checked);

        // 写入该主题的独立配置
        $themeConfKey = ThemeService::NAV_MENU_ITEMS_THEME_PREFIX . $key;
        $themeName = (string) ($registry['themes'][$key]['name'] ?? $key);
        ThemeService::upsertConfByKey($themeConfKey, $this->getNavMenuItemsDefaults($themeConfKey, $value, $themeName));

        // 当前启用主题：同步发布到全局生效键
        if ($key === ThemeService::currentThemeKey($registry)) {
            ThemeService::upsertConfByKey(ThemeService::NAV_MENU_ITEMS_KEY, $this->getNavMenuItemsDefaults(ThemeService::NAV_MENU_ITEMS_KEY, $value));
        }

        return jok('板块显示配置保存成功', ['value' => $value]);
    }

    /**
     * 前台外观设置字段列表（按分组返回）
     * 汇聚站点信息（conf_type=0）、前端模版（conf_type=3 非主题键）、搜索空态（conf_type=1 中的 search_tips/search_bg）、SEO 与统计（conf_type=9）；
     * 不过滤 conf_status；排除已由主题卡片管理的键（frontend_template 与 nav_menu_items*）
     * 传 theme 时合并该主题覆盖值（overridden 标记已自定义项，conf_value 已替换为覆盖值）
     */
    public function getAppearance()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $themeKey = (string) input('theme', '');
        $overrides = [];
        if ($themeKey !== '') {
            $registry = ThemeService::registry();
            if (!isset($registry['themes'][$themeKey])) {
                return jerr("主题不存在", 400);
            }
            $overrides = ThemeService::themeOverrides($themeKey);
        }
        $datalist = $this->model->where('conf_type', 'in', [0, 1, 3, 9])
            ->order("conf_sort desc, conf_id asc")->select()->toArray();
        $groups = [];
        foreach (self::APPEARANCE_GROUPS as $group) {
            $groups[$group['key']] = ['key' => $group['key'], 'title' => $group['title'], 'desc' => $group['desc'], 'items' => []];
        }
        foreach ($datalist as $value) {
            if (ThemeService::isManagedKey((string) $value['conf_key'])) {
                continue;
            }
            $groupKey = $this->matchAppearanceGroup((string) $value['conf_key'], (int) $value['conf_type']);
            if ($groupKey === null) {
                continue;
            }
            if ($value['conf_content']) {
                $value['conf_content'] = explode("\n", $value['conf_content']);
            }
            $itemKey = (string) $value['conf_key'];
            $value['overridden'] = isset($overrides[$itemKey]);
            if ($value['overridden']) {
                $value['conf_value'] = $overrides[$itemKey];
            }
            $groups[$groupKey]['items'][] = $value;
        }
        return jok('数据获取成功', array_values($groups));
    }

    /**
     * 保存指定主题的外观设置
     * 写入 theme_{theme}_{conf_key} 覆盖行，不影响全局值与其他主题；
     * reset=1 时清空该主题全部覆盖行（恢复为全局默认）；当前启用主题保存后前台立即生效
     */
    public function saveAppearance()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $registry = ThemeService::registry();
        $themeKey = (string) input('theme', '');
        if ($themeKey === '' || !isset($registry['themes'][$themeKey])) {
            return jerr("主题不存在", 400);
        }
        $items = input('post.items');
        $reset = (int) input('post.reset', 0);

        // 恢复全局默认：按「主题前缀」一次性删除该主题全部覆盖行
        if ($reset === 1 && (!is_array($items) || empty($items))) {
            $prefix = ThemeService::THEME_OVERRIDE_PREFIX . $themeKey . '_';
            $deleted = $this->model->where('conf_key', 'like', $prefix . '%')->delete();
            return jok('已恢复为全局默认配置', ['reset' => 1, 'deleted' => $deleted]);
        }
        if (!is_array($items) || empty($items)) {
            return jerr("参数格式错误", 400);
        }
        // 一次取出全部候选行，避免循环内逐键查询
        $datalist = $this->model->where('conf_type', 'in', [0, 1, 3, 9])->select()->toArray();
        $allowKeys = [];
        foreach ($datalist as $row) {
            $key = (string) $row['conf_key'];
            if (ThemeService::isManagedKey($key)) {
                continue;
            }
            if ($this->matchAppearanceGroup($key, (int) $row['conf_type']) !== null) {
                $allowKeys[$key] = true;
            }
        }
        $saved = 0;
        foreach ($items as $key => $value) {
            $key = (string) $key;
            if (!isset($allowKeys[$key])) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(",", $value);
            }
            ThemeService::upsertThemeOverride($themeKey, $key, (string) $value);
            $saved++;
        }
        if ($saved === 0) {
            return jerr("没有可保存的配置项", 400);
        }
        $msg = $themeKey === ThemeService::currentThemeKey($registry)
            ? '外观设置保存成功，前台已生效'
            : '外观设置保存成功，该主题启用后生效';
        return jok($msg);
    }

    /**
     * 百度小说板块：绑定状态（模板管理「百度小说」卡片用）
     */
    public function baiduNovelStatus()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        return jok('数据获取成功', BaiduNovelService::status());
    }

    /**
     * 百度小说板块：生成扫码登录二维码
     */
    public function baiduNovelQr()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        try {
            return jok('二维码生成成功', BaiduNovelService::qrGenerate());
        } catch (RuntimeException $e) {
            return jerr($e->getMessage(), 500);
        }
    }

    /**
     * 百度小说板块：轮询扫码状态（status=success 时服务端已保存凭据）
     */
    public function baiduNovelQrQuery()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $sign = (string) input('sign', '');
        try {
            return jok('数据获取成功', BaiduNovelService::qrQuery($sign));
        } catch (RuntimeException $e) {
            return jerr($e->getMessage(), 500);
        }
    }

    /**
     * 百度小说板块：解除百度账号绑定
     */
    public function baiduNovelUnbind()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        BaiduNovelService::clearAccount();
        return jok('已解除绑定');
    }

    /**
     * 按 APPEARANCE_GROUPS 定义匹配配置项所属分组，未匹配返回 null
     */
    private function matchAppearanceGroup(string $key, int $type): ?string
    {
        foreach (self::APPEARANCE_GROUPS as $group) {
            if (in_array($type, $group['types'], true) || in_array($key, $group['keys'], true)) {
                return $group['key'];
            }
        }
        return null;
    }

    /**
     * frontend_template conf 行默认数据（conf_content 由注册表生成）
     */
    private function getFrontendTemplateDefaults(string $value = ThemeService::DEFAULT_THEME_KEY): array
    {
        $options = [];
        foreach (ThemeService::registry()['themes'] as $key => $theme) {
            $options[] = ($theme['name'] ?? $key) . '=>' . $key;
        }
        return [
            'conf_key' => ThemeService::FRONTEND_TEMPLATE_KEY,
            'conf_value' => $value,
            'conf_title' => '前台模板',
            'conf_desc' => '切换站点前台模板风格',
            'conf_spec' => 2,
            'conf_content' => implode("\n", $options),
            'conf_type' => 3,
            // 已迁移至「模板管理」独立页：conf_status=0，不在「系统设置」页展示（前台全表读取不受影响）
            'conf_status' => 0,
            'conf_sort' => 82,
            'conf_system' => 1,
        ];
    }

    /**
     * nav_menu_items conf 行默认数据（conf_content 由注册表生成）
     * $key 为全局生效键或主题独立键（nav_menu_items_{theme}）
     */
    private function getNavMenuItemsDefaults(string $key, string $value, string $themeName = ''): array
    {
        $options = [];
        foreach (ThemeService::registry()['nav_items'] as $navKey => $label) {
            $options[] = $label . '=>' . $navKey;
        }
        $isTheme = strpos($key, ThemeService::NAV_MENU_ITEMS_THEME_PREFIX) === 0;

        return [
            'conf_key' => $key,
            'conf_value' => $value,
            'conf_title' => $isTheme ? '顶部导航显示项（' . ($themeName !== '' ? $themeName : $key) . '）' : '顶部导航显示项',
            'conf_desc' => '控制前台顶部导航和手机端抽屉菜单显示哪些按钮',
            'conf_spec' => 3,
            'conf_content' => implode("\n", $options),
            'conf_type' => 3,
            // 全局键与主题独立键均为内部存储：conf_status=0，不在「系统设置」页展示
            'conf_status' => 0,
            'conf_sort' => 81,
            'conf_system' => 1,
        ];
    }
}
