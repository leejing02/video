<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import type { ObjectDirective } from 'vue'
import type Hls from 'hls.js'
import PlayerIcon from './PlayerIcon.vue'
import AvatarContent from './AvatarContent.vue'
import PlayerComments from './PlayerComments.vue'
import CommentDanmaku from './CommentDanmaku.vue'
import { mediaUrl } from '@/utils/media-url'
import { vEfvCoverBlob, prefetchCover } from '@/utils/efv-cover-blob'
import { useAuthStore } from '@/stores/auth'
import { shortVideoApi } from '@/api/shortVideo'
import { showToast } from '@/utils/toast'
import { playbackCoverWindow } from '@/utils/playback-window'
import type { ShortVideo, ShortVideoComment } from '@/types/models'

const auth = useAuthStore()
const props = defineProps<{ items: ShortVideo[]; initialIndex: number; initialCover?: string; returnTarget?: (id?: number) => DOMRect | undefined }>()
const emit = defineEmits<{ close: []; likesChanged: [id: number, count: number] }>()
const videos = ref<ShortVideo[]>(props.items)
const currentIdx = ref(props.initialIndex)
const containerRef = ref<HTMLElement | null>(null)
const states = ref<Record<number, { liked: boolean; favorited: boolean }>>({})
const showComments = ref(false)
const commentsEnabled = ref(false)
async function refreshCommentSetting() {
  try {
    const response = await fetch('/api/video-comments/settings', { cache: 'no-store' })
    commentsEnabled.value = response.ok && (await response.json()).enabled === true
  } catch { commentsEnabled.value = false }
  if (!commentsEnabled.value) showComments.value = false
}
const danmakuComments = ref<Record<number, ShortVideoComment[]>>({})
const danmakuEnabled = ref(false)
const pageVisible = ref(!document.hidden)
function toggleDanmaku() {
  danmakuEnabled.value = !danmakuEnabled.value
  try { localStorage.setItem('short-video-danmaku', String(danmakuEnabled.value)) } catch { /* Optional preference. */ }
}
const panel = ref<'share'|'author'|null>(null)
const preview = ref('')
const loadState = ref<'loading' | 'ready' | 'failed'>('loading')
const loadError = ref('')
const playback = ref({ paused: true, current: 0, duration: 0, waiting: false })
const backOffset = ref(0)
const backDragging = ref(false)
const zoomReturning = ref(false)
const backDuration = ref(220)
let backClosing = false
let backSettling = false
let backTimer: ReturnType<typeof setTimeout> | undefined
let backTouch: { id: number; x: number; y: number; lastX: number; time: number; velocity: number } | undefined
let ignoreClickUntil = 0
let disposed = false
let suspended = false
let manuallyPaused = false
let resumePlaying = false

function cancelBack() {
  backTouch = undefined
  backDragging.value = false
  backOffset.value = 0
}
function closePlayer() {
  if (backClosing) return
  backClosing = true
  containerRef.value?.querySelectorAll('video').forEach(video => video.pause())
  emit('close')
}
function zoomBack() {
  if (backClosing || backSettling) return
  const player = containerRef.value?.parentElement
  if (!player?.animate || matchMedia('(prefers-reduced-motion: reduce)').matches) return closePlayer()

  const bounds = player.getBoundingClientRect()
  const target = props.returnTarget?.(videos.value[currentIdx.value]?.id)
  const visibleTarget = target && target.width > 0 && target.height > 0 &&
    target.bottom > bounds.top && target.top < bounds.bottom
  const scale = visibleTarget ? Math.min(target.width / bounds.width, target.height / bounds.height, .85) : .88
  const x = visibleTarget ? target.left + target.width / 2 - (bounds.left + bounds.width / 2) : 0
  const y = visibleTarget ? target.top + target.height / 2 - (bounds.top + bounds.height / 2) : 0

  backSettling = true
  zoomReturning.value = true
  const animation = player.animate([
    { transform: 'translate3d(0,0,0) scale(1)', opacity: 1 },
    { transform: `translate3d(${x}px,${y}px,0) scale(${scale})`, opacity: 1, borderRadius: '16px', offset: .8 },
    { transform: `translate3d(${x}px,${y}px,0) scale(${scale})`, opacity: 0, borderRadius: '16px' },
  ], { duration: 420, easing: 'ease-out', fill: 'forwards' })
  void animation.finished.then(closePlayer, closePlayer)
}

