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
    foreach (['type', 'title', 'description', 'url', 'status', 'cover'] as $column) $table->string($column)->nullable();
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

// EFV crops 封面1 to a landscape size; a portrait screenshot must replace it.
$pngOf = function (int $width, int $height): string {
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IEND', '');
};
$landscape = $pngOf(450, 230);
$portrait = $pngOf(720, 1280);
$requested = [];
$screenshots = ['/videos/x/1.png', '/videos/x/0.png'];
Http::swap(new \Illuminate\Http\Client\Factory());
Http::fake(function ($request) use ($id, $landscape, $portrait, &$requested, &$screenshots) {
    $path = parse_url($request->url(), PHP_URL_PATH);
    if ($path === '/api/getmoviestatus') {
        return Http::response(['success' => 1, 'data' => ['_id' => $id, 'poster' => '/cover.png', 'screenshots' => $screenshots]]);
    }
    $requested[] = $path;
    return Http::response($path === '/cover.png' ? $landscape : $portrait, 200, ['Content-Type' => 'image/png']);
});
Video::whereKey($video->id)->update(['cover' => 'shorts/covers/efv-old.webp']);
(new EfvShortVideoSync())->refreshPublishedCover($video->fresh(), $id);
$assert($video->fresh()->cover === 'shorts/covers/efv-'.hash('sha256', $portrait).'.png', 'A portrait screenshot must replace a cropped landscape poster.');
$assert($requested === ['/cover.png', '/videos/x/1.png'], 'Only the poster and first screenshot should be fetched.');

$screenshots = [];
$requested = [];
Video::whereKey($video->id)->update(['cover' => 'shorts/covers/efv-old.webp']);
(new EfvShortVideoSync())->refreshPublishedCover($video->fresh(), $id);
$assert($video->fresh()->cover === 'shorts/covers/efv-'.hash('sha256', $landscape).'.png', 'Without a portrait alternative the poster must be kept.');
echo "EFV portrait cover preference checks passed.\n";
