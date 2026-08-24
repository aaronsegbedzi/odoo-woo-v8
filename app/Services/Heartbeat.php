<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Liveness stamp for Laravel's scheduler tick.
 *
 * `woo:cron` overwrites this file every minute; `woo:cron --check` reads it and
 * fails when it goes stale. The split is the whole point: a heartbeat that is
 * only ever *written* cannot tell "cron is dead" apart from "cron is fine but
 * nobody looked" — both produce silence. Only a read side that asserts
 * freshness turns a missing tick into a signal.
 *
 * Backed by a JSON file in storage/app for the same reasons as
 * {@see SyncWatermark}: it is gitignored, survives deploys, and is not wiped by
 * a routine `php artisan cache:clear` — which, for a monitoring artefact, would
 * manufacture exactly the outage it exists to detect.
 *
 * Deliberately not a log line per tick. The previous implementation wrote one
 * INFO record a minute to the unrotated single-file channel: 1,440 lines a day
 * appended forever, interleaved with real errors and stack traces, readable
 * only by grep. One file overwritten in place stays O(1) in size and can be
 * read by anything — this command, a status endpoint, an external monitor.
 */
class Heartbeat
{
    private const FILENAME = 'scheduler-heartbeat.json';

    /** Lock file guarding the data file — see withLock(). */
    private const LOCKFILE = 'scheduler-heartbeat.lock';

    /**
     * Record "the scheduler reached this point, now".
     *
     * Returns false rather than throwing: a heartbeat that cannot be written is
     * a monitoring failure, not a sync failure, and must never take down the
     * scheduler tick that carries it. The caller reports it.
     */
    public static function stamp(): bool
    {
        $path = self::path(self::FILENAME);

        $payload = [
            // Human-readable, app timezone (UTC) — matches the watermark file
            // and the log timestamps it will be read alongside.
            'at'   => date('Y-m-d H:i:s'),
            // What ageInSeconds() actually uses. Kept separate from 'at' so a
            // hand-edited or reformatted 'at' cannot silently change the age.
            'unix' => time(),
            'host' => gethostname() ?: 'unknown',
            'pid'  => getmypid() ?: 0,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        return (bool) self::withLock(static fn () => @file_put_contents($path, $json, LOCK_EX) !== false);
    }

    /**
     * The last stamp, or null when there is none that can be trusted.
     *
     * @return array{at: string, unix: int, host: string, pid: int}|null
     */
    public static function read(): ?array
    {
        $path = self::path(self::FILENAME);

        $raw = self::withLock(static function () use ($path) {
            if (!is_file($path)) {
                return null;
            }

            $contents = @file_get_contents($path);

            return $contents === false ? null : $contents;
        });

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['unix']) || !is_int($decoded['unix'])) {
            Log::channel('sync')->warning('Scheduler heartbeat file is unreadable — treating the scheduler as unseen', [
                'path' => $path,
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * Seconds since the last usable stamp, or null when there is none.
     *
     * Null means "unknown", and every caller must read that as stale rather
     * than fresh. A liveness check resolves its uncertainty towards alerting:
     * a false alarm costs a glance at the server, a false all-clear costs a
     * silently dead sync.
     */
    public static function ageInSeconds(): ?int
    {
        $stamp = self::read();

        if ($stamp === null) {
            return null;
        }

        $age = time() - $stamp['unix'];

        // A stamp from the future means this host's clock moved backwards
        // between the write and this read. The age is meaningless, and the one
        // reading that must not be produced is a small positive number — that
        // would report a healthy scheduler on the strength of a broken clock.
        if ($age < 0) {
            Log::channel('sync')->warning('Scheduler heartbeat is in the future — clock skew on this host', [
                'stamped_at' => $stamp['at'] ?? null,
                'host'       => $stamp['host'] ?? null,
            ]);

            return null;
        }

        return $age;
    }

    public static function path(string $file = self::FILENAME): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . $file);
    }

    /**
     * Serialise access to the heartbeat file across processes.
     *
     * A separate lock file is used rather than locking the data file itself,
     * for the reason spelled out in SyncWatermark::withLock(): file_put_contents()
     * truncates the target *before* it takes LOCK_EX, so a reader can observe a
     * zero-byte file even when both sides lock. Here that window is worse than
     * merely untidy — an empty read is indistinguishable from a missing
     * heartbeat, so it would page someone about a scheduler that is running
     * perfectly, once every time the check lands on the same millisecond as the
     * tick.
     *
     * @template T
     * @param  \Closure(): T $fn
     * @return T
     */
    private static function withLock(\Closure $fn)
    {
        $handle = @fopen(self::path(self::LOCKFILE), 'c');

        if ($handle === false) {
            // Storage is unwritable. Running unlocked is no worse than having no
            // lock file at all, and stamp() still reports its own write failure.
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
}
