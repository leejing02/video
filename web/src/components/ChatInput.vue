<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

defineProps<{ isSending?: boolean }>()
const emit = defineEmits<{ send: [text: string] }>()

const text    = ref('')
const showPicker = ref(false)
const inputRef   = ref<HTMLInputElement | null>(null)
const pickerRef  = ref<HTMLElement | null>(null)

const EMOJIS = [
  '😀','😂','🥹','😅','😊','😍','🥰','😘','😏','🤔','😴','🥳','🤩','😭','🤣','🫡',
  '👍','👎','👏','🙌','🤝','💪','🫶','✌️','🤞','🫠','🙏','👋',
  '❤️','💕','💔','🔥','⭐','✨','💯','🎉','🎊','🎮','🎵','🎁','💎','👑',
  '😺','🐶','🐸','🐼','🦊','🦄','🐳','🍕','🍔','🍣','🧋','🍺','🍻',
]

function insertEmoji(emoji: string) {
  const el = inputRef.value
  if (!el) { text.value += emoji; return }

  const start = el.selectionStart ?? text.value.length
  const end   = el.selectionEnd   ?? text.value.length
  text.value  = text.value.slice(0, start) + emoji + text.value.slice(end)

  // restore caret after Vue updates the input
  const next = start + [...emoji].length
  requestAnimationFrame(() => { el.focus(); el.setSelectionRange(next, next) })
}

function send() {
  const t = text.value.trim()
  if (!t) return
  emit('send', t)
  text.value = ''
  showPicker.value = false
}

function onClickOutside(e: MouseEvent) {
  if (
    pickerRef.value && !pickerRef.value.contains(e.target as Node) &&
    !(e.target as Element).closest('[data-emoji-btn]')
  ) {
    showPicker.value = false
  }
}

onMounted(()  => document.addEventListener('pointerdown', onClickOutside))
onUnmounted(() => document.removeEventListener('pointerdown', onClickOutside))
</script>

<template>
  <div class="relative">
    <!-- Emoji picker panel -->
    <Transition name="slide-up">
      <div
        v-if="showPicker"
        ref="pickerRef"
        class="absolute bottom-full left-0 right-0 bg-gray-900 border border-white/10 rounded-t-xl p-3 grid grid-cols-8 gap-1.5 max-h-48 overflow-y-auto"
      >
        <button
          v-for="e in EMOJIS"
          :key="e"
          class="text-2xl leading-none aspect-square flex items-center justify-center rounded-lg hover:bg-white/10 active:scale-90 transition-transform"
          @click="insertEmoji(e)"
        >{{ e }}</button>
      </div>
    </Transition>

    <!-- Input row -->
    <div class="flex gap-2 px-3 py-2 border-t border-white/10 safe-bottom">
      <button
        data-emoji-btn
        :class="['text-xl leading-none w-9 h-9 flex items-center justify-center rounded-full transition-colors', showPicker ? 'bg-primary/30 text-yellow-300' : 'text-gray-400 hover:text-white']"
        @click="showPicker = !showPicker"
      >😊</button>

      <input
        ref="inputRef"
        v-model="text"
        placeholder="说点什么…"
        class="flex-1 px-4 py-2 rounded-full bg-gray-900 outline-none text-sm"
        @keyup.enter="send"
      />

      <button
        :disabled="isSending || !text.trim()"
        class="px-4 py-2 rounded-full bg-primary text-white text-sm font-medium disabled:opacity-50"
        @click="send"
      >发送</button>
    </div>
  </div>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
  transition: transform 0.2s ease, opacity 0.2s ease;
}
.slide-up-enter-from,
.slide-up-leave-to {
  transform: translateY(8px);
  opacity: 0;
}
</style>
