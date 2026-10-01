-- ============================================================
-- 系统升级功能安装 SQL
-- 手动执行一次即可(通过 phpMyAdmin 或命令行)
-- ============================================================

-- 1. 创建升级日志表
CREATE TABLE IF NOT EXISTS `qf_upgrade_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `log_type` varchar(20) NOT NULL DEFAULT '' COMMENT '类型: upgrade/upgrade_fail/rollback',
  `log_from_version` varchar(50) NOT NULL DEFAULT '' COMMENT '原版本',
  `log_to_version` varchar(50) NOT NULL DEFAULT '' COMMENT '目标版本',
  `log_update_count` int(11) NOT NULL DEFAULT 0 COMMENT '更新文件数',
  `log_delete_count` int(11) NOT NULL DEFAULT 0 COMMENT '删除文件数',
  `log_backup_name` varchar(50) NOT NULL DEFAULT '' COMMENT '备份名称',
  `log_result` varchar(20) NOT NULL DEFAULT '' COMMENT '结果: 成功/失败',
  `log_message` varchar(500) NOT NULL DEFAULT '' COMMENT '消息',
  `log_sql_result` varchar(200) NOT NULL DEFAULT '' COMMENT 'SQL执行结果',
  `log_admin` varchar(50) NOT NULL DEFAULT '' COMMENT '操作人',
  `log_createtime` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`log_id`) USING BTREE,
  INDEX `log_type`(`log_type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统升级日志表';

-- 2. 新增配置项(更新源地址、通信密钥)
INSERT INTO `qf_conf` (`conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`)
VALUES
('upgrade_server', '', '更新源地址', '主服务器地址,如 https://update.example.com(不带末尾斜杠)', 0, 0, NULL, 5, 1, 70, 1, UNIX_TIMESTAMP(), 0),
('upgrade_secret', '', '升级通信密钥', '主从服务器需保持一致,用于接口签名校验', 0, 0, NULL, 5, 1, 69, 1, UNIX_TIMESTAMP(), 0);

-- 3. 新增后台菜单节点(系统更新,放在"系统"分类 node_pid=3 下)
INSERT INTO `qf_node` (`node_title`, `node_desc`, `node_module`, `node_controller`, `node_action`, `node_pid`, `node_order`, `node_show`, `node_icon`, `node_extend`, `node_status`, `node_createtime`, `node_updatetime`)
VALUES ('系统更新', '', 'qfadmin', 'upgrade', 'index', 3, 1, 1, 'el-icon-upload2', '', 0, UNIX_TIMESTAMP(), 0);

-- 4. 给超级管理员用户组(group_id=1)授权新节点
-- 注意: 超级管理员默认拥有所有权限,此步骤可选
