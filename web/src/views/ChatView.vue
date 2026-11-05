<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick, inject, watch, type Ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { chatApi } from '@/api/chat'
import { WsClient } from '@/utils/ws'
import { singleFlight } from '@/utils/single-flight'
import ChatMessage from '@/components/ChatMessage.vue'
import ChatInput from '@/components/ChatInput.vue'
import AvatarContent from '@/components/AvatarContent.vue'
import type { ChatRoom, ChatMessage as Message } from '@/types/models'

const auth = useAuthStore()
const rooms = ref<ChatRoom[]>([])
const selected = ref<number | null>(null)
const cache = ref<Record<number, Message[]>>({})
const exhausted = ref<Record<number, boolean>>({})
const query = ref('')
const filter = ref('all')
const mobileOpen = ref(false)
const chatDetailOpen = inject<Ref<boolean>>('mainNavigationHidden', ref(false))
watch(mobileOpen, value => { chatDetailOpen.value = value }, { flush: 'sync' })
const connected = ref(false)
const loading = ref(false)
const olderLoading = ref(false)
const error = ref('')
const pending = ref<Record<number, boolean>>({})
const scrollEl = ref<HTMLElement | null>(null)
const socket = new WsClient()
const cleanups: (() => void)[] = []
let refreshTimer: ReturnType<typeof setInterval>
let selectionVersion = 0
let disposed = false
const room = computed(() => rooms.value.find(item => item.id === selected.value))
const messages = computed(() => selected.value === null ? [] : cache.value[selected.value] ?? [])
const filtered = computed(() => rooms.value.filter(item => item.name.toLowerCase().includes(query.value.toLowerCase()) && (filter.value === 'all' || (filter.value === 'direct' ? item.kind === 'direct' : item.kind !== 'direct'))).sort((a,b) => (Date.parse(b.last_message_at ?? '') || 0) - (Date.parse(a.last_message_at ?? '') || 0)))
const totalUnread = computed(() => rooms.value.reduce((sum, item) => sum + item.unread_count, 0))

const swipeOffset = ref(0)
const swipeDragging = ref(false)
const swipeClosing = ref(false)
const swipeDuration = ref(220)
let swipeTimer: ReturnType<typeof setTimeout> | undefined
let backSwipe: { id: number; x: number; y: number; lastX: number; lastTime: number; velocity: number } | undefined

function dismissChatKeyboard() {
  const active = document.activeElement
  if ((active instanceof HTMLInputElement || active instanceof HTMLTextAreaElement)
      && active.closest('.chat-composer')) active.blur()
}

function cancelBackSwipe() {
  backSwipe = undefined
  swipeDragging.value = false
  swipeOffset.value = 0
}

function returnToConversations() {
  clearTimeout(swipeTimer)
  cancelBackSwipe()
  swipeClosing.value = false
  mobileOpen.value = false
}

function startBackSwipe(event: TouchEvent) {
  if (swipeClosing.value) return
  cancelBackSwipe()
  if (!mobileOpen.value || !window.matchMedia('(max-width: 700px)').matches || event.touches.length !== 1) return
  if (event.target instanceof Element && event.target.closest('input, textarea, [contenteditable]')) return
  const touch = event.touches[0]
  if (touch.clientX <= 32) backSwipe = { id: touch.identifier, x: touch.clientX, y: touch.clientY, lastX: touch.clientX, lastTime: event.timeStamp, velocity: 0 }
}

function moveBackSwipe(event: TouchEvent) {
  const start = backSwipe
  if (!start) return
  const touch = event.touches[0]
  if (event.touches.length !== 1 || touch.identifier !== start.id) {
    cancelBackSwipe()
    return
  }
  const dx = touch.clientX - start.x
  const dy = Math.abs(touch.clientY - start.y)
  if (!swipeDragging.value && dy > 12 && dy >= dx) {
    cancelBackSwipe()
  } else if (swipeDragging.value || (dx > 8 && dx > dy)) {
    if (event.cancelable) event.preventDefault()
    swipeDragging.value = true
    swipeOffset.value = Math.max(0, Math.min(dx - 6, window.innerWidth))
    const elapsed = event.timeStamp - start.lastTime
    if (elapsed > 0) start.velocity = (touch.clientX - start.lastX) / elapsed
    start.lastX = touch.clientX
    start.lastTime = event.timeStamp
  }
}

