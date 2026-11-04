<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { mediaUrl } from '@/utils/media-url'
import { vEfvCoverBlob } from '@/utils/efv-cover-blob'
import type { ShortVideo, Video } from '@/types/models'
const props = defineProps<{ video: Video | ShortVideo; layout?: 'horizontal' | 'vertical' }>()
const failed = ref(false)
const cover = computed(() => mediaUrl(props.video.cover))
watch(cover, () => { failed.value = false })
</script>

<template>
  <button class="video-tile" :class="{ landscape: layout === 'horizontal' }" :aria-label="`播放：${video.title}`">
    <div class="video-poster">
      <img v-if="cover && !failed" v-efv-cover-blob="cover" :alt="video.title" loading="lazy" @error="failed = true" />
      <div v-else class="poster-fallback"><svg viewBox="0 0 48 48" aria-hidden="true"><rect x="5" y="8" width="38" height="32" rx="5"/><path d="m20 17 12 7-12 7Z"/></svg><span>暂无封面</span></div>
      <span v-if="layout === 'horizontal' && video.duration" class="video-duration">{{ Math.floor(video.duration / 60) }}:{{ String(Math.floor(video.duration % 60)).padStart(2, '0') }}</span>
    </div>
    <p v-if="layout === 'horizontal'" class="tile-title">{{ video.title }}</p>
    <div class="tile-meta">
      <img v-if="video.user?.avatar" class="tile-avatar" :src="mediaUrl(video.user.avatar)" alt="" loading="lazy" />
      <span v-else class="tile-avatar avatar-letter">{{ (video.user?.name || video.user?.username || '用')[0] }}</span>
      <span class="tile-author">{{ video.user?.name || video.user?.username || '用户' }}</span>
      <span class="tile-likes"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21S2 15 2 8.5A5.5 5.5 0 0 1 12 5a5.5 5.5 0 0 1 10 3.5C22 15 12 21 12 21Z" /></svg>{{ video.likes }}</span>
    </div>
  </button>
</template>
