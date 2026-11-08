<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { mediaUrl } from '@/utils/media-url'
import { videoApi } from '@/api/video'
import LongVideoPlayer from '@/components/LongVideoPlayer.vue'
import type { Video } from '@/types/models'

const route = useRoute()
const router = useRouter()
const id = computed(() => Number(route.params.id))
const video = ref<Video | null>(null)
const failed = ref(false)
// 更多推荐: other videos of the same category from the existing list API (no recommendation logic).
const more = ref<Video[]>([])
const moreState = ref<'loading' | 'ready' | 'failed'>('loading')
const brokenCovers = ref<Record<number, boolean>>({})
const scroller = ref<HTMLElement | null>(null)
let generation = 0

const published = computed(() => {
  const value = video.value?.published_at
  const date = value ? new Date(value) : null
  return date && !Number.isNaN(date.getTime())
    ? `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
    : ''
})

// A video opened from 我的视频 marks its history entry (videoFrom: 'me') so the bottom bar keeps
// 我 highlighted; a video opened from 更多推荐 inherits that mark.
function openMore(target: number) {
  const from = (window.history.state as { videoFrom?: unknown } | null)?.videoFrom
  router.push({ name: 'video-detail', params: { id: target }, state: from === 'me' ? { videoFrom: 'me' } : undefined })
}

function duration(seconds: number) {
  return `${Math.floor(seconds / 60)}:${String(Math.floor(seconds % 60)).padStart(2, '0')}`
}

async function load() {
  const request = ++generation
  const current = id.value
  failed.value = false
  video.value = null
  more.value = []
  moreState.value = 'loading'
  try {
    const result = await videoApi.show(current)
    if (request !== generation) return
    video.value = result
    if (scroller.value) scroller.value.scrollTop = 0
  } catch {
    if (request === generation) failed.value = true
    return
  }
  try {
    const list = await videoApi.list({ type: 'long', category_id: video.value.category_id, page: 1, per_page: 12 })
    if (request !== generation) return
    more.value = (list.data ?? []).filter(item => item.id !== current).slice(0, 10)
    moreState.value = 'ready'
  } catch {
    if (request === generation) moreState.value = 'failed'
  }
}

watch(id, () => { void load() }, { immediate: true })
</script>

<template>
  <div class="flex flex-col h-full bg-white">
    <header class="flex-none px-3 py-3 border-b border-gray-200 safe-top flex items-center gap-3">
      <button class="text-2xl" aria-label="返回" @click="router.back()">‹</button>
    </header>

    <div v-if="failed" class="feed-empty" role="alert"><p>视频加载失败</p><button @click="load">点击重试</button></div>

    <div v-else-if="video" ref="scroller" class="flex-1 overflow-y-auto">
      <LongVideoPlayer :key="video.id" :src="video.url" :poster="mediaUrl(video.cover) || undefined" :title="video.title" />

      <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="font-semibold">{{ video.title }}</h2>
        <p class="text-sm text-gray-600 mt-1">{{ video.views }} 次播放<template v-if="published"> · {{ published }}</template></p>
      </div>

      <section class="py-3" aria-label="更多推荐">
        <h3 class="px-4 mb-2 text-sm font-semibold">更多推荐</h3>
        <div v-if="moreState === 'loading'" class="more-row" aria-busy="true"><div v-for="n in 3" :key="n" class="more-card"><span class="more-cover" /></div></div>
        <p v-else-if="moreState === 'failed'" class="px-4 text-sm text-gray-500">推荐加载失败</p>
        <p v-else-if="!more.length" class="px-4 text-sm text-gray-500">暂无推荐</p>
        <div v-else class="more-row">
          <button v-for="item in more" :key="item.id" class="more-card" :aria-label="`播放：${item.title}`" @click="openMore(item.id)">
            <span class="more-cover">
              <img v-if="item.cover && !brokenCovers[item.id]" :src="mediaUrl(item.cover)" alt="" loading="lazy" @error="brokenCovers[item.id] = true" />
              <span v-if="item.duration" class="video-duration">{{ duration(item.duration) }}</span>
            </span>
            <span class="more-title">{{ item.title }}</span>
          </button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.more-row { display: flex; gap: 10px; overflow-x: auto; padding: 0 16px 4px; scroll-padding-inline: 16px; scrollbar-width: none; scroll-snap-type: x proximity; }
.more-row::-webkit-scrollbar { display: none; }
.more-card { flex: 0 0 42%; max-width: 200px; min-width: 0; text-align: left; scroll-snap-align: start; }
.more-cover { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 6px; background: #e8e8e8; }
.more-cover img { display: block; width: 100%; height: 100%; object-fit: cover; }
.more-title { display: -webkit-box; margin-top: 6px; font-size: 13px; line-height: 1.35; color: var(--ink); overflow: hidden; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
</style>