function endBackSwipe(event: TouchEvent) {
  const start = backSwipe
  backSwipe = undefined
  swipeDragging.value = false
  if (!start) return
  const touch = Array.from(event.changedTouches).find(item => item.identifier === start.id)
  if (!touch) {
    cancelBackSwipe()
    return
  }
  const dx = touch.clientX - start.x
  const dy = Math.abs(touch.clientY - start.y)
  const velocity = event.timeStamp - start.lastTime < 80 ? Math.max(0, start.velocity) : 0
  const projected = swipeOffset.value + Math.min(velocity, 2) * 85
  const shouldReturn = dx >= 32 && dx > dy * 1.5 && projected >= Math.min(100, window.innerWidth * .24)
  if (!shouldReturn) {
    swipeDuration.value = Math.min(240, 145 + swipeOffset.value)
    cancelBackSwipe()
    return
  }
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    returnToConversations()
    return
  }
  const remaining = window.innerWidth - swipeOffset.value
  swipeDuration.value = Math.min(300, Math.max(120, remaining / Math.max(.95, velocity)))
  swipeClosing.value = true
  swipeTimer = setTimeout(returnToConversations, swipeDuration.value + 20)
}

function merge(id: number, incoming: Message[], history = false) {
  const values = new Map((cache.value[id] ?? []).map(item => [item.id, item]))
  for (const item of incoming) if (item.chat_room_id === id) values.set(item.id, item)
  const sorted = [...values.values()].sort((a, b) => a.id - b.id)
  const active = selected.value === id && mobileOpen.value
  const nearBottom = !scrollEl.value || scrollEl.value.scrollHeight - scrollEl.value.scrollTop - scrollEl.value.clientHeight < 100
  const limit = active ? (!history && nearBottom ? 500 : 0) : 200
  cache.value[id] = limit ? sorted.slice(-limit) : sorted
  if (limit && sorted.length > limit) exhausted.value[id] = false
}
function bottom() { scrollEl.value?.scrollTo({ top: scrollEl.value.scrollHeight }) }
async function refreshRooms() {
  try { const result = await chatApi.myRooms(); if (!disposed) { rooms.value = result; if (error.value === '会话加载失败，请点击刷新') error.value = '' } }
  catch { /* Keep existing conversations during temporary outages. */ }
}
async function markRead(id: number) {
  if (document.hidden) return
  try { await chatApi.markRead(id); const item = rooms.value.find(r => r.id === id); if (item) item.unread_count = 0 } catch {}
}
async function catchUp(id: number, start?: number) {
  if (start === undefined) return
  let cursor = start
  for (let pageNumber = 0; pageNumber < 10 && !disposed && !document.hidden && selected.value === id; pageNumber++) {
    const page = await chatApi.messagesAfter(id, cursor)
    merge(id, page)
    if (page.length < 100) break
    const next = page[page.length - 1].id
    if (next <= cursor) break
    cursor = next
  }
}
async function selectRoom(item: ChatRoom) {
  const version = ++selectionVersion
  selected.value = item.id
  mobileOpen.value = true
  error.value = ''
  loading.value = true
  olderLoading.value = false
  try {
    if (item.kind === 'group') await chatApi.join(item.id)
    if (version !== selectionVersion || disposed) return
    const previous = cache.value[item.id]?.slice(-1)[0]?.id
    const page = await chatApi.messages(item.id, undefined, 50)
    merge(item.id, page)
    if (previous) await catchUp(item.id, previous)
    if (version !== selectionVersion || disposed) return
    exhausted.value[item.id] = page.length < 50
    // A refresh can overlap a websocket delivery; merging preserves both.
    await nextTick(); bottom()
    await markRead(item.id)
  } catch (e: any) { if (version === selectionVersion) error.value = e.response?.data?.error || '无法加载会话，请重试' }
  finally { if (version === selectionVersion) loading.value = false }
}
async function older() {
  const id = selected.value
  if (id === null || olderLoading.value || !messages.value.length) return
  const version = selectionVersion
  const height = scrollEl.value?.scrollHeight ?? 0
  const top = scrollEl.value?.scrollTop ?? 0
  olderLoading.value = true
  try {
    const page = await chatApi.messages(id, messages.value[0].id, 50)
    merge(id, page, true); exhausted.value[id] = page.length < 50
    await nextTick()
    if (version === selectionVersion && scrollEl.value) scrollEl.value.scrollTop = top + scrollEl.value.scrollHeight - height
  } catch { if (version === selectionVersion) error.value = '历史消息加载失败，请重试' }
  finally { if (version === selectionVersion) olderLoading.value = false }
}
async function send(text: string, done: (success: boolean) => void) {
  const id = selected.value
  if (id === null || pending.value[id]) { done(false); return }
  pending.value[id] = true
  try {
    const message = await chatApi.send(id, text)
    merge(id, [message]); done(true)
    if (selected.value === id) { await nextTick(); bottom(); await markRead(id) }
    await refreshRooms()
  } catch (e: any) { error.value = e.response?.data?.error || '发送失败，内容已保留，请重试'; done(false) }
  finally { pending.value[id] = false }
}
async function direct(message: Message) {
  if (!room.value || room.value.kind === 'direct' || message.user_id === auth.user?.id) return
  try {
    const result = await chatApi.direct(message.user_id, room.value.id)
    await refreshRooms()
    const target = rooms.value.find(item => item.id === result.id)
    if (target) await selectRoom(target)
  } catch (e: any) { error.value = e.response?.data?.error || '暂时无法发起私聊' }
}
const recover = singleFlight(async () => {
  if (disposed || document.hidden) return
  try {
    const id = selected.value
    const cursor = id === null ? undefined : cache.value[id]?.slice(-1)[0]?.id
    await refreshRooms()
    if (id !== null) { await catchUp(id, cursor); if (selected.value === id && mobileOpen.value) await markRead(id) }
  } catch { error.value = '消息同步暂时失败，正在重试' }
})
function onVisible() {
  clearTimeout(swipeTimer)
  cancelBackSwipe()
  if (swipeClosing.value) returnToConversations()
  if (!document.hidden) { socket.reconnect(); void recover() }
}
function onPageShow(event: PageTransitionEvent) { if (event.persisted) onVisible() }
function time(value: string | null) { return value ? new Date(value).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) : '' }

