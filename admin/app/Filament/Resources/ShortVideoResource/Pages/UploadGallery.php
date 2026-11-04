<?php

namespace App\Filament\Resources\ShortVideoResource\Pages;

use App\Filament\Resources\ShortVideoResource;
use App\Models\Category;
use App\Models\Video;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Filament\Resources\Pages\Page;

class UploadGallery extends Page
{
    protected static string $resource = ShortVideoResource::class;

    protected static string $view = 'filament.short-videos.upload-gallery';

    protected static ?string $title = '批量上传图片和视频';

    public static function canAccess(array $parameters = []): bool
    {
        $admin = auth('admin')->user();

        return $admin && $admin->is_active && Gate::forUser($admin)->allows('uploadGallery', Video::class);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        return ['categories' => Category::query()->where('type', Category::TYPE_SHORT)
            ->where('is_active', true)->orderBy('sort')->get(['id', 'name', 'efv_section']),
            'uploaders' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'username']),
        ];
    }
}
