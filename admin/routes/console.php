<?php

use App\Models\Video;
use App\Services\EfvShortVideoSync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('efv:sync-short-videos', function () {
    $sync = app(EfvShortVideoSync::class);
    $deadline = microtime(true) + 45;
    foreach ($sync->linkedMovies() as $videoId => $movieId) {
        if (microtime(true) >= $deadline) {
            break;
        }

        $video = Video::find($videoId);
        if (!$video || !$sync->needsAutomaticSync($video)
            || !Cache::add("efv:sync-retry:{$videoId}", true, 300)) {
            continue;
        }

        try {
            $warning = $sync->sync($video, $movieId, automatic: true);
            if ($warning) {
                $this->warn("短视频 {$videoId}：{$warning}");
                Log::warning("EFV 短视频 {$videoId}：{$warning}");
            }
        } catch (DomainException $exception) {
            $this->warn("短视频 {$videoId}：{$exception->getMessage()}");
        }
    }
})->purpose('同步 EFV 已转码的短视频并补齐封面');

Schedule::command('efv:sync-short-videos')
    ->everyMinute()
    ->withoutOverlapping(5);
