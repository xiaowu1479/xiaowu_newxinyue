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
     * 获取升级配置(更新源地址、通信密钥)
     */
    public function getConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $server = $this->confModel->where('conf_key', 'upgrade_server')->value('conf_value');
        $secret = $this->confModel->where('conf_key', 'upgrade_secret')->value('conf_value');
        return jok('获取成功', [
            'upgrade_server' => $server ?? '',
            'upgrade_secret' => $secret ?? '',
        ]);
    }

    /**
     * 保存升级配置
     */
    public function saveConfig()
    {
        $error = $this->access();
        if ($error) {
            return $error;
        }
        $server = input('upgrade_server', '');
        $secret = input('upgrade_secret', '');
        $this->confModel->where('conf_key', 'upgrade_server')->update(['conf_value' => $server]);
        $this->confModel->where('conf_key', 'upgrade_secret')->update(['conf_value' => $secret]);
        return jok('配置保存成功');
    }
}
