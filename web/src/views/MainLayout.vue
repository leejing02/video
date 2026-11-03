<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
const route = useRoute()
const router = useRouter()
const tabs = [
  { name: 'chat', label: '群聊', path: 'M5 4h14a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H9l-5 4v-4H3V6a2 2 0 0 1 2-2ZM7 9h10M7 13h6' },
  { name: 'short', label: '短视频', path: 'M9 3h6v4h4v10h-4v4H9v-4H5V7h4V3Zm1 6 5 3-5 3V9Z' },
  { name: 'long', label: '长视频', path: 'M4 5h16v14H4V5Zm6 4 5 3-5 3V9Z' },
  { name: 'me', label: '我的', path: 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2' },
]
</script>

<template>
  <div class="app-shell">
    <main class="app-content"><RouterView /></main>
    <nav class="bottom-nav" aria-label="主导航"><button v-for="tab in tabs" :key="tab.name" :class="['tab-btn', { active: route.name === tab.name || (tab.name === 'long' && route.name === 'video-detail') }]" :aria-current="route.name === tab.name ? 'page' : undefined" @click="router.replace({ name: tab.name })"><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="tab.path" /></svg><span>{{ tab.label }}</span><i></i></button></nav>
  </div>
</template>
