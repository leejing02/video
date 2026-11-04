<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'
import { provide, ref, watch } from 'vue'
import { warmShortFeed } from '@/utils/short-feed-prefetch'
const navigationHidden = ref(false)
provide('mainNavigationHidden', navigationHidden)
const route = useRoute()
const router = useRouter()
watch(() => route.name, name => {
  if (name === 'chat') warmShortFeed()
}, { immediate: true })
const tabs = [
  { name: 'chat', label: '群聊' },
  { name: 'short', label: '短视频' },
  { name: 'upload', label: '发布视频' },
  { name: 'long', label: '长视频' },
  { name: 'me', label: '我' },
]
function isActive(name: string) {
  return route.name === name || (name === 'long' && route.name === 'video-detail')
}
</script>

<template>
  <div class="app-shell" :class="{ 'chat-mode': route.name === 'chat' }">
    <main class="app-content"><RouterView /></main>
    <nav v-if="!navigationHidden" class="bottom-nav" aria-label="主导航">
      <button v-for="tab in tabs" :key="tab.name" :class="['tab-btn', { active: isActive(tab.name), 'tab-publish': tab.name === 'upload' }]" :aria-current="isActive(tab.name) ? 'page' : undefined" :aria-label="tab.label" @click="router.replace({ name: tab.name })">
        <svg v-if="tab.name === 'upload'" class="publish-icon" viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="24" r="21" /><path d="M24 15v18M15 24h18" /></svg>
        <span v-else>{{ tab.label }}</span>
      </button>
    </nav>
  </div>
</template>
