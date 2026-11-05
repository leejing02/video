<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import type { ObjectDirective } from 'vue'
import type Hls from 'hls.js'
import { mediaUrl } from '@/utils/media-url'
import { vEfvCoverBlob } from '@/utils/efv-cover-blob'
import { useAuthStore } from '@/stores/auth'
import { shortVideoApi } from '@/api/shortVideo'
import { showToast } from '@/utils/toast'
import type { ShortVideo, ShortVideoComment } from '@/types/models'

const auth = useAuthStore()
const props = defineProps<{ items: ShortVideo[]; initialIndex: number; initialCover?: string }>()
defineEmits<{ close: [] }>()
const videos = ref<ShortVideo[]>(props.items)
const currentIdx = ref(props.initialIndex)
const containerRef = ref<HTMLElement | null>(null)
const states = ref<Record<number, { liked: boolean; favorited: boolean }>>({})
const showComments = ref(false)
const commentText = ref('')
const comments = ref<ShortVideoComment[]>([])
const isSending = ref(false)
const loadState = ref<'loading' | 'ready' | 'failed'>('loading')
const loadError = ref('')
const playback = ref({ paused: true, current: 0, duration: 0, waiting: false })

function updatePlayback(event: Event, idx: number) {
  if (idx !== currentIdx.value) return
  const video = event.currentTarget as HTMLVideoElement
  playback.value = {
    paused: video.paused,
    current: video.currentTime,
    duration: Number.isFinite(video.duration) ? video.duration : 0,
    waiting: !video.paused && video.readyState < HTMLMediaElement.HAVE_FUTURE_DATA,
  }
}

function formatTime(seconds: number) {
  const whole = Math.floor(seconds)
  return String(Math.floor(whole / 60)).padStart(2, '0') + ':' + String(whole % 60).padStart(2, '0')
}


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

async function playVideo(video: HTMLVideoElement, autoplay = false) {
  if (video.readyState === HTMLMediaElement.HAVE_NOTHING) return

  try {
    await video.play()
  } catch (error) {
    if (video !== activeVideo() || (error instanceof DOMException && error.name === 'AbortError')) return
    if (autoplay && error instanceof DOMException && error.name === 'NotAllowedError') {
      video.muted = true
      await video.play().catch(() => {})
    } else if (!autoplay) {
      showToast('暂时无法播放')
    }
  }
}

function syncVideo(el: HTMLVideoElement, idx: number) {
  if (idx === currentIdx.value) {
    el.muted = false
    void playVideo(el, true)
  } else {
    el.pause()
    try { el.currentTime = 0 } catch {}
  }
}

interface VideoSource {
  hls?: Hls
  timeout?: ReturnType<typeof setTimeout>
}
const streams = new WeakMap<HTMLVideoElement, VideoSource>()

function sourceFailed(el: HTMLVideoElement, source: VideoSource, reason: string) {
  if (streams.get(el) !== source || el !== activeVideo()) return
  loadError.value = reason
  loadState.value = 'failed'
  detachSource(el)
  el.pause()
}

function fitMediaWidth(media: HTMLVideoElement | HTMLImageElement) {
  const width = media instanceof HTMLVideoElement ? media.videoWidth : media.naturalWidth
  const height = media instanceof HTMLVideoElement ? media.videoHeight : media.naturalHeight
  const area = media.parentElement
  if (!area || !width || !height) return
  // Preserve the displayed height from proportional fitting, then expand only the width.
  const fittedHeight = Math.min(area.clientHeight, area.clientWidth * height / width)
  media.style.height = fittedHeight + 'px'
}
const mediaSizeObserver = new ResizeObserver(() => {
  containerRef.value?.querySelectorAll<HTMLVideoElement | HTMLImageElement>('video, img').forEach(fitMediaWidth)
})

function mediaReady(event: Event, idx: number) {
  const el = event.currentTarget as HTMLVideoElement
  const source = streams.get(el)
  if (!source) return
  fitMediaWidth(el)
  clearTimeout(source.timeout)
  if (idx === currentIdx.value) loadState.value = 'ready'
  updatePlayback(event, idx)
  syncVideo(el, idx)
}

function revealVideo(event: Event) {
  const video = event.currentTarget as HTMLVideoElement
  if (streams.has(video) && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
    fitMediaWidth(video)
    video.dataset.frameReady = 'true'
  }
}

function mediaFailed(event: Event) {
  const el = event.currentTarget as HTMLVideoElement
  const source = streams.get(el)
  if (source) sourceFailed(el, source, '视频读取失败，点击重试')
}

function detachSource(el: HTMLVideoElement) {
  const source = streams.get(el)
  streams.delete(el)
  delete el.dataset.frameReady
  clearTimeout(source?.timeout)
  source?.hls?.destroy()
}

