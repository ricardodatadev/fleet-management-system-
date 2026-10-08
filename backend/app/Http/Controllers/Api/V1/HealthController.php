<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use App\Support\Api\HealthChecker;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthChecker $checker): JsonResponse
    {
        $db = $checker->database();
        $redis = $checker->redis();

        $data = [
            'app' => 'up',
            'db' => $db ? 'up' : 'down',
            'redis' => $redis ? 'up' : 'down',
            'version' => config('app.version'),
        ];

        if (! $db || ! $redis) {
            return ApiResponse::error(__('api.health_down'), 503, null, $data);
        }

        return ApiResponse::success($data);
    }
}
