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
const loading = ref(false)
const error = ref(false)
async function loadVideos() {
  loading.value = true
  error.value = false
  try { myVideos.value = (await videoApi.mine(1)).data ?? [] }
  catch { error.value = true }
  finally { loading.value = false }
}
onMounted(loadVideos)
</script>

<template>
  <div class="profile-page">
    <header class="profile-header"><h1>我的</h1><button class="publish-button" @click="router.push('/upload')">＋ 发布</button></header>
    <section v-if="auth.user" class="profile-identity">
      <img v-if="auth.user.avatar" :src="auth.user.avatar" class="profile-avatar" alt="我的头像" />
      <span v-else class="profile-avatar">{{ auth.user.name?.[0] || '我' }}</span>
      <div><h2>{{ auth.user.name }}</h2><p>@{{ auth.user.username }}</p><p v-if="auth.user.bio" class="profile-bio">{{ auth.user.bio }}</p></div>
    </section>
    <nav class="profile-links" aria-label="个人功能">
      <button @click="router.push('/upload')"><span>发布视频</span><span aria-hidden="true">↗</span></button>
      <button @click="router.push('/fishing')"><span>捕鱼达人</span><span aria-hidden="true">›</span></button>
      <button @click="router.push('/fortune')"><span>命理中心</span><span aria-hidden="true">›</span></button>
    </nav>
    <section class="profile-works"><h2>作品 <span>{{ myVideos.length }}</span></h2>
      <p v-if="loading" class="feed-empty">正在加载作品…</p>
      <div v-else-if="error" class="feed-empty" role="alert"><p>作品加载失败</p><button @click="loadVideos">重试</button></div>
      <p v-else-if="!myVideos.length" class="feed-empty">还没有发布视频，记录你的第一份精彩吧</p>
      <div v-else class="video-grid long-grid"><VideoCard v-for="v in myVideos" :key="v.id" :video="v" layout="horizontal" @click="router.push({ name: 'video-detail', params: { id: v.id } })" /></div>
    </section>
    <button class="logout-button" @click="auth.logout()">退出登录</button>
  </div>
</template>
