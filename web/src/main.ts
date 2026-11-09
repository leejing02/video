import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './style.css'
import { viewportFrame } from './utils/mobile-shell'
import { installEdgeGestureGuard } from './utils/edge-gesture'

installEdgeGestureGuard()

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
const auth = useAuthStore()
auth.init()
app.use(router)

window.addEventListener('auth:unauthorized', async () => {
  const wasGuest = auth.isGuest || !auth.user
  auth.$patch({ token: null, user: null })
  localStorage.removeItem('auth_user')
  localStorage.removeItem('auth_guest_expires')
  if (router.currentRoute.value.name === 'login') return
  // An expired guest identity is renewed for this device; real accounts sign in again.
  if (wasGuest && await auth.ensureGuest()) return
  router.replace({ name: 'login' })
})

app.mount('#app')

// Match the visual viewport, including Safari's keyboard-induced vertical offset.
let viewportUpdate = 0
function resizeViewport() {
  cancelAnimationFrame(viewportUpdate)
  viewportUpdate = requestAnimationFrame(() => {
    const viewport = window.visualViewport
    if (viewport && viewport.scale !== 1) return
    const frame = viewportFrame(window.innerHeight, viewport?.height, viewport?.offsetTop)
    const root = document.documentElement
    root.style.setProperty('--app-height', `${frame.height}px`)
    root.style.setProperty('--app-top', `${frame.top}px`)
    const active = document.activeElement
    const editing = active instanceof HTMLTextAreaElement || (active instanceof HTMLInputElement && !['checkbox','radio','range','button','submit'].includes(active.type))
    root.classList.toggle('input-focused', editing)
  })
}
resizeViewport()
window.visualViewport?.addEventListener('resize', resizeViewport)
window.visualViewport?.addEventListener('scroll', resizeViewport)
window.addEventListener('resize', resizeViewport)
document.addEventListener('focusin', resizeViewport)
document.addEventListener('focusout', resizeViewport)
// Restore auth after Safari restores an older document from its back-forward cache.
window.addEventListener('pageshow', () => {
  auth.init()
  if (auth.isLoggedIn && !auth.isGuest && router.currentRoute.value.name === 'login') void router.replace('/chat')
  resizeViewport()
})
document.addEventListener('gesturestart', event => event.preventDefault(), { passive: false })
document.addEventListener('gesturechange', event => event.preventDefault(), { passive: false })
