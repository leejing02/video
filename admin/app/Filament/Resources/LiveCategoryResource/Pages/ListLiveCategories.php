<?php

namespace App\Filament\Resources\LiveCategoryResource\Pages;

use App\Filament\Resources\LiveCategoryResource;
use App\Services\BilibiliAreaScraper;
use App\Services\DouyuScraper;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListLiveCategories extends ListRecords
{
    protected static string $resource = LiveCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('scrape')
                ->label('采集 Bilibili 分类')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('采集 Bilibili 直播分类')
                ->modalDescription('将从 Bilibili 采集全部父子分类，已存在的分类会被更新，新分类会被创建。确认继续？')
                ->modalSubmitActionLabel('开始采集')
                ->action(function () {
                    try {
                        $result = app(BilibiliAreaScraper::class)->scrape();

                        $body = "新增 {$result['created']} 个，更新 {$result['updated']} 个";
                        if ($result['errors']) {
                            $body .= '；' . count($result['errors']) . ' 条失败';
                        }

                        Notification::make()
                            ->title('采集完成')
                            ->body($body)
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('采集失败')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\Action::make('scrape_douyu')
                ->label('采集斗鱼直播')
                ->icon('heroicon-o-fire')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('采集斗鱼直播')
                ->modalDescription('将从斗鱼采集各分类热门直播间，未被采集到的旧房间自动标记为下线。确认继续？')
                ->modalSubmitActionLabel('开始采集')
                ->action(function () {
                    try {
                        $result = app(DouyuScraper::class)->scrape();

                        $body = "新增 {$result['created']} 个，更新 {$result['updated']} 个";
                        if ($result['errors']) {
                            $body .= '；' . count($result['errors']) . ' 个分类失败';
                        }

                        Notification::make()
                            ->title('斗鱼采集完成')
                            ->body($body)
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('采集失败')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\CreateAction::make()->label('新建分类'),
        ];
    }
}
