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
     */
    public function __invoke(): JsonResponse
    {
        $database = false;
        $redis    = false;
        $queue    = false;

        // Independent check 1: database connectivity
        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable) {}

        // Independent check 2: Redis connectivity
        try {
            Redis::ping();
            $redis = true;
        } catch (\Throwable) {}

        // Independent check 3: queue connection (driver-independent)
        // Uses the Queue factory contract — works for any configured driver:
        //   redis      → opens TCP connection, executes LLEN
        //   database   → executes SELECT COUNT(*)
        //   sync       → returns 0 immediately (always healthy)
        //   sqs/beanstalkd → uses respective SDK
        // Does not assume Redis; does not use Queue facade directly.
        try {
            app(QueueFactory::class)->connection()->size();
            $queue = true;
        } catch (\Throwable) {}

        // Build metadata — wrapped independently so a missing file never
        // throws and never influences the health response fields above.
        $buildInfo = [];
        try {
            $path = public_path('build-info');
            if (file_exists($path)) {
                $buildInfo = json_decode((string) file_get_contents($path), true) ?? [];
            }
        } catch (\Throwable) {}

        $healthy = $database && $redis && $queue;

        return response()->json([
            'status'   => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'redis'    => $redis,
            'queue'    => $queue,
            'version'  => $buildInfo['version']  ?? 'unknown',
            'commit'   => $buildInfo['commit']   ?? 'unknown',
            'built_at' => $buildInfo['built_at'] ?? 'unknown',
        ], $healthy ? 200 : 503);
    }
}
