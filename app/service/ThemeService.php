<?php

namespace app\service;

use app\model\Conf as ConfModel;

/**
 * 前台主题（模板）领域服务
 *
 * 统一收口模板管理域的键名规则、主题注册表读取、覆盖配置的合并与存取，
 * 供后台「模板管理」（app\admin\controller\Template）与前台基类（app\index\QfShop）共用，
 * 键名前缀与存储契约只在本类维护。
 *
 * 存储（qf_conf）：
 * - frontend_template        当前启用主题（前台解析视图目录依赖，保留在 qfshop 配置中）
 * - nav_menu_items           全局生效板块显隐值（前台模板统一读取的键）
 * - nav_menu_items_{theme}   各主题独立板块配置（conf_status=0，不在「系统参数」页展示）
 * - theme_{theme}_{conf_key} 各主题外观覆盖值（conf_type=20；前台合并时覆盖到原键，缺行回退全局值）
 */
class ThemeService
{
    /** conf 表：当前启用主题键 */
    public const FRONTEND_TEMPLATE_KEY = 'frontend_template';
    /** conf 表：全局生效板块显隐键 */
    public const NAV_MENU_ITEMS_KEY = 'nav_menu_items';
    /** 各主题独立板块配置键前缀：{前缀}{主题key} */
    public const NAV_MENU_ITEMS_THEME_PREFIX = 'nav_menu_items_';
    /** 主题外观覆盖键前缀：{前缀}{主题key}_{原conf_key} */
    public const THEME_OVERRIDE_PREFIX = 'theme_';
    /** 主题覆盖行专用 conf_type（不在系统设置页展示） */
    public const THEME_OVERRIDE_TYPE = 20;
    /** 全局板块键缺行时的预置值（与 config/themes.php nav_items、upgrade.sql 预置行保持一致） */
    public const NAV_MENU_ITEMS_DEFAULT = 'home,ranking,top250,game,category,novel,contact';
    /** 注册表异常时的兜底主题 */
    public const DEFAULT_THEME_KEY = 'classic';
    /** 百度小说扫码绑定凭据键前缀（凭据不进前后台 qfshop 配置） */
    public const BAIDU_NOVEL_KEY_PREFIX = 'baidu_novel_';

    /**
     * 读取主题注册表（config/themes.php）
     */
    public static function registry(): array
    {
        $navItems = config('themes.nav_items');
        $themes = config('themes.themes');
        return [
            'nav_items' => is_array($navItems) ? $navItems : [],
            'themes' => is_array($themes) ? $themes : [],
        ];
    }

    /**
     * 当前主题 key（conf 缺失或非法时回退注册表第一项）
     * 依赖 config('qfshop') 已由各模块基类 initialize 装载
     */
    public static function currentThemeKey(?array $registry = null): string
    {
        $registry = $registry ?? self::registry();
        $template = (string) config('qfshop.frontend_template', '');
        if ($template !== '' && isset($registry['themes'][$template])) {
            return $template;
        }
        // 列表第一项为默认主题，注册表异常时回退 classic
        $keys = array_keys($registry['themes']);
        return $keys[0] ?? self::DEFAULT_THEME_KEY;
    }

    /**
     * 指定主题的视图目录（未知主题回退注册表第一项目录，注册表异常兜底 news）
     */
    public static function themeDirectory(string $themeKey): string
    {
        $themes = self::registry()['themes'];
        if (isset($themes[$themeKey]['directory'])) {
            return (string) $themes[$themeKey]['directory'];
        }
        // 回退值不再硬编码：未知主题用注册表第一项（默认主题）的目录
        $keys = array_keys($themes);
        $fallbackKey = $keys[0] ?? self::DEFAULT_THEME_KEY;
        return (string) ($themes[$fallbackKey]['directory'] ?? 'news');
    }

    /**
     * 判断 conf_key 是否为模板管理域内部键（外观表单过滤 / 前台配置装载剔除）
     */
    public static function isManagedKey(string $key): bool
    {
        return $key === self::FRONTEND_TEMPLATE_KEY
            || $key === self::NAV_MENU_ITEMS_KEY
            || strpos($key, self::NAV_MENU_ITEMS_THEME_PREFIX) === 0
            || strpos($key, self::THEME_OVERRIDE_PREFIX) === 0
            || strpos($key, self::BAIDU_NOVEL_KEY_PREFIX) === 0;
    }

