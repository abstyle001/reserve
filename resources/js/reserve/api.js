/**
 * 预约大屏 - API 客户端
 *
 * 分层约定：这一层只负责发请求和解包，不写业务判断、不碰 Alpine 状态。
 * 所有接口统一返回 {code, message, data}；这里把响应体直接返回给上层，
 * 调用方只处理 data，非 0 的 code 由 store 转换成用户可读提示。
 */

function unwrap(response) {
    return response.data;
}

function isBusinessFailure(payload) {
    // 约定：code === 0 为成功；其余（1 / 422 / 500）都视为业务失败
    return !payload || payload.code !== 0;
}

function handle(payload) {
    if (isBusinessFailure(payload)) {
        const errors = payload.errors || {};
        const firstKey = Object.keys(errors)[0];
        const text = firstKey ? errors[firstKey][0] : payload.message;
        const error = new Error(text || '请求失败');
        error.payload = payload;
        throw error;
    }
    return payload.data;
}

function request(config) {
    return window.axios(config).then(unwrap).then(handle);
}

/** 查询队列当前状态：GET /api/reserve/state?key=xxx */
export function fetchState(key) {
    return request({ method: 'get', url: '/reserve/state', params: { key: key } });
}

/** 取号：POST /api/reserve */
export function takeNumber(key) {
    return request({ method: 'post', url: '/reserve', data: { key: key } });
}

/** 放号：DELETE /api/reserve，参数走请求体 */
export function releaseNumber(key, batchNo, serialNo) {
    return request({
        method: 'delete',
        url: '/reserve',
        // axios 0.21：DELETE 的 body 必须写在 config.data，直接传第二个参数会被当成 config 丢弃
        data: { key: key, batch_no: batchNo, serial_no: serialNo },
    });
}
