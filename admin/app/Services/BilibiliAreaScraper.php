<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class BilibiliAreaScraper
{
    // Returns ['created' => int, 'updated' => int, 'errors' => string[]]
    public function scrape(): array
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Referer'    => 'https://live.bilibili.com',
                'Origin'     => 'https://live.bilibili.com',
            ])
            ->get('https://api.live.bilibili.com/room/v1/Area/getList');

        if (! $response->successful()) {
            throw new \RuntimeException('Bilibili API 请求失败：HTTP ' . $response->status());
        }

        $body = $response->json();
        if (($body['code'] ?? -1) !== 0) {
            throw new \RuntimeException('Bilibili API 返回错误：' . ($body['msg'] ?? $body['message'] ?? '未知'));
        }

        $created = 0;
        $updated = 0;
        $errors  = [];
        $sort    = 1;

        foreach ($body['data'] as $parent) {
            $parentName = $parent['name'] ?? '';
            $parentSlug = $this->toSlug($parentName, 'area-' . $parent['id']);

            [$cat, $wasNew] = $this->upsert([
                'name'      => $parentName,
                'slug'      => $parentSlug,
                'type'      => Category::TYPE_LIVE,
                'parent_id' => null,
                'sort'      => $sort++,
                'is_active' => true,
            ]);

            $wasNew ? $created++ : $updated++;

            foreach ($parent['list'] ?? [] as $sub) {
                $subName = $sub['name'] ?? '';
                $subSlug = $this->toSlug($subName, 'sub-' . $sub['id']);

                try {
                    [, $wasNew] = $this->upsert([
                        'name'      => $subName,
                        'slug'      => $subSlug,
                        'type'      => Category::TYPE_LIVE,
                        'parent_id' => $cat->id,
                        'sort'      => $sort++,
                        'is_active' => true,
                        // 不存 CDN 图片 URL：Bilibili 有防盗链，直接引用会 403
                    ]);
                    $wasNew ? $created++ : $updated++;
                } catch (\Throwable $e) {
                    $errors[] = "{$subName}: " . $e->getMessage();
                }
            }
        }

        return compact('created', 'updated', 'errors');
    }

    // [Category, bool $isNew]
    private function upsert(array $attrs): array
    {
        $slug = $attrs['slug'];

        $existing = Category::where('type', Category::TYPE_LIVE)
            ->where('slug', $slug)
            ->first();

        if ($existing) {
            $existing->update($attrs);
            return [$existing, false];
        }

        // slug collision with non-live category — append suffix
        if (Category::where('slug', $slug)->exists()) {
            $attrs['slug'] = $slug . '-live';
        }

        return [Category::create($attrs), true];
    }

    private function toSlug(string $name, string $fallback): string
    {
        $slug = Str::slug($name);
        return $slug !== '' ? $slug : $fallback;
    }
}
