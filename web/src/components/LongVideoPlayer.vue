<script setup lang="ts">
// Long-video player (V1, MP4): one <video> element with custom controls, kept for the whole
// page so toggling controls or fullscreen never reloads it.
// Fullscreen: element fullscreen on the player box where the browser supports it (Android Chrome),
// then a best-effort landscape lock; otherwise the video's own native fullscreen (iPhone Safari,
// which then shows the iOS controls); without either, no fullscreen button.
import { computed, inject, onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

const props = defineProps<{ src: string; poster?: string; title: string }>()

const RATES = [1, 1.25, 1.5, 2] as const
const HIDE_AFTER_MS = 3000

const root = ref<HTMLElement | null>(null)
const video = ref<HTMLVideoElement | null>(null)
const navigationHidden = inject<Ref<boolean>>('mainNavigationHidden', ref(false))

const loading = ref(true)
const waiting = ref(false)
const paused = ref(true)
const ended = ref(false)
const error = ref('')
const current = ref(0)
const duration = ref(0)
const rate = ref(1)
const dragging = ref(false)
const rateMenu = ref(false)
const controlsVisible = ref(true)
const fullscreen = ref(false)
const nativeFullscreen = ref(false)
const fullscreenMode = ref<'element' | 'native' | 'none'>('none')
const toast = ref('')
const canShare = typeof navigator !== 'undefined' && (typeof navigator.share === 'function' || typeof navigator.clipboard?.writeText === 'function')

let hideTimer: ReturnType<typeof setTimeout> | undefined
let toastTimer: ReturnType<typeof setTimeout> | undefined
let triedAutoplay = false
let orientationLocked = false
let hidNavigation = false

const progressMax = computed(() => duration.value > 0 ? duration.value : 0)
const rateLabel = computed(() => (rate.value === 1 ? '1.0' : String(rate.value)) + '×')
const progressPercent = computed(() => progressMax.value ? Math.min(100, (current.value / progressMax.value) * 100) : 0)

function formatTime(value: number) {
  if (!Number.isFinite(value) || value < 0) return '--:--'
  const total = Math.floor(value)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = String(total % 60).padStart(2, '0')
  return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

function showToast(message: string) {
  toast.value = message
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = '' }, 2200)
}

// ---- controls visibility ----
function scheduleHide() {
  clearTimeout(hideTimer)
  if (paused.value || ended.value || dragging.value || rateMenu.value || error.value) return
  hideTimer = setTimeout(() => { controlsVisible.value = false }, HIDE_AFTER_MS)
}
function showControls() {
  controlsVisible.value = true
  scheduleHide()
}
function toggleControls() {
  if (controlsVisible.value && !paused.value) {
    clearTimeout(hideTimer)
    controlsVisible.value = false
    rateMenu.value = false
  } else {
    showControls()
  }
}

// ---- video events ----
function syncDuration() {
  const v = video.value
  duration.value = v && Number.isFinite(v.duration) && v.duration > 0 ? v.duration : 0
}
function onLoadedMetadata() {
  loading.value = false
  syncDuration()
  if (triedAutoplay) return
  triedAutoplay = true
  // Plays with sound only when the browser allows it; otherwise the play button stays.
  video.value?.play().catch(() => { paused.value = true; showControls() })
}
function onTimeUpdate() {
  if (!dragging.value && video.value) current.value = video.value.currentTime
}
function onPlay() { paused.value = false; ended.value = false; scheduleHide() }
function onPause() { paused.value = true; showControls() }
function onEnded() { ended.value = true; paused.value = true; showControls() }
function onWaiting() { waiting.value = true }
function onPlaying() { waiting.value = false; loading.value = false }
function onCanPlay() { waiting.value = false; loading.value = false }
function onRateChange() { if (video.value) rate.value = video.value.playbackRate }
function onError() {
  const code = video.value?.error?.code
  if (!code || code === 1) return
  loading.value = false
  waiting.value = false
  paused.value = true
  error.value = code === 4 ? '当前浏览器无法播放此视频' : code === 3 ? '视频解码失败，请重试' : '视频加载失败，请检查网络后重试'
  showControls()
}

// ---- playback ----
function togglePlay() {
  const v = video.value
  if (!v || error.value) return
  if (v.paused || v.ended) {
    if (v.ended) v.currentTime = 0
    v.play().catch(() => showToast('无法开始播放，请重试'))
  } else {
    v.pause()
  }
  showControls()
}
function retry() {
  const v = video.value
  if (!v) return
  error.value = ''
  loading.value = true
  triedAutoplay = true
  v.load()
  v.play().catch(() => { paused.value = true })
}

