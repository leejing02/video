<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class UserGrowthChart extends ChartWidget
{
    protected static ?string $heading = '用户增长（累计）';
    protected static ?int $sort       = 7;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7'  => '近 7 天',
            '30' => '近 30 天',
            '90' => '近 90 天',
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $days = max(1, (int) $this->filter);
        $base = User::where('created_at', '<', now()->subDays($days)->startOfDay())->count();

        $rows = User::query()
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('d')
            ->pluck('c', 'd')
            ->all();

        $labels = []; $cumulative = []; $daily = []; $running = $base;
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $labels[] = Carbon::parse($d)->format('m-d');
            $today = (int) ($rows[$d] ?? 0);
            $running += $today;
            $daily[]      = $today;
            $cumulative[] = $running;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'type'            => 'line',
                    'label'           => '累计用户',
                    'data'            => $cumulative,
                    'borderColor'     => '#0ea5e9',
                    'backgroundColor' => 'rgba(14,165,233,0.15)',
                    'tension'         => 0.3,
                    'yAxisID'         => 'y1',
                ],
                [
                    'type'            => 'bar',
                    'label'           => '每日新增',
                    'data'            => $daily,
                    'backgroundColor' => 'rgba(99,102,241,0.7)',
                    'yAxisID'         => 'y',
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y'  => ['type' => 'linear', 'position' => 'left',  'beginAtZero' => true],
                'y1' => ['type' => 'linear', 'position' => 'right', 'beginAtZero' => true,
                         'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }
}
