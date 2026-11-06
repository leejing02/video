<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, inject, watch, ref, type Ref, type ObjectDirective } from 'vue'
import { useRouter } from 'vue-router'
import { categoryApi } from '@/api/category'
import { shortVideoApi } from '@/api/shortVideo'
import { videoApi } from '@/api/video'
import { mediaUrl } from '@/utils/media-url'
import { portablePlaybackCover } from '@/utils/playback-cover'
import { captureReturnSnapshot, removeReturnReplica, RETURN_SNAPSHOT_KEY } from '@/utils/playback-return'
import { loadShortFeedFirstPage, warmShortFeed } from '@/utils/short-feed-prefetch'
import type { ShortVideo, Video } from '@/types/models'
import CategoryStrip from './CategoryStrip.vue'
import VideoCard from './VideoCard.vue'
import ShortVideoPlayer from './ShortVideoPlayer.vue'

// Each tile spans enough small grid rows for its measured height and gutter.
const masonryObserver = new ResizeObserver(entries => {
  for (const entry of entries) {
    const tile = entry.target as HTMLElement
    tile.style.gridRowEnd = 'span ' + Math.ceil((tile.offsetHeight + 6) / 2)
  }
})
const vMasonryTile: ObjectDirective<HTMLElement, boolean> = {
  mounted(el, { value }) {
    if (value) masonryObserver.observe(el)
  },
  beforeUnmount(el) {
    masonryObserver.unobserve(el)
  },
}
onUnmounted(() => masonryObserver.disconnect())

const props = defineProps<{ kind: 'short' | 'long' }>()
// Temporary test: display short videos in the long-video tab without copying database rows.
const previewShortsInLongTab = true
// Temporarily clear the discovery display without deleting the source videos.
const hideDiscoveryVideos = true
const router = useRouter()
const navigationHidden = inject<Ref<boolean>>('mainNavigationHidden', ref(false))
const shortCategories = [
  { id: 8, name: '国产', cover: '/category-icons/domestic.jpg' },
  { id: 9, name: '精选', cover: '/category-icons/selected.jpg' },
  { id: 11, name: '抖舞', cover: '/category-icons/dance.jpg' },
  { id: 10, name: '猎奇', cover: '/category-icons/curiosity.jpg' },
  { id: 99, name: '99', cover: null },
]
const categories = ref<{ id: number; name: string; cover: string | null }[]>(shortCategories)
let longCategoryRequest: ReturnType<typeof categoryApi.list> | undefined
function loadLongCategories() {
  if (!longCategoryRequest) {
    longCategoryRequest = categoryApi.list('long').catch(error => {
      longCategoryRequest = undefined
      throw error
    })
  }
  return longCategoryRequest
}
const selected = ref<number | null>(null)
const displayedCategory = ref<number | null>(null)
const slideDirection = ref(0)
const dragDirection = ref(0)
const dragOffset = ref(0)
const releaseOffset = ref(0)
const previewReady = computed(() => !!staged.value && !pendingEntryCovers.value.size)
const previewing = computed(() => dragDirection.value !== 0 && previewReady.value)
const categoryOrder = computed(() => [null, ...categories.value.map(category => category.id)])
let categoryTouch: { x: number; y: number; id: number } | undefined

function startCategorySwipe(event: TouchEvent) {
  categoryTouch = undefined
  if (loading.value || playerIndex.value !== null || event.touches.length !== 1) return
  const touch = event.touches[0]
  if (touch.clientX < 24 || touch.clientX > window.innerWidth - 24) return
  categoryTouch = { x: touch.clientX, y: touch.clientY, id: touch.identifier }
}

function cancelCategorySwipe() {
  categoryTouch = undefined
  if (!dragDirection.value) return
  dragOffset.value = 0
  const request = ++generation
  selected.value = displayedCategory.value
  clearTimeout(entryCoverTimeout)
  setTimeout(() => {
    if (request !== generation) return
    dragDirection.value = 0
    staged.value = undefined
    pendingEntryCovers.value.clear()
    loading.value = false
    error.value = ''
  }, 180)
}

