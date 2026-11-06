<?php

$loader = require __DIR__.'/../vendor/autoload.php';
$loader->setPsr4('App\\', __DIR__.'/../app');
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Video;
use App\Services\EfvShortVideoImport;
use App\Services\EfvShortVideoSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
    'cache.default' => 'array',
    'services.efv.endpoint' => 'https://efv.example.test',
    'services.efv.cms_api_key' => '',
]);
DB::purge('sqlite');
Schema::create('videos', function ($table) {
    $table->id();
    foreach (['type', 'title', 'description', 'url', 'status', 'cover', 'cover_source'] as $column) $table->string($column)->nullable();
    $table->timestamps();
});
Schema::create('efv_gallery_batches', function ($table) {
    $table->id();
    $table->string('section');
    $table->text('files');
    $table->string('group_id')->nullable();
});
$id = str_repeat('a', 24);
$removed = str_repeat('b', 24);
DB::table('efv_gallery_batches')->insert([
    'section' => 'category-import',
    'files' => json_encode([['efv_id' => $id, 'video_id' => 1], ['efv_id' => $removed, 'video_id' => 2]]),
]);
foreach ([1, 2, 3] as $videoId) {
    Video::forceCreate(['id' => $videoId, 'type' => 'short', 'title' => 'old', 'status' => 'published', 'cover' => 'existing.jpg']);
}
$sync = new class extends EfvShortVideoSync {
    public function applyTranscodedMovie(Video $video, array $movie, bool $automatic = false, bool $refreshCover = true): ?string
    {
        $video->update(['url' => $movie['m3u8paths'][0]['path'], 'status' => Video::STATUS_PUBLISHED]);
        return null;
    }
};
$total = 1;
$pages = 1;
$snapshotSupported = false;
Http::fake(function ($request) use ($id, $removed, &$total, &$pages, &$snapshotSupported) {
    if ($request['ac'] === 'list') return Http::response(['code' => 1, 'class' => [['type_name' => '国产短视频', 'type_id' => 9]]]);
    if ($snapshotSupported && (int) $request['num'] === 2) {
        $records = [];
        foreach ([$id, $removed] as $sourceId) {
            $records[] = [
                'type_name' => '国产短视频', 'vod_id' => $sourceId, 'vod_name' => 'snapshot title',
                'vod_play_url' => 'HD$https://efv.example.test/videos/new/index.m3u8',
            ];
        }
        return Http::response(['code' => 1, 'page' => 1, 'pagecount' => 1, 'total' => 2, 'list' => $records]);
    }
    return Http::response(['code' => 1, 'page' => (int) $request['pg'], 'pagecount' => $pages, 'total' => $total, 'list' => [[
        'type_name' => '国产短视频', 'vod_id' => $id, 'vod_name' => 'new title', 'vod_content' => '<p>new summary</p>',
        'vod_play_url' => 'HD$https://efv.example.test/videos/new/index.m3u8',
    ]]]);
});
$run = fn ($preview = false) => app(EfvShortVideoImport::class)->run($sync, $preview, fn () => null, cms: true, reconcile: true);
$assert = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$stats = $run(true);
$assert($stats['archived'] === 1 && Video::find(2)->status === 'published', 'Preview mutated records.');
$total = 2;
$stats = $run();
$assert($stats['archive_skipped'], 'Incomplete snapshot was not marked unsafe.');
$assert(Video::find(2)->status === 'published', 'Incomplete snapshot archived records.');
$pages = 2;
$stats = $run();
$assert($stats['unique'] === 1 && count($stats['duplicates']) === 1 && $stats['archive_skipped'], 'Duplicate pages did not complete safely.');
$assert(Video::find(2)->status === 'published', 'Duplicate pages archived records.');
$snapshotSupported = true;
$stats = $run();
$assert($stats['unique'] === 2 && !$stats['archive_skipped'] && !$stats['duplicates'], 'Snapshot did not recover overlapping pages.');
$snapshotSupported = false;
$pages = 1;
$total = 1;
$stats = $run();
$assert(Video::find(1)->title === 'new title' && Video::find(1)->description === 'new summary', 'Existing metadata was not updated.');
$assert(Video::find(2)->status === 'archived', 'Missing imported record was not archived.');
$assert(Video::find(3)->status === 'published', 'Unrelated local record was changed.');
$assert($run()['archived'] === 0, 'Repeated synchronization was not idempotent.');
Video::find(1)->update(['status' => 'archived']);
$run();
$assert(Video::find(1)->status === 'published', 'Reappearing record was not restored.');
Video::find(1)->delete();
$stats = $run(true);
$assert($stats['eligible'] === 1 && $stats['existing'] === 0, 'Stale import link prevented reimport.');
$assert($stats['source_total'] === 1 && $stats['scanned'] === 1, 'Source totals were not reported.');
echo "EFV reconciliation checks passed.\n";

