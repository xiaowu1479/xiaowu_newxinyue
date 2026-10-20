<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 小说任务僵尸回收 + 事件流清理
 *
 * 用法：
 *   php think novel:reap
 *
 * crontab 配置（每 2 分钟执行一次）：
 *   每2分钟: cd /path/to/project && php think novel:reap
 *
 * 职责：
 *   1. FPM 内联任务被强杀/进程意外中断时，任务停留 queued/running：
 *      last_event_at 超过 10 分钟 → 标记 fail（前端 120s sweeper 只对本机视角生效，
 *      此处兜底保证任务表不残留假运行态，允许用户重新发起）
 *   2. 清理 6 小时前的事件流（SSE 重连重放窗口 10 分钟，6h 保留已足够宽裕）
 */
class NovelReap extends Command
{
    /** 任务无事件超时（秒）：超过即视为僵尸 */
    const TASK_STALE_SECONDS = 600;

    /** 事件流保留时长（秒） */
    const EVENT_KEEP_SECONDS = 21600;

    protected function configure()
    {
        $this->setName('novel:reap')
            ->setDescription('小说任务僵尸回收与事件流清理');
    }

    protected function execute(Input $input, Output $output)
    {
        $now = time();

        // 1. 僵尸任务回收
        $staleTasks = Db::name('novel_task')
            ->where('status', 'in', ['queued', 'running'])
            ->where('last_event_at', '<', $now - self::TASK_STALE_SECONDS)
            ->field('id,book_url')
            ->select()
            ->toArray();
        if (!empty($staleTasks)) {
            Db::name('novel_task')
                ->where('status', 'in', ['queued', 'running'])
                ->where('last_event_at', '<', $now - self::TASK_STALE_SECONDS)
                ->update([
                    'status' => 'fail',
                    'stage' => 'fail',
                    'error' => '任务超时回收（执行进程中断）',
                    'update_time' => $now,
                ]);
        }

        // 2. 过期事件清理
        $removedEvents = Db::name('novel_task_event')
            ->where('create_time', '<', $now - self::EVENT_KEEP_SECONDS)
            ->delete();

        $output->info(sprintf(
            'novel:reap 完成：回收僵尸任务 %d 个，清理过期事件 %d 条',
            count($staleTasks),
            intval($removedEvents)
        ));
        return 0;
    }
}
