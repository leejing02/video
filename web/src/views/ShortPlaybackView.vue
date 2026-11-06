<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import ShortVideoPlayer from '@/components/ShortVideoPlayer.vue'
import { shortVideoApi } from '@/api/shortVideo'
import type { ShortVideo } from '@/types/models'
import { fadeReturnDim, readReturnSnapshot, renderReturnSnapshot, replicaCardRect, RETURN_SNAPSHOT_KEY, type ReturnSnapshot } from '@/utils/playback-return'

const route = useRoute()
const items = ref<ShortVideo[]>([])
const initialIndex = ref(0)
const initialCover = ref('')
const error = ref('')
const id = Number(route.query.id)

function saveLikes(id: number, count: number) {
  if (!route.query.entry) return
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    if (saved?.entry !== route.query.entry || saved.returning) return
    const item = saved.items.find((video: ShortVideo) => video.id === id)
    if (item) item.likes = count
    sessionStorage.setItem('short-playback-feed', JSON.stringify({
      ...saved, updatedLikes: { ...saved.updatedLikes, [id]: count },
    }))
  } catch { /* The player still shows the confirmed count. */ }
}

// The list captured when playback opened, drawn under the player so the back
// animation shrinks the video into its card instead of fading to black.
let returnLayer: HTMLElement | undefined
let returnSnapshot: ReturnSnapshot | null = null
let returnTop: number | undefined
async function prepareReturnLayer(covers: Record<string, string>) {
  returnSnapshot = readReturnSnapshot(typeof route.query.entry === 'string' ? route.query.entry : null)
  const app = document.getElementById('app')
  if (!returnSnapshot || !app) return
  try { returnLayer = await renderReturnSnapshot(returnSnapshot, covers, app) } catch { returnLayer = undefined }
}
// player.html keeps a black strip at the top edge for iOS 26 Safari's status bar.
// Safari re-samples only when that element is replaced, so swap in a new strip
// when leaving playback: the status bar turns white with the list, not after it.
function tintStatusBar(color: string) {
  const strip = document.getElementById('playback-status-tint')
  if (!strip || strip.style.backgroundColor === color) return
  const next = strip.cloneNode() as HTMLElement
  next.style.backgroundColor = color
  strip.replaceWith(next)
}
// The list page that opened playback: /long while 推荐 shows short videos.
function listPath(saved: { path?: unknown } | null | undefined) {
  return saved?.path === '/long' ? '/long' : '/short'
}
function returnTarget(videoId?: number) {
  if (!returnLayer?.isConnected) return undefined
  tintStatusBar('#ffffff')
  const { rect, top } = replicaCardRect(returnLayer, videoId)
  returnTop = top
  // Same duration as the player's shrink animation (ShortVideoPlayer zoomBack).
  fadeReturnDim(returnLayer, 420)
  return rect
}

function close() {
  let back = '/short'
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    back = listPath(saved)
    if (saved?.id === id && saved.entry === route.query.entry) {
      // Restore the list where the return animation ended.
      const discovery = returnTop === undefined ? saved.discovery : { ...saved.discovery, top: returnTop }
      sessionStorage.setItem('short-playback-feed', JSON.stringify({ ...saved, discovery, returning: true }))
      if (returnSnapshot && returnTop !== undefined) {
        sessionStorage.setItem(RETURN_SNAPSHOT_KEY, JSON.stringify({ ...returnSnapshot, top: returnTop }))
      }
    }
  } catch { /* Returning to the list does not require session storage. */ }
  tintStatusBar('#ffffff')
  location.replace(back)
}

async function load() {
  error.value = ''
  if (!Number.isSafeInteger(id) || id <= 0) {
    error.value = '视频地址无效'
    return
  }
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    if (route.query.entry && saved && (saved.entry !== route.query.entry || saved.returning)) {
      location.replace(listPath(saved))
      return
    }
    if (saved?.id === id && saved.entry === route.query.entry && Array.isArray(saved.items)) {
      const index = saved.items.findIndex((video: ShortVideo) => video.id === id)
      if (index >= 0) {
        initialIndex.value = index
        initialCover.value = typeof saved.cover === 'string' && !saved.cover.startsWith('blob:') ? saved.cover : ''
        items.value = saved.items
        void prepareReturnLayer({ ...saved.returnCovers })
        return
      }
    }
  } catch { /* A direct link does not need a saved discovery feed. */ }
  try {
    const result = await shortVideoApi.show(id)
    items.value = [result.video]
  } catch {
    error.value = '视频加载失败，请重试'
  }
}
function onPageShow(event: PageTransitionEvent) {
  if (!event.persisted) return
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    if (route.query.entry && (saved?.entry !== route.query.entry || saved.returning)) location.replace(listPath(saved))
  } catch { location.replace('/short') }
}
onMounted(() => { window.addEventListener('pageshow', onPageShow); void load() })
onUnmounted(() => { window.removeEventListener('pageshow', onPageShow); returnLayer?.remove() })
</script>

<template>
  <div class="h-full bg-black text-white">
    <ShortVideoPlayer v-if="items.length" :items="items" :initial-index="initialIndex" :initial-cover="initialCover" :return-target="returnTarget" @likes-changed="saveLikes" @close="close" />
    <div v-else class="flex h-full flex-col items-center justify-center gap-4" role="status">
      <span>{{ error || '正在加载视频…' }}</span>
      <button v-if="error" @click="load">重试</button>
      <button @click="close">返回短视频</button>
    </div>
  </div>
</template>
