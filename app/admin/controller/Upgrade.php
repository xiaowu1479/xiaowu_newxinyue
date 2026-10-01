<?php

namespace app\admin\controller;

use app\admin\QfShop;
use util\Upgrade as UpgradeService;
use app\model\UpgradeLog as UpgradeLogModel;

/**
 * 系统升级控制器(从服务器端)
 * 提供检查更新、执行更新、回滚、备份列表、更新日志等接口
 */
class Upgrade extends QfShop
{
    /**
     * 检查更新 - 对比本地与主服务器文件差异
     */
    public function check()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $upgrade = new UpgradeService();
        $result = $upgrade->diffFiles();
        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok('检查完成', $result['data']);
    }

    /**
     * 执行更新 - 备份后下载差异文件并应用
     */
    public function apply()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        @set_time_limit(0);
        $upgrade = new UpgradeService();

        // 1. 获取差异
        $diff = $upgrade->diffFiles();
        if ($diff['code'] != 200) {
            return jerr($diff['message']);
        }
        if (!$diff['data']['has_update']) {
            return jok('当前已是最新版本');
        }

        $localVersion = $diff['data']['local_version'];
        $remoteVersion = $diff['data']['remote_version'];

        // 2. 备份
        $backup = $upgrade->backup();

        // 3. 应用更新
        $result = $upgrade->applyUpdate($diff['data']);

        // 4. 记录日志
        try {
            $logModel = new UpgradeLogModel();
            $logModel->insert([
                'log_type'           => $result['code'] == 200 ? 'upgrade' : 'upgrade_fail',
                'log_from_version'   => $localVersion,
                'log_to_version'     => $remoteVersion,
                'log_update_count'   => $diff['data']['update_count'],
                'log_delete_count'   => $diff['data']['delete_count'],
                'log_backup_name'    => $backup['name'] ?? '',
                'log_result'         => $result['code'] == 200 ? '成功' : '失败',
                'log_message'        => $result['message'] ?? '',
                'log_sql_result'     => $result['data']['sql_result'] ?? '',
                'log_admin'          => $this->admin['admin_account'] ?? '',
                'log_createtime'     => time(),
            ]);
        } catch (\Throwable $e) {
            // 日志记录失败不影响主流程
        }

        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok($result['message'], $result['data'] ?? null);
    }

    /**
     * 回滚到指定备份
     */
    public function rollback()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $backupName = input('backup_name', '');
        if (empty($backupName)) {
            return jerr('请选择要回滚的备份');
        }
        // 校验备份名格式(只允许数字和下划线)
        if (!preg_match('/^\d{8}_\d{6}$/', $backupName)) {
            return jerr('备份名称格式非法');
        }
        $upgrade = new UpgradeService();
        $localVersion = $upgrade->getLocalVersion();
        $result = $upgrade->rollback($backupName);

        try {
            $logModel = new UpgradeLogModel();
            $logModel->insert([
                'log_type'         => 'rollback',
                'log_from_version' => $localVersion,
                'log_to_version'   => $backupName,
                'log_update_count' => 0,
                'log_delete_count' => 0,
                'log_backup_name'  => $backupName,
                'log_result'       => $result['code'] == 200 ? '成功' : '失败',
                'log_message'      => $result['message'],
                'log_sql_result'   => '',
                'log_admin'        => $this->admin['admin_account'] ?? '',
                'log_createtime'   => time(),
            ]);
        } catch (\Throwable $e) {
        }

        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok($result['message']);
    }

    /**
     * 获取备份列表
     */
    public function backups()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $upgrade = new UpgradeService();
        $list = $upgrade->getBackupList();
        return jok('获取成功', $list);
    }

    /**
     * 删除指定备份
     */
    public function deleteBackup()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $backupName = input('backup_name', '');
        if (empty($backupName)) {
            return jerr('请选择要删除的备份');
        }
        $upgrade = new UpgradeService();
        $result = $upgrade->deleteBackup($backupName);
        if ($result['code'] != 200) {
            return jerr($result['message']);
        }
        return jok($result['message']);
    }

    /**
     * 更新日志列表
     */
    public function logList()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $logModel = new UpgradeLogModel();
        $list = $logModel->order('log_id', 'desc')->limit(50)->select()->toArray();
        return jok('获取成功', $list);
    }

    /**
     * 手动备份
     */
    public function doBackup()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $upgrade = new UpgradeService();
        $backup = $upgrade->backup();
        return jok('备份完成', $backup);
    }

    /**
     * 升级配置项的元信息(配置项不存在时自动创建用)
     */
    private const CONF_META = [
        'upgrade_source'        => ['更新源类型', 'server=主服务器,github=GitHub仓库'],
        'upgrade_server'        => ['更新源地址', '主服务器地址,如 https://update.example.com(不带末尾斜杠)'],
        'upgrade_secret'        => ['升级通信密钥', '主从服务器需保持一致,用于接口签名校验'],
        'upgrade_github_repo'   => ['GitHub 仓库', '格式: 用户名/仓库名,如 yourname/xinyue(需为公开仓库)'],
        'upgrade_github_branch' => ['GitHub 分支', '仓库分支名,默认 main'],
    ];

    /**
     * 获取升级配置(更新源类型、主服务器地址、通信密钥、GitHub 仓库、分支)
     */
    public function getConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $saved = [];
        $rows = $this->confModel->where('conf_key', 'in', array_keys(self::CONF_META))->select();
        foreach ($rows as $row) {
            $saved[$row['conf_key']] = (string) $row['conf_value'];
        }
        $branch = $saved['upgrade_github_branch'] ?? '';
        return jok('获取成功', [
            'upgrade_source'        => $saved['upgrade_source'] ?? 'github',
            'upgrade_server'        => $saved['upgrade_server'] ?? '',
            'upgrade_secret'        => $saved['upgrade_secret'] ?? '',
            'upgrade_github_repo'   => $saved['upgrade_github_repo'] ?? '',
            'upgrade_github_branch' => $branch === '' ? 'main' : $branch,
        ]);
    }

    /**
     * 保存升级配置(配置项不存在时自动创建)
     */
    public function saveConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $source = (string) input('upgrade_source', 'github');
        if (!in_array($source, ['server', 'github'], true)) {
            $source = 'server';
        }
        // 允许直接粘贴完整仓库地址,统一规范成 用户名/仓库名
        $repo = trim((string) input('upgrade_github_repo', ''));
        $repo = (string) preg_replace('#^https?://(www\.)?github\.com/#i', '', $repo);
        $repo = (string) preg_replace('#\.git$#i', '', $repo);
        $repo = trim($repo, " \t\n\r\0\x0B/");

        $branch = trim((string) input('upgrade_github_branch', ''), " \t\n\r\0\x0B/");

        $values = [
            'upgrade_source'        => $source,
            'upgrade_server'        => trim((string) input('upgrade_server', '')),
            'upgrade_secret'        => trim((string) input('upgrade_secret', '')),
            'upgrade_github_repo'   => $repo,
            'upgrade_github_branch' => $branch === '' ? 'main' : $branch,
        ];

        $now = time();
        foreach ($values as $key => $val) {
            if ($this->confModel->where('conf_key', $key)->find()) {
                $this->confModel->where('conf_key', $key)->update(['conf_value' => $val]);
                continue;
            }
            $meta = self::CONF_META[$key] ?? [$key, ''];
            $this->confModel->insert([
                'conf_key'        => $key,
                'conf_value'      => $val,
                'conf_title'      => $meta[0],
                'conf_desc'       => $meta[1],
                'conf_type'       => 5,
                'conf_status'     => 1,
                'conf_sort'       => 0,
                'conf_system'     => 1,
                'conf_createtime' => $now,
                'conf_updatetime' => 0,
            ]);
        }
        return jok('配置保存成功');
    }

    /**
     * 手动执行本地 upgrade.sql 数据库迁移
     * 适用于直接拷贝文件到其他服务器后，手动执行数据库更新
     */
    public function manualSql()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        @set_time_limit(0);
        $upgrade = new UpgradeService();
        $localSqlVersion = $upgrade->getLocalSqlVersion();
        $result = $upgrade->runLocalSqlUpdate();

        // 记录日志
        try {
            $logModel = new UpgradeLogModel();
            $logModel->insert([
                'log_type'         => $result['code'] == 200 ? 'upgrade' : 'upgrade_fail',
                'log_from_version' => $localSqlVersion,
                'log_to_version'   => '手动SQL更新',
                'log_update_count' => 0,
                'log_delete_count' => 0,
                'log_backup_name'  => '',
                'log_result'       => $result['code'] == 200 ? '成功' : '失败',
                'log_message'      => $result['data']['sql_result'] ?? $result['message'],
                'log_sql_result'   => $result['data']['sql_result'] ?? '',
                'log_admin'        => $this->admin['admin_account'] ?? '',
                'log_createtime'   => time(),
            ]);
        } catch (\Throwable $e) {
        }

        if ($result['code'] != 200) {
            return jerr($result['message'] . '（' . ($result['data']['sql_result'] ?? '') . '）');
        }
        return jok($result['message'], $result['data'] ?? null);
    }
}
