<?php

namespace App\Services\Fly;

use App\Models\Booking;
use App\Models\Hotel;
use App\Models\RoomType;
use Carbon\Carbon;

/**
 * C 端酒店搜索 + 卡片载荷。
 *
 * SSR 首屏与只读 JSON 接口共用 search() / formatCard()，字段名一字不差，
 * 避免 Blade 与 Alpine 两份渲染漂移。
 *
 * 查询条数恒定：paginate 的 count + 页查询（2 条）+ 房型清单 + 可售聚合 + 占用聚合（3 条）= 5 条，
 * 与「本页几家酒店、每家几个房型、每天几个订单」无关 —— 分步批量聚合，
 * 绝不在卡片循环里发查询。
 */
class HotelSearchService
{
    const SORTS = [
        'recommended' => '推荐',
        'price_asc'   => '价格从低到高',
        'price_desc'  => '价格从高到低',
        'score_desc'  => '评分优先',
    ];

    const MAX_KEYWORD_LENGTH = 50;

    private $availability;

    public function __construct(AvailabilityService $availability)
    {
        $this->availability = $availability;
    }

    /**
     * 归一化搜索条件：非法值一律夹紧到默认值而不是抛异常，
     * 前台参数来自 URL，不能因为一个脏日期白屏。
     *
     * 日期基准取 config('fly.timezone')：全局 app.timezone 是 UTC，
     * 直接 now() 会让中国用户早上 8 点前的「今天」少一天。
     */
    public function normalize($input = [])
    {
        $input = is_array($input) ? $input : [];

        $today = Carbon::now(config('fly.timezone'))->startOfDay();
        $maxAhead = (int) config('fly.max_days_ahead');

        $checkIn = $this->parseDate(isset($input['check_in']) ? $input['check_in'] : null);
        if ($checkIn === null) {
            $checkIn = $today->copy();
        }
        if ($checkIn->lt($today)) {
            $checkIn = $today->copy();
        }
        $latest = $today->copy()->addDays($maxAhead);
        if ($checkIn->gt($latest)) {
            $checkIn = $latest->copy();
        }

        $checkOut = $this->parseDate(isset($input['check_out']) ? $input['check_out'] : null);
        if ($checkOut === null || $checkOut->lte($checkIn)) {
            $checkOut = $checkIn->copy()->addDay();
        }
        $maxNights = (int) config('fly.max_nights');
        if (Booking::nightsBetween($checkIn->toDateString(), $checkOut->toDateString()) > $maxNights) {
            $checkOut = $checkIn->copy()->addDays($maxNights);
        }

        $city = trim((string) (isset($input['city']) ? $input['city'] : ''));
        $q = trim((string) (isset($input['q']) ? $input['q'] : ''));
        if (mb_strlen($q) > self::MAX_KEYWORD_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_KEYWORD_LENGTH);
        }

        $sort = (string) (isset($input['sort']) ? $input['sort'] : '');
        if (!isset(self::SORTS[$sort])) {
            $sort = 'recommended';
        }

        $perPage = (int) config('fly.per_page');
        $page = (int) (isset($input['page']) ? $input['page'] : 1);
        if ($page < 1) {
            $page = 1;
        }

