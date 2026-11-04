<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import type { ObjectDirective } from 'vue'
import type Hls from 'hls.js'
import { mediaUrl } from '@/utils/media-url'
import { useAuthStore } from '@/stores/auth'
import { shortVideoApi } from '@/api/shortVideo'
import { showToast } from '@/utils/toast'
import type { ShortVideo, ShortVideoComment } from '@/types/models'

const auth = useAuthStore()
const props = defineProps<{ items: ShortVideo[]; initialIndex: number }>()
defineEmits<{ close: [] }>()
const videos = ref<ShortVideo[]>(props.items)
const currentIdx = ref(props.initialIndex)
const containerRef = ref<HTMLElement | null>(null)
const states = ref<Record<number, { liked: boolean; favorited: boolean }>>({})
const showComments = ref(false)
const commentText = ref('')
const comments = ref<ShortVideoComment[]>([])
const isSending = ref(false)

const NEAR = 2

function isNear(idx: number) { return Math.abs(idx - currentIdx.value) <= NEAR }

let observer: IntersectionObserver | null = null

function setupObserver() {
  observer?.disconnect()
  if (!containerRef.value) return
  observer = new IntersectionObserver(entries => {
    for (const e of entries) {
      if (e.isIntersecting && e.intersectionRatio > 0.7) {
        const idx = Number((e.target as HTMLElement).dataset.idx)
        if (!Number.isNaN(idx)) currentIdx.value = idx
      }
    }
  }, { root: containerRef.value, threshold: [0, 0.7, 1] })
  setTimeout(() => {
    containerRef.value?.querySelectorAll('[data-idx]').forEach(el => observer?.observe(el))
  }, 0)
}

function syncVideo(el: HTMLVideoElement | null, idx: number) {
  if (!el) return
  if (idx === currentIdx.value) {
    el.muted = false
    el.play().catch(() => { el.muted = true; el.play().catch(() => {}) })
  } else {
    el.pause()
    try { el.currentTime = 0 } catch {}
  }
}

const streams = new WeakMap<HTMLVideoElement, { hls?: Hls }>()

function detachSource(el: HTMLVideoElement) {
  streams.get(el)?.hls?.destroy()
  streams.delete(el)
}

