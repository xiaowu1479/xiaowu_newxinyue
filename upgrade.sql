-- ============================================================
-- 数据库迁移脚本 upgrade.sql
-- SQL 版本: 20261001
-- ============================================================
-- 说明:
--   1. 本文件用于把「已部署的旧版本」数据库升级到最新结构
--   2. 后台「系统更新 -> 手动更新数据库」会执行本文件;
--      当更新源为 GitHub 时,系统更新也会自动拉取并执行本文件
--   3. 脚本可重复执行: 已存在的表/字段/索引/配置项会自动跳过
--   4. 本文件表前缀按默认的 qf_ 书写,若你的库用了别的前缀,请先整体替换
-- ============================================================

-- ------------------------------------------------------------
-- 1. 新增缺失的表
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qf_banner`  (
  `banner_id` int(11) NOT NULL AUTO_INCREMENT,
  `banner_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '名称(点击触发搜索)',
  `banner_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '图片路径',
  `banner_link` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '跳转链接(空则用title搜索)',
  `banner_sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序(升序)',
  `banner_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0启用 1禁用',
  `banner_createtime` int(11) NOT NULL DEFAULT 0,
  `banner_updatetime` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`banner_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '首页轮播图' ROW_FORMAT = Dynamic;

CREATE TABLE IF NOT EXISTS `qf_install_server`  (
  `server_id` int(11) NOT NULL AUTO_INCREMENT,
  `server_domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '从服务器域名',
  `server_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '从服务器IP',
  `server_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '最后上报的代码版本',
  `server_sql_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '最后上报的SQL版本',
  `server_last_check` int(11) NOT NULL DEFAULT 0 COMMENT '最后检查更新时间',
  `server_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0正常 1禁用',
  `server_createtime` int(11) NOT NULL DEFAULT 0,
  `server_updatetime` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`server_id`) USING BTREE,
  UNIQUE INDEX `uk_domain_ip`(`server_domain`, `server_ip`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '已安装服务器记录' ROW_FORMAT = Dynamic;

CREATE TABLE IF NOT EXISTS `qf_sync_log`  (
  `sync_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `sync_server_id` int(11) NOT NULL DEFAULT 0 COMMENT '关联qf_sync_server.sync_server_id',
  `server_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '服务器名称',
  `total_fetched` int(11) NOT NULL DEFAULT 0 COMMENT '拉取总数',
  `new_added` int(11) NOT NULL DEFAULT 0 COMMENT '新增数',
  `updated` int(11) NOT NULL DEFAULT 0 COMMENT '更新数',
  `skipped` int(11) NOT NULL DEFAULT 0 COMMENT '跳过数',
  `failed` int(11) NOT NULL DEFAULT 0 COMMENT '失败数',
  `fail_reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '失败原因',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=失败 1=成功 2=部分成功',
  `start_time` int(11) NOT NULL DEFAULT 0 COMMENT '开始时间',
  `end_time` int(11) NOT NULL DEFAULT 0 COMMENT '结束时间',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`sync_log_id`) USING BTREE,
  INDEX `idx_sync_server_id`(`sync_server_id`) USING BTREE,
  INDEX `idx_create_time`(`create_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '资源同步日志表' ROW_FORMAT = Dynamic;

CREATE TABLE IF NOT EXISTS `qf_sync_server`  (
  `sync_server_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '服务器名称',
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '源站域名',
  `api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '同步密钥',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0=禁用 1=启用',
  `default_category_id` int(11) NOT NULL DEFAULT 0 COMMENT '默认本地分类ID',
  `category_map` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT '分类映射JSON',
  `sync_is_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '同步网盘类型，逗号分隔',
  `auto_sync` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用定时同步(0=否 1=是)',
  `sync_hour` tinyint(2) NOT NULL DEFAULT 2 COMMENT '定时同步小时(0-23)',
  `last_sync_time` int(11) NOT NULL DEFAULT 0 COMMENT '上次同步时间戳',
  `last_sync_count` int(11) NOT NULL DEFAULT 0 COMMENT '上次同步数量',
  `total_synced` int(11) NOT NULL DEFAULT 0 COMMENT '累计同步总数',
  `remark` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`sync_server_id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '资源同步服务器配置表' ROW_FORMAT = Dynamic;

-- ------------------------------------------------------------
-- 2. qf_api_list 补充认证相关字段
-- ------------------------------------------------------------
ALTER TABLE `qf_api_list` ADD COLUMN `auth_enabled` tinyint(1) DEFAULT '0' COMMENT '是否开启认证' AFTER `headers`;
ALTER TABLE `qf_api_list` ADD COLUMN `auth_username` varchar(100) DEFAULT NULL COMMENT '认证用户名' AFTER `auth_enabled`;
ALTER TABLE `qf_api_list` ADD COLUMN `auth_password` varchar(100) DEFAULT NULL COMMENT '认证密码' AFTER `auth_username`;

-- ------------------------------------------------------------
-- 3. qf_conf 增加 conf_key 唯一索引(防止配置项重复)
-- ------------------------------------------------------------
ALTER TABLE `qf_conf` ADD UNIQUE INDEX `uk_conf_key`(`conf_key`);

-- ------------------------------------------------------------
-- 4. 新增后台菜单: Banner管理
-- ------------------------------------------------------------
INSERT INTO `qf_node` (`node_id`, `node_title`, `node_desc`, `node_module`, `node_controller`, `node_action`, `node_pid`, `node_order`, `node_show`, `node_icon`, `node_extend`, `node_status`, `node_createtime`, `node_updatetime`) SELECT 130, 'Banner管理', '首页轮播图管理', 'qfadmin', 'banner', 'index', 0, 5, 1, 'el-icon-picture-outline', NULL, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_node` WHERE `node_id` = 130);
ALTER TABLE `qf_node` AUTO_INCREMENT = 131;

