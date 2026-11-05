<script setup lang="ts">
defineProps<{ name: string }>()
const paths: Record<string,string> = {
 heart:'M12 21 3 12C-3 5 6-1 12 6c6-7 15-1 9 6Z',
 comment:'M21 11a9 9 0 0 1-9 9H3l2-5A9 9 0 1 1 21 11Z',
 star:'m12 2 3 6 7 1-5 5 1 8-6-4-6 4 1-8-5-5 7-1Z',
 share:'m14 3 8 7-8 7v-4C7 13 4 16 2 21c0-11 5-15 12-15Z',
 link:'m10 14 4-4M8 16l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 10a4 4 0 0 1 0-6l5-5a4 4 0 0 1 6 6l-2 2',
 user:'M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10ZM3 22v-2a9 9 0 0 1 18 0v2',
}
</script>
<template><svg viewBox="0 0 24 24" aria-hidden="true"><path :d="paths[name] || paths.share" /></svg></template>
