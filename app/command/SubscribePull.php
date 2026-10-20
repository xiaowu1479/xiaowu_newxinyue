<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use app\service\SubscribeService;

/**
 * 资源订阅定时拉取命令
 *
 * 用法：
 *   php think subscribe:pull                拉取所有到期的启用订阅
 *   php think subscribe:pull --subscribe-id=1  只拉取指定订阅（忽略到期判断）
 *
 * crontab 配置（每 5 分钟执行一次，由命令内部按 interval_minutes 判断到期）：
 *   每5分钟: cd /path/to/project && php think subscribe:pull
 *
 * 与宝塔 HTTP 触发（/api/sync/autoPull）二选一即可，共用同一把锁不会重复拉取
 */
class SubscribePull extends Command
{
    protected function configure()
    {
        $this->setName('subscribe:pull')
            ->setDescription('定时拉取资源订阅')
            ->addOption('subscribe-id', 's', Option::VALUE_OPTIONAL, '只拉取指定订阅ID（忽略到期判断）', 0);
    }

    protected function execute(Input $input, Output $output)
    {
        $subscribeId = intval($input->getOption('subscribe-id'));
        $result = SubscribeService::run($subscribeId);

        if ($result['code'] != 1) {
            $output->error('订阅拉取失败: ' . $result['message']);
            return 0;
        }
        if (empty($result['data']['results'])) {
            $output->info($result['message']);
            return 0;
        }

        foreach ($result['data']['results'] as $r) {
            $name = $r['name'] ?? ('订阅#' . ($r['subscribe_id'] ?? ''));
            if (intval($r['code'] ?? 1) != 1) {
                $output->error("  ✗ [{$name}] 拉取失败: " . ($r['message'] ?? '未知错误'));
                continue;
            }
            $output->info("  ✓ [{$name}] 拉取 {$r['pull_count']} 条，新增 {$r['new_count']}，跳过 {$r['skipped']}，失败 {$r['failed']}"
                . (!empty($r['last_error']) ? '（' . $r['last_error'] . '）' : ''));
        }
        $output->info('订阅拉取完毕');
        return 0;
    }
}
