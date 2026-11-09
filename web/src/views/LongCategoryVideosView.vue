<script setup lang="ts">
// Videos of one long-video category (/long/category/:id), opened from the category home.
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import VideoDiscovery from '@/components/VideoDiscovery.vue'

const route = useRoute()
const categoryId = computed(() => Number(route.params.id))
</script>
<template><VideoDiscovery :key="categoryId" kind="long" :category-id="categoryId" /></template>
