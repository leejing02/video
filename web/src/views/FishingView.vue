<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const iframeRef = ref<HTMLIFrameElement | null>(null)
const loading = ref(true)
const fullscreen = ref(false)

function onLoaded() {
  loading.value = false
}

function goBack() {
  router.back()
}

async function toggleFullscreen() {
  const el = document.documentElement
  if (!document.fullscreenElement) {
    await el.requestFullscreen().catch(() => {})
    fullscreen.value = true
  } else {
    await document.exitFullscreen().catch(() => {})
    fullscreen.value = false
  }
}

function onFsChange() {
  fullscreen.value = !!document.fullscreenElement
}

onMounted(() => document.addEventListener('fullscreenchange', onFsChange))
onUnmounted(() => document.removeEventListener('fullscreenchange', onFsChange))
</script>

<template>
  <div class="relative w-full h-full bg-black overflow-hidden" style="height: 100dvh;">

    <!-- Loading overlay -->
    <Transition name="fade">
      <div v-if="loading" class="absolute inset-0 z-20 bg-black flex flex-col items-center justify-center gap-4">
        <div class="text-4xl animate-bounce">🎣</div>
        <p class="text-gray-300 text-sm">游戏加载中...</p>
        <div class="w-40 h-1 bg-gray-800 rounded-full overflow-hidden">
          <div class="h-full bg-indigo-500 animate-pulse rounded-full" style="width:60%"></div>
        </div>
      </div>
    </Transition>

    <!-- HUD 工具栏（半透明浮层） -->
    <div class="absolute top-0 left-0 right-0 z-10 flex items-center justify-between px-3 pt-2 pb-1
                bg-gradient-to-b from-black/60 to-transparent pointer-events-none">
      <button
        class="pointer-events-auto flex items-center gap-1.5 px-3 py-1.5 rounded-full
               bg-black/50 backdrop-blur text-white text-sm active:scale-95 transition-transform"
        @click="goBack"
      >
        ‹ 返回
      </button>

      <span class="text-white/60 text-xs font-medium tracking-wider select-none">捕鱼达人</span>

      <button
        class="pointer-events-auto px-3 py-1.5 rounded-full bg-black/50 backdrop-blur
               text-white text-sm active:scale-95 transition-transform"
        @click="toggleFullscreen"
        :title="fullscreen ? '退出全屏' : '全屏'"
      >
        {{ fullscreen ? '⊠' : '⛶' }}
      </button>
    </div>

    <!-- 游戏 iframe -->
    <iframe
      ref="iframeRef"
      src="/fishing/index.html"
      class="w-full h-full border-0"
      allow="autoplay; fullscreen"
      sandbox="allow-scripts allow-same-origin allow-forms"
      @load="onLoaded"
    />
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active { transition: opacity 0.4s; }
.fade-enter-from,
.fade-leave-to   { opacity: 0; }
</style>
