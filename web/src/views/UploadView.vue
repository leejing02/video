<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { categoryApi } from '@/api/category'
import type { Category } from '@/types/models'

const router = useRouter()
const categories = ref<Category[]>([])
const selectedSlug = ref('')
const videoType = ref<'long' | 'short'>('long')

async function loadCategories() {
  const res = await categoryApi.list(videoType.value)
  categories.value = res.data ?? (res as any)
  if (categories.value.length && !selectedSlug.value) {
    selectedSlug.value = categories.value[0].slug
  }
}

onMounted(loadCategories)
</script>

<template>
  <div class="flex flex-col h-full overflow-y-auto">
    <header class="px-3 py-3 border-b border-gray-200 safe-top flex items-center gap-3">
      <button class="text-2xl" @click="router.back()">‹</button>
      <h1 class="text-lg font-semibold flex-1">上传视频</h1>
    </header>

    <div class="p-4 space-y-5">
      <div>
        <p class="text-sm text-gray-600 mb-2">视频类型</p>
        <div class="grid grid-cols-2 gap-2">
          <button
            :class="['py-2.5 rounded-xl border', videoType === 'long' ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-600']"
            @click="videoType = 'long'; loadCategories()"
          >长视频</button>
          <button
            :class="['py-2.5 rounded-xl border', videoType === 'short' ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-600']"
            @click="videoType = 'short'; loadCategories()"
          >短视频</button>
        </div>
      </div>

      <div>
        <p class="text-sm text-gray-600 mb-2">分类</p>
        <div class="flex gap-2 overflow-x-auto">
          <button
            v-for="cat in categories"
            :key="cat.id"
            :class="['px-3 py-1.5 rounded-full border text-sm whitespace-nowrap', selectedSlug === cat.slug ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 text-gray-600']"
            @click="selectedSlug = cat.slug"
          >{{ cat.name }}</button>
        </div>
      </div>

      <!-- Upload area placeholder -->
      <div class="border-2 border-dashed border-gray-200 rounded-2xl p-8 text-center">
        <p class="text-4xl mb-3">📹</p>
        <p class="text-sm text-gray-600">上传功能需要配置后端存储</p>
        <p class="text-xs text-gray-500 mt-1">支持 MP4 / MOV / MKV / FLV 等</p>
      </div>

      <div class="text-xs text-gray-500 leading-relaxed bg-gray-50 rounded-xl p-3 mt-4">
        <p class="font-medium text-gray-600 mb-1">注意事项</p>
        <ul class="list-disc list-inside space-y-1">
          <li>提交后视频默认进入审核队列，管理员审核通过后才能被其他人看到</li>
          <li>禁止上传违法 / 色情 / 暴力 / 政治敏感 / 商业广告内容</li>
        </ul>
      </div>
    </div>
  </div>
</template>
