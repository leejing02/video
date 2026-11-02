<script setup lang="ts">
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/api/chat'
import { globalWs } from '@/utils/ws'
import ChatMessage from '@/components/ChatMessage.vue'
import ChatInput from '@/components/ChatInput.vue'
import type { ChatRoom, ChatMessage as IChatMessage } from '@/types/models'

const auth = useAuthStore()
const room = ref<ChatRoom | null>(null)
const messages = ref<IChatMessage[]>([])
const loading = ref(false)
const connected = ref(false)
const isSending = ref(false)
const scrollEl = ref<HTMLElement | null>(null)
const cleanups: (() => void)[] = []

async function loadRoom() {
  loading.value = true
  try {
    room.value = await chatApi.globalRoom()
    const res = await chatApi.messages(room.value.id, undefined, 50)
    messages.value = res.data ?? (res as any)
    await nextTick()
    scrollToBottom()
  } finally {
    loading.value = false
  }
}

async function connectWs() {
  if (!room.value) return
  const token = localStorage.getItem('auth_token') ?? ''
  globalWs.connect(room.value.id, token, window.location.origin)
  cleanups.push(
    globalWs.on('open',  () => { connected.value = true }),
    globalWs.on('close', () => { connected.value = false }),
    globalWs.on('message.sent', (msg: IChatMessage) => {
      if (!messages.value.some(m => m.id === msg.id)) {
        messages.value.push(msg)
        nextTick(scrollToBottom)
      }
    }),
  )
}

async function send(text: string) {
  if (isSending.value || !room.value) return
  isSending.value = true
  try {
    const msg = await chatApi.send(room.value.id, text)
    if (msg.id && !messages.value.some(m => m.id === msg.id)) {
      messages.value.push(msg)
      await nextTick()
      scrollToBottom()
    }
  } catch (e: any) {
    alert(e.response?.data?.error || '发送失败')
  } finally {
    isSending.value = false
  }
}

function scrollToBottom() {
  scrollEl.value?.scrollTo({ top: scrollEl.value.scrollHeight, behavior: 'smooth' })
}

onMounted(async () => { await loadRoom(); await connectWs() })
onUnmounted(() => { cleanups.forEach(f => f()); globalWs.disconnect() })
</script>

<template>
  <div class="flex flex-col h-full">
    <header class="px-4 py-3 border-b border-gray-800 safe-top flex items-center justify-between">
      <div>
        <h1 class="font-semibold">{{ room?.name || '广场' }}</h1>
        <p class="text-xs text-gray-400">
          {{ messages.length }} 条消息
          <span :class="['ml-2', connected ? 'text-emerald-400' : 'text-gray-600']">
            {{ connected ? '● 已连接' : '○ 连接中…' }}
          </span>
        </p>
      </div>
    </header>

    <div ref="scrollEl" class="flex-1 overflow-y-auto p-3 space-y-2">
      <div v-if="loading && !messages.length" class="text-center text-gray-500 py-10">加载中…</div>
      <ChatMessage
        v-for="msg in messages"
        :key="msg.id"
        :message="msg"
        :is-mine="msg.user?.id === auth.user?.id"
      />
    </div>

    <ChatInput :is-sending="isSending" @send="send" />
  </div>
</template>
