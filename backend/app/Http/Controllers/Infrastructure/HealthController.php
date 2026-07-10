<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    /**
     * Each dependency check is wrapped independently.
     * A failure in one check must never prevent the others from running.
     * The response always contains all fields regardless of which checks fail.
     *
     * HTTP 200  → database + redis + queue all healthy (nginx healthcheck passes)
     * HTTP 503  → at least one core dependency unreachable
     *
     * storage, scheduler, disk_free, memory are informational — they do not
     * influence the status code so a storage issue does not cascade into
     * container restart loops via the Docker/nginx healthcheck.
     */
    public function __invoke(): JsonResponse
    {
        $database  = false;
        $redis     = false;
        $queue     = false;
        $storage   = false;
        $scheduler = false;

        // 1. Database — attempt PDO connection
        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable) {}

        // 2. Redis — ping the configured connection
        try {
            Redis::ping();
            $redis = true;
        } catch (\Throwable) {}

        // 3. Queue — driver-independent size check
        //    redis   → LLEN on the queue key
        //    database → SELECT COUNT(*) on jobs table
        //    sync    → returns 0 immediately (always healthy)
        try {
            app(QueueFactory::class)->connection()->size();
            $queue = true;
        } catch (\Throwable) {}

        // 4. Storage — all four framework directories must be writable.
        //    If any are read-only, Blade compilation and session writes fail.
        try {
            $storage = is_writable(storage_path('logs'))
                    && is_writable(storage_path('framework/cache'))
                    && is_writable(storage_path('framework/sessions'))
                    && is_writable(storage_path('framework/views'));
        } catch (\Throwable) {}

        // 5. Scheduler — check whether artisan schedule:work is in the process list.
        //    Supervisor starts it as a child of supervisord; pgrep finds it by
        //    command string. shell_exec may be disabled on hardened configs;
        //    failure returns false rather than influencing the status code.
        try {
            if (function_exists('shell_exec')) {
                $count     = (int) trim(shell_exec('pgrep -cf "artisan schedule" 2>/dev/null') ?? '0');
                $scheduler = $count > 0;
            }
        } catch (\Throwable) {}

        // 6. Build metadata — missing file is non-fatal
        $buildInfo = [];
        try {
            $path = public_path('build-info');
            if (file_exists($path)) {
                $buildInfo = json_decode((string) file_get_contents($path), true) ?? [];
            }
        } catch (\Throwable) {}

        // 7. System resources — informational; absent on failure, never 503
        $diskFree    = null;
        $memoryUsage = null;
        try {
            $free     = disk_free_space(storage_path());
            $diskFree = $free !== false
                ? round($free / 1_073_741_824, 2).' GB'
                : null;
        } catch (\Throwable) {}
        try {
            $used        = memory_get_usage(true);
            $limit       = ini_get('memory_limit');
            $memoryUsage = round($used / 1_048_576, 1).' MB / '.$limit;
        } catch (\Throwable) {}

        $healthy = $database && $redis && $queue;

        return response()->json([
            'status'      => $healthy ? 'ok' : 'degraded',
            'environment' => app()->environment(),
            'version'     => $buildInfo['version']  ?? 'unknown',
            'git_sha'     => $buildInfo['commit']   ?? 'unknown',
            'built_at'    => $buildInfo['built_at'] ?? 'unknown',
            'database'    => $database,
            'redis'       => $redis,
            'queue'       => $queue,
            'storage'     => $storage,
            'scheduler'   => $scheduler,
            'disk_free'   => $diskFree,
            'memory'      => $memoryUsage,
            'timestamp'   => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
