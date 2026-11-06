<?php

namespace util;

use think\facade\Config;
use think\facade\Db;
use think\facade\Cache;

/**
 * 系统升级服务类
 * 主服务器用于生成文件清单;从服务器用于对比、备份、下载、应用、回滚
 */
class Upgrade
{
    // 纳入同步的目录(相对项目根目录,统一用 / 分隔)
    protected $syncDirs = ['app', 'config', 'extend', 'route', 'public/static', 'public/views'];
    // 排除的文件/目录模式
    protected $excludePatterns = [
        '#\.env$#i',
        '#install\.lock$#i',
        '#runtime/#i',
        '#uploads/#i',
        '#backup/#i',
        '#\.upgrade_tmp/#i',
        '#panchek/cache/#i',
        '#access_token.*\.json$#i',
        '#_token\.json$#i',
        '#_(auth|device)\.json$#i',
        '#\.log$#i',
    ];
    // 项目根目录(带末尾分隔符)
    protected $rootPath;
    // 备份目录名
    protected $backupDir = 'backup';

    public function __construct()
    {
        $this->rootPath = root_path();
    }

    /**
     * 扫描受控目录,返回 [相对路径 => md5]
     */
    public function scanFiles(): array
    {
        $result = [];
        foreach ($this->syncDirs as $dir) {
            $fullDir = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            if (!is_dir($fullDir)) {
                continue;
            }
            $this->scanDir($fullDir, $dir, $result);
        }
        return $result;
    }

    /**
     * 递归扫描目录
     */
    protected function scanDir(string $fullDir, string $relDir, array &$result): void
    {
        $items = @scandir($fullDir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullPath = $fullDir . DIRECTORY_SEPARATOR . $item;
            $relPath = $relDir . '/' . $item;
            // 统一为 / 分隔符
            $relPath = str_replace('\\', '/', $relPath);
            if (is_dir($fullPath)) {
                $this->scanDir($fullPath, $relPath, $result);
            } else {
                if ($this->shouldExclude($relPath)) {
                    continue;
                }
                $md5 = @md5_file($fullPath);
                if ($md5 !== false) {
                    $result[$relPath] = $md5;
                }
            }
        }
    }

