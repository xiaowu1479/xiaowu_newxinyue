<?php
/**
 * manifest.json 生成脚本
 *
 * 作用: 扫描受控目录,生成「文件路径 => MD5」清单,供后台「系统更新」比对差异
 *
 * 用法:
 *   php tools/make_manifest.php
 *
 * 生成后请把 manifest.json 一起提交推送到仓库,否则站点检查更新时会读不到清单
 *
 * 注意: 扫描范围与排除规则必须与 extend/util/Upgrade.php 中的 scanFiles() 保持一致
 */

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;

// 纳入同步的目录(相对项目根目录)
$syncDirs = ['app', 'config', 'extend', 'route', 'public/static', 'public/views'];

// 排除的文件/目录模式(与 Upgrade.php 保持一致)
$excludePatterns = [
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

function mf_should_exclude(string $relPath, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $relPath)) {
            return true;
        }
    }
    return false;
}

function mf_scan(string $fullDir, string $relDir, array &$result, array $patterns): void
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
        $relPath  = str_replace('\\', '/', $relDir . '/' . $item);
        if (is_dir($fullPath)) {
            mf_scan($fullPath, $relPath, $result, $patterns);
            continue;
        }
        if (mf_should_exclude($relPath, $patterns)) {
            continue;
        }
        $md5 = @md5_file($fullPath);
        if ($md5 !== false) {
            $result[$relPath] = $md5;
        }
    }
}

$files = [];
foreach ($syncDirs as $dir) {
    $fullDir = $root . str_replace('/', DIRECTORY_SEPARATOR, $dir);
    if (!is_dir($fullDir)) {
        fwrite(STDERR, "[warn] 目录不存在,已跳过: {$dir}\n");
        continue;
    }
    mf_scan($fullDir, $dir, $files, $excludePatterns);
}
ksort($files);

$version    = trim((string) @file_get_contents($root . 'VERSION'));
$sqlVersion = trim((string) @file_get_contents($root . '.sql_version'));
$changelog  = trim((string) @file_get_contents($root . 'CHANGELOG'));

// 注意: 这里刻意不写入时间戳之类每次都变化的字段。
// 否则 GitHub Action 每次推送都会生成一个多余提交,
// 也无法用「重新生成后 git status 无变化」来判断清单是否已是最新。
$manifest = [
    'version'     => $version,
    'sql_version' => $sqlVersion,
    'changelog'   => $changelog,
    'file_count'  => count($files),
    'files'       => $files,
];

$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "[error] JSON 编码失败\n");
    exit(1);
}

$outFile = $root . 'manifest.json';
if (@file_put_contents($outFile, $json . "\n") === false) {
    fwrite(STDERR, "[error] 写入 manifest.json 失败,请检查目录写权限\n");
    exit(1);
}

echo "manifest.json 已生成\n";
echo "  文件数   : " . count($files) . "\n";
echo "  版本号   : " . ($version !== '' ? $version : '(空)') . "\n";
echo "  SQL 版本 : " . ($sqlVersion !== '' ? $sqlVersion : '(空)') . "\n";
echo "  输出     : " . $outFile . "\n";

// 工作区存在未提交改动时给出提醒(避免清单与实际推送内容不一致)
if (function_exists('shell_exec')) {
    $isRepo = @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --is-inside-work-tree 2>&1');
    $dirty  = (is_string($isRepo) && trim($isRepo) === 'true')
        ? @shell_exec('git -C ' . escapeshellarg($root) . ' status --porcelain 2>&1')
        : '';
    if (is_string($dirty) && trim($dirty) !== '') {
        $lines = array_filter(explode("\n", trim($dirty)), function ($line) {
            return strpos($line, 'manifest.json') === false;
        });
        if (!empty($lines)) {
            echo "\n[提醒] git 工作区还有未提交的改动,清单可能与你将要推送的版本不一致:\n";
            foreach (array_slice(array_values($lines), 0, 10) as $line) {
                echo "  " . $line . "\n";
            }
            echo "  建议先提交代码,再执行本脚本并把 manifest.json 一起提交\n";
        }
    }
}
