<?php

namespace App\Filament\Resources\CommentResource\Pages;

use App\Filament\Resources\CommentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListComments extends ListRecords
{
    protected static string $resource = CommentResource::class;
    protected static string $view = 'filament.pages.comments-list';
    public bool $commentsEnabled = true;

    public function mount(): void
    {
        parent::mount();
        $this->commentsEnabled = app(\App\Services\VideoCommentSettings::class)->enabled();
    }

    public function updatedCommentsEnabled(bool $enabled): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
        try {
            app(\App\Services\VideoCommentSettings::class)->save($enabled);
            \Filament\Notifications\Notification::make()->success()->title($enabled ? '视频评论已开启' : '视频评论已关闭')->send();
        } catch (\Throwable $error) {
            $this->commentsEnabled = app(\App\Services\VideoCommentSettings::class)->enabled();
            \Filament\Notifications\Notification::make()->danger()->title('评论设置保存失败，请检查配置目录权限')->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