function startBack(event: TouchEvent) {
  if (backClosing || backSettling || showComments.value || panel.value || event.touches.length !== 1) return
  if (event.target instanceof Element && event.target.closest('button,input,textarea,a')) return
  const touch = event.touches[0]
  if (touch.clientX > 32) return
  backTouch = { id: touch.identifier, x: touch.clientX, y: touch.clientY, lastX: touch.clientX, time: event.timeStamp, velocity: 0 }
}
function moveBack(event: TouchEvent) {
  const start = backTouch
  if (!start) return
  const touch = event.touches[0]
  if (event.touches.length !== 1 || touch.identifier !== start.id) return cancelBack()
  const dx = touch.clientX - start.x
  const dy = Math.abs(touch.clientY - start.y)
  if (!backDragging.value && dy > 12 && dy >= dx) return cancelBack()
  if (backDragging.value || (dx > 8 && dx > dy * 1.3)) {
    if (event.cancelable) event.preventDefault()
    backDragging.value = true
    backOffset.value = Math.max(0, Math.min(dx - 6, window.innerWidth))
    const elapsed = event.timeStamp - start.time
    if (elapsed > 0) start.velocity = (touch.clientX - start.lastX) / elapsed
    start.lastX = touch.clientX
    start.time = event.timeStamp
  }
}
function endBack(event: TouchEvent) {
  const start = backTouch
  const wasDragging = backDragging.value
  backTouch = undefined
  backDragging.value = false
  if (!start || !wasDragging) return cancelBack()
  ignoreClickUntil = performance.now() + 350
  const touch = Array.from(event.changedTouches).find(item => item.identifier === start.id)
  const dx = touch ? touch.clientX - start.x : 0
  const dy = touch ? Math.abs(touch.clientY - start.y) : Infinity
  const speed = event.timeStamp - start.time < 80 ? Math.max(0, start.velocity) : 0
  if (dx < 32 || dx < dy * 1.5 || backOffset.value + Math.min(speed, 2) * 85 < Math.min(100, window.innerWidth * .24)) {
    backDuration.value = 180
    return cancelBack()
  }
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return closePlayer()
  backDuration.value = Math.min(300, Math.max(120, (window.innerWidth - backOffset.value) / Math.max(.95, speed)))
  backSettling = true
  backOffset.value = window.innerWidth
  backTimer = setTimeout(closePlayer, backDuration.value + 20)
}

function warmCovers() {
  // Keep the current and next five posters ready, without decoding the entire feed.
  for (const video of playbackCoverWindow(videos.value, currentIdx.value)) {
    const url = mediaUrl(video.cover)
    if (url) void prefetchCover(url, Date.now() + 60000)
  }
}

function suspendPlayback() {
  if (suspended) return
  suspended = true
  resumePlaying = !manuallyPaused
  clearTimeout(backTimer)
  backSettling = false
  cancelBack()
  observer?.disconnect()
  containerRef.value?.querySelectorAll('video').forEach(video => {
    const source = streams.get(video)
    clearTimeout(source?.timeout)
    source?.hls?.stopLoad()
    video.pause()
  })
}
function resumePlayback() {
  if (!suspended || disposed || document.hidden || backClosing) return
  suspended = false
  warmCovers()
  void nextTick(() => {
    if (disposed || suspended) return
    setupObserver()
    const video = activeVideo()
    const item = currentVideo.value
    if (!video || !item) return
    const source = streams.get(video)
    if (!source || source.needsRefresh || video.error) {
      void attachSource(video, item, video.currentTime)
      return
    }
    source.hls?.startLoad()
    if (video.readyState === HTMLMediaElement.HAVE_NOTHING) {
      source.timeout = setTimeout(() => sourceFailed(video, source, '视频恢复超时，点击重试'), 15000)
    }
    if (resumePlaying) void playVideo(video, true)
  })
}
function onVisibility() { pageVisible.value = !document.hidden; if (document.hidden) suspendPlayback(); else {resumePlayback();void refreshInteractions()} }
function onPageShow(event: PageTransitionEvent) { if (event.persisted) resumePlayback() }

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
  void nextTick(() => {
    if (disposed || suspended) return
    containerRef.value?.querySelectorAll('[data-idx]').forEach(el => observer?.observe(el))
  })
}

