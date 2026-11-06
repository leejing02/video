<x-filament-panels::page>
    <div class="flex items-center gap-3">
        <label for="video-comments-enabled" class="font-medium">视频评论</label>
        <label class="video-comment-switch">
            <input id="video-comments-enabled" type="checkbox" role="switch" wire:model.live="commentsEnabled" />
            <span aria-hidden="true"></span>
        </label>
        <span>{{ $commentsEnabled ? '已开启' : '已关闭' }}</span>
    </div>
    {{ $this->table }}
    <style>
        .video-comment-switch { position: relative; display: inline-flex; width: 44px; height: 24px; }
        .video-comment-switch input { position: absolute; opacity: 0; width: 100%; height: 100%; z-index: 1; cursor: pointer; }
        .video-comment-switch span { width: 44px; border-radius: 999px; background: #777; transition: background .2s; }
        .video-comment-switch span::after { content: ''; display: block; width: 18px; height: 18px; margin: 3px; border-radius: 50%; background: white; transition: transform .2s; }
        .video-comment-switch input:checked + span { background: #6366f1; }
        .video-comment-switch input:checked + span::after { transform: translateX(20px); }
        .video-comment-switch input:focus-visible + span { outline: 2px solid #818cf8; outline-offset: 2px; }
    </style>
</x-filament-panels::page>
