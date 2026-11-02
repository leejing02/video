<script setup lang="ts">
import type { Video } from '@/types/models'
defineProps<{ video: Video; layout?: 'horizontal' | 'vertical' }>()
</script>

<template>
  <div class="flex gap-3 p-3 active:bg-white/5">
    <div class="w-32 h-20 rounded-lg overflow-hidden bg-white/10 shrink-0 relative">
      <img v-if="video.cover" :src="video.cover" class="w-full h-full object-cover" />
      <div v-else class="w-full h-full grid place-items-center text-2xl">🎬</div>
      <span class="absolute bottom-1 right-1 text-[10px] bg-black/60 px-1 rounded">
        {{ Math.floor(video.duration / 60) }}:{{ String(video.duration % 60).padStart(2, '0') }}
      </span>
    </div>
    <div class="flex-1 min-w-0">
      <p class="text-sm font-medium line-clamp-2">{{ video.title }}</p>
      <p class="text-xs text-gray-400 mt-1">{{ video.user?.name || '匿名' }}</p>
      <p class="text-xs text-gray-500 mt-1 flex gap-3">
        <span>▶ {{ video.views }}</span>
        <span>♥ {{ video.likes }}</span>
        <span>💬 {{ video.comments_count }}</span>
      </p>
    </div>
  </div>
</template>
