<?php

namespace App\Filament\Widgets;

use App\Models\Video;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class VideosTrendChart extends ChartWidget
{
    protected static ?string $heading = '近 30 天视频发布趋势';
    protected static ?int $sort       = 6;
    protected int|string|array $columnSpan = 2;
    protected static ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7'  => '7 天',
            '30' => '30 天',
            '90' => '90 天',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = max(1, (int) $this->filter);

        $rows = Video::query()
            ->selectRaw("DATE(created_at) as d, type, COUNT(*) as c")
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('d', 'type')
            ->get();

        // 按 type 拆开
        $long = []; $short = []; $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($d)->format('m-d');
            $long[$d]  = 0;
            $short[$d] = 0;
        }
        foreach ($rows as $r) {
            $key = $r->d instanceof \DateTimeInterface ? $r->d->format('Y-m-d') : (string) $r->d;
            if ($r->type === Video::TYPE_LONG && isset($long[$key])) {
                $long[$key] = (int) $r->c;
            } elseif ($r->type === Video::TYPE_SHORT && isset($short[$key])) {
                $short[$key] = (int) $r->c;
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label'           => '长视频',
                    'data'            => array_values($long),
                    'borderColor'     => '#6366f1',
                    'backgroundColor' => 'rgba(99,102,241,0.15)',
                    'tension'         => 0.3,
                    'fill'            => true,
                ],
                [
                    'label'           => '短视频',
                    'data'            => array_values($short),
                    'borderColor'     => '#10b981',
                    'backgroundColor' => 'rgba(16,185,129,0.15)',
                    'tension'         => 0.3,
                    'fill'            => true,
                ],
            ],
        ];
    }
}
