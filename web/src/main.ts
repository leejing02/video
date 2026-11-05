import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './style.css'
import { viewportFrame } from './utils/mobile-shell'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
const auth = useAuthStore()
auth.init()
app.use(router)

window.addEventListener('auth:unauthorized', () => {
  auth.$patch({ token: null, user: null })
  localStorage.removeItem('auth_user')
  if (router.currentRoute.value.name !== 'login') {
    router.replace({ name: 'login' })
  }
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
  if (auth.isLoggedIn && router.currentRoute.value.name === 'login') void router.replace('/chat')
  resizeViewport()
})
document.addEventListener('gesturestart', event => event.preventDefault(), { passive: false })
document.addEventListener('gesturechange', event => event.preventDefault(), { passive: false })

// Custom chat/category gestures handle movement themselves. Prevent a horizontal
// drag elsewhere from moving the document; keep text editing and category scrolling native.
let horizontalStart: { id: number; x: number; y: number } | undefined
const clearHorizontalStart = () => { horizontalStart = undefined }
document.addEventListener('touchstart', event => {
  clearHorizontalStart()
  if (event.touches.length !== 1) return
  if (event.target instanceof Element && event.target.closest('input,textarea,select,[contenteditable],.category-strip')) return
  const touch = event.touches[0]
  horizontalStart = { id: touch.identifier, x: touch.clientX, y: touch.clientY }
}, { passive: true })
document.addEventListener('touchmove', event => {
  const start = horizontalStart
  if (!start || event.touches.length !== 1) return
  const touch = event.touches[0]
  if (touch.identifier !== start.id) return
  const dx = Math.abs(touch.clientX - start.x)
  const dy = Math.abs(touch.clientY - start.y)
  if (dx > 12 && dx > dy * 1.3 && event.cancelable) event.preventDefault()
}, { passive: false })
document.addEventListener('touchend', clearHorizontalStart, { passive: true })
document.addEventListener('touchcancel', clearHorizontalStart, { passive: true })
document.addEventListener('visibilitychange', clearHorizontalStart)
window.addEventListener('pageshow', clearHorizontalStart)
