<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use App\Models\Category;
use App\Models\User;
use App\Models\Video;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class GalleryUploadHistory extends Page
{
    protected static string $resource = ShortVideoResource::class;

    protected static string $view = 'filament.short-videos.upload-history';

    protected static ?string $title = '批量上传记录';

    public static function canAccess(array $parameters = []): bool
    {
        return UploadGallery::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function importVideosAction(): Action
    {
        return Action::make('importVideos')
            ->label('补录到短视频')
            ->form([
                Select::make('user_id')->label('本地短视频上传者')->required()->searchable()
                    ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            ])
            ->action(function (array $data, array $arguments) {
                abort_unless(static::canAccess(), 403);
                $uploader = User::where('is_active', true)->findOrFail($data['user_id']);
                $batchId = $arguments['batch'] ?? '';
                abort_unless(is_string($batchId) && Str::isUuid($batchId), 422);
                $query = DB::table('efv_gallery_batches')->where('admin_id', auth('admin')->id())->where('id', $batchId);
                $batch = $query->first();
                abort_unless($batch && $batch->group_id, 404);
                $category = Category::where('type', Category::TYPE_SHORT)->findOrFail($batch->category_id);

                try {
                    $media = $this->galleryMedia($batch);
                } catch (ConnectionException | \RuntimeException $error) {
                    Notification::make()->danger()->title($error instanceof ConnectionException
                        ? '无法连接 EFV，请稍后重试'
                        : $error->getMessage())->send();
                    return;
                }

                $lock = Cache::lock('efv-gallery:'.$batchId, 55);
                if (!$lock->get()) {
                    Notification::make()->warning()->title('该批次正在处理，请稍后重试')->send();
                    return;
                }

                try {
                    $count = DB::transaction(function () use ($query, $media, $uploader, $category) {
                        $files = json_decode($query->first()->files ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                        $count = 0;
                        foreach ($media as $item) {
                            $order = $item['galleryGroupOrder'] ?? null;
                            if (($item['type'] ?? null) !== 'video' || !is_int($order) || $order < 0 || $order > 99 || !empty($files[$order]['video_id'])) {
                                continue;
                            }
                            $name = (string) ($files[$order]['name'] ?? $item['title'] ?? '未命名短视频');
                            $video = Video::create([
                                'user_id' => $uploader->id,
                                'category_id' => $category->id,
                                'type' => Video::TYPE_SHORT,
                                'title' => Str::limit($name ?: '未命名短视频', 150, ''),
                                'url' => '',
                                'status' => Video::STATUS_DRAFT,
                            ]);
                            $files[$order] = ['name' => $name, 'status' => '已补录本地草稿', 'video_id' => $video->id];
                            $count++;
                        }
                        ksort($files);
                        $query->update(['files' => json_encode($files, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
                        return $count;
                    });
                } finally {
                    $lock->release();
                }

                Notification::make()->success()->title($count ? "已补录 {$count} 条短视频草稿" : '该合集没有待补录的视频')->send();
            });
    }

    private function galleryMedia(object $batch): array
    {
        $config = config('services.efv');
        $endpoint = rtrim($config['endpoint'], '/');
        if (parse_url($endpoint, PHP_URL_SCHEME) !== 'https' || !$config['api_key'] || !$config['api_secret']) {
            throw new \RuntimeException('请检查 EFV 地址及图集管理凭证');
        }
        $http = Http::baseUrl($endpoint)->acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting()
            ->withHeaders(['apikey' => $config['api_key'], 'apisecret' => $config['api_secret']]);
        $deadline = microtime(true) + 20;
        for ($page = 1; $page <= 1000 && microtime(true) < $deadline; $page++) {
            $response = $http->post('/api/gallerymanage', ['action' => 'list', 'section' => $batch->section, 'page' => $page]);
            if (!$response->successful() || !$response->json('success') || !is_array($response->json('items'))) {
                throw new \RuntimeException('EFV 未返回图集列表，请检查管理凭证和接口');
            }
            foreach ($response->json('items') as $item) {
                if (($item['type'] ?? null) === 'group' && ($item['_id'] ?? null) === $batch->group_id) {
                    if (!is_array($item['media'] ?? null)) {
                        throw new \RuntimeException('EFV 未返回合集文件明细，请检查接口版本');
                    }
                    return $item['media'];
                }
            }
            if (!$response->json('hasMore')) {
                throw new \RuntimeException('EFV 未找到该合集，请核对合集 ID 和栏目');
            }
        }

        throw new \RuntimeException('查找合集超时，请稍后重试');
    }

    protected function getViewData(): array
    {
        return [
            'batches' => DB::table('efv_gallery_batches as batches')
                ->leftJoin('categories', 'categories.id', '=', 'batches.category_id')
                ->where('batches.admin_id', auth('admin')->id())
                ->orderByDesc('batches.created_at')->orderByDesc('batches.id')
                ->simplePaginate(20, ['batches.*', 'categories.name as category_name']),
        ];
    }
}
