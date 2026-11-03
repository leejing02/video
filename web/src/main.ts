import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import './style.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

const auth = useAuthStore()
auth.init()

window.addEventListener('auth:unauthorized', () => {
  if (router.currentRoute.value.name !== 'login') {
    router.replace({ name: 'login' })
  }
})

app.mount('#app')

// Keep the conversation composer above the mobile keyboard.
function resizeViewport() {
  const viewport = window.visualViewport
  if (!viewport || viewport.scale === 1) {
    document.documentElement.style.setProperty('--app-height', `${viewport?.height ?? window.innerHeight}px`)
  }
}
resizeViewport()
window.visualViewport?.addEventListener('resize', resizeViewport)
window.addEventListener('resize', resizeViewport)
// Safari gesture events are separate from pointer touch-action handling.
document.addEventListener('gesturestart', event => event.preventDefault(), { passive: false })
document.addEventListener('gesturechange', event => event.preventDefault(), { passive: false })