function trackCategorySwipe(event: TouchEvent) {
  const start = categoryTouch
  if (!start) return
  const touch = event.touches[0]
  if (event.touches.length !== 1 || touch.identifier !== start.id) {
    cancelCategorySwipe()
    return
  }
  const dx = touch.clientX - start.x
  const dy = Math.abs(touch.clientY - start.y)
  if (!dragDirection.value) {
    if (dy > 12 && dy >= Math.abs(dx)) {
      cancelCategorySwipe()
      return
    }
    if (Math.abs(dx) < 10 || Math.abs(dx) < dy * 1.5) return
    const direction = dx < 0 ? 1 : -1
    const category = categoryOrder.value[categoryOrder.value.indexOf(displayedCategory.value) + direction]
    if (category === undefined) return
    dragDirection.value = direction
    returnCovers.value = {}
    returnRatios.value = {}
    selected.value = category
    staged.value = { key: ++generation, items: [], coverIds: new Set(), more: false }
  }
  const width = scrollArea.value?.clientWidth ?? window.innerWidth
  dragOffset.value = dragDirection.value * Math.min(width, Math.max(0, -dx * dragDirection.value))
}

function endCategorySwipe(event: TouchEvent) {
  const start = categoryTouch
  categoryTouch = undefined
  if (!start || !dragDirection.value) return
  const touch = Array.from(event.changedTouches).find(item => item.identifier === start.id)
  if (!touch || (start.x - touch.clientX) * dragDirection.value < 55) {
    cancelCategorySwipe()
    return
  }
  releaseOffset.value = previewing.value ? dragOffset.value : 0
  dragDirection.value = 0
  dragOffset.value = 0
  if (staged.value && !pendingEntryCovers.value.size) {
    showStagedGrid()
    void load()
  }
}
const videos = ref<(ShortVideo | Video)[]>([])
const eagerCoverIds = ref(new Set<number>())
const pendingEntryCovers = ref(new Set<number>())
const displayedGrid = ref(0)
const retryAppend = ref(false)
const staged = ref<{ key: number; items: (ShortVideo | Video)[]; coverIds: Set<number>; more: boolean }>()
let disposed = false
let entryCoverTimeout: ReturnType<typeof setTimeout> | undefined
const query = ref('')
const searching = ref(false)
const loading = ref(false)
const error = ref('')
const playerItems = ref<ShortVideo[]>([])
const playerIndex = ref<number | null>(null)
const playerCover = ref('')
watch(playerIndex, index => { navigationHidden.value = index !== null }, { flush: 'sync' })
onUnmounted(() => {
  disposed = true
  generation++
  clearTimeout(entryCoverTimeout)
  navigationHidden.value = false
})
const page = ref(1)
const more = ref(false)
const scrollArea = ref<HTMLElement | null>(null)
const nextPageMarker = ref<HTMLElement | null>(null)
const autoLoad = typeof IntersectionObserver !== 'undefined'
watch(nextPageMarker, (marker, _, onCleanup) => {
  if (!marker) return
  const observer = new IntersectionObserver(entries => {
    if (nextPageMarker.value === marker && !loading.value && !error.value && entries.some(entry => entry.isIntersecting)) {
      void load(true)
    }
  }, { root: scrollArea.value, rootMargin: '0px 0px 1200px' })
  observer.observe(marker)
  onCleanup(() => observer.disconnect())
}, { flush: 'post' })
let generation = 0
function filterVideos(items: (ShortVideo | Video)[]) {
  const search = query.value.trim().toLowerCase()
  return items.filter(v => `${v.title} ${v.user?.name ?? ''} ${v.user?.username ?? ''}`.toLowerCase().includes(search))
}
const visible = computed(() => filterVideos(videos.value))
const grids = computed(() => {
  const current = { key: displayedGrid.value, items: visible.value, coverIds: eagerCoverIds.value, hidden: false }
  const incoming = staged.value
  if (!incoming) return [current]
  return [current, { ...incoming, items: filterVideos(incoming.items), hidden: true }]
})
function showStagedGrid() {
  const incoming = staged.value
  if (!incoming || incoming.key !== generation || dragDirection.value) return
  clearTimeout(entryCoverTimeout)
  videos.value = incoming.items
  eagerCoverIds.value = incoming.coverIds
  slideDirection.value = displayedGrid.value !== 0 || !incoming.items.length
    ? Math.sign(categoryOrder.value.indexOf(selected.value) - categoryOrder.value.indexOf(displayedCategory.value))
    : 0
  displayedGrid.value = incoming.key
  displayedCategory.value = selected.value
  page.value = 1
  more.value = incoming.more
  staged.value = undefined
  pendingEntryCovers.value.clear()
  loading.value = false
  void nextTick(() => {
    if (displayedGrid.value === incoming.key && scrollArea.value) scrollArea.value.scrollTop = 0
  })
}
function coverSettled(grid: number, id: number) {
  if (staged.value?.key !== grid) return
  pendingEntryCovers.value.delete(id)
  if (!pendingEntryCovers.value.size) {
    clearTimeout(entryCoverTimeout)
    // Let the hidden grid finish its masonry measurements before it becomes visible.
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (staged.value?.key === grid) showStagedGrid()
    }))
  }
}
async function load(append = false) {
  const request = ++generation
  if (props.kind === 'short' && hideDiscoveryVideos) {
    staged.value = { key: request, items: [], coverIds: new Set(), more: false }
    pendingEntryCovers.value.clear()
    error.value = ''
    showStagedGrid()
    return
  }
  retryAppend.value = props.kind === "short" && append
  loading.value = true
  error.value = ''
  if (!append) {
    staged.value = undefined
    pendingEntryCovers.value.clear()
    clearTimeout(entryCoverTimeout)
    if (props.kind === 'long') {
      videos.value = []
      more.value = false
    }
  }
  const nextPage = append ? page.value + 1 : 1
  try {
    let categoryId = selected.value ?? undefined
    if (props.kind === 'long' && !previewShortsInLongTab && categoryId !== undefined) {
      const selectedName = shortCategories.find(category => category.id === categoryId)?.name
      const response = await loadLongCategories()
      if (request !== generation) return
      const longCategories = Array.isArray(response) ? response : response.data ?? []
      const matchedCategory = longCategories.find(category => category.name === selectedName)
      if (!matchedCategory) {
        videos.value = []
        page.value = 1
        more.value = false
        return
      }
      categoryId = matchedCategory.id
    }
    const params = { category_id: categoryId, page: nextPage, per_page: 30 }
    const result = await (props.kind === 'long' && !previewShortsInLongTab
      ? videoApi.list({ ...params, type: 'long' })
      : !append
        ? loadShortFeedFirstPage(selected.value)
        : shortVideoApi.list(params))
    if (request !== generation) return
    const items = result.data ?? []
    const count = items.length + (append ? videos.value.length : 0)
    const hasMore = result.meta ? nextPage < result.meta.last_page : result.total !== undefined ? count < result.total : items.length === 30
    if (props.kind === 'short') {
      const coverIds = new Set(items.filter(video => mediaUrl(video.cover)).slice(0, 6).map(video => video.id))
      if (!append) {
        staged.value = { key: request, items, coverIds, more: hasMore }
        pendingEntryCovers.value = new Set(filterVideos(items).filter(video => coverIds.has(video.id)).map(video => video.id))
        if (pendingEntryCovers.value.size) {
          entryCoverTimeout = setTimeout(() => {
            if (!visible.value.length) {
              showStagedGrid()
              return
            }
            staged.value = undefined
            pendingEntryCovers.value.clear()
            loading.value = false
            dragDirection.value = 0
            dragOffset.value = 0
            releaseOffset.value = 0
            error.value = '分类封面加载超时，请重试'
          }, 5000)
        } else showStagedGrid()
        return
      }
      for (const id of coverIds) eagerCoverIds.value.add(id)
    }
    videos.value = append ? [...videos.value, ...items] : items
    page.value = nextPage
    more.value = hasMore
  } catch {
    if (request === generation) {
      dragDirection.value = 0
      dragOffset.value = 0
      releaseOffset.value = 0
      error.value = '视频加载失败，请重试'
    }
  } finally {
    if (request === generation && !staged.value) loading.value = false
  }
}
function selectCategory(category: number | null) {
  if (dragDirection.value) return
  if (category === selected.value && !error.value) return
  releaseOffset.value = 0
  returnCovers.value = {}
  returnRatios.value = {}
  selected.value = category
  if (props.kind === 'long') {
    staged.value = { key: ++generation, items: [], coverIds: new Set(), more: false }
    showStagedGrid()
  }
  void load()
}
let playbackReturnPoster: HTMLImageElement | null = null
function playbackReturnTarget() {
  return playbackReturnPoster?.isConnected ? playbackReturnPoster.getBoundingClientRect() : undefined
}
function closePlayer() {
  playbackReturnPoster = null
  playerIndex.value = null
}
const returnCovers = ref<Record<number, string>>({})
const returnRatios = ref<Record<number, string>>({})
let openingPlayback = false
async function open(video: ShortVideo | Video, event: MouseEvent) {
  if (props.kind === 'long') {
    if (!previewShortsInLongTab) return router.push({ name: 'video-detail', params: { id: video.id } })
    const poster = (event.currentTarget as HTMLElement).querySelector<HTMLImageElement>('.video-poster img')
    playbackReturnPoster = poster
    playerCover.value = poster?.naturalWidth ? poster.currentSrc : ''
    playerItems.value = visible.value as ShortVideo[]
    playerIndex.value = visible.value.findIndex(v => v.id === video.id)
    return
  }
  if (openingPlayback) return
  const poster = (event.currentTarget as HTMLElement).querySelector<HTMLImageElement>('.video-poster img')
  if (/iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
    openingPlayback = true
    const entry = crypto.randomUUID()
    const viewportTop = scrollArea.value?.getBoundingClientRect().top ?? 0
    const loaded = [...(scrollArea.value?.querySelectorAll<HTMLImageElement>('.video-grid:not(.invisible) img[data-video-cover]') ?? [])]
      .filter(image => image.naturalWidth && image.naturalHeight)
    const ratios = Object.fromEntries(loaded.map(image => [
      image.dataset.videoCover, `${image.naturalWidth} / ${image.naturalHeight}`,
    ]))
    const nearby = loaded.sort((a, b) =>
      Math.abs(a.getBoundingClientRect().top - viewportTop) - Math.abs(b.getBoundingClientRect().top - viewportTop),
    ).slice(0, 6)
    const snapshot = {
      id: video.id, entry, items: visible.value, created: Date.now(),
      discovery: { ratios, searching: searching.value, categories: categories.value, items: videos.value, category: displayedCategory.value, page: page.value, more: more.value, query: query.value, top: scrollArea.value?.scrollTop ?? 0 },
    }
    // A static copy of this screen lets the player shrink back into its card and
    // index.html repaint the list before Vue starts (no black or white flash).
    const app = document.getElementById('app')
    const returnSnapshot = app ? captureReturnSnapshot(app, entry, ratios as Record<string, string>) : null
    const [cover, nearbyCovers] = await Promise.all([
      portablePlaybackCover(poster),
      Promise.all(nearby.map(async image => [image.dataset.videoCover, await portablePlaybackCover(image)])),
    ])
    if (disposed) return
    try {
      // The initial HTML can paint these bytes before loading the Vue bundle.
      try {
        sessionStorage.setItem('short-playback-feed', JSON.stringify({
          ...snapshot, cover, returnCovers: Object.fromEntries(nearbyCovers.filter(([, source]) => source)),
        }))
      } catch {
        sessionStorage.setItem('short-playback-feed', JSON.stringify({ ...snapshot, cover }))
      }
      try {
        if (returnSnapshot) sessionStorage.setItem(RETURN_SNAPSHOT_KEY, JSON.stringify(returnSnapshot))
        else sessionStorage.removeItem(RETURN_SNAPSHOT_KEY)
      } catch {
        sessionStorage.removeItem(RETURN_SNAPSHOT_KEY)
      }
      window.location.replace(`/player.html?id=${video.id}&entry=${entry}`)
      return
    } catch {
      // Restricted/full storage must not open a document with a lost cover or return position.
      openingPlayback = false
    }
  }
  playbackReturnPoster = poster
  playerCover.value = poster?.naturalWidth ? poster.currentSrc : ''
  playerItems.value = visible.value as ShortVideo[]
  playerIndex.value = visible.value.findIndex(v => v.id === video.id)
}
function applyPlaybackLikes(counts: Record<number, number> | undefined) {
  if (!counts) return
  for (const video of videos.value) {
    const count = counts[video.id]
    if (typeof count === 'number') video.likes = count
  }
}

