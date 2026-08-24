<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Per-sync high-water marks — the timestamp of the last *successful* run.
 *
 * The sync commands filter Odoo by `write_date >= $since`. A fixed window
 * (e.g. "-30 minutes") loses data the moment a run is skipped or fails: the
 * window it would have covered is never looked at again. Storing the last
 * successful run's start time instead means the next run automatically widens
 * to cover whatever the missed runs left behind.
 *
 * Backed by a JSON file rather than the cache: `storage/app` is gitignored and
 * survives deploys, whereas a routine `php artisan cache:clear` would silently
 * reset every watermark and quietly narrow the sync window back to the default.
 *
 * All timestamps are 'Y-m-d H:i:s' in the app timezone (UTC), which is directly
 * comparable to Odoo's `write_date` without conversion. Note that the value
 * stored is *this host's* clock while `write_date` is the *Odoo server's*: the
 * two must be NTP-synchronised to within `odoowoo_sync_overlap_minutes`, which
 * is the only margin absorbing skew at the window boundary.
 *
 * Every uncertainty in here resolves by widening the window, never narrowing
 * it. A window that is too wide costs a slow run; a window that is too narrow
 * skips records and then marks them covered, which is unrecoverable.
 */
class SyncWatermark
{
    /** Keys in use: 'simple' (woo:sync) and 'variable' (woo:sync-woo-product-variables). */
    private const FILENAME = 'sync-watermarks.json';

    /** Lock file guarding read-modify-write on FILENAME — see withLock(). */
    private const LOCKFILE = 'sync-watermarks.lock';

    /**
     * Last successful run timestamp for $key, or null if none is usable.
     */
    public static function get(string $key): ?string
    {
        $marks = self::withLock(static fn () => self::read());
        $value = $marks[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $parsed = strtotime($value);

        // A hand-edited or corrupted entry must not silently become "epoch" —
        // returning null hands the caller the widest window instead.
        if ($parsed === false) {
            return null;
        }

        // A watermark ahead of the clock (a host clock that jumped forward and
        // back) would filter out every record and then overwrite itself with
        // "now", marking the skipped window covered. Treat it as unusable.
        if ($parsed > time()) {
            Log::channel('sync')->warning('Sync watermark is in the future — ignoring it and widening the window', [
                'key'   => $key,
                'value' => $value,
            ]);
            return null;
        }

        return $value;
    }

    /**
     * Record $timestamp as the watermark for $key.
     *
     * Callers pass the timestamp captured at the *start* of the run, not the
     * end, so records modified while the sync was running are picked up next
     * time rather than falling into the gap between the two.
     */
    public static function set(string $key, string $timestamp): void
    {
        $path = self::path(self::FILENAME);

        $ok = self::withLock(static function () use ($key, $timestamp, $path) {
            $marks       = self::read();
            $marks[$key] = $timestamp;

            $json = json_encode($marks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                return false;
            }

            return @file_put_contents($path, $json, LOCK_EX) !== false;
        });

        if (!$ok) {
            // Never fatal: the previous value survives, so the next run resolves
            // a wider window — the safe direction. Losing the file outright is
            // handled on the read side, which widens to the ceiling rather than
            // falling back to a narrow default.
            Log::channel('sync')->error('Failed to persist sync watermark', [
                'key'  => $key,
                'path' => $path,
            ]);
        }
    }

    /**
     * Work out the `write_date >= $since` window for a batch run.
     *
     * Resolution order: explicit --since override, then the watermark (minus a
     * small overlap buffer), then — when no usable watermark exists — the
     * lookback ceiling.
     *
     * There is deliberately no narrow "first run" default. A watermark that is
     * absent is indistinguishable from one that was wiped by a deploy, emptied
     * by a crash mid-write, or hand-deleted, so the only safe assumption is
     * that an unknown amount of time is uncovered. Widening to the ceiling
     * costs one slow run; narrowing to the schedule interval would skip
     * everything older than it and then mark the gap covered.
     *
     * The overlap buffer covers `write_date`'s one-second granularity and
     * modest clock skew between this host and Odoo at the window boundary.
     * It is deliberately small — the variable sync has no change detection, so
     * every re-seen product costs a full push — but never zero, because it is
     * the only margin there is.
     *
     * @param  string      $key      Watermark key ('simple' | 'variable').
     * @param  string|null $override Raw --since option value, if given.
     * @return array{since: string, source: string, window_minutes: int, clamped_from: string|null}
     *
     * @throws \InvalidArgumentException when $override is not a parseable date.
     */
    public static function resolveSince(string $key, ?string $override): array
    {
        $now = time();

        if ($override !== null && $override !== '') {
            $parsed = strtotime($override);

            if ($parsed === false) {
                throw new \InvalidArgumentException(
                    "Invalid --since value: {$override}. Expected a parseable date, e.g. \"2026-08-01 00:00:00\"."
                );
            }

            // An explicit --since is a deliberate operator instruction and is
            // returned unclamped. Applying the ceiling here would silently
            // narrow a backfill — it would report success having skipped most
            // of the range it was asked to cover.
            return [
                'since'          => date('Y-m-d H:i:s', $parsed),
                'source'         => 'option',
                'window_minutes' => (int) round(($now - $parsed) / 60),
                'clamped_from'   => null,
            ];
        }

        $overlap  = max(1, (int) config('app.odoowoo_sync_overlap_minutes', 3));
        $maxHours = max(1, (int) config('app.odoowoo_sync_max_lookback_hours', 168));
        $floor    = $now - ($maxHours * 3600);

        if (($mark = self::get($key)) !== null) {
            $timestamp = strtotime($mark) - ($overlap * 60);
            $source    = 'watermark';
        } else {
            $timestamp = $floor;
            $source    = 'default';
        }

        $clampedFrom = null;

        // The ceiling guards the scheduled path only: a watermark left stale by
        // a long outage must not turn one tick into an unbounded catalogue
        // pull. But clamping *discards* the range between the watermark and the
        // floor, and the run that clamps goes on to mark that range covered —
        // so the caller is handed the discarded interval and is expected to say
        // so loudly rather than let it pass as a normal run.
        if ($timestamp < $floor) {
            $clampedFrom = date('Y-m-d H:i:s', $timestamp);
            $timestamp   = $floor;
            $source      = 'clamped';
        }

        return [
            'since'          => date('Y-m-d H:i:s', $timestamp),
            'source'         => $source,
            'window_minutes' => (int) round(($now - $timestamp) / 60),
            'clamped_from'   => $clampedFrom,
        ];
    }

    /**
     * Read the marks file. Callers must hold the lock — see withLock().
     *
     * Returns [] for anything it cannot make sense of. That is safe only
     * because every consumer treats a missing key as "widen to the ceiling";
     * do not add a narrow fallback on top of this.
     *
     * @return array<string, string>
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
            Log::channel('sync')->warning('Sync watermark file is unreadable — falling back to the maximum lookback window', [
                'path' => $path,
            ]);
            return [];
        }

        return $decoded;
    }

    /**
     * Serialise read-modify-write on the marks file across processes.
     *
     * A separate lock file is used rather than locking the data file itself:
     * file_put_contents() opens the target with 'wb' — truncating it — *before*
     * it acquires LOCK_EX, so a concurrent reader can observe a zero-byte file
     * even if both sides lock. Locking a file that nobody ever truncates avoids
     * that window entirely.
     *
     * Must not be nested: this takes LOCK_EX, and a second acquisition from the
     * same process on a different handle would block against itself.
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
            // lock file at all, and set() still reports its own write failure.
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
