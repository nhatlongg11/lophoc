<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
            if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
                Redis::connection()->ping();
            }

            return response()->json(['status' => 'ready']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['status' => 'unavailable'], 503);
        }
    }
}