    /**
     * 判断文件是否应排除
     */
    protected function shouldExclude(string $relPath): bool
    {
        foreach ($this->excludePatterns as $pattern) {
            if (preg_match($pattern, $relPath)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取本地版本号
     */
    public function getLocalVersion(): string
    {
        $versionFile = $this->rootPath . 'VERSION';
        return trim(is_file($versionFile) ? (string) file_get_contents($versionFile) : '1.0.0');
    }

    /**
     * 获取当前服务器域名
     */
    public function getLocalDomain(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }
        return '';
    }

    /**
     * 写入本地版本号
     */
    public function setLocalVersion(string $version): bool
    {
        return (bool) file_put_contents($this->rootPath . 'VERSION', $version);
    }

    /**
     * 读取升级相关配置(从 qf_conf 表)
     */
    protected function getConfig(string $key, string $default = ''): string
    {
        $val = Config::get('qfshop.' . $key);
        return ($val === null || $val === '') ? $default : (string) $val;
    }

    /**
     * 更新源类型: server=主服务器  github=GitHub 仓库
     */
    public function getSourceType(): string
    {
        return $this->getConfig('upgrade_source', 'server') === 'github' ? 'github' : 'server';
    }

    /**
     * 是否使用 GitHub 更新源
     */
    public function isGithubSource(): bool
    {
        return $this->getSourceType() === 'github';
    }

    /**
     * 规范化后的 GitHub 仓库(用户名/仓库名)
     */
    protected function getGithubRepo(): string
    {
        $repo = trim($this->getConfig('upgrade_github_repo', ''));
        $repo = (string) preg_replace('#^https?://(www\.)?github\.com/#i', '', $repo);
        $repo = trim($repo, " \t\n\r\0\x0B/");
        // 允许粘贴带 .git 后缀的克隆地址
        $repo = (string) preg_replace('#\.git$#i', '', $repo);
        return trim($repo, " \t\n\r\0\x0B/");
    }

    /**
     * GitHub 分支名
     */
    protected function getGithubBranch(): string
    {
        $branch = trim($this->getConfig('upgrade_github_branch', ''), " \t\n\r\0\x0B/");
        return $branch === '' ? 'main' : $branch;
    }

    /**
     * 构造 GitHub raw 文件地址
     */
    protected function githubUrl(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        return 'https://raw.githubusercontent.com/' . $this->getGithubRepo() . '/'
            . rawurlencode($this->getGithubBranch()) . '/' . $path;
    }

    /**
     * 从 GitHub 仓库读取单个文件(仅支持公开仓库)
     * 成功返回文件内容,失败返回 null
     */
    protected function githubFetch(string $path, int $timeout = 30): ?string
    {
        $repo = $this->getGithubRepo();
        if ($repo === '' || strpos($repo, '/') === false) {
            return null;
        }
        // Cache-Control: no-cache 用于绕开 GitHub CDN 的短期缓存
        $header = ['User-Agent: qfshop-upgrade', 'Cache-Control: no-cache'];
        $resp = curlHelper($this->githubUrl($path), 'GET', null, $header, '', '', $timeout);
        if (isset($resp['error'])) {
            return null;
        }
        if ((int) ($resp['detail']['http_code'] ?? 0) !== 200) {
            return null;
        }
        return $resp['body'];
    }

    /**
     * 读取 GitHub 仓库根目录的 manifest.json 文件清单
     */
    protected function getGithubManifest(): array
    {
        $repo = $this->getGithubRepo();
        if ($repo === '' || strpos($repo, '/') === false) {
            return ['code' => 500, 'message' => '未配置 GitHub 仓库,格式应为 用户名/仓库名'];
        }
        $body = $this->githubFetch('manifest.json');
        if ($body === null) {
            return ['code' => 500, 'message' => '读取 manifest.json 失败,请检查仓库地址、分支名是否正确,以及仓库是否为公开仓库'];
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['files']) || !is_array($data['files'])) {
            return ['code' => 500, 'message' => 'manifest.json 格式不正确或文件清单为空'];
        }
        return ['code' => 200, 'data' => [
            'version'     => (string) ($data['version'] ?? ''),
            'sql_version' => (string) ($data['sql_version'] ?? ''),
            'changelog'   => (string) ($data['changelog'] ?? ''),
            'files'       => $data['files'],
            'file_count'  => count($data['files']),
        ]];
    }

    /**
     * 请求更新源获取文件清单
     */
    public function getRemoteManifest(): array
    {
        if ($this->isGithubSource()) {
            return $this->getGithubManifest();
        }
        $server = rtrim($this->getConfig('upgrade_server', ''), '/');
        if (empty($server)) {
            return ['code' => 500, 'message' => '未配置更新源地址'];
        }
        $secret = $this->getConfig('upgrade_secret', '');
        // 上报当前服务器域名和版本
        $domain = $this->getLocalDomain();
        $version = $this->getLocalVersion();
        $sqlVersion = $this->getLocalSqlVersion();
        $url = $server . '/api/update/manifest?secret=' . urlencode($secret)
            . '&domain=' . urlencode($domain)
            . '&version=' . urlencode($version)
            . '&sql_version=' . urlencode($sqlVersion);
        $resp = curlHelper($url, 'GET', null, [], '', '', 30);
        if (isset($resp['error'])) {
            return ['code' => 500, 'message' => '连接更新源失败: ' . $resp['error']];
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || ($data['code'] ?? 0) != 200) {
            return ['code' => 500, 'message' => $data['message'] ?? '更新源返回异常'];
        }
        return ['code' => 200, 'data' => $data['data']];
    }

    /**
     * 对比本地与远程清单,生成差异计划
     */
    public function diffFiles(): array
    {
        $remote = $this->getRemoteManifest();
        if ($remote['code'] != 200) {
            return $remote;
        }
        $remoteData = $remote['data'];
        $remoteFiles = $remoteData['files'] ?? [];
        $local = $this->scanFiles();

        $update = [];
        $delete = [];
        $same = 0;

        foreach ($remoteFiles as $path => $md5) {
            if (!isset($local[$path])) {
                $update[] = ['path' => $path, 'action' => 'add', 'md5' => $md5];
            } elseif ($local[$path] !== $md5) {
                $update[] = ['path' => $path, 'action' => 'update', 'md5' => $md5];
            } else {
                $same++;
            }
        }
        foreach ($local as $path => $md5) {
            if (!isset($remoteFiles[$path])) {
                $delete[] = ['path' => $path, 'action' => 'delete'];
            }
        }

        $remoteSqlVersion = $remoteData['sql_version'] ?? '';
        $localSqlVersion = $this->getLocalSqlVersion();
        $hasSqlUpdate = !empty($remoteSqlVersion) && $remoteSqlVersion !== $localSqlVersion;

        $hasUpdate = count($update) > 0 || count($delete) > 0 || $hasSqlUpdate;
        return [
            'code' => 200,
            'data' => [
                'has_update' => $hasUpdate,
                'update_count' => count($update),
                'delete_count' => count($delete),
                'same_count' => $same,
                'update_files' => $update,
                'delete_files' => $delete,
                'remote_version' => $remoteData['version'] ?? '',
                'remote_sql_version' => $remoteSqlVersion,
                'local_sql_version' => $localSqlVersion,
                'has_sql_update' => $hasSqlUpdate,
                'remote_changelog' => $remoteData['changelog'] ?? '',
                'local_version' => $this->getLocalVersion(),
            ],
        ];
    }

    /**
     * 从更新源下载单个文件,成功返回内容,失败返回 null
     */
    public function downloadFile(string $path): ?string
    {
        if ($this->isGithubSource()) {
            return $this->githubFetch($path, 60);
        }
        $server = rtrim($this->getConfig('upgrade_server', ''), '/');
        $secret = $this->getConfig('upgrade_secret', '');
        $url = $server . '/api/update/file?path=' . urlencode($path) . '&secret=' . urlencode($secret);
        $resp = curlHelper($url, 'GET', null, [], '', '', 60);
        if (isset($resp['error'])) {
            return null;
        }
        return $resp['body'];
    }

    /**
     * 从更新源获取 SQL 迁移脚本
     */
    public function getRemoteSql(): array
    {
        if ($this->isGithubSource()) {
            $body = $this->githubFetch('upgrade.sql');
            return ['code' => 200, 'data' => [
                'sql_version' => $this->getLocalSqlVersion(),
                'sql'         => $body === null ? '' : $body,
            ]];
        }
        $server = rtrim($this->getConfig('upgrade_server', ''), '/');
        $secret = $this->getConfig('upgrade_secret', '');
        $url = $server . '/api/update/sql?secret=' . urlencode($secret);
        $resp = curlHelper($url, 'GET', null, [], '', '', 30);
        if (isset($resp['error'])) {
            return ['code' => 500, 'message' => '获取SQL失败: ' . $resp['error']];
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data) || ($data['code'] ?? 0) != 200) {
            return ['code' => 500, 'message' => $data['message'] ?? '获取SQL失败'];
        }
        return ['code' => 200, 'data' => $data['data']];
    }