// Seeks inside the seekable range; nothing happens while the duration is unknown.
function seekTo(target: number) {
  const v = video.value
  if (!v || !(duration.value > 0) || !Number.isFinite(target)) return
  let lo = 0
  let hi = duration.value
  if (v.seekable && v.seekable.length) {
    lo = v.seekable.start(0)
    hi = Math.min(hi, v.seekable.end(v.seekable.length - 1))
  }
  const time = Math.min(Math.max(target, lo), hi)
  v.currentTime = time
  current.value = time
}
function onSeekInput(event: Event) {
  dragging.value = true
  clearTimeout(hideTimer)
  seekTo((event.currentTarget as HTMLInputElement).valueAsNumber)
}
function onSeekEnd() {
  dragging.value = false
  showControls()
}

function toggleRateMenu() {
  rateMenu.value = !rateMenu.value
  if (rateMenu.value) clearTimeout(hideTimer)
  else scheduleHide()
}
function chooseRate(value: number) {
  if (video.value) video.value.playbackRate = value
  rate.value = value
  rateMenu.value = false
  showControls()
}

// ---- fullscreen ----
function setNavigationHidden(hidden: boolean) {
  if (hidden === hidNavigation) return
  hidNavigation = hidden
  navigationHidden.value = hidden
}
function unlockOrientation() {
  if (!orientationLocked) return
  orientationLocked = false
  try { screen.orientation?.unlock?.() } catch { /* nothing to undo */ }
}
async function enterFullscreen() {
  const box = root.value
  const v = video.value
  if (!box || !v) return
  if (fullscreenMode.value === 'element') {
    try {
      await box.requestFullscreen({ navigationUI: 'hide' })
    } catch {
      showToast('无法进入全屏')
      return
    }
    try {
      const orientation = screen.orientation as ScreenOrientation & { lock?: (type: string) => Promise<void> }
      await orientation?.lock?.('landscape')
      orientationLocked = true
    } catch { /* Fullscreen still works without the landscape lock. */ }
  } else if (fullscreenMode.value === 'native') {
    try {
      (v as HTMLVideoElement & { webkitEnterFullscreen: () => void }).webkitEnterFullscreen()
    } catch {
      showToast('暂时无法全屏，请开始播放后再试')
    }
  }
}
function exitFullscreen() {
  if (document.fullscreenElement === root.value) document.exitFullscreen().catch(() => {})
}
function toggleFullscreen() {
  rateMenu.value = false
  if (fullscreen.value) exitFullscreen()
  else void enterFullscreen()
}
function onFullscreenChange() {
  fullscreen.value = !!root.value && document.fullscreenElement === root.value
  setNavigationHidden(fullscreen.value)
  if (!fullscreen.value) unlockOrientation()
  showControls()
}
function onFullscreenError() {
  fullscreen.value = false
  setNavigationHidden(false)
  unlockOrientation()
  showToast('无法进入全屏')
}
function onNativeBegin() { nativeFullscreen.value = true }
function onNativeEnd() {
  nativeFullscreen.value = false
  // The iOS player may have changed the state; the page controls follow the video element.
  if (video.value) {
    paused.value = video.value.paused
    current.value = video.value.currentTime
    rate.value = video.value.playbackRate
  }
  showControls()
}

async function share() {
  const url = location.href
  try {
    if (typeof navigator.share === 'function') {
      await navigator.share({ title: props.title, url })
      return
    }
    await navigator.clipboard.writeText(url)
    showToast('链接已复制')
  } catch (err) {
    if ((err as DOMException)?.name !== 'AbortError') showToast('分享失败')
  }
}

onMounted(() => {
  const box = root.value
  const v = video.value
  if (box && v) {
    fullscreenMode.value = typeof box.requestFullscreen === 'function' && document.fullscreenEnabled ? 'element'
      : typeof (v as HTMLVideoElement & { webkitEnterFullscreen?: unknown }).webkitEnterFullscreen === 'function' ? 'native'
        : 'none'
    v.addEventListener('webkitbeginfullscreen', onNativeBegin)
    v.addEventListener('webkitendfullscreen', onNativeEnd)
  }
  document.addEventListener('fullscreenchange', onFullscreenChange)
  document.addEventListener('fullscreenerror', onFullscreenError)
})

