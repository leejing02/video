<x-filament-panels::page>
    @forelse ($batches as $batch)
        <x-filament::section>
            <x-slot name="heading">
                {{ $batch->category_name ?? '分类 #' . $batch->category_id }}
                · {{ $batch->section === 'chigua' ? '吃瓜' : '图集' }}
                · {{ $batch->created_at }}
            </x-slot>
            <p>EFV 合集 ID：{{ $batch->group_id ?? '尚未取得' }}</p>
            @if ($batch->group_id)
                <x-filament::button type="button" wire:click="mountAction('importVideos', {{ \Illuminate\Support\Js::from(['batch' => $batch->id]) }})">
                    补录到短视频
                </x-filament::button>
            @endif
            <ul>
                @forelse (json_decode($batch->files ?? '[]', true) as $file)
                    <li>{{ $file['name'] }} · {{ $file['status'] }}</li>
                @empty
                    <li>暂无本地文件明细。旧版仅保存合集批次。</li>
                @endforelse
            </ul>
        </x-filament::section>
    @empty
        <p>暂无批量上传记录。</p>
    @endforelse
    {{ $batches->links() }}
</x-filament-panels::page>