    /**
     * 备份当前文件和数据库,返回备份信息
     */
    public function backup(): array
    {
        $name = date('Ymd_His');
        $backupPath = $this->rootPath . $this->backupDir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0777, true);
        }
        $filesOk = $this->backupFilesToZip($backupPath . DIRECTORY_SEPARATOR . 'files.zip');
        $dbOk = $this->backupDatabase($backupPath . DIRECTORY_SEPARATOR . 'database.sql');
        file_put_contents($backupPath . DIRECTORY_SEPARATOR . 'version.txt', $this->getLocalVersion());
        return [
            'name' => $name,
            'files_backup' => $filesOk,
            'database_backup' => $dbOk,
        ];
    }

    /**
     * 打包受控文件到 zip
     */
    protected function backupFilesToZip(string $zipFile): bool
    {
        if (!class_exists('ZipArchive')) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $files = $this->scanFiles();
        foreach ($files as $path => $md5) {
            $fullPath = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($fullPath)) {
                $zip->addFile($fullPath, $path);
            }
        }
        $zip->close();
        return true;
    }

    /**
     * 备份数据库(纯 PHP 导出所有表结构和数据)
     */
    protected function backupDatabase(string $sqlFile): bool
    {
        try {
            $prefix = config('database.connections.mysql.prefix', 'qf_');
            $tables = Db::query("SHOW TABLES");
            $key = "Tables_in_" . config('database.connections.mysql.database', '');
            $sql = "-- 数据库备份 " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n";
            foreach ($tables as $row) {
                $table = array_values($row)[0];
                // 只备份带前缀的系统表
                if (strpos($table, $prefix) !== 0) {
                    continue;
                }
                $sql .= "\n-- ----------------------------\n-- 表结构 {$table}\n-- ----------------------------\n";
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $create = Db::query("SHOW CREATE TABLE `{$table}`");
                $sql .= $create[0]['Create Table'] . ";\n\n";
                $sql .= "-- ----------------------------\n-- 表数据 {$table}\n-- ----------------------------\n";
                $rows = Db::query("SELECT * FROM `{$table}`");
                foreach ($rows as $data) {
                    $values = array_map(function ($v) {
                        return is_null($v) ? 'NULL' : "'" . addslashes($v) . "'";
                    }, array_values($data));
                    $fields = array_map(function ($k) {
                        return "`{$k}`";
                    }, array_keys($data));
                    $sql .= "INSERT INTO `{$table}` (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ");\n";
                }
            }
            return (bool) file_put_contents($sqlFile, $sql);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 执行更新:下载、校验、替换文件、执行SQL
     */
    public function applyUpdate(array $diff, callable $logger = null): array
    {
        $updateFiles = $diff['update_files'] ?? [];
        $deleteFiles = $diff['delete_files'] ?? [];
        $remoteVersion = $diff['remote_version'] ?? '';
        $hasSqlUpdate = $diff['has_sql_update'] ?? false;

        // 没有文件更新也没有 SQL 更新才跳过
        if (empty($updateFiles) && empty($deleteFiles) && !$hasSqlUpdate) {
            return ['code' => 200, 'message' => '无需更新'];
        }

        $tmpDir = $this->rootPath . '.upgrade_tmp';
        $this->rmDir($tmpDir);
        mkdir($tmpDir, 0777, true);

        // 1. 下载所有变更文件到临时目录
        $downloaded = [];
        foreach ($updateFiles as $idx => $file) {
            $path = $file['path'];
            $content = $this->downloadFile($path);
            if ($content === null) {
                $this->rmDir($tmpDir);
                return ['code' => 500, 'message' => "下载失败: {$path}"];
            }
            // 校验 MD5
            if (md5($content) !== $file['md5']) {
                $this->rmDir($tmpDir);
                return ['code' => 500, 'message' => "MD5校验失败: {$path}"];
            }
            $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_dir(dirname($tmpPath))) {
                mkdir(dirname($tmpPath), 0777, true);
            }
            file_put_contents($tmpPath, $content);
            $downloaded[] = $path;
            if ($logger) {
                $logger("已下载 ({$idx}/" . count($updateFiles) . "): {$path}");
            }
        }

        // 2. 替换文件
        foreach ($downloaded as $path) {
            $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            $destPath = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_dir(dirname($destPath))) {
                mkdir(dirname($destPath), 0777, true);
            }
            @copy($tmpPath, $destPath);
        }

        // 3. 删除文件
        foreach ($deleteFiles as $file) {
            $path = $file['path'];
            $destPath = $this->rootPath . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_file($destPath)) {
                @unlink($destPath);
            }
        }

        // 4. 执行 SQL 迁移
        $sqlResult = '';
        $sqlSuccess = true;
        $remoteSqlVersion = $diff['remote_sql_version'] ?? '';
        $localSqlVersion = $this->getLocalSqlVersion();
        if (!empty($remoteSqlVersion) && $remoteSqlVersion !== $localSqlVersion) {
            $sqlResp = $this->getRemoteSql();
            if ($sqlResp['code'] == 200 && !empty($sqlResp['data']['sql'])) {
                $sqlExecResult = $this->executeSql($sqlResp['data']['sql']);
                $sqlResult = $sqlExecResult['message'];
                $sqlSuccess = $sqlExecResult['success'];
                // 只有 SQL 全部执行成功才更新版本号，失败则保留旧版本号以便下次重试
                if ($sqlSuccess) {
                    $this->setLocalSqlVersion($remoteSqlVersion);
                }
            }
        }

        // 5. 更新版本号
        if (!empty($remoteVersion)) {
            $this->setLocalVersion($remoteVersion);
        }

        // 6. 清理
        $this->rmDir($tmpDir);
        try {
            Cache::clear();
        } catch (\Throwable $e) {
        }
        // 清除 ThinkPHP 模板编译缓存，确保新模板生效
        $this->clearRuntime();

        return [
            'code' => 200,
            'message' => $sqlSuccess ? '更新完成' : '代码更新完成，但数据库迁移失败，下次更新将自动重试',
            'data' => [
                'updated' => count($downloaded),
                'deleted' => count($deleteFiles),
                'sql_result' => $sqlResult,
                'sql_success' => $sqlSuccess,
                'new_version' => $remoteVersion,
            ],
        ];
    }

    /**
     * 执行 SQL 脚本(按分号分割)
     * 返回结构化结果，包含成功/失败数量和错误详情
     */
    public function executeSql(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $statements = [];
        $buffer = '';
        foreach (explode("\n", $sql) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '--') === 0 || strpos($line, '#') === 0) {
                continue;
            }
            $buffer .= $line . "\n";
            if (substr(rtrim($line), -1) === ';') {
                $statements[] = $buffer;
                $buffer = '';
            }
        }
        if ($buffer !== '') {
            $statements[] = $buffer;
        }
        $ok = 0;
        $fail = 0;
        $errors = [];
        foreach ($statements as $stmt) {
            try {
                Db::execute($stmt);
                $ok++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                // 忽略"已存在"类错误，保证幂等性（ALTER TABLE ADD COLUMN/KEY 重复执行时会出现）
                if (preg_match('/Duplicate column name|Duplicate key name|already exists/i', $msg)) {
                    $ok++;
                    continue;
                }
                $fail++;
                $errors[] = $msg;
            }
        }
        return [
            'success' => $fail === 0,
            'ok'      => $ok,
            'fail'    => $fail,
            'errors'  => $errors,
            'message' => "成功{$ok}条,失败{$fail}条" . ($fail > 0 ? '，失败原因: ' . implode(' | ', array_slice($errors, 0, 5)) : ''),
        ];
    }

    /**
     * 手动执行本地 upgrade.sql 数据库迁移
     * 适用于直接拷贝文件到其他服务器后，手动执行数据库更新
     */
    public function runLocalSqlUpdate(): array
    {
        $sqlFile = $this->rootPath . 'upgrade.sql';
        if (!is_file($sqlFile)) {
            return ['code' => 404, 'message' => 'upgrade.sql 文件不存在', 'data' => null];
        }
        $sql = file_get_contents($sqlFile);
        if (empty(trim($sql))) {
            return ['code' => 200, 'message' => 'upgrade.sql 为空，无需执行', 'data' => ['sql_result' => '空文件', 'sql_success' => true]];
        }
        $result = $this->executeSql($sql);
        // 执行成功后更新 .sql_version 为当前日期
        if ($result['success']) {
            $this->setLocalSqlVersion(date('Ymd'));
        }
        return [
            'code' => $result['success'] ? 200 : 500,
            'message' => $result['success'] ? '数据库更新完成' : '数据库更新部分失败',
            'data' => [
                'sql_result' => $result['message'],
                'sql_success' => $result['success'],
                'ok' => $result['ok'],
                'fail' => $result['fail'],
            ],
        ];
    }

    /**
     * 获取本地已执行的 SQL 版本
     */
    public function getLocalSqlVersion(): string
    {
        $file = $this->rootPath . '.sql_version';
        return trim(is_file($file) ? (string) file_get_contents($file) : '');
    }

    /**
     * 记录本地已执行的 SQL 版本
     */
    public function setLocalSqlVersion(string $version): bool
    {
        return (bool) file_put_contents($this->rootPath . '.sql_version', $version);
    }

    /**
     * 从备份回滚
     */
    public function rollback(string $backupName): array
    {
        $backupPath = $this->rootPath . $this->backupDir . DIRECTORY_SEPARATOR . $backupName;
        if (!is_dir($backupPath)) {
            return ['code' => 500, 'message' => '备份不存在: ' . $backupName];
        }

        // 恢复文件
        $zipFile = $backupPath . DIRECTORY_SEPARATOR . 'files.zip';
        if (is_file($zipFile) && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) === true) {
                $zip->extractTo($this->rootPath);
                $zip->close();
            }
        }

        // 恢复数据库
        $sqlFile = $backupPath . DIRECTORY_SEPARATOR . 'database.sql';
        if (is_file($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $this->executeSql($sql);
        }

        // 恢复版本号
        $versionFile = $backupPath . DIRECTORY_SEPARATOR . 'version.txt';
        if (is_file($versionFile)) {
            $version = trim(file_get_contents($versionFile));
            $this->setLocalVersion($version);
        }

        try {
            Cache::clear();
        } catch (\Throwable $e) {
        }
        $this->clearRuntime();

        return ['code' => 200, 'message' => '回滚完成'];
    }

    /**
     * 获取备份列表
     */
    public function getBackupList(): array
    {
        $dir = $this->rootPath . $this->backupDir;
        if (!is_dir($dir)) {
            return [];
        }
        $list = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $list[] = [
                    'name' => $item,
                    'time' => date('Y-m-d H:i:s', filemtime($path)),
                    'has_files' => is_file($path . DIRECTORY_SEPARATOR . 'files.zip'),
                    'has_database' => is_file($path . DIRECTORY_SEPARATOR . 'database.sql'),
                ];
            }
        }
        // 按时间倒序
        usort($list, function ($a, $b) {
            return strcmp($b['name'], $a['name']);
        });
        return $list;
    }

    /**
     * 删除指定备份
     */
    public function deleteBackup(string $backupName): array
    {
        if (!preg_match('/^\d{8}_\d{6}$/', $backupName)) {
            return ['code' => 500, 'message' => '备份名称格式非法'];
        }
        $backupPath = $this->rootPath . $this->backupDir . DIRECTORY_SEPARATOR . $backupName;
        if (!is_dir($backupPath)) {
            return ['code' => 500, 'message' => '备份不存在: ' . $backupName];
        }
        $this->rmDir($backupPath);
        if (is_dir($backupPath)) {
            return ['code' => 500, 'message' => '备份删除失败'];
        }
        return ['code' => 200, 'message' => '备份删除成功'];
    }

    /**
     * 清除 runtime 目录下的编译缓存（保留 .gitignore）
     */
    protected function clearRuntime(): void
    {
        $runtimePath = $this->rootPath . 'runtime';
        if (!is_dir($runtimePath)) {
            return;
        }
        $items = @scandir($runtimePath);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.gitignore') {
                continue;
            }
            $path = $runtimePath . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
    }

    /**
     * 递归删除目录
     */
    protected function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
