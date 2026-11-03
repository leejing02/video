<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { mediaUrl } from '@/utils/media-url'
const props = defineProps<{ src?: string | null; name?: string | null }>()
const url = computed(() => mediaUrl(props.src))
const failed = ref(false)
watch(url, () => { failed.value = false })
</script>
<template>
  <img v-if="url && !failed" :src="url" alt="" @error="failed = true" />
  <span v-else>{{ (name || '?').slice(0, 1) }}</span>
</template>
