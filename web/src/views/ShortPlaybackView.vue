<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import ShortVideoPlayer from '@/components/ShortVideoPlayer.vue'
import { shortVideoApi } from '@/api/shortVideo'
import type { ShortVideo } from '@/types/models'

const route = useRoute()
const items = ref<ShortVideo[]>([])
const initialIndex = ref(0)
const error = ref('')
const id = Number(route.query.id)

function close() {
  try {
    const saved = JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null')
    if (saved?.id === id) sessionStorage.setItem('short-playback-feed', JSON.stringify({ ...saved, returning: true }))
  } catch { /* Returning to the list does not require session storage. */ }
  location.replace('/short')
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
      location.replace('/short')
      return
    }
    if (saved?.id === id && Date.now() - saved.created < 300000 && Array.isArray(saved.items)) {
      const index = saved.items.findIndex((video: ShortVideo) => video.id === id)
      if (index >= 0) {
        initialIndex.value = index
        items.value = saved.items
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
    if (route.query.entry && (saved?.entry !== route.query.entry || saved.returning)) location.replace('/short')
  } catch { location.replace('/short') }
}
onMounted(() => { window.addEventListener('pageshow', onPageShow); void load() })
onUnmounted(() => window.removeEventListener('pageshow', onPageShow))
</script>

<template>
  <div class="h-full bg-black text-white">
    <ShortVideoPlayer v-if="items.length" :items="items" :initial-index="initialIndex" @close="close" />
    <div v-else class="flex h-full flex-col items-center justify-center gap-4" role="status">
      <span>{{ error || '正在加载视频…' }}</span>
      <button v-if="error" @click="load">重试</button>
      <button @click="close">返回短视频</button>
    </div>
  </div>
</template>
