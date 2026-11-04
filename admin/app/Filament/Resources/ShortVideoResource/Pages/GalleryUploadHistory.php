<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use Filament\Resources\Pages\Page;
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
