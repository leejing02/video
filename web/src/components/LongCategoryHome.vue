<script setup lang="ts">
// Long-video category home (/long): two-column cards of the site's long-video categories
// (GET /api/categories?kind=long). A card shows the category's own cover, never a video cover.
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { categoryApi } from '@/api/category'
import { mediaUrl } from '@/utils/media-url'
import type { Category } from '@/types/models'

const router = useRouter()
const categories = ref<Category[]>([])
const loading = ref(true)
const failed = ref(false)
const brokenCovers = ref<Record<number, boolean>>({})

async function load() {
  loading.value = true
  failed.value = false
  try {
    const response = await categoryApi.list('long')
    categories.value = Array.isArray(response) ? response : response.data ?? []
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <section class="discovery-page" aria-label="长视频分类">
    <header class="discovery-header">
      <!-- No menu in the first version; the empty slot keeps 「长视频」 centred. -->
      <span class="feed-icon" aria-hidden="true" />
      <nav class="feed-tabs" aria-label="视频频道">
        <button class="selected" aria-current="page">长视频</button>
      </nav>
      <span class="feed-icon" aria-hidden="true" />
    </header>
    <div class="discovery-scroll">
      <div v-if="loading" class="long-category-grid" aria-busy="true">
        <div v-for="n in 6" :key="n" class="long-category-skeleton" />
      </div>
      <div v-else-if="failed" class="feed-empty" role="alert"><p>加载失败</p><button @click="load">点击重试</button></div>
      <div v-else-if="!categories.length" class="feed-empty"><p>暂无分类</p></div>
      <div v-else class="long-category-grid">
        <button v-for="category in categories" :key="category.id" class="long-category-card" :aria-label="`打开分类：${category.name}`"
          @click="router.push({ name: 'long-category', params: { id: category.id } })">
          <span class="long-category-cover">
            <img v-if="category.cover && !brokenCovers[category.id]" :src="mediaUrl(category.cover)" alt="" loading="lazy" @error="brokenCovers[category.id] = true" />
          </span>
          <span class="long-category-name">{{ category.name }}</span>
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped>
.long-category-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; padding: 4px; max-width: 1440px; margin: 0 auto; }
/* Top-aligned: a wrapped name makes the row taller, and a button would centre its content. */
.long-category-card { display: block; align-self: start; min-width: 0; text-align: left; }
.long-category-cover { display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 6px; background: #e8e8e8; }
.long-category-cover img { display: block; width: 100%; height: 100%; object-fit: cover; }
.long-category-name { display: block; padding: 6px 2px 4px; font-size: 18px; font-weight: 600; line-height: 1.3; color: var(--ink); text-align: center; white-space: normal; overflow-wrap: anywhere; }
.long-category-skeleton { aspect-ratio: 16 / 9; border-radius: 6px; background: #e8e8e8; margin-bottom: 26px; }
@media (min-width: 700px) { .long-category-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; } }
</style>
