<script setup lang="ts">
import type { ChatMessage } from '@/types/models'
defineProps<{ message: ChatMessage; isMine: boolean; allowDirect?: boolean }>()
defineEmits<{ direct: [] }>()
function time(value: string) { return new Date(value).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) }
</script>

<template>
  <div class="message-row" :class="{ mine: isMine }">
    <button v-if="!isMine" class="sender-avatar" :disabled="!allowDirect" :aria-label="allowDirect ? `与 ${message.user?.name || '群成员'} 私聊` : '会话成员'" @click="$emit('direct')">
      <img v-if="message.user?.avatar" :src="message.user.avatar" alt="" /><span v-else>{{ (message.user?.name || message.user?.username || '?').slice(0,1) }}</span>
    </button>
    <div class="message-bubble"><button v-if="!isMine" class="sender-name" :disabled="!allowDirect" @click="$emit('direct')">{{ message.user?.name || message.user?.username || '群成员' }}</button><p>{{ message.content }}</p><div class="message-meta"><time>{{ time(message.created_at) }}</time><span v-if="isMine" aria-label="已发送">✓</span></div></div>
  </div>
</template>
