<?php

namespace App\Console\Commands;

use App\Http\Controllers\OdooPOS;
use Illuminate\Console\Command;

class OdooCustomerSMSNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'odoo:customer-sms-daily
                            {--date= : Trading day to message, Y-m-d. Defaults to yesterday.}
                            {--dry-run : Resolve and log the recipient list without sending or recording anything.}
                            {--limit= : Send to at most this many customers. The remainder is reported, not silently dropped.}
                            {--retry-failed : Also re-attempt recipients whose previous send is recorded as failed.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send Customer POS Daily Notifications via SMS';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('OdooWoo Customer POS Daily Notifications Initiated - ' . date('F j, Y, g:i a'));

        // Defaulted here rather than baked into the scheduler entry: the old
        // Kernel passed --date as a pre-rendered string, and an omitted option
        // left the Odoo domain comparing against a bare " 00:00:00".
        $date = (string) ($this->option('date') ?: date('Y-m-d', strtotime('-1 day')));

        $parsed = strtotime($date);

        if ($parsed === false) {
            $this->error('Invalid --date value: ' . $date . '. Expected Y-m-d, e.g. ' . date('Y-m-d', strtotime('-1 day')) . '.');
            return 1;
        }

        $date = date('Y-m-d', $parsed);

        $limit = $this->option('limit');

        if ($limit !== null && $limit !== '' && (!ctype_digit((string) $limit) || (int) $limit < 1)) {
            $this->error('Invalid --limit value: ' . $limit . '. Expected a positive integer.');
            return 1;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Date: ' . date('F j, Y', $parsed));

        if ($dryRun) {
            $this->warn('DRY RUN — no messages will be sent and nothing will be recorded.');
        }

        try {
            $summary = (new OdooPOS())->getDailyCustomers($date, [
                'dry_run'      => $dryRun,
                'limit'        => ($limit === null || $limit === '') ? null : (int) $limit,
                'retry_failed' => (bool) $this->option('retry-failed'),
            ]);
        } catch (\Throwable $th) {
            $this->error('Failed: ' . $th->getMessage());
            return 1;
        }

        $this->line('');
        $this->table(['Metric', 'Count'], [
            ['POS sessions (closed)', $summary['sessions']],
            ['POS sessions (still open)', $summary['sessions_open']],
            ['Orders', $summary['orders']],
            ['Distinct customers', $summary['partners']],
            ['Skipped — no phone', $summary['no_phone']],
            ['Skipped — unusable phone', $summary['invalid_phone']],
            ['Skipped — no consent', $summary['no_consent']],
            ['Skipped — already messaged', $summary['already_sent']],
            ['Eligible', $summary['candidates']],
            ['Held back by --limit', $summary['capped']],
            [$dryRun ? 'Would send' : 'Sent', $summary['sent']],
            ['Failed', $summary['failed']],
        ]);

        if ($summary['sessions_open'] > 0) {
            $this->warn($summary['sessions_open'] . ' session(s) from this date are still open. Their customers were not messaged, and this job will not revisit the date.');
        }

        if ($summary['capped'] > 0) {
            $this->warn($summary['capped'] . ' eligible customer(s) were held back by --limit. Re-run without it to reach them.');
        }

        if ($summary['failed'] > 0) {
            $this->warn($summary['failed'] . ' message(s) failed. See storage/logs/sms.log, then re-run with --retry-failed.');
        }

        $this->info('OdooWoo Customer POS Daily Notifications Completed.');

        return $summary['failed'] > 0 ? 1 : 0;
    }
}
