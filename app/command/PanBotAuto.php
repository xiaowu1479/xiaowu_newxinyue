<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\service\PanBotService;

/**
 * PanBot 第三方同步定时任务
 *
 * 用法：
 *   php think panbot:auto                  扫描所有启用的配置，对到期的执行增量同步
 *   php think panbot:auto --panbot-id=1    只检查指定 panbot_id（忽略到期判断）
 *
 * crontab 配置（每 5 分钟执行一次，配置内的 interval_minutes 决定具体间隔）：
 *   每5分钟: cd /path/to/project && php think panbot:auto
 *
 * 与宝塔 HTTP 触发（/api/sync/panbotAuto）二选一即可，共用同一把锁不会重复拉取
 */
class PanBotAuto extends Command
{
    protected function configure()
    {
        $this->setName('panbot:auto')
            ->setDescription('PanBot 第三方同步定时任务')
            ->addOption('panbot-id', 'p', Option::VALUE_OPTIONAL, '只检查指定 PanBot 配置 ID', 0);
    }

    protected function execute(Input $input, Output $output)
    {
        $panbotId = intval($input->getOption('panbot-id'));
        $result = PanBotService::syncAuto($panbotId);

        if ($result['code'] != 1) {
            $output->error('PanBot 自动同步失败: ' . $result['message']);
            return 0;
        }

        $results = $result['data']['results'] ?? [];
        if (empty($results)) {
            $output->info($result['message']);
            return 0;
        }

        $output->info('PanBot 自动同步检查完毕，共 ' . count($results) . ' 个配置:');
        foreach ($results as $r) {
            $name = $r['name'] ?? ('PanBot#' . ($r['panbot_id'] ?? ''));
            if (intval($r['code']) != 1) {
                $output->comment("  - [{$name}] 跳过: " . ($r['message'] ?? ''));
                continue;
            }
            $data = $r['data'] ?? [];
            $output->info("  ✓ [{$name}] 模式 {$r['mode']}: 新增 {$data['resources_new']}/更新 {$data['resources_updated']}/失败 {$data['resources_failed']}");
        }
        return 0;
    }
}