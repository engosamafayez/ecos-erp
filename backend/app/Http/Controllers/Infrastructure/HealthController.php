<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        $database = false;
        $redis    = false;
        $queue    = false;

        try {
            DB::connection()->getPdo();
            $database = true;
        } catch (\Throwable) {}

        try {
            Redis::ping();
            $redis = true;
        } catch (\Throwable) {}

        try {
            Queue::size('default');
            $queue = true;
        } catch (\Throwable) {}

        $buildInfo = [];
        $path = public_path('build-info');
        if (file_exists($path)) {
            $buildInfo = json_decode(file_get_contents($path), true) ?? [];
        }

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
