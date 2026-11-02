<script setup lang="ts">
import type { ChatMessage } from '@/types/models'
defineProps<{ message: ChatMessage; isMine: boolean }>()
</script>

<template>
  <div :class="['flex gap-2 items-end', isMine ? 'flex-row-reverse' : '']">
    <img
      v-if="message.user?.avatar"
      :src="message.user.avatar"
      class="w-8 h-8 rounded-full shrink-0"
    />
    <div v-else class="w-8 h-8 rounded-full bg-white/20 grid place-items-center text-xs shrink-0">
      {{ message.user?.username?.[0]?.toUpperCase() ?? '?' }}
    </div>
    <div :class="['max-w-[70%]', isMine ? 'items-end' : 'items-start', 'flex flex-col']">
      <span v-if="!isMine" class="text-xs text-gray-400 mb-0.5 px-1">{{ message.user?.username }}</span>
      <div :class="['px-3 py-2 rounded-2xl text-sm', isMine ? 'bg-primary text-white rounded-br-sm' : 'bg-white/10 rounded-bl-sm']">
        {{ message.content }}
      </div>
    </div>
  </div>
</template>
