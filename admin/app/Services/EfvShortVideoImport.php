<?php

namespace App\Services;

use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EfvShortVideoImport
{
    public const CATEGORY = '国产短视频';

    public function run(EfvShortVideoSync $sync, bool $preview, callable $progress, bool $cms = false): array
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $key = config('services.efv.api_key');
        if ((!$cms && !$key) || parse_url($endpoint, PHP_URL_SCHEME) !== 'https') {
            throw new DomainException('请检查 EFV_ENDPOINT 和 EFV_API_KEY。');
        }

        $lock = Cache::lock('efv:import-domestic-short-videos', 28800);
        if (!$lock->get()) {
            throw new DomainException('已有一个国产短视频导入任务正在执行。');
        }

        try {
            $known = array_fill_keys(iterator_to_array($sync->linkedMovies()), true);
            $http = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(20);
            $categoryId = null;
            $cmsAuth = array_filter(['apiKey' => config('services.efv.cms_api_key')]);
            if ($cms) {
                try {
                    $classes = $http->get($endpoint.'/api/provider/vod/', $cmsAuth + ['ac' => 'list', 'num' => 1]);
                } catch (ConnectionException) {
                    throw new DomainException('连接 EFV CMS 采集接口失败。');
                }
                if (!$classes->successful() || (int) $classes->json('code') !== 1 || !is_array($classes->json('class'))) {
                    throw new DomainException('CMS 采集接口不可用，请检查开关、采集 Key 和 IP 白名单。');
                }
                $categoryId = (int) (collect($classes->json('class'))->firstWhere('type_name', self::CATEGORY)['type_id'] ?? 0);
                if ($categoryId < 1) {
                    throw new DomainException('CMS 采集接口未返回「国产短视频」分类。');
                }
            } else {
                $http->withHeaders(['token' => $key]);
            }
            $stats = ['eligible' => 0, 'existing' => 0, 'published' => 0, 'pending' => 0, 'missing_cover' => 0];
            for ($page = 1; ; $page++) {
                try {
                    $response = $cms
                        ? $http->get($endpoint.'/api/provider/vod/', $cmsAuth + [
                            'ac' => 'detail', 't' => $categoryId, 'pg' => $page, 'num' => 50, 'm3u8url' => $endpoint,
                        ])
                        : $http->get($endpoint.'/api/videolist', [
                            'type' => 'movie', 'status' => 'finished', 'category' => self::CATEGORY,
                            'limit' => 50, 'page' => $page,
                        ]);
                } catch (ConnectionException) {
                    throw new DomainException("EFV 第 {$page} 页查询超时，可重新执行导入。");
                }

                $movies = $response->json('list');
                $pageCount = filter_var($response->json('pagecount'), FILTER_VALIDATE_INT);
                if (!$response->successful() || (int) $response->json('code') !== 1
                    || !is_array($movies) || $pageCount === false || $pageCount < 0
                    || (int) $response->json('page') !== $page) {
                    throw new DomainException("EFV 第 {$page} 页返回异常，请检查 API 设置。");
                }

                $new = [];
                foreach ($movies as $movie) {
                    if ($cms) {
                        $movie = $this->fromCms($movie, $endpoint);
                        if (!$movie) {
                            continue;
                        }
                    }
                    $id = $movie['_id'] ?? null;
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)
                        || ($movie['category'] ?? null) !== self::CATEGORY
                        || !in_array($movie['status'] ?? null, ['finished', '转码完成'], true)) {
                        continue;
                    }
                    if (isset($known[$id])) {
                        $stats['existing']++;
                        continue;
                    }
                    $known[$id] = true;
                    $new[$id] = $movie;
                    $stats['eligible']++;
                }

                if (!$preview && $new) {
                    foreach ($this->recordBatch($new) as $id => $video) {
                        try {
                            $warning = $sync->applyTranscodedMovie($video, $new[$id], automatic: true);
                            if ($video->status === Video::STATUS_PUBLISHED) {
                                $stats['published']++;
                                $stats['missing_cover'] += (int) (bool) $warning;
                            } else {
                                $stats['pending']++;
                            }
                        } catch (DomainException) {
                            $stats['pending']++;
                        }
                    }
                }

                $progress($page, $pageCount, $stats);
                if ($page >= $pageCount) {
                    return $stats;
                }
            }
        } finally {
            $lock->release();
        }
    }

    private function fromCms(array $movie, string $endpoint): ?array
    {
        if (($movie['type_name'] ?? null) !== self::CATEGORY || (int) ($movie['vod_total'] ?? 0) !== 0 || (int) ($movie['vod_serial'] ?? 0) !== 0) {
            return null;
        }

        $streams = [];
        foreach (explode('#', str_replace('$$$', '#', (string) ($movie['vod_play_url'] ?? ''))) as $entry) {
            $url = trim(str_contains($entry, '$') ? substr($entry, strpos($entry, '$') + 1) : $entry);
            $parts = parse_url($url);
            $path = $parts['path'] ?? '';
            if (($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== parse_url($endpoint, PHP_URL_HOST)
                || isset($parts['user']) || isset($parts['pass']) || !str_ends_with($path, '.m3u8')
                || !(str_starts_with($path, '/videos/') || preg_match('~^/movie/auto/[a-f0-9]{24}\.m3u8$~D', $path))) {
                continue;
            }
            $streams[] = ['path' => $path];
            break;
        }

        $duration = (string) ($movie['vod_duration'] ?? '');

        return [
            '_id' => $movie['vod_id'] ?? null,
            'category' => self::CATEGORY,
            'status' => 'finished',
            'originalname' => $movie['vod_name'] ?? '',
            'summary' => strip_tags((string) ($movie['vod_content'] ?? '')),
            'm3u8paths' => $streams,
            'poster' => $movie['vod_pic'] ?? null,
            'poster2' => ['url' => $movie['vod_pic_slide'] ?? null],
            'time' => preg_match('/^\d{1,4}:\d{2}:\d{2}$/D', $duration) ? $duration : '',
        ];
    }

    private function recordBatch(array $movies): array
    {
        return DB::transaction(function () use ($movies) {
            $category = Category::firstOrCreate(
                ['type' => Category::TYPE_SHORT, 'name' => self::CATEGORY],
                ['is_active' => true, 'sort' => 0],
            );
            if (!$category->is_active) {
                throw new DomainException('本站「国产短视频」分类已停用，请先启用。');
            }
            $owner = User::adminUploadOwner();
            $videos = [];
            $files = [];
            foreach ($movies as $id => $movie) {
                $title = trim((string) ($movie['originalname'] ?? '')) ?: '未命名短视频';
                $video = Video::create([
                    'user_id' => $owner->id, 'category_id' => $category->id,
                    'type' => Video::TYPE_SHORT, 'title' => Str::limit($title, 150, ''),
                    'description' => Str::limit((string) ($movie['summary'] ?? ''), 500, ''),
                    'url' => '', 'status' => Video::STATUS_DRAFT,
                ]);
                $videos[$id] = $video;
                $files[] = ['name' => $video->title, 'status' => 'EFV 分类导入', 'video_id' => $video->id, 'efv_id' => $id];
            }
            DB::table('efv_gallery_batches')->insert([
                'id' => (string) Str::uuid(), 'admin_id' => 0, 'category_id' => $category->id,
                'section' => 'category-import', 'created_at' => now(),
                'files' => json_encode($files, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);

            return $videos;
        });
    }
}
