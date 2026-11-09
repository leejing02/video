<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { toPng } from 'html-to-image'
import { baziApi } from '@/api/bazi'
import type { BaziResult } from '@/api/bazi'

const route = useRoute()
const router = useRouter()

const WU_XING_ORDER = ['木', '火', '土', '金', '水']
const WU_XING_COLOR: Record<string, string> = {
  木: 'bg-green-500', 火: 'bg-red-500', 土: 'bg-yellow-600', 金: 'bg-gray-300', 水: 'bg-blue-500',
}
const WU_XING_TEXT: Record<string, string> = {
  木: 'text-green-700', 火: 'text-red-700', 土: 'text-yellow-700', 金: 'text-gray-700', 水: 'text-blue-700',
}

const loading = ref(true)
const error = ref('')
const chart = ref<BaziResult | null>(null)
const copied = ref(false)
const saving = ref(false)
const chartRef = ref<HTMLElement | null>(null)

onMounted(async () => {
  const token = route.params.token as string
  try {
    chart.value = await baziApi.getChart(token)
  } catch {
    error.value = '命盘不存在或已过期'
  } finally {
    loading.value = false
  }
})

// Inject OG/social meta tags for JS-aware crawlers (Telegram, Discord, etc.)
watch(chart, (c) => {
  if (!c) return
  const token = route.params.token as string
  const title = `${c.pillars.day}日主 · AI八字命盘`
  const desc = `年${c.pillars.year} 月${c.pillars.month} 日${c.pillars.day} 时${c.pillars.hour} · 五行: 木${c.wuxing['木']}火${c.wuxing['火']}土${c.wuxing['土']}金${c.wuxing['金']}水${c.wuxing['水']}`
  const imgUrl = `${window.location.origin}/api/og/chart/${token}`

  document.title = title
  setMeta('og:title', title)
  setMeta('og:description', desc)
  setMeta('og:image', imgUrl)
  setMeta('og:url', window.location.href)
  setMeta('twitter:card', 'summary_large_image')
  setMeta('twitter:title', title)
  setMeta('twitter:image', imgUrl)
})

