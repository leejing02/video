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
    // Existing covers use the remaining time after pending videos have been handled.
    if (microtime(true) < $deadline) {
        $checkpoint = Cache::get('efv:cover-refresh:checkpoint');
        $lastVisited = null;
        foreach ($sync->linkedMovies() as $videoId => $movieId) {
            if ($checkpoint !== null) {
                if ($checkpoint === $videoId) {
                    $checkpoint = null;
                }
                continue;
            }
            if (microtime(true) >= $deadline) {
                Cache::put('efv:cover-refresh:checkpoint', $lastVisited, now()->addDay());
                return;
            }

            $lastVisited = $videoId;
            $video = Video::find($videoId);
            if (!$video || $video->status !== Video::STATUS_PUBLISHED
                || !str_starts_with((string) $video->cover, 'shorts/covers/efv-')
                || !Cache::add("efv:cover-refresh:{$videoId}", true, 300)) {
                continue;
            }

            try {
                $sync->refreshPublishedCover($video, $movieId);
            } catch (DomainException $exception) {
                $this->warn("短视频 {$videoId}：{$exception->getMessage()}");
            }
        }
        Cache::forget('efv:cover-refresh:checkpoint');
    }
})->purpose('同步 EFV 已转码的短视频并补齐封面');

Schedule::command('efv:sync-short-videos')
    ->everyMinute()
    ->withoutOverlapping(5);

// One-off resync: store every published EFV short video's 封面1 address (cover_source,
// loaded encrypted by the front end) and its local copy now, instead of waiting for the
// scheduled task. Earlier CMS imports stored the small vod_pic preview instead of 封面1.
Artisan::command('efv:refresh-covers {--video=* : 只刷新这些本站短视频 ID}', function () {
    $sync = app(EfvShortVideoSync::class);
    $only = array_map('intval', (array) $this->option('video'));
    $stats = ['checked' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
    $seen = [];
    foreach ($sync->linkedMovies() as $videoId => $movieId) {
        if (isset($seen[$videoId]) || ($only && !in_array($videoId, $only, true))) {
            continue;
        }
        $seen[$videoId] = true;
        $video = Video::find($videoId);
        // Same scope as the scheduled refresh: never replace a manually uploaded cover.
        if (!$video || $video->type !== Video::TYPE_SHORT || $video->status !== Video::STATUS_PUBLISHED
            || !str_starts_with((string) $video->cover, 'shorts/covers/efv-')) {
            continue;
        }
        $stats['checked']++;
        $before = [$video->cover, $video->cover_source];
        try {
            $sync->refreshPublishedCover($video, $movieId);
            $after = $video->fresh();
            if ([$after->cover, $after->cover_source] === $before) {
                $stats['unchanged']++;
            } else {
                $stats['updated']++;
                $this->line("短视频 {$videoId}：已同步封面1 {$after->cover_source}");
            }
        } catch (DomainException $exception) {
            $stats['failed']++;
            $this->warn("短视频 {$videoId}：{$exception->getMessage()}");
        }
    }
    $this->info("检查 {$stats['checked']}，更新为封面1 {$stats['updated']}，未变化 {$stats['unchanged']}，失败 {$stats['failed']}。");

    return $stats['failed'] ? 1 : 0;
})->purpose('立即把已发布 EFV 短视频的封面重新同步为 EFV 封面1');

Artisan::command('efv:import-domestic-shorts {--preview : 只统计，不导入} {--cms : 使用苹果 CMS 采集接口} {--sync : 更新已导入的视频，并下架 EFV 已移除的视频}', function () {
    try {
        $stats = app(\App\Services\EfvShortVideoImport::class)->run(
            app(EfvShortVideoSync::class),
            (bool) $this->option('preview'),
            fn ($page, $pages, $stats) => $this->line("已扫描 {$page}/{$pages} 页，新增候选 {$stats['eligible']}，已发布 {$stats['published']}"),
            cms: (bool) $this->option('cms'),
            reconcile: (bool) $this->option('sync'),
        );
    } catch (DomainException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $this->line("接口总数 ".($stats['source_total'] ?? '未返回')."，实际读取 {$stats['scanned']}，过滤 ".count($stats['skipped'])."。");
    if ($this->option('sync')) {
        $this->line("去重后 {$stats['unique']}，重复 ".count($stats['duplicates'])."。");
        foreach ($stats['duplicates'] as $item) {
            $this->warn("第 {$item['page']} 页重复视频 ID：{$item['id']}");
        }
        if ($stats['archive_skipped']) {
            $this->warn('本轮已处理读到的视频，但去重数量与接口总数不一致，未执行下架。请核查 EFV 采集接口的分页数据。');
        }
    }
    foreach ($stats['skipped'] as $item) {
        $this->warn(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
    $this->info("新增候选 {$stats['eligible']}，已有 {$stats['existing']}，已发布 {$stats['published']}，待同步 {$stats['pending']}，待补封面 {$stats['missing_cover']}，更新 {$stats['updated']}，下架 {$stats['archived']}。");

    return 0;
})->purpose('导入 EFV 国产短视频分类');