-- ------------------------------------------------------------
-- 5. 新增配置项
-- ------------------------------------------------------------
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'simple_default_theme', 'dark', '简约模板默认模式', '控制简约模板默认使用黑夜或白天模式，用户手动切换后以其选择为准', 0, 2, '黑夜模式=>dark\n白天模式=>light', 3, 1, 80, 1, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'simple_default_theme');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'notice_image', '', '公告图片', '首页公告弹窗图片，留空则不显示图片；上传后首页进入时弹窗展示', 0, 4, NULL, 0, 1, 85, 0, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'notice_image');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'notice_text', '', '公告内容', '首页公告弹窗文字内容，留空则不显示文字；支持纯文本换行', 0, 1, NULL, 0, 1, 84, 0, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'notice_text');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'nav_menu_items', 'home,ranking,top250,game,category,contact', '顶部导航显示项', '控制前台顶部导航和手机端抽屉菜单显示哪些按钮', 0, 3, '首页=>home\n夸克榜单=>ranking\nTop250=>top250\n游戏排行=>game\n最近更新=>category\n联系我们=>contact', 3, 1, 81, 1, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'nav_menu_items');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'upgrade_source', 'github', '更新源类型', 'server=主服务器,github=GitHub仓库', 0, 2, '主服务器=>server\nGitHub仓库=>github', 5, 1, 68, 1, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'upgrade_source');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'upgrade_github_repo', 'xiaowu1479/xiaowu_newxinyue', 'GitHub 仓库', '格式: 用户名/仓库名,如 yourname/xinyue(需为公开仓库)', 0, 0, NULL, 5, 1, 67, 1, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'upgrade_github_repo');
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) SELECT 'upgrade_github_branch', 'main', 'GitHub 分支', '仓库分支名,默认 main', 0, 0, NULL, 5, 1, 66, 1, UNIX_TIMESTAMP(), 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `qf_conf` WHERE `conf_key` = 'upgrade_github_branch');

-- ------------------------------------------------------------
-- 6. 修正 qf_source.is_time 字段注释
-- ------------------------------------------------------------
ALTER TABLE `qf_source` MODIFY COLUMN `is_time` int(11) NOT NULL DEFAULT 0 COMMENT '0永久 1临时 2外部资源';
