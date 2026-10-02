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

    /**
     * 查询指定队列的当前状态（只读接口）
     *
     * GET /api/reserve/state?key=xxx
     *
     * 说明：本接口只做读，不开启事务、不加行锁 —— 前端会 5s 轮询，
     * 加 lockForUpdate 在 sqlite 下会与并发取号抢写锁。
     */
    public function state(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                "key" => "required|string|max:255",
            ],
            [
                "key.required" => "队列标识不能为空",
                "key.max" => "队列标识不能超过 255 个字符",
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
            // 取该队列最新批次（batch_no 最大的那条，无论是否已办结）
            $generator = SerialGenerator::where("queue_key", $key)
                ->orderByDesc("batch_no")
                ->first();

            $batchNo = $generator ? (int) $generator->batch_no : 0;

            // 已放号数量按「当前批次」统计，不能用全队列 count，否则跨批次会把剩余算错
            $releasedTotal = Customer::where("queue_key", $key)
                ->where("batch_no", $batchNo)
                ->where("is_finish", 1)
                ->count();

            // 当前批次的号票列表：大屏号票面板的唯一数据源。
            // 号票必须由后端下发（而不是浏览器 localStorage），
            // 否则不同设备 / 不同 origin 看到的面板不一致，本地 is_finish 也会与后端脱节。
            $tickets = Customer::where("queue_key", $key)
                ->where("batch_no", $batchNo)
                ->orderBy("serial_no")
                ->get(["serial_no", "is_finish"])
                ->map(function ($c) {
                    return [
                        "serial_no" => (int) $c->serial_no,
                        "is_finish" => (int) $c->is_finish,
                    ];
                })
                ->values()
                ->all();

            $data = [
                "queue_key" => $key,
                "batch_no" => $batchNo,
                "current_no" => $generator ? (int) $generator->current_no : 0, // 本批次已发号上限
                "active_count" => $generator ? (int) $generator->active_count : 0, // 未办结数量
                "need_reset" => $generator ? (int) $generator->need_reset : 1, // 1=本批已办结
                "released_total" => $releasedTotal, // 本批已放号
                "remaining" => $generator ? (int) $generator->active_count : 0, // 剩余 = 未办结数量
                "tickets" => $tickets, // 当前批次号票 [{serial_no, is_finish}]
                "server_time" => now()->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            return response()->json(
                [
                    "code" => 500,
                    "message" => "查询队列状态失败：" . $e->getMessage(),
                ],
                500,
            );
        }

        return response()->json([
            "code" => 0,
            "message" => "查询成功",
            "data" => $data,
        ]);
    }

    /**
     * 移动端排队查询（只读接口）
     *
     * GET /api/reserve/position?key=xxx&serial_no=27
     *
     * 用户在大屏上只看到自己的 serial_no，不知道 batch_no。
     * 由状态机推论：新批次只会在上一批全部办结（need_reset=1）后才开启，
     * 因此仍在排队的号码必然位于该队列的最新批次 —— 这里按 max(batch_no) 定位，
     * 用户无需输入批次号。
     *
     * 与 state() 一样：纯 SELECT，不开事务、不加锁（前端会 5s 轮询）。
     * 「未找到」不是错误而是正常状态（换批后旧号失效），所以 code 恒为 0，
     * 用 data.status 区分 waiting / finished / not_found。
     */
    public function position(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                "key" => "required|string|max:255",
                "serial_no" => "required|integer|min:1",
            ],
            [
                "key.required" => "队列标识不能为空",
                "key.max" => "队列标识不能超过 255 个字符",
                "serial_no.required" => "号码不能为空",
                "serial_no.integer" => "号码必须是数字",
                "serial_no.min" => "号码必须大于 0",
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
        $serialNo = (int) $request->input("serial_no");

        try {
            // 取该队列最新批次（batch_no 最大的那条，无论是否已办结）
            $generator = SerialGenerator::where("queue_key", $key)
                ->orderByDesc("batch_no")
                ->first();

            $batchNo = $generator ? (int) $generator->batch_no : 0;

            $customer = $generator
                ? Customer::where("queue_key", $key)
                    ->where("batch_no", $batchNo)
                    ->where("serial_no", $serialNo)
                    ->first()
                : null;

            $aheadCount = 0;
            if (!$customer) {
                $status = "not_found";
            } elseif ((int) $customer->is_finish === 1) {
                $status = "finished";
            } else {
                $status = "waiting";
                // 前面还有几人 = 同批次未办结且号码更小的数量
                $aheadCount = Customer::where("queue_key", $key)
                    ->where("batch_no", $batchNo)
                    ->where("is_finish", 0)
                    ->where("serial_no", "<", $serialNo)
                    ->count();
            }

            $data = [
                "queue_key" => $key,
                "batch_no" => $batchNo,
                "serial_no" => $serialNo,
                "status" => $status, // waiting / finished / not_found
                "ahead_count" => $aheadCount, // 仅 waiting 时有意义
                "current_no" => $generator ? (int) $generator->current_no : 0,
                "active_count" => $generator ? (int) $generator->active_count : 0,
                "server_time" => now()->toDateTimeString(),
            ];
        } catch (\Throwable $e) {
            return response()->json(
                [
                    "code" => 500,
                    "message" => "查询排队位置失败：" . $e->getMessage(),
                ],
                500,
            );
        }

        return response()->json([
            "code" => 0,
            "message" => "查询成功",
            "data" => $data,
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
