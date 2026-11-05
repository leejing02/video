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
Http::fake(function ($request) use ($id, &$total) {
    if ($request['ac'] === 'list') return Http::response(['code' => 1, 'class' => [['type_name' => '国产短视频', 'type_id' => 9]]]);
    return Http::response(['code' => 1, 'page' => 1, 'pagecount' => 1, 'total' => $total, 'list' => [[
        'type_name' => '国产短视频', 'vod_id' => $id, 'vod_name' => 'new title', 'vod_content' => '<p>new summary</p>',
        'vod_play_url' => 'HD$https://efv.example.test/videos/new/index.m3u8',
    ]]]);
});
$run = fn ($preview = false) => app(EfvShortVideoImport::class)->run($sync, $preview, fn () => null, cms: true, reconcile: true);
$assert = function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
$stats = $run(true);
$assert($stats['archived'] === 1 && Video::find(2)->status === 'published', 'Preview mutated records.');
$total = 2;
try {
    $run();
    throw new RuntimeException('Incomplete snapshot was accepted.');
} catch (DomainException) {}
$assert(Video::find(2)->status === 'published', 'Incomplete snapshot archived records.');
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
