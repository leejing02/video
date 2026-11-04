<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use DomainException;

class EfvShortVideoSync
{
    public function movieId(Video $video): ?string
    {
        if ($video->type !== Video::TYPE_SHORT) {
            return null;
        }

        foreach (DB::table('efv_gallery_batches')->whereNotNull('group_id')->whereNotNull('files')->cursor() as $batch) {
            foreach (json_decode($batch->files, true) ?? [] as $order => $entry) {
                if ((string) ($entry['video_id'] ?? '') === (string) $video->getKey()) {
                    $id = $entry['efv_id'] ?? substr(hash('sha256', $batch->group_id.':'.$order), 0, 24);
                    return preg_match('/^[a-f0-9]{24}$/iD', $id) ? $id : null;
                }
            }
        }

        return null;
    }

    public function publish(Video $video): void
    {
        $id = $this->movieId($video);
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $key = config('services.efv.api_key');

        if (!$id || !$key || !str_starts_with($endpoint, 'https://')) {
            throw new DomainException('缺少 EFV 视频关联或查询配置。');
        }

        try {
            $response = Http::acceptJson()->withHeaders(['token' => $key])
                ->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->get($endpoint.'/api/getmoviestatus', ['id' => $id]);
        } catch (ConnectionException) {
            throw new DomainException('连接 EFV 超时，请稍后重试。');
        }

        if (!$response->successful()) {
            throw new DomainException('EFV 查询失败，请检查 API 是否已启用及查询密钥是否正确。');
        }

        $movie = $response->json('data');
        if ((int) $response->json('success') !== 1 || !is_array($movie) || ($movie['_id'] ?? null) !== $id) {
            throw new DomainException('EFV 尚未返回该视频的转码结果，请检查 EFV 视频状态和查询配置。');
        }

        $url = collect($movie['m3u8paths'] ?? [])->sortByDesc('hd')
            ->map(fn ($stream) => $this->mediaUrl($endpoint, $stream['path'] ?? null))
            ->first(fn ($url) => $url && str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.m3u8'));
        if (!$url) {
            throw new DomainException('EFV 已返回视频信息，但没有可用的 M3U8 地址。');
        }

        $cover = $this->mediaUrl($endpoint, $movie['poster'] ?? null)
            ?? $this->mediaUrl($endpoint, data_get($movie, 'poster2.url'));
        $time = (string) ($movie['time'] ?? '');
        $duration = 0;
        if (preg_match('/^(\d{1,4}):([0-5]\d):([0-5]\d)(?:\.\d+)?$/D', $time, $parts)) {
            $duration = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) $parts[3];
        } elseif (is_numeric($time) && (float) $time > 0 && (float) $time <= 2147483647) {
            $duration = (int) ceil((float) $time);
        }

        DB::transaction(function () use ($video, $url, $cover, $duration) {
            $record = $video->newQuery()->lockForUpdate()->findOrFail($video->getKey());
            $record->update([
                'url' => $url,
                'cover' => $record->cover ?: $cover,
                'duration' => $duration ?: $record->duration,
                'status' => Video::STATUS_PUBLISHED,
                'published_at' => $record->published_at ?? now(),
            ]);
        });
        $video->refresh();
    }

    private function mediaUrl(string $endpoint, mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || preg_match('~[\x00-\x20]~', $value) || str_contains($value, chr(92))) {
            return null;
        }

        if (str_starts_with($value, './public/')) {
            $value = substr($value, strlen('./public'));
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            $value = $endpoint.$value;
        }

        $parts = parse_url($value);
        if (strlen($value) > 255 || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('~(?:^|/)\.\.(?:/|$)~', rawurldecode($parts['path'] ?? ''))) {
            return null;
        }

        return $value;
    }
}
