/**
 * 移动端排队查询 - API 客户端
 *
 * 分层约定：这一层只负责发请求和解包，不写业务判断、不碰 Alpine 状态。
 * 只读模块：整层没有任何 POST/DELETE，移动端不允许取号/放号。
 */

function unwrap(response) {
    return response.data;
}

function handle(payload) {
    // 约定：code === 0 为成功；422/500 视为失败（not_found 是 data.status，不是错误）
    if (!payload || payload.code !== 0) {
        const errors = payload.errors || {};
        const firstKey = Object.keys(errors)[0];
        const text = firstKey ? errors[firstKey][0] : payload.message;
        const error = new Error(text || '请求失败');
        error.payload = payload;
        throw error;
    }
    return payload.data;
}

/** 查询自己号码的排队位置：GET /api/reserve/position?key=xxx&serial_no=27 */
export function fetchPosition(key, serialNo) {
    return window
        .axios({
            method: 'get',
            url: '/reserve/position',
            params: { key: key, serial_no: serialNo },
        })
        .then(unwrap)
        .then(handle);
}
