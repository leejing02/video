<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { videoApi } from '@/api/video'
import VideoCard from '@/components/VideoCard.vue'
import type { Video } from '@/types/models'

const auth = useAuthStore()
const router = useRouter()
const myVideos = ref<Video[]>([])

async function loadVideos() {
  try {
    const res = await videoApi.mine(1)
    myVideos.value = res.data ?? (res as any)
  } catch {}
}

onMounted(loadVideos)
</script>

<template>
  <div class="flex flex-col h-full overflow-y-auto">
    <header class="px-4 py-3 border-b border-gray-800 safe-top">
      <h1 class="text-lg font-semibold">我的</h1>
    </header>

    <section v-if="auth.user" class="p-5 flex items-center gap-4 border-b border-gray-800">
      <img
        :src="auth.user.avatar || `https://ui-avatars.com/api/?name=${encodeURIComponent(auth.user.name)}&background=6366f1&color=fff`"
        class="w-16 h-16 rounded-full"
      />
      <div class="flex-1 min-w-0">
        <p class="font-semibold truncate">{{ auth.user.name }}</p>
        <p class="text-sm text-gray-400 truncate">@{{ auth.user.username }}</p>
        <p v-if="auth.user.bio" class="text-xs text-gray-500 mt-1 truncate">{{ auth.user.bio }}</p>
      </div>
    </section>

    <nav class="border-b border-gray-800">
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900" @click="router.push('/upload')">
        <span class="flex items-center gap-2"><span class="text-primary">📤</span> 上传视频</span>
        <span class="text-gray-500">›</span>
      </button>
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900" @click="router.push('/fishing')">
        <span class="flex items-center gap-2">
          <span>🎣</span>
          <span>捕鱼达人</span>
          <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-indigo-500/20 text-indigo-400 font-medium">游戏</span>
        </span>
        <span class="text-gray-500">›</span>
      </button>
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900" @click="router.push('/fortune')">
        <span class="flex items-center gap-2">
          <span>🔯</span>
          <span>命理中心</span>
          <span class="ml-1 text-xs px-1.5 py-0.5 rounded-full bg-purple-500/20 text-purple-400 font-medium">AI</span>
        </span>
        <span class="text-gray-500">›</span>
      </button>
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900">
        <span>编辑资料</span><span class="text-gray-500">›</span>
      </button>
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900">
        <span>我的评论</span><span class="text-gray-500">›</span>
      </button>
      <button class="w-full px-5 py-4 flex justify-between items-center hover:bg-gray-900">
        <span>设置</span><span class="text-gray-500">›</span>
      </button>
    </nav>

    <section class="p-3">
      <h2 class="text-sm font-semibold text-gray-400 mb-2 px-1">我的视频</h2>
      <div v-if="!myVideos.length" class="text-center text-gray-500 py-8 text-sm">还没有发布过视频</div>
      <div class="space-y-3">
        <VideoCard v-for="v in myVideos" :key="v.id" :video="v" layout="horizontal" />
      </div>
    </section>

    <div class="p-4 mt-auto safe-bottom">
      <button class="w-full py-3 rounded-xl bg-red-500/20 text-red-400 font-medium" @click="auth.logout()">
        退出登录
      </button>
    </div>
  </div>
</template>
