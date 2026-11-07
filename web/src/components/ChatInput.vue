<script setup lang="ts">
import { ref } from 'vue'
const props = defineProps<{ isSending?: boolean; locked?: boolean }>()
const emit = defineEmits<{ send: [text: string, done: (success: boolean) => void]; locked: [] }>()
const text = ref('')
const busy = ref(false)
const emoji = ref(false)
const composing = ref(false)
function send() {
  if (props.locked) return emit('locked')
  const draft = text.value
  if (!draft.trim() || props.isSending || busy.value || composing.value) return
  busy.value = true
  emit('send', draft.trim(), success => { busy.value = false; if (success && text.value === draft) text.value = ''; if (success) emoji.value = false })
}
</script>

<template>
  <form class="chat-composer" @submit.prevent="send">
    <div v-if="emoji" class="emoji-tray"><button v-for="item in ['😀','😂','🥰','👍','❤️','🔥','🎉','🙏','👋','🤔','✨','💪']" :key="item" type="button" :aria-label="`插入 ${item}`" @click="text += item">{{ item }}</button></div>
    <button class="emoji-toggle" type="button" aria-label="表情" :aria-expanded="emoji" @click="locked ? emit('locked') : (emoji = !emoji)">☺</button>
    <input v-if="locked" aria-label="消息内容" placeholder="注册后才能在群里发言" readonly @click="emit('locked')" @focus="emit('locked')" />
    <input v-else v-model="text" aria-label="消息内容" placeholder="发送消息…" maxlength="2000" autocomplete="off" @compositionstart="composing = true" @compositionend="composing = false" />
    <button class="send-button" type="submit" @pointerdown.prevent :disabled="!locked && (isSending || busy || !text.trim())" aria-label="发送消息"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 16-8-5 16-4-6-7-2Zm7 2 9-10" /></svg></button>
  </form>
</template>
