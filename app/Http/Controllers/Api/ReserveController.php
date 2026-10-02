<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\SerialGenerator;

class ReserveController extends Controller
{
    public function add(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                "key" => "required|string|max:255",
            ],
            [
                "key.required" => "标题不能为空",
                "key.max" => "标题不能超过 255 个字符",
            ],
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    "code" => 422,
                    "message" => "参数校验失败",
                    "errors" => $validator->errors(),
                ],
                422,
            );
        }

        $key = $request->input("key");

        try {
            $result = DB::transaction(function () use ($key) {
                // 查询该队列下未办结（need_reset = 0）的取号记录，加行锁防止并发取号冲突
                $generator = SerialGenerator::where("queue_key", $key)
                    ->where("need_reset", 0)
                    ->lockForUpdate()
                    ->first();

                if ($generator) {
                    // 已有未办结批次：当前号加一作为新序列号
                    $serialNo = $generator->current_no + 1;
                    $batchNo = $generator->batch_no;

                    $generator->current_no = $serialNo;
                } else {
                    // 没有未办结记录：取该队列历史最大批次号 + 1 开新批次
                    $maxBatchNo = SerialGenerator::where(
                        "queue_key",
                        $key,
                    )->max("batch_no");

                    $generator = SerialGenerator::create([
                        "queue_key" => $key,
                        "batch_no" => ($maxBatchNo ?? 0) + 1,
                        "current_no" => 0,
                        "need_reset" => 0,
                    ]);

                    $batchNo = $generator->batch_no;
                    $serialNo = $generator->current_no + 1;

                    // 与上面分支保持同一套逻辑：取号后 current_no 同步加一
                    $generator->current_no = $serialNo;
                    $generator->active_count = 0;
                }

                $generator->active_count = $generator->active_count + 1;
                $generator->save();

                // 新增一条客户取号记录
                Customer::create([
                    "queue_key" => $key,
                    "batch_no" => $batchNo,
                    "serial_no" => $serialNo,
                    "is_finish" => 0,
                ]);

                return [
                    "queue_key" => $key,
                    "batch_no" => $batchNo,
                    "serial_no" => $serialNo,
                ];
            });
        } catch (\Throwable $e) {
            return response()->json(
                [
                    "code" => 500,
                    "message" => "取号失败：" . $e->getMessage(),
                ],
                500,
            );
        }

        return response()->json([
            "code" => 0,
            "message" => "取号成功",
            "data" => $result,
        ]);
    }

    public function remove(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                "key" => "required|string|max:255",
                "batch_no" => "required|integer",
                "serial_no" => "required|integer",
            ],
            [
                "key.required" => "标题不能为空",
                "key.max" => "标题不能超过 255 个字符",
                "batch_no.required" => "批次号不能为空",
                "serial_no.required" => "序列号不能为空",
            ],
        );

        if ($validator->fails()) {
            return response()->json(
                [
                    "code" => 422,
                    "message" => "参数校验失败",
                    "errors" => $validator->errors(),
                ],
                422,
            );
        }

        $key = $request->input("key");
        $batch_no = $request->input("batch_no");
        $serial_no = $request->input("serial_no");

        try {
            $customer = Customer::where("queue_key", $key)
                ->where("batch_no", $batch_no)
                ->where("serial_no", $serial_no)
                ->lockForUpdate()
                ->first();

            if (!$customer)
            {
                return response()->json([
                    "code" => 1,
                    "message" => "放号失败，该预约不存在",
                ]);
            }

            if ($customer->is_finish == 1)
            {
                return response()->json([
                    "code" => 1,
                    "message" => "放号失败，重复放号",
                ]);
            }

            $customer->is_finish = 1;
            $customer->save();

            $generator = SerialGenerator::where("queue_key", $key)
                ->where("batch_no", $batch_no)
                ->lockForUpdate()
                ->first();

            if ($generator)
            {
                $generator->active_count = $generator->active_count - 1;
                if ($generator->active_count == 0)
                {
                    $generator->need_reset = 1;
                }

                $generator->save();
            }
            return response()->json([
                "code" => 0,
                "message" => "放号成功",
            ]);
        } catch (\Throwable $e) {
            return response()->json(
                [
                    "code" => 500,
                    "message" => "取号失败：" . $e->getMessage(),
                ],
                500,
            );
        }
    }
}
