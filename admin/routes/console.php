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

Artisan::command('efv:import-domestic-shorts {--preview : 只统计，不导入}', function () {
    try {
        $stats = app(\App\Services\EfvShortVideoImport::class)->run(
            app(EfvShortVideoSync::class),
            (bool) $this->option('preview'),
            fn ($page, $pages, $stats) => $this->line("已扫描 {$page}/{$pages} 页，新增候选 {$stats['eligible']}，已发布 {$stats['published']}"),
        );
    } catch (DomainException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->info("新增候选 {$stats['eligible']}，已有 {$stats['existing']}，已发布 {$stats['published']}，待同步 {$stats['pending']}，待补封面 {$stats['missing_cover']}。");

    return 0;
})->purpose('导入 EFV 国产短视频分类');
