<script setup lang="ts">
import { computed } from 'vue'
import type { ShortVideoComment } from '@/types/models'
import { commentDanmaku } from '@/utils/comment-danmaku'
const props = defineProps<{ comments: ShortVideoComment[]; paused: boolean }>()
const rows = computed(() => commentDanmaku(props.comments))
</script>

<template>
  <div class="comment-danmaku" :class="{ paused }" aria-hidden="true">
    <span v-for="(row,index) in rows" :key="row.id" class="danmaku-text"
      :style="{ top: (index % 4) * 34 + 'px', animationDelay: index * 2.2 + 's' }">{{row.text}}</span>
  </div>
</template>

<style scoped>
.comment-danmaku{position:absolute;top:16%;left:0;right:0;height:136px;overflow:hidden;pointer-events:none;contain:layout paint}
.danmaku-text{position:absolute;left:100%;width:max-content;max-width:none;white-space:nowrap;color:#fff;font-size:16px;font-weight:500;line-height:28px;text-shadow:0 1px 3px #000,1px 0 2px #000;animation:comment-flight 27s linear infinite both;will-change:transform}
.paused .danmaku-text{animation-play-state:paused}
@keyframes comment-flight{0%{transform:translateX(0);opacity:1}30%{transform:translateX(calc(-100vw - 100%));opacity:1}30.1%,100%{transform:translateX(calc(-100vw - 100%));opacity:0}}
@media(prefers-reduced-motion:reduce){.danmaku-text{animation-duration:54s}}
</style>
