<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the simple and variable product syncs back to back in one process.
 *
 * This exists to guarantee the two never run concurrently. Scheduling them as
 * two entries cannot achieve that: `withoutOverlapping()` keys its mutex on
 * sha1($expression . $command), so each command holds its own lock and neither
 * can see the other's — two entries on the same cron expression start at the
 * same instant and hammer Odoo and Woo in parallel.
 *
 * Chaining them under a single scheduled entry means one lock covers both, so
 * they cannot overlap in start time or finish time however long either runs.
 */
class SyncAll extends Command
{
    protected $signature = 'woo:sync-all {--images : Force image push for every product and variant}';
    protected $description = 'Run the simple and variable product syncs back to back';

    public function handle(): int
    {
        $startTime = microtime(true);
        $this->info('OdooWoo Full Sync — ' . date('Y-m-d H:i:s'));
        Log::channel('sync')->info('Chained sync started');

        $args = $this->option('images') ? ['--images' => true] : [];

        $simple   = self::SUCCESS;
        $variable = self::SUCCESS;

        if (config('app.odoowoo_sync_simple')) {
            $this->line('');
            $this->info('── Simple products ──────────────────────────────────');
            $simple = $this->runLeg('woo:sync', $args, 'Simple product');
        } else {
            $this->comment('Simple product sync disabled (ODOOWOO_SYNC_SIMPLE_ENABLED) — skipping.');
        }

        // Runs regardless of the simple leg's outcome — including an uncaught
        // throwable, which runLeg() turns into an exit code rather than letting
        // it unwind this method. Each command keeps its own watermark, so a
        // failing simple sync must not stall variable products.
        if (config('app.odoowoo_sync_variable')) {
            $this->line('');
            $this->info('── Variable products ────────────────────────────────');
            $variable = $this->runLeg('woo:sync-woo-product-variables', $args, 'Variable product');
        } else {
            $this->comment('Variable product sync disabled (ODOOWOO_SYNC_VARIABLE_ENABLED) — skipping.');
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $failed  = $simple !== self::SUCCESS || $variable !== self::SUCCESS;

        $this->line('');
        if ($failed) {
            $this->error("Full sync finished in {$elapsed}s with failures — see sync log for details.");
        } else {
            $this->info("Full sync finished in {$elapsed}s.");
        }

        Log::channel('sync')->info('Chained sync finished', [
            'elapsed_s'     => $elapsed,
            'simple_exit'   => $simple,
            'variable_exit' => $variable,
        ]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run one leg, converting an uncaught throwable into a failure exit code.
     *
     * $this->call() goes straight to Symfony's Command::run(), which has no
     * try/catch around execute() — exception handling lives in
     * Application::doRun, which is not on this path. Several remote calls
     * inside the legs are unguarded (the Woo category, brand, attribute and
     * variation reads), so without this a 502 from one endpoint during the
     * simple sync would abort the whole chained run and the variable leg would
     * never start.
     *
     * Nothing is lost by continuing: the throwing leg never reached
     * advanceWatermark(), so its window is still open for the next run.
     */
    private function runLeg(string $command, array $args, string $label): int
    {
        try {
            return $this->call($command, $args);
        } catch (\Throwable $e) {
            $this->error("{$label} sync aborted: " . $e->getMessage());
            Log::channel('sync')->error('Sync leg threw — continuing with the remaining legs', [
                'command'  => $command,
                'error'    => $e->getMessage(),
                'location' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return self::FAILURE;
        }
    }
}
