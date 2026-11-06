<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\model\SyncServer as SyncServerModel;
use app\service\SyncService;

/**
 * 定时自动同步命令
 *
 * 用法：
 *   php think sync:auto              同步所有启用了定时同步的服务器
 *   php think sync:auto --server-id=1  只同步指定服务器
 *
 * crontab 配置（每小时执行一次，由命令内部判断各服务器的 sync_hour）：
 *   0 * * * * cd /path/to/project && php think sync:auto
 */
class SyncAuto extends Command
{
    protected function configure()
    {
        $this->setName('sync:auto')
            ->setDescription('定时自动同步资源')
            ->addOption('server-id', 's', Option::VALUE_OPTIONAL, '只同步指定服务器ID', 0);
    }

    protected function execute(Input $input, Output $output)
    {
        $serverId = intval($input->getOption('server-id'));
        $model = new SyncServerModel();

        if ($serverId > 0) {
            // 指定服务器：直接同步，不检查 auto_sync 和 sync_hour
            $servers = $model->where('sync_server_id', $serverId)->where('status', 1)->select();
        } else {
            // 查询所有启用定时同步的服务器
            $servers = $model->where('auto_sync', 1)->where('status', 1)->select();
        }

        if (empty($servers) || count($servers) == 0) {
            $output->info('没有需要定时同步的服务器');
            return 0;
        }

        $currentHour = intval(date('G'));
        $output->info('当前时间: ' . date('Y-m-d H:i:s') . ' (小时: ' . $currentHour . ')');
        $output->info('找到 ' . count($servers) . ' 台服务器需要检查');

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($servers as $server) {
            $name = $server['name'];
            $id = $server['sync_server_id'];

            // 指定服务器模式跳过时间检查；否则检查 sync_hour 是否匹配当前小时
            if ($serverId <= 0) {
                if (intval($server['sync_hour']) !== $currentHour) {
                    $output->comment("  跳过 [{$name}] - 设定同步时间为 {$server['sync_hour']} 点，当前 {$currentHour} 点");
                    $skipped++;
                    continue;
                }

                // 防重复：如果今天已经同步过（last_sync_time 在今天之后），跳过
                $todayStart = strtotime(date('Y-m-d'));
                if (intval($server['last_sync_time']) >= $todayStart) {
                    $output->comment("  跳过 [{$name}] - 今天已同步过");
                    $skipped++;
                    continue;
                }
            }

            $output->info("  开始同步 [{$name}] (ID: {$id})...");
            $result = SyncService::run($id);

            if ($result['code'] == 1) {
                $data = $result['data'];
                $output->info("  ✓ [{$name}] 同步完成: 拉取 {$data['total']}, 新增 {$data['new_added']}, 更新 {$data['updated']}, 失败 {$data['failed']}");
                $synced++;
            } else {
                $output->error("  ✗ [{$name}] 同步失败: " . $result['message']);
                $failed++;
            }
        }

        $output->info('');
        $output->info("同步完毕: 成功 {$synced}, 跳过 {$skipped}, 失败 {$failed}");
        return 0;
    }
}
