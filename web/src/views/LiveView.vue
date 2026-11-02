<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { liveApi } from '@/api/live'
import type { LiveCategory, LiveStream } from '@/types/models'

const categories = ref<LiveCategory[]>([])
const streams = ref<LiveStream[]>([])
const selectedCat = ref<number | null>(null)
const loading = ref(false)
const error = ref('')

async function loadCategories() {
  try {
    const res = await liveApi.categories()
    categories.value = res.data ?? (res as any)
  } catch {
    // 分类加载失败不阻塞直播流加载
  }
}

async function loadStreams() {
  loading.value = true
  error.value = ''
  try {
    const res = await liveApi.list({
      category_id: selectedCat.value ?? undefined,
      per_page: 30,
    })
    streams.value = res.data ?? []
  } catch (e: any) {
    error.value = '加载失败，请重试'
    streams.value = []
  } finally {
    loading.value = false
  }
}

function selectCat(id: number | null) {
  selectedCat.value = id
  loadStreams()
}

function openStream(url: string) {
  window.open(url, '_blank')
}

function formatViewers(n: number): string {
  if (n >= 10000) return (n / 10000).toFixed(1) + '万'
  return String(n)
}

onMounted(async () => {
  await loadCategories()
  await loadStreams()
})
</script>

<template>
  <div class="h-full flex flex-col bg-black text-white">
    <!-- Category strip -->
    <div class="flex-none overflow-x-auto scrollbar-hide py-2 px-3 flex gap-2 border-b border-white/10 safe-top">
      <button
        :class="['px-3 py-1 rounded-full text-sm whitespace-nowrap transition-colors', selectedCat === null ? 'bg-primary text-white' : 'bg-white/10 text-gray-300']"
        @click="selectCat(null)"
      >全部</button>
      <button
        v-for="cat in categories"
        :key="cat.id"
        :class="['px-3 py-1 rounded-full text-sm whitespace-nowrap transition-colors', selectedCat === cat.id ? 'bg-primary text-white' : 'bg-white/10 text-gray-300']"
        @click="selectCat(cat.id)"
      >{{ cat.name }}</button>
    </div>

    <!-- Stream grid -->
    <div class="flex-1 overflow-y-auto">
      <div v-if="loading" class="flex items-center justify-center h-40 text-gray-500">加载中…</div>
      <div v-else-if="error" class="flex flex-col items-center justify-center h-40 gap-3 text-gray-500">
        <span>{{ error }}</span>
        <button class="px-4 py-1.5 rounded-full bg-white/10 text-sm" @click="loadStreams">重试</button>
      </div>
      <div v-else-if="!streams.length" class="flex items-center justify-center h-40 text-gray-500">暂无直播</div>
      <div v-else class="grid grid-cols-2 gap-2 p-3">
        <div
          v-for="s in streams"
          :key="s.id"
          class="relative rounded-xl overflow-hidden bg-gray-900 aspect-video cursor-pointer active:opacity-80"
          @click="openStream(s.stream_url)"
        >
          <!-- Cover -->
          <img
            v-if="s.cover"
            :src="s.cover"
            :alt="s.title"
            referrerpolicy="no-referrer"
            class="w-full h-full object-cover"
            loading="lazy"
            @error="(e) => (e.target as HTMLImageElement).style.display = 'none'"
          />
          <div class="w-full h-full bg-gradient-to-br from-gray-800 to-gray-900 flex items-center justify-center absolute inset-0 -z-10">
            <span class="text-3xl">📺</span>
          </div>

          <!-- Platform + LIVE badge -->
          <span class="absolute top-1.5 left-1.5 flex gap-1">
            <span class="bg-red-600 text-white text-[10px] font-bold px-1.5 py-0.5 rounded">LIVE</span>
            <span
              :class="['text-[10px] font-bold px-1.5 py-0.5 rounded text-white', s.platform === 'douyu' ? 'bg-orange-500' : 'bg-blue-500']"
            >{{ s.platform === 'douyu' ? '斗鱼' : 'B站' }}</span>
          </span>

          <!-- Viewers -->
          <span class="absolute top-1.5 right-1.5 bg-black/60 text-white text-[10px] px-1.5 py-0.5 rounded flex items-center gap-0.5">
            👁 {{ formatViewers(s.viewers) }}
          </span>

          <!-- Title + category -->
          <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent px-2 py-2">
            <p class="text-xs font-semibold line-clamp-1 leading-tight">{{ s.title }}</p>
            <p class="text-[10px] text-gray-400 mt-0.5">{{ s.category?.name }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