config(['services.efv.api_key' => 'test-key']);
app()->instance(\App\Services\EfvMediaDomains::class, new \App\Services\EfvMediaDomains('/nonexistent/efv-test-domains.json'));
\Illuminate\Support\Facades\Storage::fake('public');
Http::swap(new \Illuminate\Http\Client\Factory());
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP1sAAAAASUVORK5CYII=');
Http::fake(function ($request) use ($id, $png, $assert) {
    $path = parse_url($request->url(), PHP_URL_PATH);
    if ($path === '/api/getmoviestatus') {
        return Http::response(['success' => 1, 'data' => [
            '_id' => $id,
            'poster' => '/primary.png',
            'poster2' => ['url' => '/secondary.png'],
        ]]);
    }

    $assert($path === '/primary.png', 'A valid primary cover should take precedence over the secondary cover.');
    $assert(str_contains($request->url(), '?_efv_cover_refresh='), 'A refresh must bypass the cached unsigned image URL.');

    return Http::response($png, 200, ['Content-Type' => 'image/png']);
});
$video = Video::findOrFail(3);
$video->update(['cover' => 'shorts/covers/efv-old.webp']);
(new EfvShortVideoSync())->refreshPublishedCover($video, $id);
$expected = 'shorts/covers/efv-'.hash('sha256', $png).'.png';
$assert($video->fresh()->cover === $expected, 'Refreshing a published cover must update its storage key.');
$assert(\Illuminate\Support\Facades\Storage::disk('public')->get($expected) === $png, 'The updated cover bytes were not stored.');
echo "EFV published cover refresh checks passed.\n";

// CMS imports must sync 封面1 (cover.webp beside the HLS output), not the vod_pic preview.
$fromCms = new ReflectionMethod(EfvShortVideoImport::class, 'fromCms');
$movie = $fromCms->invoke(new EfvShortVideoImport(), [
    'type_name' => '国产短视频', 'vod_id' => $id, 'vod_name' => 't',
    'vod_play_url' => 'HD$https://efv.example.test/videos/202611/05/'.$id.'/abc123/index.m3u8',
    'vod_pic' => 'https://efv.example.test/preview/small.webp',
], 'https://efv.example.test');
$assert($movie['poster'] === '/videos/202611/05/'.$id.'/cover.webp', 'CMS import must use 封面1 as the primary cover.');
$assert($movie['poster2']['url'] === 'https://efv.example.test/preview/small.webp', 'The CMS preview must remain a fallback.');
$covers = (new EfvShortVideoSync())->coverCandidates($movie);
$assert($covers[0] === 'https://efv.example.test/videos/202611/05/'.$id.'/cover.webp', '封面1 must be downloaded first.');
$auto = $fromCms->invoke(new EfvShortVideoImport(), [
    'type_name' => '国产短视频', 'vod_id' => $id, 'vod_play_url' => 'https://efv.example.test/movie/auto/'.$id.'.m3u8',
    'vod_pic' => 'https://efv.example.test/preview/small.webp',
], 'https://efv.example.test');
$assert($auto['poster'] === 'https://efv.example.test/preview/small.webp', 'Without an EFV movie folder the preview is used.');
echo "EFV CMS cover1 mapping checks passed.\n";

// efv:refresh-covers replaces a synced preview with 封面1 and leaves manual covers alone.
$cover1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAADCAIAAAA2iEnWAAAAEElEQVR42mP8z8DAwMDAAAAMAAH8yBLzAAAAAElFTkSuQmCC');
DB::table('efv_gallery_batches')->insert(['section' => 'category-import', 'files' => json_encode([
    ['efv_id' => $id, 'video_id' => 20], ['efv_id' => $removed, 'video_id' => 21],
])]);
Video::forceCreate(['id' => 20, 'type' => 'short', 'title' => 'preview', 'status' => 'published', 'cover' => 'shorts/covers/efv-preview.webp']);
Video::forceCreate(['id' => 21, 'type' => 'short', 'title' => 'manual', 'status' => 'published', 'cover' => 'shorts/covers/manual.jpg']);
Http::swap(new \Illuminate\Http\Client\Factory());
Http::fake(function ($request) use ($id, $removed, $cover1) {
    if (parse_url($request->url(), PHP_URL_PATH) === '/api/getmoviestatus') {
        $movie = $request['id'] === $removed ? $removed : $id;
        return Http::response(['success' => 1, 'data' => ['_id' => $movie, 'poster' => '/videos/202611/05/'.$movie.'/cover.png']]);
    }
    return Http::response($cover1, 200, ['Content-Type' => 'image/png']);
});
$exit = \Illuminate\Support\Facades\Artisan::call('efv:refresh-covers', ['--video' => [20, 21]]);
$output = \Illuminate\Support\Facades\Artisan::output();
$assert($exit === 0, 'efv:refresh-covers failed: '.$output);
$assert(Video::find(20)->cover === 'shorts/covers/efv-'.hash('sha256', $cover1).'.png', 'The preview cover was not replaced with 封面1.');
$assert(Video::find(20)->cover_source === '/videos/202611/05/'.$id.'/cover.png', 'The 封面1 address must be stored for the front end.');
$assert(Video::find(21)->cover_source === null, 'A manual cover must not get an EFV address.');
$assert(Video::find(21)->cover === 'shorts/covers/manual.jpg', 'A manually uploaded cover must not be replaced.');
$assert(str_contains($output, '更新为封面1 1') && str_contains($output, '/videos/202611/05/'), 'The command must report the updated count.');
echo "efv:refresh-covers checks passed.\n";
