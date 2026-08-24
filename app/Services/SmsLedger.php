<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Record of which numbers have already been messaged, per campaign per day.
 *
 * Without this, re-running a send — a manual retry, a backfill, a scheduler
 * that fires twice after a clock change — bills the shop again and messages
 * the same customers again. Unlike a failed sync, an SMS cannot be withdrawn
 * once sent, so the guard has to sit in front of the gateway rather than being
 * reconciled afterwards.
 *
 * Backed by a JSON file for the same reasons as App\Services\SyncWatermark:
 * this app has no database configured, and `storage/app` survives deploys
 * where the cache would be wiped by a routine `cache:clear` — silently
 * re-arming every duplicate this class exists to prevent.
 *
 * An entry is written *before* the gateway call and updated with the outcome
 * after. A crash between the two therefore leaves a 'sending' entry, which
 * still blocks a re-send: for a courtesy message, a miss that shows up in the
 * log as 'sending' is recoverable by an operator, whereas a duplicate charged
 * to the customer's attention is not. `--retry-failed` reopens the entries
 * that are known to have failed.
 */
class SmsLedger
{
    private const FILENAME = 'sms-sent.json';

    /** Lock file guarding read-modify-write on FILENAME — see withLock(). */
    private const LOCKFILE = 'sms-sent.lock';

    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT    = 'sent';
    public const STATUS_FAILED  = 'failed';

    /**
     * All numbers already handled for this campaign/date, as a lookup set.
     *
     * One locked read for the whole run rather than one per customer.
     *
     * @param  bool $retryFailed Treat a recorded failure as "not yet handled".
     * @return array<string, true>
     */
    public static function handledSet(string $campaign, string $date, bool $retryFailed = false): array
    {
        $entries = self::withLock(static fn () => self::read())[$campaign][$date] ?? [];

        if (!is_array($entries)) {
            return [];
        }

        $set = [];

        foreach ($entries as $msisdn => $entry) {
            $status = is_array($entry) ? ($entry['status'] ?? null) : null;

            if ($retryFailed && $status === self::STATUS_FAILED) {
                continue;
            }

            $set[(string) $msisdn] = true;
        }

        return $set;
    }

    /**
     * Write (or overwrite) the entry for one recipient.
     *
     * @param array<string, mixed> $context Gateway detail kept for auditing.
     */
    public static function record(string $campaign, string $date, string $msisdn, string $status, array $context = []): void
    {
        $path = self::path(self::FILENAME);

        $ok = self::withLock(static function () use ($campaign, $date, $msisdn, $status, $context, $path) {
            $data = self::read();

            $data[$campaign][$date][$msisdn] = $context + [
                'status' => $status,
                'at'     => date('Y-m-d H:i:s'),
            ];

            return self::write($path, $data);
        });

        if (!$ok) {
            // Loud, because the run continues and the next run will no longer
            // be able to tell that this number was already messaged.
            Log::channel('sms')->error('Failed to persist SMS ledger entry — duplicate sends are now possible', [
                'campaign' => $campaign,
                'date'     => $date,
                'msisdn'   => self::mask($msisdn),
                'path'     => $path,
            ]);
        }
    }

    /**
     * Drop campaign days older than $keepDays so the file stays bounded.
     */
    public static function prune(int $keepDays): void
    {
        $keepDays = max(1, $keepDays);
        $cutoff   = date('Y-m-d', strtotime('-' . $keepDays . ' days'));
        $path     = self::path(self::FILENAME);

        self::withLock(static function () use ($cutoff, $path) {
            $data    = self::read();
            $changed = false;

            foreach ($data as $campaign => $days) {
                if (!is_array($days)) {
                    unset($data[$campaign]);
                    $changed = true;
                    continue;
                }

                foreach ($days as $day => $_ignored) {
                    // String comparison is exact for zero-padded Y-m-d and
                    // avoids re-parsing every key on every run.
                    if ((string) $day < $cutoff) {
                        unset($data[$campaign][$day]);
                        $changed = true;
                    }
                }
            }

            return $changed ? self::write($path, $data) : true;
        });
    }

    /**
     * Last four digits only — enough to trace one delivery in the log without
     * turning the log file into a copy of the customer list.
     */
    public static function mask(string $msisdn): string
    {
        return strlen($msisdn) <= 4
            ? $msisdn
            : str_repeat('*', strlen($msisdn) - 4) . substr($msisdn, -4);
    }

    /**
     * Read the ledger. Callers must hold the lock — see withLock().
     *
     * @return array<string, mixed>
     */
    private static function read(): array
    {
        $path = self::path(self::FILENAME);

        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            // Treated as empty, which re-arms every duplicate the file was
            // holding back — so it is reported rather than absorbed.
            Log::channel('sms')->error('SMS ledger is unreadable — duplicate-send protection is not in effect', [
                'path' => $path,
            ]);
            return [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function write(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        return @file_put_contents($path, $json, LOCK_EX) !== false;
    }

    /**
     * Serialise read-modify-write across processes.
     *
     * A separate lock file is used rather than locking the data file itself:
     * file_put_contents() truncates the target before it acquires LOCK_EX, so a
     * concurrent reader can observe a zero-byte file even when both sides lock.
     *
     * Must not be nested — this takes LOCK_EX and would block against itself.
     *
     * @template T
     * @param  \Closure(): T $fn
     * @return T
     */
    private static function withLock(\Closure $fn)
    {
        $handle = @fopen(self::path(self::LOCKFILE), 'c');

        if ($handle === false) {
            return $fn();
        }

        $locked = @flock($handle, LOCK_EX);

        try {
            return $fn();
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            @fclose($handle);
        }
    }

    private static function path(string $file): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . $file);
    }
}
