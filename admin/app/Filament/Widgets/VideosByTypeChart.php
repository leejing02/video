<?php

namespace App\Filament\Widgets;

use App\Models\Video;
use Filament\Widgets\ChartWidget;

class VideosByTypeChart extends ChartWidget
{
    protected static ?string $heading = '视频类型分布';
    protected static ?int $sort       = 5;
    protected int|string|array $columnSpan = 1;
    protected static ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $long  = Video::where('type', Video::TYPE_LONG)->count();
        $short = Video::where('type', Video::TYPE_SHORT)->count();

        return [
            'datasets' => [[
                'label'           => '视频数',
                'data'            => [$long, $short],
                'backgroundColor' => ['#6366f1', '#10b981'],
                'borderWidth'     => 0,
            ]],
            'labels' => ['长视频', '短视频'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
        ];
    }
}