function isHls(url: string) {
  return /\.m3u8(?:[?#]|$)/i.test(url)
}

async function attachSource(el: HTMLVideoElement, { id, url }: { id: number; url: string }) {
  detachSource(el)
  const source: VideoSource = {}
  streams.set(el, source)
  if (el === activeVideo()) {
    loadState.value = 'loading'
    source.timeout = setTimeout(() => sourceFailed(el, source, '视频加载超时，点击重试'), 15000)
  }
  if (!isHls(url)) {
    el.src = url
    return
  }

  el.removeAttribute('src')
  el.load()
  try {
    const { video } = await shortVideoApi.show(id)
    if (streams.get(el) !== source) return
    url = video.url
    const { default: Hls } = await import('hls.js')
    if (streams.get(el) !== source) return
    if (!Hls.isSupported()) {
      if (el.canPlayType('application/vnd.apple.mpegurl')) {
        el.src = url
      } else {
        sourceFailed(el, source, '当前浏览器不支持此视频格式')
      }
      return
    }
    source.hls = new Hls()
    source.hls.on(Hls.Events.ERROR, (_, error) => {
      if (!error.fatal) return
      const status = error.response?.code
      sourceFailed(el, source, status ? '视频请求失败（HTTP ' + status + '），点击重试' : '视频加载失败，点击重试')
    })
    source.hls.loadSource(url)
    source.hls.attachMedia(el)
  } catch {
    sourceFailed(el, source, '获取播放地址失败，点击重试')
  }
}

const vVideoSource: ObjectDirective<HTMLVideoElement, { id: number; url: string }> = {
  mounted(el, { value }) { void attachSource(el, value) },
  updated(el, { value, oldValue }) {
    if (value.id !== oldValue?.id || value.url !== oldValue?.url) void attachSource(el, value)
  },
  beforeUnmount: detachSource,
}

watch(currentIdx, () => {
  loadState.value = 'loading'
  playback.value = { paused: true, current: 0, duration: 0, waiting: false }
  const el = activeVideo()
  if (el && el.readyState !== HTMLMediaElement.HAVE_NOTHING) loadState.value = 'ready'
  containerRef.value?.querySelectorAll('video').forEach(v => {
    const idx = Number((v.parentElement as HTMLElement)?.dataset.idx)
    syncVideo(v, idx)
  })
})

watch(videos, () => setupObserver())

function activeVideo() {
  return containerRef.value?.querySelector<HTMLVideoElement>('[data-idx="' + currentIdx.value + '"] video')
}

function togglePlay() {
  const video = activeVideo()
  const item = currentVideo.value
  if (!video || !item) return
  if (loadState.value === 'failed') {
    void attachSource(video, item)
    return
  }
  if (video.paused) {
    void playVideo(video)
  } else {
    video.pause()
  }
}

function seek(event: Event) {
  const video = activeVideo()
  if (!video || !playback.value.duration) return
  video.currentTime = (event.currentTarget as HTMLInputElement).valueAsNumber
  playback.value.current = video.currentTime
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

const playbackChrome = [
  { name: 'theme-color', content: '#000000' },
  { name: 'color-scheme', content: 'dark' },
  { name: 'apple-mobile-web-app-status-bar-style', content: 'black' },
].map(({ name, content }) => ({
  element: document.querySelector<HTMLMetaElement>(`meta[name="${name}"]`),
  content,
  previous: null as string | null,
}))
const pageChrome = [document.documentElement, document.body]
const previousChrome = pageChrome.map(element => ({
  background: element.style.backgroundColor,
  scheme: element.style.colorScheme,
}))
onMounted(async () => {
  playbackChrome.forEach(setting => {
    setting.previous = setting.element?.getAttribute('content') ?? null
    setting.element?.setAttribute('content', setting.content)
  })
  pageChrome.forEach(element => {
    element.style.backgroundColor = '#000000'
    element.style.colorScheme = 'dark'
  })
  await nextTick()
  containerRef.value?.scrollTo({ top: props.initialIndex * containerRef.value.clientHeight })
  setupObserver()
  if (containerRef.value) mediaSizeObserver.observe(containerRef.value)
  containerRef.value?.parentElement?.querySelector<HTMLButtonElement>('.player-close')?.focus()
})
onUnmounted(() => {
  observer?.disconnect()
  mediaSizeObserver.disconnect()
  pageChrome.forEach((element, index) => {
    element.style.backgroundColor = previousChrome[index].background
    element.style.colorScheme = previousChrome[index].scheme
  })
  playbackChrome.forEach(setting => {
    if (setting.previous !== null) setting.element?.setAttribute('content', setting.previous)
    else setting.element?.removeAttribute('content')
  })
})
</script>

<template>
  <div class="short-player relative flex h-full flex-col bg-black text-white">
    <div class="player-top-bar" aria-hidden="true"></div>
    <button class="player-close" aria-label="返回视频列表" @click="$emit('close')">‹</button>

    <div ref="containerRef" class="min-h-0 flex-1 overflow-y-scroll snap-y snap-mandatory">
      <section
        v-for="(v, idx) in videos"
        :key="v.id"
        :data-idx="idx"
        :inert="idx !== currentIdx || undefined"
        class="relative h-full w-full snap-start flex items-center justify-center overflow-hidden bg-black"
        @click="togglePlay"
      >
        <img v-if="v.cover" v-efv-cover-blob="(idx === initialIndex && initialCover) || mediaUrl(v.cover)" alt="" class="short-playback-cover absolute left-0 top-1/2 h-full w-full -translate-y-1/2 object-fill bg-black" @load="fitMediaWidth($event.currentTarget as HTMLImageElement)" :loading="isNear(idx) ? 'eager' : 'lazy'" />
        <video
          v-if="isHls(v.url) ? idx === currentIdx : isNear(idx)"
          v-video-source="{ id: v.id, url: v.url }"
          @loadedmetadata="mediaReady($event, idx)"
          @resize="fitMediaWidth($event.currentTarget as HTMLVideoElement)"
          @durationchange="updatePlayback($event, idx)"
          @timeupdate="updatePlayback($event, idx)"
          @play="updatePlayback($event, idx)"
          @loadeddata="revealVideo"
          @playing="revealVideo($event); updatePlayback($event, idx)"
          @waiting="updatePlayback($event, idx)"
          @pause="updatePlayback($event, idx)"
          @ended="updatePlayback($event, idx)"
          @error="mediaFailed"
          loop
          playsinline
          preload="metadata"
          class="short-playback-frame absolute left-0 top-1/2 h-full w-full -translate-y-1/2 bg-black"
        />

        <template v-if="idx === currentIdx">
          <button
            v-if="loadState === 'failed'"
            type="button"
            class="absolute max-w-[80%] rounded-full bg-black/70 px-4 py-3 text-sm"
            @click.stop="togglePlay"
          >{{ loadError }}</button>
          <span v-else-if="loadState === 'loading'" role="status" class="pointer-events-none absolute rounded-full bg-black/60 px-4 py-2 text-sm">
            正在加载视频…
          </span>
          <button
            v-else-if="playback.paused"
            type="button"
            aria-label="继续播放"
            class="absolute grid h-16 w-16 place-items-center rounded-full bg-black/50 focus-visible:outline focus-visible:outline-2"
            @click.stop="togglePlay"
          >
            <svg aria-hidden="true" viewBox="0 0 24 24" class="ml-1 h-9 w-9 fill-current">
              <path d="M7 3v18l15-9z" />
            </svg>
          </button>
          <span v-else-if="playback.waiting" role="status" class="pointer-events-none absolute rounded-full bg-black/60 px-4 py-2 text-sm">
            正在缓冲…
          </span>
          <div
            class="absolute inset-x-0 bottom-0 flex items-center gap-3 bg-gradient-to-t from-black/85 to-black/25 px-3 pt-2 pb-[max(8px,env(safe-area-inset-bottom))]"
            @click.stop
          >
            <button
              type="button"
              :aria-label="playback.paused ? '播放' : '暂停'"
              class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-black/45 focus-visible:outline focus-visible:outline-2"
              @click="togglePlay"
            >
              <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5 fill-current">
                <path v-if="playback.paused" d="M7 3v18l15-9z" />
                <path v-else d="M6 4h4v16H6zm8 0h4v16h-4z" />
              </svg>
            </button>
            <input
              type="range"
              aria-label="播放进度"
              :aria-valuetext="formatTime(playback.current) + ' / ' + formatTime(playback.duration)"
              :value="playback.current"
              :max="playback.duration"
              :disabled="!playback.duration"
              min="0"
              step="any"
              class="h-8 min-w-0 flex-1 cursor-pointer touch-none accent-white disabled:cursor-default disabled:opacity-50"
              @input="seek"
            />
            <span aria-hidden="true" class="shrink-0 text-xs tabular-nums">
              {{ formatTime(playback.current) }} / {{ formatTime(playback.duration) }}
            </span>
          </div>
        </template>

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

<style scoped>
:global(html:has(.short-player)),
:global(body:has(.short-player)) { background: #000; }
.player-top-bar {
  height: calc(env(safe-area-inset-top, 0px) + 56px);
  flex-shrink: 0;
  background: #000;
  pointer-events: none;
}
.short-player .player-close {
  background: transparent;
  text-shadow: 0 1px 4px #0009;
}

.short-playback-frame { object-fit: fill; object-position: center; }
.short-playback-frame:not([data-frame-ready="true"]) { opacity: 0; }
</style>
