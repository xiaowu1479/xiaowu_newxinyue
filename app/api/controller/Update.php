<?php

namespace app\api\controller;

use app\api\QfShop;
use util\Upgrade;
use think\facade\Db;

/**
 * 主服务器更新源接口
 * 部署在主服务器上,供从服务器拉取文件清单、下载文件、获取SQL迁移脚本
 */
class Update extends QfShop
{
    /**
     * 生成签名
     */
    private function makeSign(string $version, string $secret): string
    {
        return md5($version . $secret);
    }

    /**
     * 校验访问密钥
     */
    private function checkSecret(): ? string
    {
        $secret = Config('qfshop.upgrade_secret');
        if (empty($secret)) {
            jerr('未配置升级密钥');
        }
        $inputSecret = input('secret', '');
        if ($inputSecret !== $secret) {
            jerr('密钥无效');
        }
        return $secret;
    }

    /**
     * 记录请求方（从服务器）的域名和IP
     */
    private function recordServer(): void
    {
        try {
            $ip = getClientIp() ?? '';
            $domain = input('domain', '');
            $version = input('version', '');
            $sqlVersion = input('sql_version', '');
            $now = time();

            $existing = Db::name('install_server')
                ->where('server_domain', $domain)
                ->where('server_ip', $ip)
                ->find();

            if ($existing) {
                Db::name('install_server')
                    ->where('server_id', $existing['server_id'])
                    ->update([
                        'server_version' => $version,
                        'server_sql_version' => $sqlVersion,
                        'server_last_check' => $now,
                        'server_updatetime' => $now,
                    ]);
            } else {
                Db::name('install_server')->insert([
                    'server_domain' => $domain,
                    'server_ip' => $ip,
                    'server_version' => $version,
                    'server_sql_version' => $sqlVersion,
                    'server_last_check' => $now,
                    'server_status' => 0,
                    'server_createtime' => $now,
                    'server_updatetime' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // 记录失败不影响主流程
        }
    }

    /**
     * 文件清单接口
     * 返回所有受控文件的 MD5 字典、版本号、SQL版本、更新日志
     */
    public function manifest()
    {
        $this->checkSecret();
        $this->recordServer();
        $upgrade = new Upgrade();
        $files = $upgrade->scanFiles();
        $version = $upgrade->getLocalVersion();

        // 读取更新日志(CHANGELOG 文件)
        $changelog = '';
        $logFile = root_path() . 'CHANGELOG';
        if (is_file($logFile)) {
            $changelog = file_get_contents($logFile);
        }

        // SQL 版本(主服务器维护)
        $sqlVersion = $upgrade->getLocalSqlVersion();

        $data = [
            'version' => $version,
            'sql_version' => $sqlVersion,
            'changelog' => $changelog,
            'files' => $files,
            'file_count' => count($files),
            'server_time' => date('Y-m-d H:i:s'),
        ];
        return jok('success', $data);
    }

    /**
     * 下载单个文件
     * 直接输出文件内容
     */
    public function file()
    {
        $this->checkSecret();
        $path = input('path', '');
        if (empty($path)) {
            jerr('path 参数不能为空');
        }
        // 防止目录穿越
        if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            jerr('非法路径');
        }
        // 只允许受控目录
        $upgrade = new Upgrade();
        $files = $upgrade->scanFiles();
        if (!isset($files[$path])) {
            jerr('文件不在更新范围内');
        }
        $fullPath = root_path() . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($fullPath)) {
            jerr('文件不存在');
        }
        // 直接输出文件内容
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($fullPath));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($fullPath);
        exit;
    }

    /**
     * 获取 SQL 迁移脚本
     */
    public function sql()
    {
        $this->checkSecret();
        $upgrade = new Upgrade();
        $sqlVersion = $upgrade->getLocalSqlVersion();
        $sql = '';

        // SQL 迁移脚本存放在根目录 upgrade.sql
        $sqlFile = root_path() . 'upgrade.sql';
        if (is_file($sqlFile)) {
            $sql = file_get_contents($sqlFile);
        }

        return jok('success', [
            'sql_version' => $sqlVersion,
            'sql' => $sql,
        ]);
    }

    /**
     * 版本信息接口(轻量,只返回版本号,用于快速检查)
     */
    public function version()
    {
        $this->checkSecret();
        $this->recordServer();
        $upgrade = new Upgrade();
        return jok('success', [
            'version' => $upgrade->getLocalVersion(),
            'sql_version' => $upgrade->getLocalSqlVersion(),
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }
}
