<?php

namespace App\Filament\Widgets;

use App\Models\ChatMessage;
use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // 活跃用户（近 7 天注册）
        $newUsers7d = User::where('created_at', '>=', now()->subDays(7))->count();
        // 视频总数
        $videoTotal = Video::count();
        $longCount  = Video::where('type', Video::TYPE_LONG)->count();
        $shortCount = Video::where('type', Video::TYPE_SHORT)->count();
        // 评论总数 + 24h 增量
        $commentsTotal = Comment::count();
        $comments24h   = Comment::where('created_at', '>=', now()->subDay())->count();
        // 群聊消息 24h
        $chat24h = ChatMessage::where('created_at', '>=', now()->subDay())->count();

        return [
            Stat::make('用户总数', User::count())
                ->description("近7天新增 {$newUsers7d}")
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('success')
                ->chart($this->dailyCounts(User::class, 14)),

            Stat::make('视频总数', $videoTotal)
                ->description("长 {$longCount} / 短 {$shortCount}")
                ->descriptionIcon('heroicon-m-film')
                ->color('primary')
                ->chart($this->dailyCounts(Video::class, 14)),

            Stat::make('评论总数', $commentsTotal)
                ->description("24h +{$comments24h}")
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('warning')
                ->chart($this->dailyCounts(Comment::class, 14)),

            Stat::make('群聊 24h 消息', $chat24h)
                ->description('全平台聊天活跃度')
                ->descriptionIcon('heroicon-m-bolt')
                ->color('info')
                ->chart($this->dailyCounts(ChatMessage::class, 14)),
        ];
    }

    /**
     * 返回最近 N 天每天的记录数（mini chart 用）
     * @return array<int>
     */
    private function dailyCounts(string $modelClass, int $days): array
    {
        $rows = $modelClass::query()
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $key = now()->subDays($i)->toDateString();
            $out[] = (int) ($rows[$key] ?? 0);
        }
        return $out;
    }
}