onMounted(async () => {
  cleanups.push(socket.on('open', () => { connected.value = true; void recover() }), socket.on('close', () => { connected.value = false }), socket.on('message.sent', (message: Message) => {
    const known = cache.value[message.chat_room_id]?.some(item => item.id === message.id)
    const nearBottom = !scrollEl.value || scrollEl.value.scrollHeight - scrollEl.value.scrollTop - scrollEl.value.clientHeight < 100
    merge(message.chat_room_id, [message])
    const item = rooms.value.find(item => item.id === message.chat_room_id)
    if (item && !known) {
      item.last_message = message.content; item.last_message_at = message.created_at
      if (message.user_id !== auth.user?.id && (selected.value !== item.id || document.hidden || !mobileOpen.value)) item.unread_count++
    }
    if (!item) void refreshRooms()
    if (selected.value === message.chat_room_id && mobileOpen.value && !document.hidden) {
      if (nearBottom) void nextTick(bottom)
      void markRead(message.chat_room_id)
    }
  }))
  socket.connect(0, auth.token ?? '', window.location.origin)
  try { rooms.value = await chatApi.myRooms() } catch { error.value = '会话加载失败，请点击刷新' }
  if (disposed) return
  refreshTimer = setInterval(() => { void recover() }, 15000)
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('pageshow', onPageShow)
})
onUnmounted(() => { clearTimeout(swipeTimer); chatDetailOpen.value = false; disposed = true; selectionVersion++; cleanups.forEach(fn => fn()); socket.disconnect(); clearInterval(refreshTimer); document.removeEventListener('visibilitychange', onVisible); window.removeEventListener('pageshow', onPageShow) })
</script>

