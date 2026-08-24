<?php

namespace App\Console\Commands;

use App\Services\Heartbeat;
use App\Services\SyncWatermark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler liveness — write side and read side, in one command.
 *
 *   php artisan woo:cron            stamp the heartbeat (scheduled every minute)
 *   php artisan woo:cron --check    report freshness, exit non-zero when stale
 *
 * The two halves are what make this a check rather than a diary. Stamping alone
 * proves nothing to anyone who is not already reading the file; --check is the
 * half that fails loudly, so it is the half a monitor, a deploy script or a
 * person actually runs.
 *
 * --check covers two different failure modes:
 *
 *   Scheduler tick    The heartbeat stops advancing when `schedule:run` is no
 *                     longer being called at all — the crontab entry was lost
 *                     in a deploy, the server was rebuilt, PHP's path changed.
 *                     Nothing else in this application notices that.
 *
 *   Sync watermarks   These stop advancing while the scheduler keeps ticking
 *                     happily: every run failing, Odoo unreachable, or a run
 *                     wedged and holding the withoutOverlapping() lock for its
 *                     full 6-hour expiry. A tick heartbeat alone reports green
 *                     through all three, because the tick is genuinely fine.
 *
 * Both thresholds are wall-clock ages, not counters, so a check means the same
 * thing on a host that has been up for four minutes or four months.
 */
class CronJobCheck extends Command
{
    protected $signature = 'woo:cron
                            {--check : Report scheduler and sync freshness instead of stamping the heartbeat}
                            {--max-tick= : Staleness threshold for the scheduler tick, in minutes}
                            {--max-sync= : Staleness threshold for the sync watermarks, in minutes}';

    protected $description = 'Stamp the scheduler heartbeat, or (--check) report whether cron and the syncs are still alive';

    public function handle(): int
    {
        return $this->option('check') ? $this->report() : $this->stamp();
    }

    /**
     * Write side: one file overwritten in place, no log line.
     *
     * Runs inline in the schedule:run process rather than via runInBackground() —
     * backgrounding this would fork 1,440 PHP processes a day to write four
     * fields to one file.
     */
    private function stamp(): int
    {
        if (!Heartbeat::stamp()) {
            $this->error('Could not write the scheduler heartbeat — check permissions on ' . Heartbeat::path());
            Log::channel('sync')->error('Failed to write scheduler heartbeat', [
                'path' => Heartbeat::path(),
            ]);

            return self::FAILURE;
        }

        // Visible when a person runs this by hand; discarded by the crontab
        // entry in deploy/SCHEDULER.md, which redirects output.
        $this->line('Scheduler heartbeat stamped at ' . date('Y-m-d H:i:s') . ' UTC.');

        return self::SUCCESS;
    }

    /**
     * Read side: the part that can fail.
     */
    private function report(): int
    {
        $maxTick = $this->threshold('max-tick', 'app.odoowoo_heartbeat_max_tick_minutes');
        $maxSync = $this->threshold('max-sync', 'app.odoowoo_heartbeat_max_sync_minutes');

        $stamp = Heartbeat::read();

        $checks = [
            $this->check(
                'Scheduler tick',
                (bool) config('app.odoowoo_heartbeat'),
                Heartbeat::ageInSeconds(),
                $stamp['at'] ?? null,
                $maxTick,
                'ODOOWOO_HEARTBEAT_ENABLED=false'
            ),
            $this->check(
                'Simple sync',
                (bool) config('app.odoowoo_sync_simple'),
                $this->watermarkAge('simple'),
                SyncWatermark::get('simple'),
                $maxSync,
                'ODOOWOO_SYNC_SIMPLE_ENABLED=false'
            ),
            $this->check(
                'Variable sync',
                (bool) config('app.odoowoo_sync_variable'),
                $this->watermarkAge('variable'),
                SyncWatermark::get('variable'),
                $maxSync,
                'ODOOWOO_SYNC_VARIABLE_ENABLED=false'
            ),
        ];

        $this->line('');
        $this->table(
            ['Check', 'Last seen (UTC)', 'Age', 'Threshold', 'Status'],
            array_column($checks, 'row')
        );

        if ($stamp !== null) {
            $this->line('  Last tick written by host ' . ($stamp['host'] ?? '?') . ', pid ' . ($stamp['pid'] ?? '?') . '.');
        }

        $stale = array_values(array_filter($checks, static fn (array $c) => $c['stale']));

        if ($stale === []) {
            $this->line('');
            $this->info('All checks fresh.');

            return self::SUCCESS;
        }

        $names = implode(', ', array_column($stale, 'label'));

        $this->line('');
        $this->error("Stale: {$names}. See storage/logs/sync-*.log, then deploy/SCHEDULER.md.");

        // Logged only when something is actually wrong — the whole reason this
        // no longer writes a line a minute is so that a line in here means
        // something.
        Log::channel('sync')->error('Scheduler health check failed', [
            'stale'          => array_column($stale, 'label'),
            'max_tick_min'   => $maxTick,
            'max_sync_min'   => $maxSync,
            'heartbeat_host' => $stamp['host'] ?? null,
        ]);

        return self::FAILURE;
    }

    /**
     * Build one table row and decide whether it counts as a failure.
     *
     * A null $ageSeconds means "no usable timestamp" — never recorded, wiped,
     * corrupted, or stamped by a clock that has since moved backwards. That is
     * reported as stale, not as unknown: for everything checked here, the
     * absence of evidence that it ran is the alarm.
     *
     * @return array{row: array<int, string>, stale: bool, label: string}
     */
    private function check(string $label, bool $enabled, ?int $ageSeconds, ?string $lastSeen, int $maxMinutes, string $disabledHint): array
    {
        if (!$enabled) {
            // Not a failure. A leg that is switched off has a watermark frozen
            // at whenever it was last on, and alerting on that would train
            // everyone to ignore this command.
            return [
                'row'   => [$label, $lastSeen ?? '—', '—', '—', 'disabled (' . $disabledHint . ')'],
                'stale' => false,
                'label' => $label,
            ];
        }

        $stale = $ageSeconds === null || $ageSeconds > $maxMinutes * 60;

        return [
            'row' => [
                $label,
                $lastSeen ?? '—',
                $ageSeconds === null ? 'never' : $this->humanAge($ageSeconds),
                $maxMinutes . 'm',
                $stale ? 'STALE' : 'ok',
            ],
            'stale' => $stale,
            'label' => $label,
        ];
    }

    /**
     * Age of a sync watermark in seconds, or null when there is no usable one.
     *
     * SyncWatermark::get() has already rejected unparseable and future values,
     * so anything it returns is safe to subtract.
     */
    private function watermarkAge(string $key): ?int
    {
        $value = SyncWatermark::get($key);

        if ($value === null) {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : max(0, time() - $parsed);
    }

    /**
     * Resolve a threshold from --option, falling back to config.
     *
     * A non-positive threshold would make every check stale on every run, so it
     * is floored at 1 rather than honoured — the same direction SyncWatermark
     * floors its overlap window.
     */
    private function threshold(string $option, string $configKey): int
    {
        $override = $this->option($option);

        $minutes = $override === null || $override === ''
            ? (int) config($configKey)
            : (int) $override;

        return max(1, $minutes);
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
        }

        return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
    }
}