function restorePlaybackList() {
  if (props.kind !== 'short') return false
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    if (!saved?.returning) return false
    sessionStorage.removeItem('short-playback-feed')
    const state = saved.discovery
    if (!Array.isArray(state?.items) || !state.items.length) return false
    if (state.category != null && !shortCategories.some(category => category.id === state.category)) return false
    returnCovers.value = saved.returnCovers ?? {}
    returnRatios.value = state.ratios ?? {}
    searching.value = !!state.searching
    videos.value = state.items
    applyPlaybackLikes(saved.updatedLikes)
    selected.value = displayedCategory.value = state.category ?? null
    page.value = Math.max(1, Number(state.page) || 1)
    more.value = !!state.more
    query.value = typeof state.query === 'string' ? state.query : ''
    displayedGrid.value = ++generation
    // Hold the remembered scroll position until cover sizes have been measured.
    const top = Math.max(0, Number(state.top) || 0)
    let pending = true
    const restoreScroll = () => {
      if (!pending || disposed || !scrollArea.value) return
      scrollArea.value.scrollTop = top
    }
    void nextTick(() => {
      restoreScroll()
      void hideReturnReplica()
      scrollArea.value?.addEventListener('load', restoreScroll, { capture: true })
      const stop = () => {
        pending = false
        scrollArea.value?.removeEventListener('load', restoreScroll, true)
      }
      scrollArea.value?.addEventListener('touchstart', stop, { once: true, passive: true })
      setTimeout(stop, 5000)
    })
    return true
  } catch { return false }
}
// index.html paints the captured list on return; drop it once the real list shows
// the same screen (visible covers decoded), or after a short limit.
async function hideReturnReplica() {
  sessionStorage.removeItem(RETURN_SNAPSHOT_KEY)
  const until = performance.now() + 1500
  while (!disposed && performance.now() < until) {
    await new Promise(resolve => requestAnimationFrame(resolve))
    const view = scrollArea.value?.getBoundingClientRect()
    const pending = view && [...scrollArea.value!.querySelectorAll<HTMLElement>('.video-grid:not(.invisible) .video-tile.invisible')]
      .some(tile => { const r = tile.getBoundingClientRect(); return r.bottom > view.top && r.top < view.bottom })
    if (view && !pending) break
  }
  await new Promise(resolve => requestAnimationFrame(resolve))
  removeReturnReplica()
}
onMounted(() => {
  const restored = !(props.kind === 'short' && hideDiscoveryVideos) && restorePlaybackList()
  if (!restored) removeReturnReplica()
  if (!videos.value.length) void load()
  if (props.kind === 'short') {
    if (restored || hideDiscoveryVideos) return
    void (async () => {
      for (let index = 0; index < shortCategories.length && !disposed && !document.hidden; index += 2) {
        await Promise.all(shortCategories.slice(index, index + 2).map(category => warmShortFeed(category.id)))
      }
    })()
    return
  }
  if (!previewShortsInLongTab) void loadLongCategories().catch(() => {})
})
</script>

