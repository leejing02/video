<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Services\EfvMediaDomains;
use App\Services\EfvShortVideoSync;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

class EfvShortVideoCoverController extends Controller
{
    public function __invoke(int $video, EfvMediaDomains $domains, EfvShortVideoSync $sync): RedirectResponse
    {
        $record = Video::query()->where('type', Video::TYPE_SHORT)
            ->where('status', Video::STATUS_PUBLISHED)->findOrFail($video);

        $origin = $domains->read()['image_origin'];
        $cacheKey = EfvShortVideoSync::frontendCoverCacheKey($video, $origin);
        $cover = Cache::get($cacheKey);
        if ($cover === null) {
            $lock = Cache::lock($cacheKey.':lock', 40);
            if ($lock->get()) {
                try {
                    $cover = Cache::get($cacheKey);
                    if ($cover === null) {
                        $cover = $this->resolveCover($record, $origin, $sync) ?? '';
                        Cache::put($cacheKey, $cover, $cover ? now()->addMinutes(5) : now()->addMinute());
                    }
                } finally {
                    $lock->release();
                }
            }
        }

        $cover = $cover ?: $record->cover;
        abort_unless(is_string($cover) && preg_match('~^shorts/covers/efv-[a-f0-9]{64}\.(?:jpg|png|webp|gif)$~D', $cover), 404);

        return new RedirectResponse('/storage/'.$cover, 302, ['Cache-Control' => 'no-store']);
    }

    private function resolveCover(Video $video, ?string $origin, EfvShortVideoSync $sync): ?string
    {
        try {
            $id = $sync->movieId($video);
            if (!$id) {
                return null;
            }
            $movie = $sync->fetchMovie($id);

            return $sync->downloadCover($sync->coverCandidates($movie), $origin);
        } catch (DomainException) {
            return null;
        }
    }
}
