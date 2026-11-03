<script setup lang="ts">
import { computed, onMounted, onUnmounted, inject, watch, ref, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { categoryApi } from '@/api/category'
import { shortVideoApi } from '@/api/shortVideo'
import { videoApi } from '@/api/video'
import type { Category, ShortCategory, ShortVideo, Video } from '@/types/models'
import CategoryStrip from './CategoryStrip.vue'
import VideoCard from './VideoCard.vue'
import ShortVideoPlayer from './ShortVideoPlayer.vue'

const props = defineProps<{ kind: 'short' | 'long' }>()
const router = useRouter()
const navigationHidden = inject<Ref<boolean>>('mainNavigationHidden', ref(false))
const categories = ref<(Category | ShortCategory)[]>([])
const selected = ref<number | null>(null)
const videos = ref<(ShortVideo | Video)[]>([])
const query = ref('')
const searching = ref(false)
const loading = ref(false)
const error = ref('')
const playerItems = ref<ShortVideo[]>([])
const playerIndex = ref<number | null>(null)
watch(playerIndex, index => { navigationHidden.value = index !== null }, { flush: 'sync' })
onUnmounted(() => { generation++; navigationHidden.value = false })
const page = ref(1)
const more = ref(false)
let generation = 0
const visible = computed(() => videos.value.filter(v => `${v.title} ${v.user?.name ?? ''} ${v.user?.username ?? ''}`.toLowerCase().includes(query.value.trim().toLowerCase())))
async function load(append = false) {
  const request = ++generation
  loading.value = true
  error.value = ''
  if (!append) videos.value = []
  const nextPage = append ? page.value + 1 : 1
  try {
    const params = { category_id: selected.value ?? undefined, page: nextPage, per_page: 30 }
    const result = props.kind === 'short' ? await shortVideoApi.list(params) : await videoApi.list({ ...params, type: 'long' })
    if (request !== generation) return
    const items = result.data ?? []
    videos.value = append ? [...videos.value, ...items] : items
    page.value = nextPage
    more.value = result.meta ? nextPage < result.meta.last_page : result.total !== undefined ? videos.value.length < result.total : items.length === 30
  } catch {
    if (request === generation) error.value = '视频加载失败，请重试'
  } finally {
    if (request === generation) loading.value = false
  }
}
function open(video: ShortVideo | Video) {
  if (props.kind === 'long') return router.push({ name: 'video-detail', params: { id: video.id } })
  playerItems.value = visible.value as ShortVideo[]
  playerIndex.value = visible.value.findIndex(v => v.id === video.id)
}
onMounted(() => {
  void load()
  const request = props.kind === 'short' ? shortVideoApi.categories() : categoryApi.list('long')
  void request.then(result => { categories.value = Array.isArray(result) ? result : result.data ?? [] }).catch(() => {})
})
</script>

<template>
  <section class="discovery-page" :aria-label="kind === 'short' ? '短视频发现' : '长视频发现'">
    <div class="discovery-browse" :inert="playerIndex !== null || undefined">
    <header class="discovery-header">
      <button class="feed-icon" aria-label="打开我的页面" @click="router.push('/me')"><svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" /></svg></button>
      <nav class="feed-tabs" aria-label="视频频道">
        <button :class="{ selected: kind === 'short' }" :aria-current="kind === 'short' ? 'page' : undefined" @click="router.replace('/short')">发现</button>
        <button :class="{ selected: kind === 'long' }" :aria-current="kind === 'long' ? 'page' : undefined" @click="router.replace('/long')">长视频</button>
      </nav>
      <button class="feed-icon" :aria-expanded="searching" aria-label="搜索视频" @click="searching = !searching"><svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="7.5" /><path d="m16 16 5 5" /></svg></button>
    </header>
    <form v-if="searching" class="feed-search" role="search" @submit.prevent>
      <input v-model="query" aria-label="搜索视频标题或作者" placeholder="搜索视频、作者" type="search" />
      <button type="button" @click="query = ''; searching = false">取消</button>
    </form>
    <CategoryStrip :categories="categories" v-model="selected" @update:model-value="load()" />
    <div class="discovery-scroll">
      <div v-if="loading && !videos.length" class="video-grid" aria-busy="true" aria-label="正在加载视频"><div v-for="n in 6" :key="n" class="video-skeleton" /></div>
      <div v-else class="video-grid" :class="{ 'long-grid': kind === 'long' }">
        <VideoCard v-for="video in visible" :key="video.id" :video="video" :layout="kind === 'long' ? 'horizontal' : 'vertical'" @click="open(video)" />
      </div>
      <div v-if="error" class="feed-empty" role="alert"><p>{{ error }}</p><button @click="load()">重新加载</button></div>
      <div v-else-if="!loading && !visible.length" class="feed-empty"><p>{{ query ? '没有找到匹配的视频' : '这里还没有视频' }}</p><span>{{ query ? '试试其他标题或作者' : '换个分类看看，或稍后再来' }}</span></div>
      <button v-if="more && !error && !query" class="feed-more" :disabled="loading" @click="load(true)">{{ loading ? '加载中…' : '加载更多' }}</button>
    </div>
    </div>
    <div v-if="playerIndex !== null" class="player-layer" role="dialog" aria-modal="true" aria-label="短视频播放器" @keydown.esc="playerIndex = null">
      <ShortVideoPlayer :items="playerItems" :initial-index="playerIndex" @close="playerIndex = null" />
    </div>
  </section>
</template>
