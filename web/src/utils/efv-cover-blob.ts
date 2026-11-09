import type { ObjectDirective } from 'vue'
import { decodeEfvImage, isEfvImageUrl, type EfvImageConfig } from './efv-image'

interface CoverRequest {
  controller: AbortController
  observer?: IntersectionObserver
  objectUrl?: string
}

const requests = new WeakMap<HTMLElement, CoverRequest>()

const efvCoverPath = /^\/efv-covers\/\d+(?:\?.*)?$/
const warmedCovers = new Map<string, { blob: Promise<Blob>; expiresAt: number }>()

let imageConfig: Promise<EfvImageConfig | null> | undefined
function loadImageConfig() {
  imageConfig ??= fetch('/api/efv-media', { cache: 'no-store' })
    .then(response => (response.ok ? response.json() as Promise<EfvImageConfig> : null))
    .catch(() => null)
    .then(config => {
      // Retry later instead of keeping a failed lookup for the whole session.
      if (!config) setTimeout(() => { imageConfig = undefined }, 30_000)
      return config
    })
  return imageConfig
}

const needsFetch = (value: string) => efvCoverPath.test(value) || isEfvImageUrl(value)

async function fetchCover(value: string, signal: AbortSignal) {
  if (isEfvImageUrl(value)) {
    // The EFV image domain sends encrypted bytes with CORS; decrypt them here.
    const [response, config] = await Promise.all([fetch(value, { signal, mode: 'cors' }), loadImageConfig()])
    if (!response.ok) throw new Error('EFV image request failed')
    const blob = await decodeEfvImage(await response.arrayBuffer(), config)
    if (!blob?.size) throw new Error('EFV image could not be decoded')
    return blob
  }
  const response = await fetch(value, { signal })
  if (!response.ok) throw new Error('EFV cover request failed')
  const blob = await response.blob()
  if (!blob.size || !blob.type.startsWith('image/')) throw new Error('Invalid EFV cover response')
  return blob
}

export function prefetchCover(value: string, expiresAt: number) {
  if (!needsFetch(value)) {
    const image = new Image()
    image.src = value
    return image.decode().catch(() => {})
  }

  const cached = warmedCovers.get(value)
  if (cached && Date.now() < cached.expiresAt) return cached.blob.catch(() => {})
  const entry = { blob: fetchCover(value, AbortSignal.timeout(10_000)), expiresAt }
  warmedCovers.set(value, entry)
  const remove = () => {
    if (warmedCovers.get(value) === entry) warmedCovers.delete(value)
  }
  setTimeout(remove, Math.max(0, expiresAt - Date.now()))
  return entry.blob.catch(remove)
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
  if (!needsFetch(value)) {
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
      if (requests.get(el) !== request) return
      // EFV domain unreachable or undecodable: show the local copy instead.
      const fallback = el.dataset.coverFallback
      if (fallback && fallback !== value) setCover(el, fallback, poster)
      else if (!poster) el.dispatchEvent(new Event('error'))
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