onBeforeUnmount(() => {
  clearTimeout(hideTimer)
  clearTimeout(toastTimer)
  document.removeEventListener('fullscreenchange', onFullscreenChange)
  document.removeEventListener('fullscreenerror', onFullscreenError)
  const v = video.value
  v?.removeEventListener('webkitbeginfullscreen', onNativeBegin)
  v?.removeEventListener('webkitendfullscreen', onNativeEnd)
  if (root.value && document.fullscreenElement === root.value) document.exitFullscreen().catch(() => {})
  unlockOrientation()
  setNavigationHidden(false)
  v?.pause()
})

defineExpose({ video })
</script>

<template>
  <div ref="root" class="lvp" :class="{ 'lvp-fullscreen': fullscreen, 'lvp-controls-hidden': !controlsVisible }" data-testid="long-video-player" @click="toggleControls">
    <video
      ref="video"
      class="lvp-video"
      :src="src"
      :poster="poster"
      preload="metadata"
      playsinline
      webkit-playsinline
      @loadedmetadata="onLoadedMetadata"
      @durationchange="syncDuration"
      @timeupdate="onTimeUpdate"
      @play="onPlay"
      @pause="onPause"
      @ended="onEnded"
      @waiting="onWaiting"
      @playing="onPlaying"
      @canplay="onCanPlay"
      @ratechange="onRateChange"
      @error="onError"
    />

    <div v-if="error" class="lvp-center" role="alert" @click.stop>
      <p class="lvp-message">{{ error }}</p>
      <button type="button" class="lvp-retry" @click="retry">重试</button>
    </div>
    <p v-else-if="loading || waiting" class="lvp-center lvp-message" role="status">加载中…</p>
    <button v-else-if="paused" type="button" class="lvp-center-play" :aria-label="ended ? '重新播放' : '播放'" @click.stop="togglePlay">
      <svg v-if="ended" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5V2L7 6l5 4V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z" /></svg>
      <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
    </button>

    <div v-if="fullscreen" v-show="controlsVisible" class="lvp-top" @click.stop>
      <button type="button" class="lvp-icon" aria-label="退出全屏" @click="exitFullscreen">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7" /></svg>
      </button>
      <span class="lvp-title">{{ title }}</span>
      <button v-if="canShare" type="button" class="lvp-icon" aria-label="分享" @click="share">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V3m0 0L7.5 7.5M12 3l4.5 4.5M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7" /></svg>
      </button>
    </div>

    <div v-show="controlsVisible" class="lvp-bottom" @click.stop>
      <button type="button" class="lvp-icon" :aria-label="paused ? '播放' : '暂停'" :disabled="!!error" @click="togglePlay">
        <svg v-if="paused" viewBox="0 0 24 24" aria-hidden="true"><path class="lvp-fill" d="M8 5v14l11-7z" /></svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path class="lvp-fill" d="M7 5h3.5v14H7zm6.5 0H17v14h-3.5z" /></svg>
      </button>
      <span class="lvp-time" data-testid="lvp-current">{{ formatTime(current) }}</span>
      <input
        type="range"
        class="lvp-range"
        aria-label="播放进度"
        :aria-valuetext="formatTime(current) + ' / ' + formatTime(duration)"
        min="0"
        step="any"
        :max="progressMax"
        :value="Math.min(current, progressMax)"
        :disabled="!progressMax || !!error"
        :style="{ '--lvp-progress': progressPercent + '%' }"
        @input="onSeekInput"
        @change="onSeekEnd"
        @pointerup="onSeekEnd"
      />
      <span class="lvp-time" data-testid="lvp-duration">{{ progressMax ? formatTime(duration) : '--:--' }}</span>
      <div class="lvp-rate">
        <button type="button" class="lvp-rate-button" aria-label="播放速度" aria-haspopup="menu" :aria-expanded="rateMenu" @click="toggleRateMenu">{{ rateLabel }}</button>
        <div v-if="rateMenu" class="lvp-rate-menu" role="menu" aria-label="播放速度">
          <button v-for="value in RATES" :key="value" type="button" role="menuitemradio" :aria-checked="rate === value" @click="chooseRate(value)">
            {{ value === 1 ? '1.0' : value }}×
          </button>
        </div>
      </div>
      <button v-if="fullscreenMode !== 'none'" type="button" class="lvp-icon" :aria-label="fullscreen ? '退出全屏' : '全屏'" @click="toggleFullscreen">
        <svg v-if="fullscreen" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5" /></svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" /></svg>
      </button>
    </div>

    <p v-if="toast" class="lvp-toast" role="status">{{ toast }}</p>
  </div>