async function attachSource(el: HTMLVideoElement, url: string) {
  detachSource(el)
  const source: { hls?: Hls } = {}
  streams.set(el, source)
  if (!/\.m3u8(?:[?#]|$)/i.test(url) || el.canPlayType('application/vnd.apple.mpegurl')) {
    el.src = url
    return
  }

  el.removeAttribute('src')
  el.load()
  try {
    const { default: Hls } = await import('hls.js')
    if (streams.get(el) !== source) return
    if (!Hls.isSupported()) {
      el.src = url
      return
    }
    source.hls = new Hls()
    source.hls.on(Hls.Events.ERROR, (_, error) => {
      if (error.fatal && el.closest('[data-idx]')?.getAttribute('data-idx') === String(currentIdx.value)) {
        showToast('视频加载失败')
      }
    })
    source.hls.loadSource(url)
    source.hls.attachMedia(el)
  } catch {
    if (streams.get(el) === source) showToast('播放器加载失败')
  }
}

const vVideoSource: ObjectDirective<HTMLVideoElement, string> = {
  mounted(el, { value }) { void attachSource(el, value) },
  updated(el, { value, oldValue }) {
    if (value !== oldValue) void attachSource(el, value)
  },
  beforeUnmount: detachSource,
}

watch(currentIdx, () => {
  containerRef.value?.querySelectorAll('video').forEach(v => {
    const idx = Number((v.parentElement as HTMLElement)?.dataset.idx)
    syncVideo(v, idx)
  })
})

watch(videos, () => setupObserver())

function togglePlay(e: MouseEvent) {
  const video = (e.currentTarget as HTMLElement).querySelector('video') as HTMLVideoElement
  if (!video) return
  video.paused ? video.play() : video.pause()
}

async function like(id: number) {
  if (!auth.isLoggedIn) return showToast('请先登录')
  const res = await shortVideoApi.like(id)
  states.value[id] = { ...states.value[id], liked: res.liked }
  const v = videos.value.find(v => v.id === id)
  if (v) v.likes = res.count
}

async function favorite(id: number) {
  if (!auth.isLoggedIn) return showToast('请先登录')
  const res = await shortVideoApi.favorite(id)
  states.value[id] = { ...states.value[id], favorited: res.favorited }
  const v = videos.value.find(v => v.id === id)
  if (v) v.favorites = res.count
  showToast(res.favorited ? '已收藏' : '已取消收藏')
}

async function share(id: number) {
  const res = await shortVideoApi.share(id, window.location.origin)
  await navigator.clipboard.writeText(res.share_url)
  showToast('链接已复制到剪贴板')
  const v = videos.value.find(v => v.id === id)
  if (v) v.shares += 1
}

async function openComments(id: number) {
  const res = await shortVideoApi.comments(id, { per_page: 20 })
  comments.value = res.data ?? (res as any)
  showComments.value = true
}

async function sendComment() {
  if (!commentText.value.trim()) return
  const v = videos.value[currentIdx.value]
  if (!v) return
  await shortVideoApi.createComment(v.id, { content: commentText.value })
  commentText.value = ''
  const res = await shortVideoApi.comments(v.id, { per_page: 20 })
  comments.value = res.data ?? (res as any)
}

const currentVideo = computed(() => videos.value[currentIdx.value] ?? null)
const currentState = computed(() => currentVideo.value ? (states.value[currentVideo.value.id] ?? { liked: false, favorited: false }) : { liked: false, favorited: false })

onMounted(async () => {
  await nextTick()
  containerRef.value?.scrollTo({ top: props.initialIndex * containerRef.value.clientHeight })
  setupObserver()
  containerRef.value?.parentElement?.querySelector<HTMLButtonElement>('.player-close')?.focus()
})
onUnmounted(() => observer?.disconnect())
</script>

<template>
  <div class="short-player relative h-full bg-black text-white">
    <button class="player-close" aria-label="返回视频列表" @click="$emit('close')">‹</button>

    <div ref="containerRef" class="h-full overflow-y-scroll snap-y snap-mandatory">
      <section
        v-for="(v, idx) in videos"
        :key="v.id"
        :data-idx="idx"
        :inert="idx !== currentIdx || undefined"
        class="relative h-full w-full snap-start flex items-center justify-center"
        @click="togglePlay"
      >
        <video
          v-if="isNear(idx)"
          :ref="el => syncVideo(el as HTMLVideoElement, idx)"
          v-video-source="v.url"
          @loadedmetadata="syncVideo($event.currentTarget as HTMLVideoElement, idx)"
          :poster="mediaUrl(v.cover) || undefined"
          loop
          playsinline
          preload="metadata"
          class="h-full w-full object-cover bg-black"
        />
        <img v-else-if="v.cover" :src="mediaUrl(v.cover)" class="h-full w-full object-cover bg-black" loading="lazy" />
        <div v-else class="h-full w-full bg-gradient-to-br from-gray-900 to-black" />

        <!-- Action buttons -->
        <div class="absolute right-3 bottom-32 flex flex-col gap-5 text-white pointer-events-auto">
          <div class="flex flex-col items-center text-xs">
            <button
              :class="['w-10 h-10 rounded-full flex items-center justify-center text-lg', currentVideo?.id === v.id && currentState.liked ? 'bg-red-500' : 'bg-white/15']"
              @click.stop="like(v.id)"
            >❤</button>
            <span class="mt-1">{{ v.likes }}</span>
          </div>
          <div class="flex flex-col items-center text-xs">
            <button
              :class="['w-10 h-10 rounded-full flex items-center justify-center text-lg', currentVideo?.id === v.id && currentState.favorited ? 'bg-yellow-500' : 'bg-white/15']"
              @click.stop="favorite(v.id)"
            >★</button>
            <span class="mt-1">{{ v.favorites }}</span>
          </div>
          <div class="flex flex-col items-center text-xs">
            <button class="w-10 h-10 rounded-full bg-white/15 flex items-center justify-center text-lg" @click.stop="openComments(v.id)">💬</button>
            <span class="mt-1">{{ v.comments_count }}</span>
          </div>
          <div class="flex flex-col items-center text-xs">
            <button class="w-10 h-10 rounded-full bg-white/15 flex items-center justify-center text-lg" @click.stop="share(v.id)">↗</button>
            <span class="mt-1">分享</span>
          </div>
        </div>

        <!-- Video info -->
        <div class="absolute left-4 bottom-24 right-20 text-white pointer-events-none">
          <p class="font-semibold">@{{ v.user?.username }}</p>
          <p class="text-sm mt-1">{{ v.title }}</p>
          <p v-if="v.description" class="text-xs text-gray-300 mt-1 line-clamp-2">{{ v.description }}</p>
        </div>
      </section>

      <div v-if="!videos.length" class="h-full flex items-center justify-center text-gray-500">暂无短视频</div>
    </div>

    <!-- Comments drawer -->
    <div v-if="showComments" class="absolute inset-0 z-20 bg-black/50" @click="showComments = false">
      <div class="absolute bottom-0 left-0 right-0 bg-white text-gray-900 rounded-t-xl max-h-[60vh] flex flex-col" @click.stop>
        <div class="flex justify-between items-center px-4 py-3 border-b">
          <span class="font-semibold">评论 ({{ comments.length }})</span>
          <button class="text-gray-500 text-xl" @click="showComments = false">×</button>
        </div>
        <div class="flex-1 overflow-y-auto p-4 space-y-3">
          <div v-for="c in comments" :key="c.id" class="flex gap-2">
            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-sm">
              {{ c.user?.username?.[0]?.toUpperCase() ?? '?' }}
            </div>
            <div class="flex-1">
              <p class="text-xs text-gray-500">{{ c.user?.username }}</p>
              <p class="text-sm mt-0.5">{{ c.content }}</p>
            </div>
          </div>
          <p v-if="!comments.length" class="text-center text-gray-400 py-4">暂无评论</p>
        </div>
        <div class="flex gap-2 px-4 py-3 border-t">
          <input
            v-model="commentText"
            placeholder="写下你的评论…"
            class="flex-1 px-3 py-2 rounded-lg bg-gray-100 text-sm focus:outline-none"
            @keyup.enter="sendComment"
          />
          <button
            :disabled="!commentText.trim()"
            class="px-4 py-2 rounded-lg bg-primary text-white text-sm font-medium disabled:opacity-50"
            @click="sendComment"
          >发送</button>
        </div>
      </div>
    </div>
  </div>
</template>
