<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { categoryApi } from '@/api/category'
import { videoApi } from '@/api/video'
import { useRouter } from 'vue-router'
import CategoryStrip from '@/components/CategoryStrip.vue'
import VideoCard from '@/components/VideoCard.vue'
import type { Category, Video } from '@/types/models'

const router = useRouter()
const categories = ref<Category[]>([])
const selectedCat = ref<number | null>(null)
const videos = ref<Video[]>([])
const loading = ref(false)

async function loadCategories() {
  const res = await categoryApi.list('long')
  categories.value = res.data ?? (res as any)
}

async function loadVideos() {
  loading.value = true
  try {
    const res = await videoApi.list({ type: 'long', category_id: selectedCat.value ?? undefined, per_page: 30 })
    videos.value = res.data ?? (res as any)
  } finally {
    loading.value = false
  }
}

onMounted(async () => { await loadCategories(); await loadVideos() })
</script>

<template>
  <div class="flex flex-col h-full">
    <header class="px-4 py-3 border-b border-gray-800 safe-top">
      <h1 class="text-lg font-semibold">长视频</h1>
    </header>
    <CategoryStrip :categories="categories" v-model="selectedCat" @update:model-value="loadVideos" />
    <div class="flex-1 overflow-y-auto px-3 py-2 space-y-3">
      <div v-if="loading && !videos.length" class="text-center text-gray-500 mt-10">加载中…</div>
      <div v-else-if="!videos.length" class="text-center text-gray-500 mt-10">暂无视频</div>
      <VideoCard
        v-for="v in videos"
        :key="v.id"
        :video="v"
        layout="horizontal"
        class="cursor-pointer"
        @click="router.push({ name: 'video-detail', params: { id: v.id } })"
      />
    </div>
  </div>
</template>