</template>

<style scoped>
.lvp { position: relative; width: 100%; aspect-ratio: 16 / 9; overflow: hidden; background: #000; color: #fff; user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent; }
.lvp.lvp-fullscreen, .lvp:fullscreen { width: 100%; height: 100%; aspect-ratio: auto; }
.lvp-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: contain; background: #000; }
.lvp-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; text-align: center; pointer-events: none; }
.lvp-center > * { pointer-events: auto; }
.lvp-message { padding: 0 24px; font-size: 14px; color: #fff; text-shadow: 0 1px 2px #0008; }
.lvp-retry { padding: 6px 20px; border: 1px solid #fff9; border-radius: 6px; color: #fff; background: #0006; font-size: 14px; }
.lvp-center-play { position: absolute; top: 50%; left: 50%; width: 56px; height: 56px; margin: -28px 0 0 -28px; display: grid; place-items: center; border-radius: 50%; background: #0008; }
.lvp-center-play svg { width: 30px; height: 30px; fill: #fff; }
.lvp-top, .lvp-bottom { position: absolute; left: 0; right: 0; display: flex; align-items: center; gap: 8px; z-index: 2; }
.lvp-top { top: 0; padding: max(8px, env(safe-area-inset-top)) max(10px, env(safe-area-inset-right)) 18px max(10px, env(safe-area-inset-left)); background: linear-gradient(#000a, #0000); }
.lvp-bottom { bottom: 0; padding: 18px 10px 6px; background: linear-gradient(#0000, #000a); }
.lvp-fullscreen .lvp-bottom { padding: 24px max(14px, env(safe-area-inset-right)) max(10px, env(safe-area-inset-bottom)) max(14px, env(safe-area-inset-left)); }
.lvp-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 15px; font-weight: 600; }
.lvp-icon { flex: none; width: 36px; height: 36px; display: grid; place-items: center; color: #fff; }
.lvp-icon:disabled { opacity: .5; }
.lvp-icon svg { width: 22px; height: 22px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.lvp-icon svg .lvp-fill { fill: currentColor; stroke: none; }
.lvp-time { flex: none; min-width: 38px; font-size: 12px; font-variant-numeric: tabular-nums; text-align: center; }
/* Drawn explicitly: WebKit's default track is invisible on the black bar. */
.lvp-range { flex: 1; min-width: 0; height: 24px; margin: 0; background: transparent; cursor: pointer; touch-action: none; -webkit-appearance: none; appearance: none; }
.lvp-range::-webkit-slider-runnable-track { height: 3px; border-radius: 2px; background: linear-gradient(to right, #fff var(--lvp-progress, 0%), #ffffff59 var(--lvp-progress, 0%)); }
.lvp-range::-webkit-slider-thumb { width: 14px; height: 14px; margin-top: -5.5px; border: 0; border-radius: 50%; background: #fff; -webkit-appearance: none; appearance: none; }
.lvp-range::-moz-range-track { height: 3px; border-radius: 2px; background: #ffffff59; }
.lvp-range::-moz-range-progress { height: 3px; border-radius: 2px; background: #fff; }
.lvp-range::-moz-range-thumb { width: 14px; height: 14px; border: 0; border-radius: 50%; background: #fff; }
.lvp-range:disabled { opacity: .5; }
.lvp-rate { position: relative; flex: none; }
.lvp-rate-button { min-width: 44px; height: 36px; padding: 0 6px; font-size: 13px; font-weight: 600; color: #fff; }
.lvp-rate-menu { position: absolute; right: 0; bottom: 100%; margin-bottom: 4px; display: flex; flex-direction: column; min-width: 72px; padding: 4px 0; border-radius: 8px; background: #000d; }
.lvp-rate-menu button { padding: 8px 14px; font-size: 14px; color: #fff; text-align: center; }
.lvp-rate-menu button[aria-checked="true"] { color: #ff315b; font-weight: 700; }
.lvp-toast { position: absolute; left: 50%; bottom: 64px; transform: translateX(-50%); z-index: 3; padding: 6px 14px; border-radius: 16px; font-size: 13px; background: #000c; white-space: nowrap; pointer-events: none; }
</style>
