<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()

const features = [
  {
    icon: '🔯',
    title: '八字命理',
    subtitle: 'AI 深度解读四柱大运',
    badge: 'AI',
    badgeColor: 'bg-purple-500/20 text-purple-700',
    gradient: 'from-purple-50 to-indigo-50',
    border: 'border-purple-700/30',
    route: '/bazi',
  },
  {
    icon: '🔮',
    title: '易经卜卦',
    subtitle: '六十四卦洞察运势',
    badge: '卦象',
    badgeColor: 'bg-yellow-500/20 text-yellow-700',
    gradient: 'from-yellow-50 to-amber-50',
    border: 'border-yellow-700/30',
    route: '/divination',
  },
  {
    icon: '🌙',
    title: '周公解梦',
    subtitle: 'AI 解析梦境深层含义',
    badge: 'AI',
    badgeColor: 'bg-blue-500/20 text-blue-700',
    gradient: 'from-blue-50 to-indigo-50',
    border: 'border-blue-700/30',
    route: '/dream',
  },
]

const tips = [
  '日主强弱决定喜忌，用神是命理调候核心',
  '大运十年一换，流年岁岁更新，把握节点最重要',
  '五行缺什么，后天补什么，但不必执着',
  '命盘是概率地图，而非命运判决书',
]

const randomTip = tips[Math.floor(Date.now() / 86400000) % tips.length]
</script>

<template>
  <div class="h-full flex flex-col bg-white text-gray-900 overflow-y-auto">

    <!-- Header -->
    <header class="flex-none px-5 pt-5 pb-4 safe-top">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold tracking-tight">命理</h1>
          <p class="text-xs text-gray-500 mt-0.5">AI 驱动的东方人生分析</p>
        </div>
        <div class="text-3xl">☯</div>
      </div>
    </header>

    <!-- Daily tip -->
    <div class="mx-5 mb-5 px-4 py-3 rounded-2xl bg-indigo-50 border border-indigo-800/30">
      <p class="text-xs text-indigo-700 leading-relaxed">
        <span class="text-indigo-500 mr-1">✦</span>{{ randomTip }}
      </p>
    </div>

    <!-- Feature cards -->
    <div class="px-5 space-y-3 flex-1">

      <!-- Bazi — hero card -->
      <button
        class="w-full rounded-2xl p-5 text-left border bg-gradient-to-br active:opacity-80 transition-opacity"
        :class="[features[0].gradient, features[0].border]"
        @click="router.push(features[0].route)"
      >
        <div class="flex items-start justify-between mb-3">
          <span class="text-4xl">{{ features[0].icon }}</span>
          <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="features[0].badgeColor">
            {{ features[0].badge }}
          </span>
        </div>
        <div class="text-lg font-bold text-gray-900 mb-1">{{ features[0].title }}</div>
        <div class="text-sm text-gray-600">{{ features[0].subtitle }}</div>
        <div class="mt-4 flex items-center gap-1.5 text-sm text-purple-700 font-medium">
          <span>输入生辰，排盘解命</span>
          <span class="text-base">›</span>
        </div>
      </button>

      <!-- Divination + Dream — side by side -->
      <div class="grid grid-cols-2 gap-3">
        <button
          v-for="f in features.slice(1)"
          :key="f.title"
          class="rounded-2xl p-4 text-left border bg-gradient-to-br active:opacity-80 transition-opacity"
          :class="[f.gradient, f.border]"
          @click="router.push(f.route)"
        >
          <span class="text-3xl block mb-2">{{ f.icon }}</span>
          <div class="text-sm font-bold text-gray-900 mb-0.5">{{ f.title }}</div>
          <div class="text-xs text-gray-600 leading-snug">{{ f.subtitle }}</div>
        </button>
      </div>

      <!-- How it works -->
      <div class="rounded-2xl bg-gray-50/80 border border-gray-200/50 p-4 mt-1">
        <h3 class="text-xs font-semibold text-gray-600 mb-3 tracking-wider">命理系统说明</h3>
        <div class="space-y-2.5">
          <div v-for="(step, i) in [
            ['四柱', '年月日时干支，构成命盘骨架'],
            ['藏干十神', '五行力量分析，判断日主强弱'],
            ['大运流年', '十年一换，岁岁更新，精算时机'],
            ['AI 解读', 'Claude 深度解析，避免迷信化'],
          ]" :key="i" class="flex items-start gap-3">
            <span class="flex-none w-5 h-5 rounded-full bg-indigo-600/30 text-indigo-700 text-xs flex items-center justify-center font-bold">
              {{ i + 1 }}
            </span>
            <div>
              <span class="text-xs font-semibold text-gray-800">{{ step[0] }}</span>
              <span class="text-xs text-gray-500 ml-1.5">{{ step[1] }}</span>
            </div>
          </div>
        </div>
      </div>

    </div>

    <div class="h-4 safe-bottom" />
  </div>
</template>
