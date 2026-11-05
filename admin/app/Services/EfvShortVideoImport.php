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

    public function run(EfvShortVideoSync $sync, bool $preview, callable $progress, bool $cms = false, bool $reconcile = false): array
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
            $localIds = array_fill_keys(Video::query()->where('type', Video::TYPE_SHORT)->pluck('id')->all(), true);
            $known = [];
            foreach ($sync->linkedMovies() as $videoId => $sourceId) {
                if (isset($localIds[$videoId])) {
                    $known[$sourceId] = true;
                }
            }
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
            $imported = [];
            foreach (DB::table('efv_gallery_batches')->where('section', 'category-import')->cursor() as $batch) {
                foreach (json_decode($batch->files, true) ?? [] as $file) {
                    if (isset($file['efv_id'], $file['video_id'])) {
                        $imported[$file['efv_id']][] = (int) $file['video_id'];
                    }
                }
            }
            $seen = [];
            $expectedTotal = null;
            $expectedPages = null;
            $scanned = 0;
            $stats = ['source_total' => null, 'scanned' => 0, 'unique' => 0, 'duplicates' => [], 'archive_skipped' => false, 'skipped' => [], 'updated' => 0, 'archived' => 0, 'eligible' => 0, 'existing' => 0, 'published' => 0, 'pending' => 0, 'missing_cover' => 0];
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

                $stats['source_total'] = $response->json('total');
                $stats['scanned'] += count($movies);
                if ($reconcile) {
                    $total = filter_var($response->json('total'), FILTER_VALIDATE_INT);
                    if ($total === false || $total < 0
                        || ($expectedTotal !== null && $expectedTotal !== $total)
                        || ($expectedPages !== null && $expectedPages !== $pageCount)
                        || ($total > 0 && !$movies)) {
                        throw new DomainException('采集分页或总数发生变化，已停止同步下架，请重新执行。');
                    }
                    $expectedTotal = $total;
                    $expectedPages = $pageCount;
                    $uniqueMovies = [];
                    foreach ($movies as $entry) {
                        $sourceId = $cms ? ($entry['vod_id'] ?? null) : ($entry['_id'] ?? null);
                        if (!is_string($sourceId) || !preg_match('/^[a-f0-9]{24}$/D', $sourceId)) {
                            throw new DomainException("采集第 {$page} 页视频 ID 格式异常，已停止同步下架。");
                        }
                        if (isset($seen[$sourceId])) {
                            $stats['duplicates'][] = ['page' => $page, 'id' => $sourceId];
                            continue;
                        }
                        $seen[$sourceId] = true;
                        $scanned++;
                        $uniqueMovies[] = $entry;
                    }
                    $movies = $uniqueMovies;
                    $stats['unique'] = $scanned;
                }

                $new = [];
                foreach ($movies as $movie) {
                    if ($cms) {
                        $source = $movie;
                        $movie = $this->fromCms($movie, $endpoint);
                        if (!$movie) {
                            $stats['skipped'][] = [
                                'id' => $source['vod_id'] ?? '',
                                'title' => $source['vod_name'] ?? '',
                                'reason' => '分类不符或被标记为剧集（vod_total / vod_serial 非零）',
                            ];
                            continue;
                        }
                    }
                    $id = $movie['_id'] ?? null;
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)
                        || ($movie['category'] ?? null) !== self::CATEGORY
                        || !in_array($movie['status'] ?? null, ['finished', '转码完成'], true)) {
                        $stats['skipped'][] = [
                            'id' => $id ?? '', 'title' => $movie['originalname'] ?? '',
                            'reason' => '视频 ID、分类或转码状态不符合导入要求',
                        ];
                        continue;
                    }
                    if (isset($known[$id])) {
                        $stats['existing']++;
                        if ($reconcile) {
                            foreach ($imported[$id] ?? [] as $videoId) {
                                $video = Video::query()->where('type', Video::TYPE_SHORT)->find($videoId);
                                if (!$video) {
                                    continue;
                                }
                                $stats['updated']++;
                                if (!$preview) {
                                    try {
                                        $sync->applyTranscodedMovie($video, $movie, refreshCover: blank($video->cover));
                                        $video->update([
                                            'title' => Str::limit(trim((string) ($movie['originalname'] ?? '')) ?: '未命名短视频', 150, ''),
                                            'description' => Str::limit((string) ($movie['summary'] ?? ''), 500, ''),
                                        ]);
                                        Cache::forget(EfvShortVideoSync::frontendCoverCacheKey(
                                            $video->id, app(EfvMediaDomains::class)->read()['image_origin'],
                                        ));
                                    } catch (DomainException) {
                                        $stats['pending']++;
                                    }
                                }
                            }
                        }
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
                    if ($reconcile) {
                        if ($scanned !== $expectedTotal) {
                            $stats['archive_skipped'] = true;
                            return $stats;
                        }
                        $missing = [];
                        foreach ($imported as $sourceId => $videoIds) {
                            if (!isset($seen[$sourceId])) {
                                array_push($missing, ...$videoIds);
                            }
                        }
                        foreach (array_chunk(array_unique($missing), 500) as $ids) {
                            $query = Video::query()->where('type', Video::TYPE_SHORT)->whereIn('id', $ids)
                                ->where('status', '!=', Video::STATUS_ARCHIVED);
                            $stats['archived'] += $preview ? $query->count() : $query->update(['status' => Video::STATUS_ARCHIVED]);
                        }
                    }
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
            'time' => preg_match('/^\d{1,4}:\d{2}:\d{2}$/D', $duration) || is_numeric($duration) ? $duration : '',
        ];
    }

    private function recordBatch(array $movies): array
    {
        return DB::transaction(function () use ($movies) {
            $category = Category::query()->where('type', Category::TYPE_SHORT)
                ->whereIn('id', DB::table('efv_gallery_batches')->where('section', 'category-import')->select('category_id'))
                ->first()
                ?? Category::query()->where('type', Category::TYPE_SHORT)->where('name', '国产')->first()
                ?? Category::firstOrCreate(
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
