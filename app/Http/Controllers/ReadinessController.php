<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            Cache::get('runtime:readiness');

            if (config('session.driver') === 'database' && config('session.connection') !== null) {
                DB::connection(config('session.connection'))->select('SELECT 1');
            }
            if (config('queue.default') === 'database' && config('queue.connections.database.connection') !== null) {
                DB::connection(config('queue.connections.database.connection'))->select('SELECT 1');
            }
            if (config('session.driver') === 'redis') {
                Redis::connection(config('session.connection') ?? 'default')->ping();
            }
            if (config('queue.default') === 'redis') {
                Redis::connection(config('queue.connections.redis.connection'))->ping();
            }
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        return response()->json(['status' => 'ready'], headers: ['Cache-Control' => 'no-store']);
    }
}