function setMeta(property: string, content: string) {
  const sel = `meta[property="${property}"],meta[name="${property}"]`
  let el = document.querySelector(sel) as HTMLMetaElement | null
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(property.startsWith('twitter') ? 'name' : 'property', property)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

const maxWuXing = computed(() => {
  if (!chart.value) return 1
  return Math.max(1, ...Object.values(chart.value.wuxing))
})

const birthDisplay = computed(() => {
  if (!chart.value?.birthday) return ''
  const [date, time] = chart.value.birthday.split(' ')
  const d = new Date(date)
  return `${d.getFullYear()}年${String(d.getMonth()+1).padStart(2,'0')}月${String(d.getDate()).padStart(2,'0')}日 ${time || ''}`
})

const genderDisplay = computed(() => {
  const g = chart.value?.gender
  return (g === 'male' || g === '男') ? '男命' : '女命'
})

const analysisCards = computed(() => {
  if (!chart.value?.analysis) return []
  const a = chart.value.analysis
  return [
    { title: '命局总评', icon: '🌟', color: 'text-yellow-700', text: a.overall },
    { title: '五行喜忌', icon: '☯️', color: 'text-indigo-400', text: a.wuxing_advice },
    { title: '大运解析', icon: '🌊', color: 'text-blue-700', text: a.dayun_now },
    { title: '事业财运', icon: '💼', color: 'text-green-700', text: a.career },
    { title: '感情婚姻', icon: '❤️', color: 'text-pink-400', text: a.love },
    { title: '人生建议', icon: '🧭', color: 'text-orange-400', text: a.advice },
  ]
})

async function share() {
  const url = window.location.href
  if (navigator.share) {
    await navigator.share({ title: '我的八字命盘', text: '来看看我的八字命理AI解读', url })
  } else {
    await navigator.clipboard.writeText(url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }
}

async function saveImage() {
  if (!chartRef.value || saving.value) return
  saving.value = true
  try {
    const dataUrl = await toPng(chartRef.value, {
      backgroundColor: '#ffffff',
      pixelRatio: 2,
    })
    const link = document.createElement('a')
    link.download = 'bazi-chart.png'
    link.href = dataUrl
    link.click()
  } catch {
    // ignore
  } finally {
    saving.value = false
  }
}

function goCalculate() {
  router.push('/bazi')
}
</script>

<template>
  <div class="min-h-screen bg-white text-gray-900">

    <!-- Loading -->
    <div v-if="loading" class="flex flex-col items-center justify-center h-screen gap-4">
      <div class="w-16 h-16 rounded-full border-4 border-indigo-500/30 border-t-indigo-500 animate-spin" />
      <p class="text-gray-600 text-sm">加载命盘中…</p>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="flex flex-col items-center justify-center h-screen gap-4 px-6 text-center">
      <div class="text-5xl">🔯</div>
      <p class="text-gray-700">{{ error }}</p>
      <button class="mt-4 px-6 py-3 rounded-xl bg-indigo-600 text-white text-sm" @click="goCalculate">
        去算一个
      </button>
    </div>

    <!-- Chart -->
    <div v-else-if="chart" class="max-w-lg mx-auto px-4 py-6 space-y-4">

      <!-- Capturable area (for screenshot) -->
      <div ref="chartRef" class="space-y-4 rounded-2xl overflow-hidden">

      <!-- Header -->
      <div class="text-center py-4">
        <div class="text-4xl mb-2">🔯</div>
        <h1 class="text-xl font-bold text-gray-900">八字命盘</h1>
        <p class="text-sm text-gray-600 mt-1">{{ birthDisplay }} · {{ genderDisplay }}</p>
      </div>

      <!-- 四柱 -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <div class="text-xs text-gray-600 font-medium mb-3 text-center tracking-widest">四 柱 八 字</div>
        <div class="grid grid-cols-4 gap-2 text-center">
          <div v-for="(label, key) in { year: '年柱', month: '月柱', day: '日柱', hour: '时柱' }" :key="key">
            <div class="text-xs text-gray-500 mb-1">{{ label }}</div>
            <div class="bg-gray-100 rounded-xl py-3">
              <div class="text-2xl font-bold text-yellow-300">{{ (chart.pillars as any)[key][0] }}</div>
              <div class="text-2xl font-bold text-yellow-100">{{ (chart.pillars as any)[key][1] }}</div>
            </div>
          </div>
        </div>
        <div class="mt-3 flex justify-center gap-3 text-xs text-gray-500 flex-wrap">
          <span>大运{{ chart.forward ? '顺行' : '逆行' }}</span>
          <span>·</span>
          <span>{{ chart.start_age.toFixed(1) }}岁起运</span>
        </div>
      </div>

      <!-- 五行 -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <div class="text-xs text-gray-600 font-medium mb-3">五行分布</div>
        <div class="space-y-2">
          <div v-for="wx in WU_XING_ORDER" :key="wx" class="flex items-center gap-2">
            <span class="w-4 text-sm" :class="WU_XING_TEXT[wx]">{{ wx }}</span>
            <div class="flex-1 bg-gray-100 rounded-full h-2.5 overflow-hidden">
              <div
                class="h-full rounded-full"
                :class="WU_XING_COLOR[wx]"
                :style="{ width: `${((chart.wuxing[wx] || 0) / maxWuXing) * 100}%` }"
              />
            </div>
            <span class="text-xs text-gray-600 w-4 text-right">{{ chart.wuxing[wx] || 0 }}</span>
          </div>
        </div>
      </div>

      <!-- 大运 -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <div class="text-xs text-gray-600 font-medium mb-3">大运走势</div>
        <div class="flex gap-2 overflow-x-auto pb-1">
          <div
            v-for="d in chart.dayun"
            :key="d.age"
            class="flex-none flex flex-col items-center gap-1 px-3 py-2 rounded-xl min-w-[56px] bg-gray-100"
          >
            <span class="text-xs text-gray-500">{{ d.age }}岁</span>
            <span class="text-base font-bold text-yellow-200">{{ d.ganzhi[0] }}</span>
            <span class="text-base font-bold text-yellow-100">{{ d.ganzhi[1] }}</span>
          </div>
        </div>
      </div>

      <!-- AI Analysis -->
      <div v-if="chart.analysis" class="space-y-3">
        <div class="text-xs text-gray-600 font-medium px-1">AI 命理解读</div>
        <div
          v-for="card in analysisCards"
          :key="card.title"
          class="bg-gray-50 rounded-2xl p-4"
        >
          <h3 class="text-sm font-semibold mb-2 flex items-center gap-1.5">
            <span>{{ card.icon }}</span>
            <span :class="card.color">{{ card.title }}</span>
          </h3>
          <p class="text-sm text-gray-700 leading-relaxed">{{ card.text }}</p>
        </div>
      </div>

      </div><!-- end chartRef -->

      <!-- Actions -->
      <div class="space-y-3 pb-8">
        <div class="grid grid-cols-2 gap-3">
          <button
            class="py-3.5 rounded-2xl bg-indigo-600 text-white font-semibold flex items-center justify-center gap-1.5 active:opacity-80 text-sm"
            @click="share"
          >{{ copied ? '✅ 已复制' : '🔗 分享链接' }}</button>
          <button
            class="py-3.5 rounded-2xl bg-gray-700 text-gray-800 font-semibold flex items-center justify-center gap-1.5 active:opacity-80 text-sm"
            :disabled="saving"
            @click="saveImage"
          >{{ saving ? '生成中…' : '📷 保存截图' }}</button>
        </div>
        <button
          class="w-full py-3.5 rounded-2xl bg-gray-100 text-gray-700 font-medium active:opacity-80"
          @click="goCalculate"
        >
          🔯 我也要算命
        </button>
      </div>

    </div>
  </div>
</template>