        return [
            'city'      => $city,
            'q'         => $q,
            'check_in'  => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights'    => Booking::nightsBetween($checkIn->toDateString(), $checkOut->toDateString()),
            'sort'      => $sort,
            'page'      => $page,
            'per_page'  => $perPage,
            'today'     => $today->toDateString(),
        ];
    }

    /**
     * @return array items / total / per_page / current_page / last_page / facets
     */
    public function search($input = [])
    {
        $filters = $this->normalize($input);

        $price = StockSql::minSellablePrice($filters['check_in'], $filters['check_out']);

        $query = Hotel::query()
            ->open()
            ->withRoomTypes()
            ->selectRaw('hotels.*, (' . $price[0] . ') as min_price', $price[1]);

        if ($filters['city'] !== '') {
            $query->inCity($filters['city']);
        }

        if ($filters['q'] !== '') {
            $query->keyword($filters['q']);
        }

        $this->applySort($query, $filters['sort']);

        $hotels = $query->paginate($filters['per_page'], ['*'], 'page', $filters['page']);

        // 页码越界（用户手改 URL，或换了筛选条件后停在旧页码）要回落到最后一页，
        // 否则会出现「明明有 14 家却显示没找到酒店」
        if ($hotels->total() > 0 && $hotels->currentPage() > $hotels->lastPage()) {
            $hotels = $query->paginate($filters['per_page'], ['*'], 'page', $hotels->lastPage());
        }

        $aggregates = $this->aggregateFor($hotels->pluck('id')->all(), $filters);

        $offset = ($hotels->currentPage() - 1) * $hotels->perPage();
        $items = [];
        foreach ($hotels->items() as $i => $hotel) {
            // 序号跨页连续，占位渐变不会每页从 fly-grad-1 重新开始
            $items[] = $this->formatCard($hotel, $aggregates, $offset + $i, $filters);
        }

        return [
            'items'        => $items,
            'total'        => $hotels->total(),
            'per_page'     => $hotels->perPage(),
            'current_page' => $hotels->currentPage(),
            'last_page'    => $hotels->lastPage(),
            'facets'       => $this->facets($filters),
        ];
    }

    /**
     * 单个酒店维度：详情页 / 下单页用同一份房量口径（3 条 SQL）。
     */
    public function roomTypesFor(Hotel $hotel, $checkIn, $checkOut)
    {
        $types = RoomType::query()
            ->where('hotel_id', $hotel->id)
            ->orderBy('price')
            ->orderBy('id')
            ->get();

        $aggregates = $this->availability->forRoomTypes($types, $checkIn, $checkOut);

        $agg = isset($aggregates[(int) $hotel->id])
            ? $aggregates[(int) $hotel->id]
            : ['types' => [], 'remaining_total' => 0, 'min_price' => null];

        return [
            'agg'   => $agg,
            'items' => $this->bookUrls($agg['types'], $hotel, $checkIn, $checkOut),
        ];
    }

    /**
     * 卡片载荷 —— Blade 与 Alpine 共用的唯一规格。
     *
     * @param array $filters 归一化后的筛选条件，用于把日期带进详情/下单链接
     */
    public function formatCard(Hotel $hotel, array $aggregates, $index = 0, array $filters = [])
    {
        $hotelId = (int) $hotel->id;
        $agg = isset($aggregates[$hotelId])
            ? $aggregates[$hotelId]
            : ['types' => [], 'remaining_total' => 0, 'min_price' => null];

        // 列表页 SQL 已按同一口径算出 min_price，优先用它（排序与展示必须同源）；
        // 详情页没有那条子查询，回落到批量聚合的结果。
        $minPrice = $hotel->min_price !== null ? (float) $hotel->min_price : $agg['min_price'];

        $remaining = (int) $agg['remaining_total'];
        $soldOut = $minPrice === null || $remaining <= 0;

        $tags = [];
        foreach ((array) $hotel->tag_list as $tag) {
            $tags[] = ['text' => $tag, 'tone' => $this->tagTone($tag)];
        }

        return [
            'id'              => $hotelId,
            'name'            => $hotel->name,
            'city'            => $hotel->city,
            'star_level'      => $hotel->star_level,
            'badge'           => null,
            'cover_url'       => $hotel->cover_url,
            'min_price'       => $soldOut ? null : (float) $minPrice,
            'min_price_text'  => $soldOut ? null : number_format((float) $minPrice, 0, '.', ''),
            'loc'             => $hotel->loc_text,
            'tags'            => $tags,
            'score'           => $hotel->score === null ? null : (float) $hotel->score,
            'score_text'      => $hotel->score_text,
            'review_text'     => $hotel->review_text,
            'sold_out'        => $soldOut,
            'remaining_total' => $remaining,
            'detail_url'      => $this->withDates('/fly/hotels/' . $hotelId, $filters),
            'book_url'        => $this->withDates('/fly/hotels/' . $hotelId . '/book', $filters),
            'grad_index'      => ((int) $index % 8) + 1,
        ];
    }

    /**
     * 把当前日期窗口拼进链接：详情页/下单页必须沿用搜索时的入住离店，
     * 否则点进去房量口径会跳回默认「今晚一晚」。
     */
    private function withDates($url, array $filters)
    {
        $query = [];
        if (!empty($filters['check_in'])) {
            $query['check_in'] = $filters['check_in'];
        }
        if (!empty($filters['check_out'])) {
            $query['check_out'] = $filters['check_out'];
        }

        return empty($query) ? $url : $url . '?' . http_build_query($query);
    }

    /**
     * 城市卡数据：过滤口径与 search() 保持一致，否则「N 家酒店」与点进去的条数对不上。
     */
    public function cities($limit = null)
    {
        $limit = $limit === null ? (int) config('fly.city_limit') : (int) $limit;

        $rows = Hotel::query()
            ->open()
            ->withRoomTypes()
            ->whereNotNull('city')
            ->where('city', '<>', '')
            ->selectRaw('city, count(*) as agg_hotels')
            ->groupBy('city')
            ->orderByDesc('agg_hotels')
            ->orderBy('city')
            ->limit($limit)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'city'        => $row->city,
                'hotel_count' => (int) $row->agg_hotels,
            ];
        }

        return $items;
    }

    private function applySort($query, $sort)
    {
        // null 排最后：两方言都支持 ORDER BY <表达式>，且 min_price / score 为 NULL 时布尔值为 1
        if ($sort === 'price_asc') {
            $query->orderByRaw('min_price is null asc')->orderBy('min_price', 'asc')->orderBy('id');
            return;
        }

        if ($sort === 'price_desc') {
            $query->orderByRaw('min_price is null asc')->orderBy('min_price', 'desc')->orderBy('id');
            return;
        }

        if ($sort === 'score_desc') {
            $query->orderByRaw('score is null asc')->orderBy('score', 'desc')->orderBy('id');
            return;
        }

        // 推荐：评分优先，其次星级，最后按 id 保证分页稳定
        $query->orderByRaw('score is null asc')
            ->orderBy('score', 'desc')
            ->orderByDesc('star_level')
            ->orderBy('id');
    }

    /**
     * 本页房量：2 条批量聚合（房型清单 + 可售/占用由 service 内部各 1 条）。
     */
    private function aggregateFor(array $hotelIds, array $filters)
    {
        if (empty($hotelIds)) {
            return [];
        }

        $types = RoomType::query()
            ->whereIn('hotel_id', $hotelIds)
            ->orderBy('price')
            ->orderBy('id')
            ->get();

        return $this->availability->forRoomTypes($types, $filters['check_in'], $filters['check_out']);
    }

    /**
     * 在现有筛选条件上改一个键，生成 /fly 的相对 URL。
     * 城市卡、热门 chip、排序、分页全靠它，避免各处手拼 query。
     */
    public function pageUrl(array $filters, array $overrides = [], $path = '/fly')
    {
        $keys = ['city', 'q', 'check_in', 'check_out', 'sort', 'page'];
        $query = array_merge(array_intersect_key($filters, array_flip($keys)), $overrides);

        $query = array_filter($query, function ($value) {
            return $value !== null && $value !== '' && $value !== 1; // 第 1 页不进 URL
        });

        return $path . (empty($query) ? '' : '?' . http_build_query($query));
    }

    private function facets(array $filters)
    {
        // 不含城市列表：那是 /api/fly/cities 的职责，塞进 facets 会让每次搜索多跑一条 SQL
        return [
            'city'         => $filters['city'],
            'q'            => $filters['q'],
            'check_in'     => $filters['check_in'],
            'check_out'    => $filters['check_out'],
            'nights'       => $filters['nights'],
            'sort'         => $filters['sort'],
            'today'        => $filters['today'],
            'hot_keywords' => (array) config('fly.hot_keywords'),
            'sorts'        => self::SORTS,
        ];
    }

    private function bookUrls(array $items, Hotel $hotel, $checkIn, $checkOut)
    {
        foreach ($items as $i => $item) {
            $items[$i]['book_url'] = '/fly/hotels/' . (int) $hotel->id . '/book'
                . '?check_in=' . rawurlencode($checkIn) . '&check_out=' . rawurlencode($checkOut)
                . '&room_type_id=' . (int) $item['id'];
        }

        return $items;
    }

    private function tagTone($tag)
    {
        if (strpos($tag, '早') !== false) {
            return 'blue';
        }

        if (strpos($tag, '取消') !== false) {
            return 'green';
        }

        return 'gray';
    }

    private function parseDate($value)
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return Carbon::create($m[1], $m[2], $m[3], 0, 0, 0, config('fly.timezone'));
    }
}
