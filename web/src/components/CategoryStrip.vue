<script setup lang="ts">
defineProps<{ categories: { id: number; name: string }[]; modelValue: number | null }>()
defineEmits<{ 'update:modelValue': [v: number | null] }>()
</script>
<template>
  <nav class="category-strip" aria-label="视频分类">
    <button :class="{ selected: modelValue === null }" :aria-pressed="modelValue === null" @click="$emit('update:modelValue', null)">全部</button>
    <button v-for="cat in categories" :key="cat.id" :class="{ selected: modelValue === cat.id }" :aria-pressed="modelValue === cat.id" @click="$emit('update:modelValue', cat.id)">{{ cat.name }}</button>
  </nav>
</template>
