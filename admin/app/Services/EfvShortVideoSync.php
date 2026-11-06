<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use DomainException;

class EfvShortVideoSync
{
    /** EFV path (e.g. /videos/<YYYYMM>/<DD>/<id>/cover.webp) of the last downloaded cover. */
    private ?string $downloadedCoverSource = null;

    public function downloadedCoverSource(): ?string
    {
        return $this->downloadedCoverSource;
    }

    /** Only EFV movie media paths are stored; the image domain is applied when serving. */
    private static function efvCoverPath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~^/videos/[A-Za-z0-9_/-]+\.(?:webp|jpe?g|png|gif)$~D', $path) ? $path : null;
    }
    public function movieId(Video $video): ?string
    {
        if ($video->type !== Video::TYPE_SHORT) {
            return null;
        }

        foreach ($this->linkedMovies() as $videoId => $movieId) {
            if ($videoId === (int) $video->getKey()) {
                return $movieId;
            }
        }

        return null;
    }

    public function linkedMovies(): iterable
    {
        foreach (DB::table('efv_gallery_batches')->whereNotNull('files')->orderBy('id')->cursor() as $batch) {
            foreach (json_decode($batch->files, true) ?? [] as $order => $entry) {
                $videoId = (int) ($entry['video_id'] ?? 0);
                $id = $entry['efv_id'] ?? ($batch->group_id ? substr(hash('sha256', $batch->group_id.':'.$order), 0, 24) : null);
                if ($videoId > 0 && is_string($id) && preg_match('/^[a-f0-9]{24}$/iD', $id)) {
                    yield $videoId => $id;
                }
            }
        }
    }

    public function publish(Video $video): ?string
    {
        return $this->sync($video, $this->movieId($video));
    }

    public function needsAutomaticSync(Video $video): bool
    {
        return $video->type === Video::TYPE_SHORT && (
            ($video->status === Video::STATUS_DRAFT && blank($video->url))
            || ($video->status === Video::STATUS_PUBLISHED
                && (blank($video->cover) || str_starts_with((string) $video->cover, 'https://')))
        );
    }

    public function refreshPublishedCover(Video $video, string $id): void
    {
        $previous = $video->cover;
        $covers = $this->coverCandidates($this->fetchMovie($id));
        $origin = app(EfvMediaDomains::class)->read()['image_origin'];
        $cover = $this->downloadCover($covers, $origin, fresh: true);
        if (!$cover) {
            throw new DomainException('未取得可用的 EFV 封面。');
        }
        $source = $this->downloadedCoverSource;
        if ($cover === $previous && $source === $video->cover_source) {
            return;
        }

        // Keep a cover an editor or another sync saved while the request was in flight.
        // cover_source is the 封面1 address the front end loads (encrypted) via the image domain.
        $video->newQuery()->whereKey($video->getKey())
            ->where('status', Video::STATUS_PUBLISHED)
            ->where('cover', $previous)->update(['cover' => $cover, 'cover_source' => $source]);
    }

    public function sync(Video $video, ?string $id, bool $automatic = false): ?string
    {
        return $this->applyTranscodedMovie($video, $this->fetchMovie($id), $automatic);
    }

    public function fetchMovie(?string $id): array
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $key = config('services.efv.api_key');

        if (!$id || !$key || !str_starts_with($endpoint, 'https://')) {
            throw new DomainException('缺少 EFV 视频关联或查询配置。');
        }

        try {
            $response = Http::acceptJson()->withHeaders(['token' => $key, 'Cache-Control' => 'no-cache'])
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

        return $movie;
    }

    public function applyTranscodedMovie(Video $video, array $movie, bool $automatic = false, bool $refreshCover = true): ?string
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $url = collect($movie['m3u8paths'] ?? [])->sortByDesc('hd')
            ->map(fn ($stream) => $this->mediaUrl($endpoint, $stream['path'] ?? null))
            ->first(fn ($url) => $url && str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.m3u8'));
        if (!$url) {
            throw new DomainException('EFV 已返回视频信息，但没有可用的 M3U8 地址。');
        }

        $covers = $this->coverCandidates($movie);
        $imageOrigin = app(EfvMediaDomains::class)->read()['image_origin'];
        $replaceCover = $refreshCover && $this->canReplaceCover($video, $covers, $automatic);
        $cover = null;
        $coverSource = null;
        $coverFailure = '未取得可用封面，请检查 EFV 封面地址。';
        if ($replaceCover) {
            try {
                $cover = $this->downloadCover($covers, $imageOrigin, fresh: true);
                $coverSource = $this->downloadedCoverSource;
            } catch (DomainException $exception) {
                $coverFailure = $exception->getMessage();
            }
        }
        $time = (string) ($movie['time'] ?? '');
        $duration = 0;
        if (preg_match('/^(\d{1,4}):([0-5]\d):([0-5]\d)(?:\.\d+)?$/D', $time, $parts)) {
            $duration = (int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) $parts[3];
        } elseif (is_numeric($time) && (float) $time > 0 && (float) $time <= 2147483647) {
            $duration = (int) ceil((float) $time);
        }

        DB::transaction(function () use ($video, $url, $cover, $coverSource, $covers, $duration, $automatic) {
            $record = $video->newQuery()->lockForUpdate()->findOrFail($video->getKey());
            if ($automatic && !$this->needsAutomaticSync($record)) {
                return;
            }

            $replace = $cover && $this->canReplaceCover($record, $covers, $automatic);
            $record->update([
                'url' => $automatic && filled($record->url) ? $record->url : $url,
                'cover' => $replace ? $cover : $record->cover,
                'cover_source' => $replace ? $coverSource : $record->cover_source,
                'duration' => $duration ?: $record->duration,
                'status' => Video::STATUS_PUBLISHED,
                'published_at' => $record->published_at ?? now(),
            ]);
        });
        $video->refresh();
        if (!$automatic && $cover && $video->cover === $cover) {
            Cache::forget(self::frontendCoverCacheKey($video->id, $imageOrigin));
        }

        return $replaceCover && !$cover
            ? $coverFailure
            : null;
    }

    private function canReplaceCover(Video $video, array $covers, bool $automatic): bool
    {
        return !$video->cover || in_array($video->cover, $covers, true)
            || (!$automatic && str_starts_with($video->cover, 'shorts/covers/efv-'));
    }

    public static function frontendCoverCacheKey(int $video, ?string $origin): string
    {
        return 'efv:frontend-cover:v4:'.hash('sha256', $video.'|'.$origin);
    }

    public function coverCandidates(array $movie): array
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');

        return array_values(array_unique(array_filter([
            $this->mediaUrl($endpoint, $movie['poster'] ?? null),
            $this->mediaUrl($endpoint, data_get($movie, 'poster2.url')),
        ])));
    }

    public function downloadCover(array $covers, ?string $imageOrigin, bool $fresh = false): ?string
    {
        $endpoint = rtrim((string) config('services.efv.endpoint'), '/');
        $failure = null;
        $this->downloadedCoverSource = null;
        foreach ($covers as $candidate) {
            try {
                $source = $this->coverSource($endpoint, $candidate, $imageOrigin);
                $cover = $source ? $this->fetchCover($imageOrigin ?? $endpoint, $source, $fresh) : null;
                if ($cover) {
                    $path = 'shorts/covers/efv-'.hash('sha256', $cover['bytes']).'.'.$cover['extension'];
                    if (!Storage::disk('public')->put($path, $cover['bytes'])) {
                        return null;
                    }
                    $this->downloadedCoverSource = self::efvCoverPath($candidate);

                    return $path;
                }
            } catch (DomainException $exception) {
                $failure = $exception;
            }
        }

        if ($failure) {
            throw $failure;
        }

        return null;
    }

    private function coverSource(string $endpoint, string $url, ?string $imageOrigin): ?string
    {
        if (!$imageOrigin) {
            return $url;
        }
        $parts = parse_url($url);
        $origin = 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (!in_array($origin, [$endpoint, $imageOrigin], true) || empty($parts['path'])) {
            return null;
        }

        return $imageOrigin.$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function fetchCover(string $endpoint, string $url, bool $fresh): ?array
    {
        if (!preg_match('/\.(?:jpe?g|png|webp|gif)$/iD', (string) parse_url($url, PHP_URL_PATH))) {
            return null;
        }

        foreach (['host', 'port'] as $part) {
            if ((parse_url($url)[$part] ?? null) !== (parse_url($endpoint)[$part] ?? null)) {
                return null;
            }
        }

        if ($fresh && parse_url($url, PHP_URL_QUERY) === null) {
            $url .= '?_efv_cover_refresh='.bin2hex(random_bytes(8));
        }

        try {
            $response = Http::withoutRedirecting()->connectTimeout(4)->timeout(8)
                ->withHeaders(['Cache-Control' => 'no-cache'])->withOptions(['stream' => true])->get($url);
            $stream = $response->toPsrResponse()->getBody();
            try {
                if (!$response->successful()) {
                    return null;
                }
                $bytes = '';
                while (!$stream->eof() && strlen($bytes) <= 5 * 1024 * 1024) {
                    $chunk = $stream->read(65536);
                    if ($chunk === '') {
                        break;
                    }
                    $bytes .= $chunk;
                }
            } finally {
                $stream->close();
            }
        } catch (ConnectionException | RuntimeException) {
            return null;
        }

        if (!$bytes || strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }
        $mode = strtolower(trim($response->header('X-Encode-Mode')));
        if ($mode === 'xor') {
            $length = min(9, strlen($bytes));
            $bytes = (substr($bytes, 0, $length) ^ str_repeat(chr(0x12), $length)).substr($bytes, $length);
        } elseif ($mode === 'aes') {
            $cipher = config('services.efv.image_aes_cipher');
            $key = (string) config('services.efv.image_aes_key');
            $iv = (string) config('services.efv.image_aes_iv');
            $lengths = ['aes-128-ctr' => 32, 'aes-128-cbc' => 32, 'aes-256-ctr' => 64, 'aes-256-cbc' => 64];
            if (!isset($lengths[$cipher]) || strlen($key) !== $lengths[$cipher]
                || strlen($iv) !== 32 || !ctype_xdigit($key.$iv)) {
                throw new DomainException('EFV 封面已加密，请检查 EFV_IMAGE_AES_KEY、EFV_IMAGE_AES_IV 和算法配置。');
            }
            $bytes = openssl_decrypt($bytes, $cipher, hex2bin($key), OPENSSL_RAW_DATA, hex2bin($iv));
        }

        $image = $bytes ? @getimagesizefromstring($bytes) : false;
        $extension = match ($image[2] ?? null) {
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            default => null,
        };
        if (!$extension) {
            throw new DomainException('EFV 封面内容无效，请检查图片响应和加密设置。');
        }

        return ['bytes' => $bytes, 'extension' => $extension];
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
