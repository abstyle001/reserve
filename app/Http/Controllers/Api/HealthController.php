<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * 健康检查接口
 *
 * 用于探活 / 部署校验 / 前端联调示例。
 */
class HealthController extends Controller
{
    /**
     * 健康检查
     *
     * GET /api/health
     */
    public function check(): JsonResponse
    {
        return response()->json([
            'code'    => 0,
            'message' => 'ok',
            'data'    => [
                'status'    => 'healthy',
                'service'   => config('app.name'),
                'env'       => config('app.env'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }
}