<template>
  <div class="messenger" :class="{ 'conversation-open': mobileOpen, 'swipe-dragging': swipeDragging, 'swipe-closing': swipeClosing }" :style="{ '--chat-swipe-offset': swipeOffset + 'px', '--chat-swipe-duration': swipeDuration + 'ms' }">
    <aside class="conversation-list">
      <header class="chat-list-header">
        <div class="chat-connection" role="status"><i :class="{ online: connected }" aria-hidden="true"></i><span>{{ connected ? '实时消息已连接' : '实时消息连接中' }}</span></div>
        <div class="chat-heading"><h1>聊天</h1><span v-if="totalUnread" class="unread-total">{{ totalUnread > 99 ? '99+' : totalUnread }}</span></div>
        <button class="round-button" aria-label="刷新会话" @click="recover">↻</button>
      </header>
      <div class="chat-search"><span aria-hidden="true">⌕</span><input v-model="query" placeholder="搜索会话" aria-label="搜索会话" type="search" /></div>
      <div class="conversation-filters"><button v-for="option in [{id:'all',name:'所有聊天'},{id:'group',name:'群组'},{id:'direct',name:'私聊'}]" :key="option.id" :class="{ selected: filter === option.id }" @click="filter = option.id">{{ option.name }}</button></div>
      <div v-if="error && !mobileOpen" class="chat-error" role="alert">{{ error }} <button @click="recover">刷新</button></div>
      <div class="conversation-items">
        <button v-for="item in filtered" :key="item.id" class="conversation-row" @click="selectRoom(item)">
          <div class="room-avatar" :class="{ direct: item.kind === 'direct' }"><AvatarContent :src="item.cover" :name="item.name" /><i v-if="item.kind !== 'direct'">#</i></div>
          <div class="conversation-copy"><div class="conversation-title"><strong>{{ item.name }}</strong><time>{{ time(item.last_message_at) }}</time></div><div class="conversation-preview"><span>{{ item.last_message || (item.kind === 'direct' ? '开始你们的对话' : '点击进入群聊') }}</span><b v-if="item.unread_count">{{ item.unread_count > 99 ? '99+' : item.unread_count }}</b></div></div>
        </button>
        <div v-if="!filtered.length" class="chat-placeholder"><span>✉</span><p>暂无{{ filter === 'direct' ? '私聊' : '会话' }}</p><small>在群聊中点击成员头像，即可发起私聊</small></div>
      </div>
    </aside>
    <section class="conversation-detail" @touchstart.passive="startBackSwipe" @touchmove="moveBackSwipe" @touchend.passive="endBackSwipe" @touchcancel.passive="cancelBackSwipe">
      <template v-if="room">
        <header class="conversation-header" @click="dismissChatKeyboard"><button class="chat-back round-button" aria-label="返回会话列表" @click="returnToConversations">‹</button><div class="header-avatar"><AvatarContent :src="room.cover" :name="room.name" /></div><div><h2>{{ room.name }}</h2><p>{{ room.kind === 'direct' ? '私聊' : '群组会话' }} · {{ connected ? '实时连接' : '正在重连' }}</p></div><span class="conversation-kind">{{ room.kind === 'direct' ? '私聊' : '群组' }}</span></header>
        <div v-if="error" class="chat-error" role="alert">{{ error }}<button @click="error = ''">关闭</button></div>
        <div ref="scrollEl" class="message-timeline" @click="dismissChatKeyboard">
          <div class="timeline-label">{{ room.kind === 'direct' ? '你们的对话，从这里开始' : '一起聊聊新鲜事' }}</div>
          <button v-if="messages.length && !exhausted[room.id]" class="load-history" :disabled="olderLoading" @click="older">{{ olderLoading ? '正在加载…' : '加载更早消息' }}</button>
          <p v-if="loading" class="chat-loading">加载消息中…</p>
          <ChatMessage v-for="message in messages" :key="message.id" :message="message" :is-mine="message.user_id === auth.user?.id" :allow-direct="room.kind !== 'direct'" @direct="direct(message)" />
          <p v-if="!loading && !messages.length" class="empty-messages">还没有消息，发一句问候吧</p>
        </div>
        <ChatInput :key="room.id" :is-sending="pending[room.id]" @send="send" />
      </template>
      <div v-else class="conversation-welcome"><div class="welcome-symbol">✈</div><h2>每一次分享，都有回应</h2><p>选择一个群聊，或从群成员发起私聊</p><span>消息实时同步 · 随时继续对话</span></div>
    </section>
  </div>
</template>
