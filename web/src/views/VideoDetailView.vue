<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { mediaUrl } from '@/utils/media-url'
import { videoApi } from '@/api/video'
import type { Video, Comment } from '@/types/models'

const route = useRoute()
const router = useRouter()
const id = Number(route.params.id)
const video = ref<Video | null>(null)
const comments = ref<Comment[]>([])
const commentText = ref('')
const commentsEnabled = ref(false)

async function load() {
  video.value = await videoApi.show(id)
  const res = await videoApi.comments(id, 1)
  comments.value = res.data ?? (res as any)
}

async function like() {
  if (!video.value) return
  const res = await videoApi.like(video.value.id)
  video.value.likes = (res as any).likes ?? video.value.likes
}

async function sendComment() {
  if (!commentsEnabled.value) return
  const text = commentText.value.trim()
  if (!text || !video.value) return
  try {
    const c = await videoApi.createComment(video.value.id, text)
    comments.value.unshift(c)
    commentText.value = ''
  } catch (e: any) {
    alert(e.response?.data?.error || '发送失败')
  }
}

onMounted(() => {
  void load()
  void fetch('/api/video-comments/settings', { cache: 'no-store' })
    .then(response => response.ok ? response.json() : null)
    .then(data => { commentsEnabled.value = data?.enabled === true })
    .catch(() => { commentsEnabled.value = false })
})
</script>

<template>
  <div v-if="video" class="flex flex-col h-full">
    <header class="flex-none px-3 py-3 border-b border-gray-200 safe-top flex items-center gap-3">
      <button class="text-2xl" @click="router.back()">‹</button>
      <h1 class="text-base font-semibold flex-1 truncate">{{ video.title }}</h1>
    </header>

    <!-- 整体可滚动区域：视频 + 信息 + 评论 -->
    <div class="flex-1 overflow-y-auto">
      <video :poster="mediaUrl(video.cover) || undefined" :src="video.url" controls autoplay class="w-full bg-black aspect-video" />

      <div class="px-4 py-3 border-b border-gray-200">
        <h2 class="font-semibold">{{ video.title }}</h2>
        <p class="text-sm text-gray-600 mt-1">{{ video.user?.name }} · {{ video.views }} 次观看</p>
        <div class="flex gap-3 mt-3">
          <button class="px-4 py-1.5 rounded-full bg-gray-100 text-sm" @click="like">❤ {{ video.likes }}</button>
          <span class="px-4 py-1.5 rounded-full bg-gray-100 text-sm">💬 {{ video.comments_count }}</span>
        </div>
        <p v-if="video.description" class="text-sm text-gray-600 mt-3 whitespace-pre-line">{{ video.description }}</p>
      </div>

      <div class="px-4 py-3">
        <h3 class="text-sm text-gray-600 mb-2">评论 ({{ comments.length }})</h3>
        <div v-if="!comments.length" class="text-center text-gray-500 py-8 text-sm">还没有评论</div>
        <div v-for="c in comments" :key="c.id" class="py-3 border-b border-gray-200 last:border-0">
          <div class="flex gap-3">
            <img
              :src="c.user?.avatar || `https://ui-avatars.com/api/?name=${c.user?.name}`"
              class="w-9 h-9 rounded-full flex-shrink-0"
            />
            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium">{{ c.user?.name }}</p>
              <p class="text-sm text-gray-600 mt-1 break-words">{{ c.content }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="commentsEnabled" class="flex-none border-t border-gray-200 p-2 flex gap-2 safe-bottom">
      <input
        v-model="commentText"
        placeholder="说点什么…"
        class="flex-1 px-4 py-2 rounded-full bg-gray-50 outline-none"
        @keyup.enter="sendComment"
      />
      <button class="px-4 py-2 rounded-full bg-primary text-white" @click="sendComment">发送</button>
    </div>
  </div>
</template>
