/**
 * 云途旅行（/fly）- API 客户端
 *
 * 分层约定与 reserve / mobile 一致：这一层只发请求 + 解包 {code,message,data}，
 * 不写业务判断、不碰 Alpine 状态。
 *
 * 整层没有 POST/DELETE：C 端的下单与取消是 Blade 表单（要 CSRF，且完成后 PRG 跳到
 * 可收藏的结果页），不暴露 JSON 写接口。
 *
 * 路径由后端注入 body[data-fly-config] 的 api 段下发，这里不写死 —— 与
 * reserve 模块「队列列表改路由/视图层，不要写死进 JS」是同一条约定。
 * 约定：这些路径是 axios baseURL('/api') 之下的相对路径（'/fly/hotels'），
 * 带上前缀会被 combineURLs 拼成 '/api/api/fly/hotels'。
 *
 * 只有两个方法：/api/fly/cities 也存在，但城市卡在 SSR 首屏就随 data-fly-config
 * 一起下发了，前端只是按当前日期重算卡片链接，再发一次请求纯属浪费。
 */

function unwrap(response) {
    return response.data;
}

function handle(payload) {
    // 约定：code === 0 为成功；422 / 500 / 1 都算失败，把后端中文 message 抛给上层
    if (!payload || payload.code !== 0) {
        const errors = (payload && payload.errors) || {};
        const firstKey = Object.keys(errors)[0];
        const text = firstKey ? errors[firstKey][0] : payload && payload.message;
        const error = new Error(text || '请求失败');
        error.payload = payload;
        throw error;
    }

    return payload.data;
}

function get(path, params) {
    if (!path) {
        return Promise.reject(new Error('缺少接口路径'));
    }

    return window
        .axios({ method: 'get', url: path, params: params || {} })
        .then(unwrap)
        .then(handle);
}

/** 酒店搜索：GET /api/fly/hotels?city&check_in&check_out&q&sort&page */
export function fetchHotels(path, params) {
    return get(path, params);
}

/** 单个酒店的房型房量：GET /api/fly/hotels/{hotel}/room-types?check_in&check_out */
export function fetchRoomTypes(path, params) {
    return get(path, params);
}
