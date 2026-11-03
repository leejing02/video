<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const mode = ref<'login' | 'register'>('login')
const name = ref('')
const username = ref('')
const email = ref('')
const password = ref('')
const error = ref('')

async function submit() {
  error.value = ''
  try {
    if (mode.value === 'login') {
      if (await auth.login(email.value, password.value)) {
        const next = (router.currentRoute.value.query.next as string) || '/'
        window.location.href = next
      }
    } else {
      if (await auth.register(name.value, username.value, email.value, password.value)) {
        window.location.href = '/'
      }
    }
  } catch (e: any) {
    error.value = e.message || '网络异常，请稍后重试'
  }
}

function switchMode(m: 'login' | 'register') {
  mode.value = m
  error.value = ''
  name.value = ''
  username.value = ''
  email.value = ''
  password.value = ''
}
</script>

<template>
  <div class="h-full overflow-y-auto flex flex-col safe-top safe-bottom px-6 pt-16 bg-black text-white">
    <h1 class="text-3xl font-bold mb-1">{{ mode === 'login' ? '登录' : '注册' }}</h1>
    <p class="text-gray-400 text-sm mb-6">
      {{ mode === 'login' ? '还没账号？' : '已有账号？' }}
      <button
        class="text-primary underline"
        @click="switchMode(mode === 'login' ? 'register' : 'login')"
      >{{ mode === 'login' ? '立即注册' : '去登录' }}</button>
    </p>

    <form class="flex flex-col gap-3" @submit.prevent="submit">
      <template v-if="mode === 'register'">
        <input
          v-model="name"
          type="text"
          placeholder="昵称"
          required
          class="px-4 py-3 rounded-xl bg-gray-900 border border-gray-800 outline-none focus:border-primary"
        />
        <input
          v-model="username"
          type="text"
          placeholder="用户名（字母/数字/下划线）"
          required
          pattern="[a-zA-Z0-9_]+"
          class="px-4 py-3 rounded-xl bg-gray-900 border border-gray-800 outline-none focus:border-primary"
        />
      </template>

      <input
        v-model="email"
        type="email"
        placeholder="邮箱"
        required
        class="px-4 py-3 rounded-xl bg-gray-900 border border-gray-800 outline-none focus:border-primary"
      />
      <input
        v-model="password"
        type="password"
        :placeholder="mode === 'register' ? '密码（至少 6 位）' : '密码'"
        required
        :minlength="mode === 'register' ? 6 : undefined"
        class="px-4 py-3 rounded-xl bg-gray-900 border border-gray-800 outline-none focus:border-primary"
      />

      <p v-if="error" class="text-red-400 text-sm">{{ error }}</p>

      <button
        type="submit"
        :disabled="auth.loading"
        class="mt-3 py-3 rounded-xl bg-primary text-white font-medium disabled:opacity-50"
      >
        {{ auth.loading ? (mode === 'login' ? '登录中…' : '注册中…') : (mode === 'login' ? '登录' : '注册') }}
      </button>
    </form>
  </div>
</template>
