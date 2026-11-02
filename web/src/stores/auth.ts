import { defineStore } from 'pinia'
import { authApi } from '@/api/auth'
import { setToken } from '@/api/client'
import type { User } from '@/types/models'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as User | null,
    token: null as string | null,
    error: null as string | null,
    loading: false,
  }),

  getters: {
    isLoggedIn: (state) => !!state.token,
  },

  actions: {
    init() {
      try {
        const token = localStorage.getItem('auth_token')
        const raw = localStorage.getItem('auth_user')
        if (token) {
          this.token = token
          setToken(token)
          if (raw) {
            try { this.user = JSON.parse(raw) } catch {}
          }
        }
      } catch (e) {
        console.error('Init auth error', e)
      }
    },

    async login(email: string, password: string) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.login(email, password)
        const data = (res as any).data ?? res
        if (!data.token) throw new Error('未获取到 Token')
        this.token = data.token
        this.user = data.user
        setToken(data.token)
        localStorage.setItem('auth_user', JSON.stringify(data.user))
        return true
      } catch (e: any) {
        this.error = e.response?.data?.error ?? e.message ?? '登录失败'
        throw new Error(this.error!)
      } finally {
        this.loading = false
      }
    },

    async register(name: string, username: string, email: string, password: string) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.register({ name, username, email, password })
        const data = (res as any).data ?? res
        if (!data.token) throw new Error('注册失败')
        this.token = data.token
        this.user = data.user
        setToken(data.token)
        localStorage.setItem('auth_user', JSON.stringify(data.user))
        return true
      } catch (e: any) {
        this.error = e.response?.data?.error ?? e.message ?? '注册失败'
        throw new Error(this.error!)
      } finally {
        this.loading = false
      }
    },

    async logout() {
      this.token = null
      this.user = null
      setToken(null)
      localStorage.removeItem('auth_user')
      window.location.href = '/login'
    },
  },
})
