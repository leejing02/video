export function showToast(msg: string, duration = 2000) {
  const el = document.createElement('div')
  el.textContent = msg
  el.className = 'fixed top-20 left-1/2 -translate-x-1/2 z-50 bg-black/80 text-white px-4 py-2 rounded-full text-sm'
  document.body.appendChild(el)
  setTimeout(() => el.remove(), duration)
}
