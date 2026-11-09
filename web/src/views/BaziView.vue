<script setup lang="ts">
import { ref, computed } from 'vue'
import { baziApi } from '@/api/bazi'
import type { BaziResult } from '@/api/bazi'

const WU_XING_ORDER = ['木', '火', '土', '金', '水']
const WU_XING_COLOR: Record<string, string> = {
  木: 'bg-green-500',
  火: 'bg-red-500',
  土: 'bg-yellow-600',
  金: 'bg-gray-300',
  水: 'bg-blue-500',
}
const WU_XING_TEXT: Record<string, string> = {
  木: 'text-green-700',
  火: 'text-red-700',
  土: 'text-yellow-700',
  金: 'text-gray-700',
  水: 'text-blue-700',
}

const birthday = ref('')
const birthtime = ref('12:00')
const gender = ref<'male' | 'female'>('male')
const loading = ref(false)
const error = ref('')
const result = ref<BaziResult | null>(null)

const copied = ref(false)

async function shareChart() {
  if (!result.value?.share_token) return
  const url = `${window.location.origin}/chart/${result.value.share_token}`
  if (navigator.share) {
    await navigator.share({ title: '我的八字命盘', text: '来看看我的八字命理AI解读', url })
  } else {
    await navigator.clipboard.writeText(url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }
}

const maxWuXing = computed(() => {
  if (!result.value) return 1
  return Math.max(1, ...Object.values(result.value.wuxing))
})

async function calculate() {
  if (!birthday.value) {
    error.value = '请选择出生日期'
    return
  }
  error.value = ''
  loading.value = true
  result.value = null
  try {
    const bday = `${birthday.value} ${birthtime.value}`
    const res = await baziApi.calculate(bday, gender.value)
    result.value = res as any
  } catch (e: any) {
    error.value = e?.response?.data?.error || '计算失败，请稍后重试'
  } finally {
    loading.value = false
  }
}

function reset() {
  result.value = null
  error.value = ''
}

const analysisCards = computed(() => {
  if (!result.value?.analysis) return []
  const a = result.value.analysis
  return [
    { title: '命局总评', icon: '🌟', color: 'text-yellow-700', text: a.overall },
    { title: '五行喜忌', icon: '☯️', color: 'text-indigo-400', text: a.wuxing_advice },
    { title: '大运解析', icon: '🌊', color: 'text-blue-700', text: a.dayun_now },
    { title: '事业财运', icon: '💼', color: 'text-green-700', text: a.career },
    { title: '感情婚姻', icon: '❤️', color: 'text-pink-400', text: a.love },
    { title: '人生建议', icon: '🧭', color: 'text-orange-400', text: a.advice },
  ]
})

const currentAge = computed(() => {
  if (!birthday.value) return 0
  return new Date().getFullYear() - new Date(birthday.value).getFullYear()
})

const currentDayunLabel = computed(() => {
  if (!result.value) return ''
  const age = currentAge.value
  let label = '未入大运'
  for (const d of result.value.dayun) {
    if (d.age <= age) label = d.ganzhi
  }
  return label
})
</script>

<template>
  <div class="h-full flex flex-col bg-white text-gray-900 overflow-y-auto">
    <header class="flex-none px-4 py-3 border-b border-gray-200 safe-top flex items-center gap-2">
      <span>🔯</span>
      <h1 class="text-lg font-semibold">八字命理</h1>
      <span v-if="result" class="ml-auto text-xs text-gray-500 cursor-pointer" @click="reset">重新算</span>
    </header>

    <!-- Form -->
    <div v-if="!result && !loading" class="flex-1 flex flex-col px-5 py-6 gap-5">
      <div class="text-center mb-2">
        <div class="text-5xl mb-3">☰☱☲☳</div>
        <p class="text-sm text-gray-600 leading-relaxed">输入出生时间，AI深度解读您的八字命盘</p>
      </div>

      <!-- Date -->
      <div class="space-y-1.5">
        <label class="text-xs text-gray-600 font-medium">出生日期</label>
        <input
          v-model="birthday"
          type="date"
          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-gray-900 text-sm focus:outline-none focus:border-indigo-500"
        />
      </div>

      <!-- Time -->
      <div class="space-y-1.5">
        <label class="text-xs text-gray-600 font-medium">出生时间 <span class="text-gray-600">（不确定可保持默认）</span></label>
        <input
          v-model="birthtime"
          type="time"
          class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-gray-900 text-sm focus:outline-none focus:border-indigo-500"
        />
      </div>

      <!-- Gender -->
      <div class="space-y-1.5">
        <label class="text-xs text-gray-600 font-medium">性别</label>
        <div class="flex gap-3">
          <button
            class="flex-1 py-3 rounded-xl text-sm font-medium transition-colors"
            :class="gender === 'male' ? 'bg-indigo-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'"
            @click="gender = 'male'"
          >男命</button>
          <button
            class="flex-1 py-3 rounded-xl text-sm font-medium transition-colors"
            :class="gender === 'female' ? 'bg-pink-600 text-white' : 'bg-gray-50 text-gray-600 border border-gray-200'"
            @click="gender = 'female'"
          >女命</button>
        </div>
      </div>

      <p v-if="error" class="text-red-400 text-sm text-center">{{ error }}</p>

      <button
        class="w-full py-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-purple-600 text-white font-semibold text-base active:opacity-80 transition-opacity mt-2"
        @click="calculate"
      >
        排盘 · AI解读
      </button>

      <p class="text-xs text-center text-gray-600">以出生时刻与地球时间能量为依据</p>
    </div>

    <!-- Loading -->
    <div v-else-if="loading" class="flex-1 flex flex-col items-center justify-center gap-6 px-6">
      <div class="relative">
        <div class="w-20 h-20 rounded-full border-4 border-indigo-500/30 border-t-indigo-500 animate-spin" />
        <div class="absolute inset-0 flex items-center justify-center text-2xl">☯</div>
      </div>
      <div class="text-center">
        <p class="text-indigo-300 font-medium mb-1">正在推演命局…</p>
        <p class="text-xs text-gray-500">AI正在解读五行能量</p>
      </div>
    </div>

    <!-- Result -->
    <div v-else-if="result" class="flex-1 flex flex-col px-4 py-4 gap-4">

      <!-- 四柱 grid -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <h2 class="text-xs text-gray-600 font-medium mb-3 text-center tracking-widest">四 柱 八 字</h2>
        <div class="grid grid-cols-4 gap-2 text-center">
          <div v-for="(label, key) in { year: '年柱', month: '月柱', day: '日柱', hour: '时柱' }" :key="key">
            <div class="text-xs text-gray-500 mb-1">{{ label }}</div>
            <div class="bg-gray-100 rounded-xl py-3">
              <div class="text-xl font-bold text-yellow-300">{{ (result.pillars as any)[key][0] }}</div>
              <div class="text-xl font-bold text-yellow-100">{{ (result.pillars as any)[key][1] }}</div>
            </div>
          </div>
        </div>
        <div class="mt-3 flex justify-center gap-2 text-xs text-gray-500">
          <span>大运{{ result.forward ? '顺行' : '逆行' }}</span>
          <span>·</span>
          <span>{{ result.start_age.toFixed(1) }}岁起运</span>
          <span v-if="currentDayunLabel">·</span>
          <span v-if="currentDayunLabel" class="text-indigo-700">当前大运{{ currentDayunLabel }}</span>
        </div>
      </div>

      <!-- 五行 distribution -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <h2 class="text-xs text-gray-600 font-medium mb-3">五行分布</h2>
        <div class="space-y-2">
          <div v-for="wx in WU_XING_ORDER" :key="wx" class="flex items-center gap-2">
            <span class="w-4 text-sm" :class="WU_XING_TEXT[wx]">{{ wx }}</span>
            <div class="flex-1 bg-gray-100 rounded-full h-2.5 overflow-hidden">
              <div
                class="h-full rounded-full transition-all duration-700"
                :class="WU_XING_COLOR[wx]"
                :style="{ width: `${((result.wuxing[wx] || 0) / maxWuXing) * 100}%` }"
              />
            </div>
            <span class="text-xs text-gray-600 w-4 text-right">{{ result.wuxing[wx] || 0 }}</span>
          </div>
        </div>
      </div>

      <!-- 大运 timeline -->
      <div class="bg-gray-50 rounded-2xl p-4">
        <h2 class="text-xs text-gray-600 font-medium mb-3">大运走势</h2>
        <div class="flex gap-2 overflow-x-auto pb-1">
          <div
            v-for="d in result.dayun"
            :key="d.age"
            class="flex-none flex flex-col items-center gap-1 px-2.5 py-2 rounded-xl min-w-[56px]"
            :class="d.ganzhi === currentDayunLabel ? 'bg-indigo-600/40 border border-indigo-500' : 'bg-gray-100'"
          >
            <span class="text-xs text-gray-500">{{ d.age }}岁</span>
            <span class="text-sm font-bold text-yellow-200">{{ d.ganzhi[0] }}</span>
            <span class="text-sm font-bold text-yellow-100">{{ d.ganzhi[1] }}</span>
          </div>
        </div>
      </div>

      <!-- AI Analysis -->
      <div v-if="result.analysis" class="space-y-3">
        <h2 class="text-xs text-gray-600 font-medium px-1">AI命理解读</h2>
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
      <div v-else class="bg-gray-50/50 rounded-2xl p-4 text-center text-sm text-gray-500">
        AI解读服务未启用
      </div>

      <div class="flex gap-3">
        <button
          v-if="result.share_token"
          class="flex-1 py-3 rounded-xl bg-indigo-600/20 text-indigo-700 font-medium active:opacity-70 flex items-center justify-center gap-1.5"
          @click="shareChart"
        >
          {{ copied ? '✅ 已复制' : '🔗 分享命盘' }}
        </button>
        <button
          class="flex-1 py-3 rounded-xl bg-gray-100 text-gray-600 font-medium active:opacity-70"
          @click="reset"
        >
          重新算命
        </button>
      </div>
    </div>
  </div>
</template>