    /**
     * 前台 qfshop 配置装载：全量 conf 键值 + 当前主题覆盖合并
     * - theme_{当前主题}_{原键} 的自定义值覆盖到原键上（模板管理页按主题独立保存）
     * - 剔除主题域内部键（主题覆盖行、各主题独立导航行），避免垃圾键进入前台配置；
     *   frontend_template 与全局 nav_menu_items 保留（前台解析视图目录与导航显隐依赖）
     *
     * @param array $configs conf 表全量行（含 conf_key/conf_value）
     */
    public static function mergeFrontendConfig(array $configs): array
    {
        $c = array_column($configs, 'conf_value', 'conf_key');

        $themes = self::registry()['themes'];
        $template = (string) ($c[self::FRONTEND_TEMPLATE_KEY] ?? '');
        if ($themes && $template !== '' && isset($themes[$template])) {
            $prefix = self::THEME_OVERRIDE_PREFIX . $template . '_';
            $len = strlen($prefix);
            foreach ($configs as $row) {
                $key = (string) $row['conf_key'];
                if (strpos($key, $prefix) === 0 && strlen($key) > $len) {
                    $c[substr($key, $len)] = (string) $row['conf_value'];
                }
            }
        }

        foreach (array_keys($c) as $key) {
            if ($key !== self::NAV_MENU_ITEMS_KEY && strpos($key, self::NAV_MENU_ITEMS_THEME_PREFIX) === 0) {
                unset($c[$key]);
            } elseif (
                strpos($key, self::THEME_OVERRIDE_PREFIX) === 0
                || strpos($key, self::BAIDU_NOVEL_KEY_PREFIX) === 0
            ) {
                unset($c[$key]);
            }
        }
        return $c;
    }

    /**
     * 全局生效键当前值（缺行时用预置默认值）
     */
    public static function globalNavValue(): string
    {
        $row = self::confModel()->where('conf_key', self::NAV_MENU_ITEMS_KEY)->find();
        return $row ? (string) $row['conf_value'] : self::NAV_MENU_ITEMS_DEFAULT;
    }

    /**
     * 指定主题的板块配置值
     * 优先读主题独立键；缺行时回退为「全局生效值 ∩ 该主题支持项」（沿用现有全局配置初始化）
     */
    public static function themeNavValue(string $themeKey, ?array $registry = null): string
    {
        $registry = $registry ?? self::registry();
        $row = self::confModel()->where('conf_key', self::NAV_MENU_ITEMS_THEME_PREFIX . $themeKey)->find();
        if ($row) {
            return (string) $row['conf_value'];
        }

        $supported = array_intersect(
            array_keys($registry['nav_items']),
            (array) ($registry['themes'][$themeKey]['nav_items'] ?? [])
        );
        $enabled = array_map('trim', explode(',', self::globalNavValue()));

        $merged = [];
        foreach ($registry['nav_items'] as $navKey => $label) {
            if (in_array($navKey, $supported, true) && in_array($navKey, $enabled, true)) {
                $merged[] = $navKey;
            }
        }
        return implode(',', $merged);
    }

    /**
     * 读取某主题的全部外观覆盖值（theme_{key}_{原键} → [原键 => 值]）
     */
    public static function themeOverrides(string $themeKey): array
    {
        $prefix = self::THEME_OVERRIDE_PREFIX . $themeKey . '_';
        $len = strlen($prefix);
        $rows = self::confModel()->where('conf_key', 'like', $prefix . '%')->column('conf_value', 'conf_key');
        $overrides = [];
        foreach ((array) $rows as $confKey => $confValue) {
            $overrides[substr((string) $confKey, $len)] = (string) $confValue;
        }
        return $overrides;
    }

    /**
     * 写入某主题单项外观覆盖值（conf_type=20，conf_status=0 不在系统设置页展示）
     */
    public static function upsertThemeOverride(string $themeKey, string $confKey, string $value): void
    {
        self::upsertConfByKey(self::THEME_OVERRIDE_PREFIX . $themeKey . '_' . $confKey, [
            'conf_value' => $value,
            'conf_title' => '主题外观覆盖（' . $themeKey . '）',
            'conf_desc' => '该主题下 ' . $confKey . ' 的独立覆盖值，缺行时前台回退全局值',
            'conf_spec' => 0,
            'conf_content' => '',
            'conf_type' => self::THEME_OVERRIDE_TYPE,
            'conf_status' => 0,
            'conf_sort' => 0,
            'conf_system' => 1,
        ]);
    }

    /**
     * 按 conf_key 写入或更新 conf 行
     */
    public static function upsertConfByKey(string $key, array $data): void
    {
        $model = self::confModel();
        $data['conf_key'] = $key;
        $data['conf_updatetime'] = time();
        $exists = $model->where('conf_key', $key)->find();
        if ($exists) {
            $model->where('conf_key', $key)->update($data);
            return;
        }
        $data['conf_createtime'] = time();
        $model->insert($data);
    }

    private static function confModel(): ConfModel
    {
        return new ConfModel();
    }
}
