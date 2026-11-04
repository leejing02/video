import type { ObjectDirective } from 'vue'

interface CoverRequest {
  controller: AbortController
  observer?: IntersectionObserver
  objectUrl?: string
}

const requests = new WeakMap<HTMLElement, CoverRequest>()

const efvCoverPath = /^\/efv-covers\/\d+(?:\?.*)?$/
const warmedCovers = new Map<string, { blob: Promise<Blob>; expiresAt: number }>()

async function fetchCover(value: string, signal: AbortSignal) {
  const response = await fetch(value, { signal })
  if (!response.ok) throw new Error('EFV cover request failed')
  const blob = await response.blob()
  if (!blob.size || !blob.type.startsWith('image/')) throw new Error('Invalid EFV cover response')
  return blob
}

export function prefetchCover(value: string, expiresAt: number) {
  if (!efvCoverPath.test(value)) {
    const image = new Image()
    image.src = value
    void image.decode().catch(() => {})
    return
  }

  if (warmedCovers.has(value)) return
  const entry = { blob: fetchCover(value, AbortSignal.timeout(10_000)), expiresAt }
  warmedCovers.set(value, entry)
  const remove = () => {
    if (warmedCovers.get(value) === entry) warmedCovers.delete(value)
  }
  void entry.blob.catch(remove)
  setTimeout(remove, Math.max(0, expiresAt - Date.now()))
}

function release(el: HTMLElement) {
  const request = requests.get(el)
  if (!request) return
  requests.delete(el)
  request.observer?.disconnect()
  request.controller.abort()
  if (request.objectUrl) URL.revokeObjectURL(request.objectUrl)
}

function setCover(el: HTMLElement, value: string, poster: boolean) {
  release(el)
  const attribute = poster ? 'poster' : 'src'
  if (!efvCoverPath.test(value)) {
    if (value) el.setAttribute(attribute, value)
    else el.removeAttribute(attribute)
    return
  }

  el.removeAttribute(attribute)
  const request: CoverRequest = { controller: new AbortController() }
  requests.set(el, request)

  async function load() {
    request.observer?.disconnect()
    try {
      const warmed = warmedCovers.get(value)
      const fetchCurrent = () => fetchCover(value, request.controller.signal)
      const blob = warmed && Date.now() < warmed.expiresAt
        ? await warmed.blob.catch(fetchCurrent)
        : await fetchCurrent()
      if (requests.get(el) !== request) return

      request.objectUrl = URL.createObjectURL(blob)
      el.setAttribute(attribute, request.objectUrl)
    } catch {
      if (requests.get(el) === request && !poster) el.dispatchEvent(new Event('error'))
    }
  }

  if (!poster && el.getAttribute('loading') !== 'eager' && typeof IntersectionObserver !== 'undefined') {
    const feed = el.closest('.short-masonry')?.closest('.discovery-scroll')
    request.observer = new IntersectionObserver(entries => {
      if (entries.some(entry => entry.isIntersecting)) void load()
    }, { root: feed ?? null, rootMargin: feed ? '200px 0px 800px' : '200px' })
    request.observer.observe(el)
  } else {
    void load()
  }
}

export const vEfvCoverBlob: ObjectDirective<HTMLElement, string> = {
  mounted(el, { value, arg }) {
    setCover(el, value, arg === 'poster')
  },
  updated(el, { value, oldValue, arg }) {
    if (value !== oldValue) setCover(el, value, arg === 'poster')
  },
  beforeUnmount: release,
}