async function playVideo(video: HTMLVideoElement, autoplay = false) {
  if (document.hidden || suspended || (autoplay && manuallyPaused)) return
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
    if (document.hidden || suspended) return
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
  needsRefresh?: boolean
  resumeAt?: number
}
const streams = new WeakMap<HTMLVideoElement, VideoSource>()

function sourceFailed(el: HTMLVideoElement, source: VideoSource, reason: string) {
  if (streams.get(el) !== source || el !== activeVideo()) return
  if (document.hidden || suspended) { source.needsRefresh = true; return }
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
  if (source.resumeAt && Number.isFinite(el.duration)) {
    el.currentTime = Math.min(source.resumeAt, Math.max(0, el.duration - .1))
    source.resumeAt = undefined
  }
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
    document.getElementById('playback-entry-cover')?.remove()
  }
}

function coverReady(event: Event, idx: number) {
  fitMediaWidth(event.currentTarget as HTMLImageElement)
  if (idx === props.initialIndex) document.getElementById('playback-entry-cover')?.remove()
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

async function attachSource(el: HTMLVideoElement, { id, url }: { id: number; url: string }, resumeAt = 0) {
  detachSource(el)
  const source: VideoSource = { resumeAt }
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
    // Use Safari's native pipeline instead of loading a JS HLS decoder on iPhone.
    if (el.canPlayType('application/vnd.apple.mpegurl')) {
      el.src = url
      return
    }
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
  manuallyPaused = false
  warmCovers()
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
  if (performance.now() < ignoreClickUntil || backClosing) return
  const video = activeVideo()
  const item = currentVideo.value
  if (!video || !item) return
  if (loadState.value === 'failed') {
    manuallyPaused = false
    void attachSource(video, item)
    return
  }
  if (video.paused) {
    manuallyPaused = false
    void playVideo(video)
  } else {
    manuallyPaused = true
    video.pause()
  }
}

function seek(event: Event) {
  const video = activeVideo()
  if (!video || !playback.value.duration) return
  video.currentTime = (event.currentTarget as HTMLInputElement).valueAsNumber
  playback.value.current = video.currentTime
}

let interactionVersion = 0
let interactionTimer: ReturnType<typeof setInterval> | undefined
const interactionBusy = ref(false)

async function refreshInteractions() {
  const item = videos.value[currentIdx.value]
  if (!item || document.hidden || interactionBusy.value) return
  const version = ++interactionVersion
  const user = auth.user?.id
  try {
    const value = await shortVideoApi.interactions(item.id)
    if (disposed || version !== interactionVersion || user !== auth.user?.id ||
        videos.value[currentIdx.value]?.id !== item.id) return
    states.value[item.id] = { liked: value.liked, favorited: value.favorited }
    Object.assign(item, {
      likes: value.likes, favorites: value.favorites,
      comments_count: value.comments_count, shares: value.shares,
    })
    if (value.comments_count === 0) {
      danmakuComments.value[item.id] = []
    }
    else void refreshDanmakuComments(item.id, version)
  } catch {
    // Preserve known state on transient connection errors.
  }
}
async function refreshDanmakuComments(id: number, version: number) {
  try {
    const result = await shortVideoApi.comments(id, { page: 1, per_page: 20 })
    if (disposed || version !== interactionVersion || videos.value[currentIdx.value]?.id !== id) return
    danmakuComments.value[id] = result.data
  } catch {
    // Keep the existing comments until the next successful refresh.
  }
}
watch(() => auth.user?.id, () => {
  states.value = {}
  interactionVersion++
  void refreshInteractions()
})
watch(currentIdx, () => { void refreshInteractions() }, { immediate: true })

async function like(id: number) {
  if (!auth.isLoggedIn) return showToast('请先登录')
  if (interactionBusy.value) return
  interactionBusy.value = true
  interactionVersion++
  const user = auth.user?.id
  try {
    const res = await shortVideoApi.like(id)
    if (disposed || user !== auth.user?.id) return
    states.value[id] = { ...states.value[id], liked: res.liked }
    const item = videos.value.find(v => v.id === id)
    if (item) item.likes = res.count
    emit('likesChanged', id, res.count)
  } catch {
    showToast('点赞失败，请重试')
  } finally {
    interactionBusy.value = false
    void refreshInteractions()
  }
}
async function favorite(id: number) {
  if (!auth.isLoggedIn) return showToast('请先登录')
  if (interactionBusy.value) return
  interactionBusy.value = true
  interactionVersion++
  const user = auth.user?.id
  try {
    const res = await shortVideoApi.favorite(id)
    if (disposed || user !== auth.user?.id) return
    states.value[id] = { ...states.value[id], favorited: res.favorited }
    const item = videos.value.find(v => v.id === id)
    if (item) item.favorites = res.count
  } catch {
    showToast('收藏失败，请重试')
  } finally {
    interactionBusy.value = false
    void refreshInteractions()
  }
}

function openPersonalCenter() {
  containerRef.value?.querySelectorAll('video').forEach(video => video.pause())
  location.assign('/me')
}

function share(_id: number) { panel.value='share'; preview.value='' }
function openComments(_id: number) { if (!commentsEnabled.value) return; showComments.value=true;void refreshInteractions() }
async function copyLink() {
 if (!currentVideo.value) return
 try {await navigator.clipboard.writeText(location.origin+'/player.html?id='+currentVideo.value.id);showToast('链接已复制')} catch {showToast('复制失败，请使用系统分享')}
}
async function systemShare() {
 if (!currentVideo.value) return
 try {if(navigator.share) await navigator.share({title:currentVideo.value.title,url:location.origin+'/player.html?id='+currentVideo.value.id});else await copyLink()}catch(error){if(!(error instanceof DOMException && error.name==='AbortError'))showToast('分享失败，请重试')}
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
  void refreshCommentSetting()
  try { danmakuEnabled.value = localStorage.getItem('short-video-danmaku') === 'true' } catch { /* Optional preference. */ }
  interactionTimer=setInterval(()=>{void refreshInteractions();void refreshCommentSetting()},15000)
  document.addEventListener('visibilitychange', onVisibility)
  window.addEventListener('pagehide', suspendPlayback)
  window.addEventListener('pageshow', onPageShow)
  warmCovers()
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
  disposed = true
  clearInterval(interactionTimer)
  interactionVersion++
  clearTimeout(backTimer)
  document.removeEventListener('visibilitychange', onVisibility)
  window.removeEventListener('pagehide', suspendPlayback)
  window.removeEventListener('pageshow', onPageShow)
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
  <div class="short-player relative flex h-full flex-col bg-black text-white"
    :data-returning="backOffset > 0 || zoomReturning"
    :style="{ transform: `translate3d(${backOffset}px,0,0)`, transition: backDragging ? 'none' : `transform ${backDuration}ms cubic-bezier(.16,1,.3,1)` }"
    @touchstart.passive="startBack" @touchmove="moveBack" @touchend.passive="endBack" @touchcancel.passive="cancelBack">
    <button class="player-close" aria-label="返回视频列表" @click="zoomBack">‹</button>

    <div ref="containerRef" class="min-h-0 flex-1 overflow-y-scroll snap-y snap-mandatory">
      <section
        v-for="(v, idx) in videos"
        :key="v.id"
        :data-idx="idx"
        :inert="idx !== currentIdx || undefined"
        class="relative h-full w-full snap-start flex items-center justify-center overflow-hidden bg-black"
        @click="togglePlay"
      >
        <img v-if="isNear(idx) && (v.cover || (idx === initialIndex && initialCover))" v-efv-cover-blob="(idx === initialIndex && initialCover) || mediaUrl(v.cover)" :data-cover-fallback="mediaUrl(v.cover_fallback) || undefined" alt="" class="short-playback-cover absolute left-0 top-1/2 h-full w-full -translate-y-1/2 object-fill bg-black" @load="coverReady($event, idx)" :loading="isNear(idx) ? 'eager' : 'lazy'" />
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
            class="player-progress-controls"
            @click.stop
          >
            <button
              type="button"
              :aria-label="playback.paused ? '播放' : '暂停'"
              class="progress-play"
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
              class="progress-range"
              @input="seek"
            />
            <span aria-hidden="true" class="shrink-0 text-xs tabular-nums">
              {{ formatTime(playback.current) }} / {{ formatTime(playback.duration) }}
            </span>
          </div>
        </template>

        <div v-if="isNear(idx)" class="player-actions" @click.stop>
          <button class="author-avatar" aria-label="查看作者主页" @click="panel='author'"><AvatarContent :src="v.user?.avatar" :name="v.user?.name || v.user?.username"/><b>＋</b></button>
          <button aria-label="点赞视频" :class="{liked:currentState.liked}" @click="like(v.id)"><PlayerIcon name="heart"/><span>{{v.likes}}</span></button>
          <button v-if="commentsEnabled" aria-label="查看评论" @click="openComments(v.id)"><PlayerIcon name="comment"/><span>{{v.comments_count}}</span></button>
          <button aria-label="收藏视频" :class="{saved:currentState.favorited}" @click="favorite(v.id)"><PlayerIcon name="star"/><span>{{v.favorites}}</span></button>
          <button aria-label="分享视频" @click="share(v.id)"><PlayerIcon name="share"/><span>{{v.shares || '分享'}}</span></button>
        </div>

        <CommentDanmaku v-if="commentsEnabled && idx === currentIdx && danmakuEnabled" :key="v.id" :comments="danmakuComments[v.id] || []" :paused="playback.paused || playback.waiting || !pageVisible || showComments || panel !== null"/>
        <div v-if="idx === currentIdx" class="player-left-stack">
        <div class="player-extras" @click.stop>
          <button v-if="commentsEnabled" class="danmaku-toggle" :class="{enabled:danmakuEnabled}" :aria-pressed="danmakuEnabled" :aria-label="danmakuEnabled ? '关闭弹幕' : '开启弹幕'" @click="toggleDanmaku">弹<span aria-hidden="true">{{danmakuEnabled?'✓':'╱'}}</span></button>
          <button class="recommend-button" :class="{liked:currentState.liked}" :aria-pressed="currentState.liked" :disabled="interactionBusy" aria-label="点赞推荐" @click="like(v.id)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h4v11H3zM9 21V10l5-8c3 0 3 3 1 7h5c2 0 2 2 1 5l-2 7z"/></svg>{{v.likes}} 人推荐<span aria-hidden="true">›</span></button>
        </div>
        <button class="player-info" aria-label="查看评论信息" @click.stop="openComments(v.id)">
          <span class="player-comment-name">{{v.user?.name || v.user?.username || '用户'}}</span>
          <span class="player-comment-content">{{v.title}}</span>
        </button>
        </div>
        <button v-if="idx === currentIdx" class="player-personal-center" aria-label="进入个人中心" @click.stop="openPersonalCenter">
          <span><AvatarContent :src="auth.user?.avatar" :name="auth.user?.name || auth.user?.username"/></span>
        </button>
        <button v-if="commentsEnabled && idx === currentIdx" class="player-comment-entry" @click.stop="openComments(v.id)">
          <span>发条有爱评论～</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 14c2 3 6 3 8 0M8 8v2m8-2v2"/></svg>
        </button>
      </section>

      <div v-if="!videos.length" class="h-full flex items-center justify-center text-gray-500">暂无短视频</div>
    </div>

    <div v-if="showComments && currentVideo" class="player-sheet-mask" @click.self="showComments=false" @touchmove.stop>
      <PlayerComments :key="currentVideo.id" :video-id="currentVideo.id" :count="currentVideo.comments_count" :video="currentVideo" @close="showComments=false" @posted="refreshInteractions"/>
    </div>
    <div v-if="panel && currentVideo" class="player-sheet-mask" @click.self="panel=null" @touchmove.stop>
      <section class="player-sheet" role="dialog" aria-modal="true" :aria-label="panel==='share'?'分享视频':'作者主页'" @click.stop>
       <header><strong>{{panel==='share'?'分享至':'作者主页'}}</strong><button aria-label="关闭面板" @click="panel=null">×</button></header>
       <template v-if="panel==='share'">
        <div class="share-options"><button @click="systemShare"><i class="pink"><PlayerIcon name="share"/></i>系统分享</button><button @click="copyLink"><i><PlayerIcon name="link"/></i>复制链接</button><button @click="preview='私信朋友'"><i class="blue"><PlayerIcon name="comment"/></i>私信朋友</button><button v-for="name in ['微信朋友','朋友圈','QQ朋友']" :key="name" @click="preview=name"><i class="green">{{name[0]}}</i>{{name}}</button></div>
        <div class="share-options secondary"><button v-for="name in ['生成海报','播放反馈','不感兴趣','作品举报']" :key="name" @click="preview=name"><i>{{name==='作品举报'?'!':name==='播放反馈'?'?':'◇'}}</i>{{name}}</button></div>
        <div v-if="preview" class="feature-preview"><strong>{{preview}}</strong><p>此功能暂未开放</p><button @click="preview=''">返回分享选项</button></div>
       </template>
       <template v-else><div class="author-profile"><div class="profile-avatar"><AvatarContent :src="currentVideo.user?.avatar" :name="currentVideo.user?.name || currentVideo.user?.username"/></div><h2>{{currentVideo.user?.name || currentVideo.user?.username}}</h2><p>@{{currentVideo.user?.username}}</p><button class="follow-preview" @click="preview='关注作者'">＋ 关注</button><span v-if="preview==='关注作者'">关注功能暂未开放</span></div><h3 class="author-work-title">作品</h3><div class="author-works"><button v-for="item in videos.filter(item=>item.user?.id===currentVideo?.user?.id)" :key="item.id" @click="currentIdx=videos.indexOf(item);containerRef?.scrollTo({top:currentIdx*containerRef.clientHeight});panel=null"><img v-efv-cover-blob="mediaUrl(item.cover)" :data-cover-fallback="mediaUrl(item.cover_fallback) || undefined" :alt="item.title"/><span>{{item.title}}</span></button></div></template>
      </section>
    </div>
  </div>
</template>

<style scoped>
.player-left-stack{position:absolute;left:12px;right:72px;bottom:64px;display:flex;flex-direction:column;gap:5px;align-items:stretch}
.player-extras{display:flex;flex-direction:column;align-items:flex-start;gap:4px}
.danmaku-toggle,.recommend-button{background:#242424b3;color:#fff;border-radius:5px;display:flex;align-items:center;justify-content:center;gap:4px;min-height:28px;padding:3px 7px;font-size:12px}
.danmaku-toggle{font-size:15px;font-weight:600}.danmaku-toggle span{font-size:10px}.danmaku-toggle.enabled{color:#fff;background:#555c}
.recommend-button svg{width:15px;height:15px;fill:currentColor}.recommend-button.liked{color:#ff315b}.recommend-button>span{font-size:20px;line-height:16px}.recommend-button:disabled{opacity:.6}

.player-info{padding:3px 0 4px;background:transparent;color:#fff;text-shadow:0 1px 3px #000;text-align:left;display:flex;flex-direction:column;gap:3px;min-height:42px}
.player-comment-name{font-size:17px;font-weight:650}
.player-comment-content{font-size:14px;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;white-space:pre-wrap}
.player-personal-center{position:absolute;right:12px;bottom:68px;width:38px;height:38px;padding:4px;border-radius:50%;background:radial-gradient(circle at 30% 20%,#454545,#151515 70%);display:grid;place-items:center}
.player-personal-center>span{width:30px;height:30px;border-radius:50%;overflow:hidden;background:#333;display:grid;place-items:center;color:#fff;font-size:13px}
.player-personal-center :deep(img){width:100%;height:100%;object-fit:cover}
.player-progress-controls{position:absolute;inset:auto 0 40px;z-index:2;height:24px;display:flex;align-items:center;gap:9px;padding:0 14px;background:#000}
.progress-play{width:24px;height:24px;display:grid;place-items:center;flex:none}.progress-range{height:20px;min-width:0;flex:1;cursor:pointer;touch-action:none;accent-color:#fff}.progress-range:disabled{opacity:.5}
.player-comment-entry{position:absolute;inset:auto 0 0;height:40px;background:#000;color:#aaa;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:2px 12px 4px;text-align:left}
.player-comment-entry span{flex:1;background:#26262b;border-radius:24px;padding:7px 14px;font-size:14px;line-height:18px}
.player-comment-entry svg{position:absolute;right:26px;bottom:10px;width:21px;height:21px}
@media(max-height:500px){.player-actions{gap:8px!important;bottom:118px!important}.player-actions svg{width:26px!important;height:26px!important}.player-info{padding-top:8px}.player-comment-content{-webkit-line-clamp:1}}

.player-actions{position:absolute;right:12px;bottom:118px;display:flex;flex-direction:column;align-items:center;gap:10px;color:white;pointer-events:auto;filter:drop-shadow(0 1px 2px #0005)}.player-actions>button{display:flex;flex-direction:column;align-items:center;gap:3px;font-size:12px}.player-actions svg{width:32px;height:32px;fill:currentColor;stroke:currentColor;stroke-width:1;stroke-linejoin:round}.player-actions .liked{color:#ff315b}.player-actions .saved{color:#ffcf44}.player-actions .author-avatar{position:relative;display:grid;width:38px;height:38px;border-radius:50%;border:2px solid white;background:#888;margin-bottom:6px}.author-avatar :deep(img){width:100%;height:100%;object-fit:cover;border-radius:50%}.author-avatar b{position:absolute;bottom:-10px;left:5px;border-radius:12px;background:#ff315b;font-size:18px;width:25px;line-height:19px}.player-sheet-mask{position:absolute;inset:0;z-index:25;background:#0004;display:flex;flex-direction:column;justify-content:flex-end;color-scheme:light}.player-sheet{max-height:75%;overflow:auto;background:#fff;color:#161616;border-radius:22px 22px 0 0;padding-bottom:max(24px,env(safe-area-inset-bottom))}.player-sheet header{position:relative;text-align:center;padding:20px;font-size:17px}.player-sheet header>button{position:absolute;right:18px;top:10px;font-size:30px}.share-options{display:flex;gap:20px;padding:16px 22px 24px;overflow-x:auto}.share-options button{flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:10px;font-size:12px}.share-options i{display:grid;place-items:center;width:52px;height:52px;border-radius:50%;background:#f4f4f4;font-size:24px;font-style:normal}.share-options svg{width:25px;height:25px;fill:none;stroke:currentColor;stroke-width:2}.share-options .pink{background:#ff315b;color:white}.share-options .blue{background:#25b8f2;color:white}.share-options .green{background:#05c76b;color:white}.secondary{border-top:1px solid #eee}.feature-preview{text-align:center;padding:20px}.feature-preview p{color:#999;margin:12px}.feature-preview button{color:#ff315b}.author-profile{text-align:center;padding:12px}.profile-avatar{width:72px;height:72px;border-radius:50%;overflow:hidden;background:#eee;display:grid;place-items:center;margin:auto;font-size:30px}.profile-avatar :deep(img){width:100%;height:100%;object-fit:cover}.author-profile h2{font-size:22px;font-weight:700;margin:12px}.author-profile p,.author-profile span{display:block;color:#888;font-size:13px}.follow-preview{margin:18px;background:#ff315b;color:#fff;padding:8px 44px;border-radius:20px}.author-work-title{padding:16px;border-top:1px solid #eee;font-weight:700}.author-works{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;padding:4px}.author-works img{width:100%;aspect-ratio:3/4;object-fit:cover}.author-works span{display:block;font-size:11px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}

:global(html:has(.short-player)),
:global(body:has(.short-player)) { background: #000; }
/* Safari 26 can preserve the previous chrome color for full-viewport fixed layers. */
:global(body:has(.short-player)) { position: static; }
:global(body:has(.short-player) #app) { position: absolute; }
.short-player .player-close {
  background: transparent;
  text-shadow: 0 1px 4px #0009;
}
.short-player { touch-action: pan-y; will-change: transform; }

.short-playback-frame { object-fit: fill; object-position: center; }
.short-playback-frame:not([data-frame-ready="true"]) { opacity: 0; }
</style>
