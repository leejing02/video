<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DouyuScraper
{
    // 硬编码分类（API 不暴露分类列表）
    private const CATEGORIES = [
        ['api_id' => 'yz',   'name' => '颜值',    'slug' => 'douyu-yz'],
        ['api_id' => 'wzry', 'name' => '王者荣耀', 'slug' => 'douyu-wzry'],
        ['api_id' => '1',    'name' => '热门',     'slug' => 'douyu-hot'],
        ['api_id' => '2',    'name' => '娱乐',     'slug' => 'douyu-ent'],
        ['api_id' => '6',    'name' => '射击',     'slug' => 'douyu-fps'],
        ['api_id' => '201',  'name' => '综合',     'slug' => 'douyu-mix'],
        ['api_id' => '203',  'name' => '手游',     'slug' => 'douyu-mobile'],
        ['api_id' => '204',  'name' => '户外',     'slug' => 'douyu-outdoor'],
    ];

    // Returns ['created' => int, 'updated' => int, 'errors' => string[]]
    public function scrape(int $perCate = 15): array
    {
        $created = 0;
        $updated = 0;
        $errors  = [];

        // 先把所有斗鱼房间置为 offline，本次抓到的再标回 live
        DB::table('live_streams')->where('platform', 'douyu')->update(['status' => 'offline']);

        foreach (self::CATEGORIES as $cate) {
            try {
                $cat = $this->ensureCategory($cate);
                $rooms = $this->fetchRooms($cate['api_id'], $perCate);

                foreach ($rooms as $room) {
                    if (empty($room['room_id']) || ($room['online'] ?? 0) <= 0) {
                        continue;
                    }

                    $url = 'https://www.douyu.com/' . $room['room_id'];
                    $row = DB::table('live_streams')->where('stream_url', $url)->first();

                    $data = [
                        'category_id' => $cat->id,
                        'platform'    => 'douyu',
                        'title'       => mb_substr($room['room_name'] ?? '直播间', 0, 100),
                        'cover'       => $room['room_src'] ?? null,
                        'stream_url'  => $url,
                        'viewers'     => (int) ($room['online'] ?? 0),
                        'status'      => 'live',
                        'updated_at'  => now(),
                    ];

                    if ($row) {
                        DB::table('live_streams')->where('id', $row->id)->update($data);
                        $updated++;
                    } else {
                        DB::table('live_streams')->insert(array_merge($data, ['created_at' => now()]));
                        $created++;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $cate['name'] . ': ' . $e->getMessage();
            }
        }

        return compact('created', 'updated', 'errors');
    }

    private function ensureCategory(array $cate): object
    {
        $existing = Category::where('type', Category::TYPE_LIVE)->where('slug', $cate['slug'])->first();
        if ($existing) {
            return $existing;
        }

        return Category::create([
            'name'      => '斗鱼·' . $cate['name'],
            'slug'      => $cate['slug'],
            'type'      => Category::TYPE_LIVE,
            'is_active' => true,
            'sort'      => 200,
        ]);
    }

    private function fetchRooms(string $cateId, int $limit): array
    {
        $response = Http::timeout(10)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Referer'    => 'https://www.douyu.com',
            ])
            ->get("https://open.douyucdn.cn/api/RoomApi/live/{$cateId}?offset=0&limit={$limit}");

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }

        $body = $response->json();
        if (($body['error'] ?? -1) !== 0) {
            throw new \RuntimeException('API error ' . ($body['error'] ?? '?'));
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
