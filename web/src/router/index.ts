import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { layout: 'plain' },
    },
    {
      path: '/',
      redirect: '/chat',
      component: () => import('@/views/MainLayout.vue'),
      children: [
        { path: 'chat',    name: 'chat',         component: () => import('@/views/ChatView.vue') },
        { path: 'long',    name: 'long',         component: () => import('@/views/LongVideoView.vue') },
        { path: 'short',   name: 'short',        component: () => import('@/views/ShortVideoView.vue') },
        { path: 'live', redirect: '/short' },
        { path: 'fortune', name: 'fortune',      component: () => import('@/views/FortuneView.vue') },
        { path: 'me',      name: 'me',           component: () => import('@/views/ProfileView.vue') },
        { path: 'divination', name: 'divination', component: () => import('@/views/DivinationView.vue') },
        { path: 'dream',      name: 'dream',      component: () => import('@/views/DreamView.vue') },
        { path: 'bazi',       name: 'bazi',       component: () => import('@/views/BaziView.vue') },
        { path: 'upload',  name: 'upload',       component: () => import('@/views/UploadView.vue') },
        { path: 'video/:id', name: 'video-detail', component: () => import('@/views/VideoDetailView.vue') },
      ],
    },
    {
      path: '/fishing',
      name: 'fishing',
      component: () => import('@/views/FishingView.vue'),
      meta: { layout: 'plain' },
    },
    {
      path: '/chart/:token',
      name: 'chart',
      component: () => import('@/views/ChartView.vue'),
      meta: { public: true },
    },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (auth.isLoggedIn && to.name === 'login') return { path: '/chat', replace: true }
  if (!auth.isLoggedIn && !to.meta.public && to.name !== 'login') {
    return { name: 'login', query: { next: to.fullPath } }
  }
})

export default router