<template>
  <section class="discovery-page" :aria-label="kind === 'short' ? '短视频发现' : '长视频发现'">
    <div class="discovery-browse" :inert="playerIndex !== null || undefined">
    <header class="discovery-header">
      <button class="feed-icon" aria-label="打开我的页面" @click="router.push('/me')"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" /></svg></button>
      <nav class="feed-tabs" aria-label="视频频道">
        <button class="selected" aria-current="page">短视频</button>
      </nav>
      <button class="feed-icon" :aria-expanded="searching" aria-label="搜索视频" @click="searching = !searching"><svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="7.5" /><path d="m16 16 5 5" /></svg></button>
    </header>
    <form v-if="searching" class="feed-search" role="search" @submit.prevent>
      <input v-model="query" aria-label="搜索视频标题或作者" placeholder="搜索视频、作者" type="search" />
      <button type="button" @click="query = ''; searching = false">取消</button>
    </form>
    <CategoryStrip :categories="categories" :model-value="kind === 'short' ? displayedCategory : selected" @update:model-value="selectCategory" />
    <div ref="scrollArea" class="discovery-scroll touch-pan-y overflow-x-hidden"
      @touchstart.passive="startCategorySwipe" @touchmove.passive="trackCategorySwipe"
      @touchend.passive="endCategorySwipe" @touchcancel.passive="cancelCategorySwipe">
      <TransitionGroup tag="div" name="category-slide" class="relative category-track" :class="{ 'category-preview': previewing, 'category-returning': previewing && dragOffset === 0 }"
        :style="{ '--category-slide-direction': slideDirection, '--category-release': releaseOffset + 'px', '--category-drag': dragOffset + 'px', '--category-preview-direction': dragDirection }">
        <div v-for="grid in grids" :key="grid.key" class="video-grid" :aria-busy="loading" :inert="grid.hidden || undefined" :class="[kind === 'short' ? 'short-masonry' : 'long-grid', { 'absolute inset-x-0 top-0 h-0 overflow-hidden invisible': grid.hidden, 'category-slide-current': !grid.hidden && slideDirection !== 0, 'category-preview-incoming': grid.hidden && previewing }]">
          <VideoCard v-for="video in grid.items" v-masonry-tile="kind === 'short'" :key="video.id" :video="video" :hide-pending-cover="kind === 'short'" :eager-cover="grid.coverIds.has(video.id) || !!returnCovers[video.id]" :cached-cover="kind === 'short' ? returnCovers[video.id] : undefined" :cover-ratio="returnRatios[video.id]" :layout="kind === 'long' ? 'horizontal' : 'vertical'" @cover-settled="coverSettled(grid.key, video.id)" @click="open(video, $event)" />
          <template v-if="!grid.items.length && ((kind === 'long' && (!query || error || loading)) || (kind === 'short' && (grid.hidden || loading)))"><div v-for="n in 4" :key="'placeholder-' + n" class="video-skeleton" /></template>
        </div>
      </TransitionGroup>
      <div v-if="error && kind === 'short'" class="feed-empty" role="alert"><p>{{ error }}</p><button @click="load(retryAppend)">重新加载</button></div>
      <div v-else-if="!loading && !visible.length && (kind === 'short' || (query && !error))" class="feed-empty"><p>{{ query ? '没有找到匹配的视频' : '这里还没有视频' }}</p><span>{{ query ? '试试其他标题或作者' : '换个分类看看，或稍后再来' }}</span></div>
      <div v-if="(kind === 'short' || (kind === 'long' && previewShortsInLongTab)) && autoLoad && more && !error && !query && playerIndex === null">
        <p v-if="loading" class="sr-only" role="status">加载中…</p>
        <div v-else ref="nextPageMarker" class="h-px" aria-hidden="true" />
      </div>
      <button v-else-if="((kind === 'long' && !previewShortsInLongTab) || !autoLoad) && more && !error && !query" class="feed-more" :disabled="loading" @click="load(true)">{{ loading ? '加载中…' : '加载更多' }}</button>
    </div>
    </div>
    <Teleport to="body">
      <div v-if="playerIndex !== null" class="player-layer" role="dialog" aria-modal="true" aria-label="短视频播放器" @keydown.esc="closePlayer">
        <ShortVideoPlayer :items="playerItems" :initial-index="playerIndex" :initial-cover="playerCover" :return-target="playbackReturnTarget" @close="closePlayer" />
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.category-track {
  --category-gap: 4px;
}
@media (min-width: 700px) {
  .category-track { --category-gap: 8px; }
}
.category-track .short-masonry { column-gap: var(--category-gap); }

.category-preview .video-grid:not(.category-preview-incoming) {
  animation: none;
  transform: translateX(calc(-1 * var(--category-drag)));
}
.category-preview .category-preview-incoming {
  height: auto;
  visibility: visible;
  transform: translateX(calc(var(--category-preview-direction) * (100% + var(--category-gap)) - var(--category-drag)));
}
.category-returning .video-grid { transition: transform 180ms ease-out; }

.category-slide-current {
  animation: category-slide-in 220ms ease-out both;
}

.category-slide-leave-active.category-slide-current,
.category-slide-leave-active:not(.invisible) {
  position: absolute;
  inset: 0 0 auto;
  pointer-events: none;
  animation: category-slide-out 220ms ease-out both;
}

@keyframes category-slide-in {
  from { transform: translateX(calc(var(--category-slide-direction) * (100% + var(--category-gap)) - var(--category-release))); }
  to { transform: translateX(0); }
}

@keyframes category-slide-out {
  from { transform: translateX(calc(-1 * var(--category-release))); }
  to { transform: translateX(calc(-1 * var(--category-slide-direction) * (100% + var(--category-gap)))); }
}
</style>
