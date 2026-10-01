-- ============================================================
-- 菜单分组结构升级脚本
-- 适用：已部署的旧系统（扁平菜单结构 → 分组菜单结构）
-- 执行方式：在目标系统数据库中运行此SQL
-- ============================================================

-- 1. 插入4个分组节点（用 INSERT IGNORE 避免重复）
INSERT IGNORE INTO `qf_node` (`node_id`,`node_title`,`node_desc`,`node_module`,`node_controller`,`node_action`,`node_pid`,`node_order`,`node_show`,`node_icon`,`node_status`,`node_createtime`,`node_updatetime`) VALUES
(124, '资源管理', '', 'qfadmin', '', '', 0, 2, 0, 'el-icon-folder', 0, 0, 0),
(125, '搜索配置', '', 'qfadmin', '', '', 0, 5, 0, 'el-icon-search', 0, 0, 0),
(126, '站点设置', '', 'qfadmin', '', '', 0, 6, 0, 'el-icon-setting', 0, 0, 0),
(127, '系统设置', '', 'qfadmin', '', '', 0, 9, 1, 'el-icon-data-board', 0, 0, 0);

-- 2. 插入2个新配置页节点
INSERT IGNORE INTO `qf_node` (`node_id`,`node_title`,`node_desc`,`node_module`,`node_controller`,`node_action`,`node_pid`,`node_order`,`node_show`,`node_icon`,`node_status`,`node_createtime`,`node_updatetime`) VALUES
(128, '上传配置', '', 'qfadmin', 'system', 'upload', 126, 4, 0, 'el-icon-upload2', 0, 0, 0),
(129, '其他配置', '', 'qfadmin', 'system', 'other', 126, 5, 0, 'el-icon-more', 0, 0, 0);

-- 3. 更新子菜单的 pid 和 order（归入各分组）
-- 资源、基础、接口菜单调到一级
UPDATE `qf_node` SET `node_pid`=0, `node_order`=2, `node_show`=1 WHERE `node_id`=109; -- 资源管理
UPDATE `qf_node` SET `node_pid`=0, `node_order`=3, `node_show`=0 WHERE `node_id`=118; -- 资源分类
UPDATE `qf_node` SET `node_pid`=0, `node_order`=4, `node_show`=1 WHERE `node_id`=114; -- 搜索记录
UPDATE `qf_node` SET `node_pid`=0, `node_order`=5, `node_show`=1 WHERE `node_id`=107; -- 基础设置
UPDATE `qf_node` SET `node_pid`=0, `node_order`=6, `node_show`=1 WHERE `node_id`=119; -- 接口配置
UPDATE `qf_node` SET `node_title`='网盘管理', `node_pid`=0, `node_order`=7, `node_show`=1 WHERE `node_id`=112; -- 网盘管理
UPDATE `qf_node` SET `node_show`=0 WHERE `node_id` IN (121, 122, 123, 124, 125, 126, 128, 129); -- 隐藏废弃分组和不要的菜单
UPDATE `qf_node` SET `node_pid`=0, `node_order`=8, `node_show`=1 WHERE `node_id`=2;   -- 运营人员
UPDATE `qf_node` SET `node_pid`=2, `node_order`=1, `node_show`=1 WHERE `node_id`=100; -- 管理员列表
UPDATE `qf_node` SET `node_pid`=2, `node_order`=2, `node_show`=1 WHERE `node_id`=101; -- 用户组管理
UPDATE `qf_node` SET `node_pid`=0, `node_order`=9, `node_show`=1 WHERE `node_id`=127; -- 系统设置分组
UPDATE `qf_node` SET `node_pid`=127, `node_order`=2 WHERE `node_id`=104; -- 菜单管理
UPDATE `qf_node` SET `node_pid`=127, `node_order`=3 WHERE `node_id`=102; -- 参数配置
UPDATE `qf_node` SET `node_pid`=127, `node_order`=4 WHERE `node_id`=105; -- 附件管理
UPDATE `qf_node` SET `node_pid`=127, `node_order`=5 WHERE `node_id`=120; -- 系统更新

-- 4. 更新系统概况描述
UPDATE `qf_node` SET `node_desc`='搜索统计及资源分布情况' WHERE `node_id`=1;

-- 5. 隐藏旧的分组节点（如果存在）
UPDATE `qf_node` SET `node_show`=0 WHERE `node_id` IN (3, 4, 108);

-- 6. 修正 AUTO_INCREMENT
ALTER TABLE `qf_node` AUTO_INCREMENT = 130;
