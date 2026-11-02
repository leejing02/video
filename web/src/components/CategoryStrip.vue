<script setup lang="ts">
defineProps<{ categories: any[]; modelValue: number | null }>()
defineEmits<{ 'update:modelValue': [v: number | null] }>()
</script>

<template>
  <div class="overflow-x-auto py-2 px-3">
    <div class="flex gap-2 min-w-max">
      <button
        :class="['px-3 py-1.5 rounded-full text-sm whitespace-nowrap', modelValue === null ? 'bg-primary text-white' : 'bg-white/10 text-gray-300']"
        @click="$emit('update:modelValue', null)"
      >全部</button>
      <button
        v-for="cat in categories"
        :key="cat.id"
        :class="['px-3 py-1.5 rounded-full text-sm whitespace-nowrap', modelValue === cat.id ? 'bg-primary text-white' : 'bg-white/10 text-gray-300']"
        @click="$emit('update:modelValue', cat.id)"
      >
        <span v-if="cat.icon" class="mr-1">{{ cat.icon }}</span>{{ cat.name }}
      </button>
    </div>
  </div>
</template>
