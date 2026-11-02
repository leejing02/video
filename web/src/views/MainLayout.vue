<script setup lang="ts">
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const tabs = [
  { name: 'chat',    label: '群聊',  icon: '💬' },
  { name: 'short',   label: '短视频', icon: '🔥' },
  { name: 'long',    label: '长视频', icon: '🎬' },
  { name: 'live',    label: '直播',  icon: '📺' },
  { name: 'me',      label: '我的',  icon: '👤' },
]
</script>

<template>
  <div class="h-full w-full flex flex-col">
    <main class="flex-1 overflow-hidden">
      <RouterView />
    </main>
    <nav class="flex border-t border-white/10 bg-black/95 backdrop-blur safe-bottom">
      <button
        v-for="tab in tabs"
        :key="tab.name"
        :class="['tab-btn', { active: route.name === tab.name }]"
        @click="router.replace({ name: tab.name })"
      >
        <span class="text-xl mb-0.5">{{ tab.icon }}</span>
        <span>{{ tab.label }}</span>
      </button>
    </nav>
  </div>
</template>
