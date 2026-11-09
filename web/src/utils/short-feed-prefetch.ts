import { shortVideoApi } from '@/api/shortVideo'
import { prefetchCover } from './efv-cover-blob'
import { mediaUrl } from './media-url'

const prefetchLifetime = 60_000
const cachedPages = new Map<number | null, {
  page: ReturnType<typeof shortVideoApi.list>
  ready: Promise<void>
  expiresAt: number
}>()

function requestFirstPage(categoryId: number | null) {
  return shortVideoApi.list({ category_id: categoryId ?? undefined, page: 1, per_page: 30 })
}

export function warmShortFeed(categoryId: number | null = null) {
  const cached = cachedPages.get(categoryId)
  if (cached && Date.now() < cached.expiresAt) return cached.ready

  const page = requestFirstPage(categoryId)
  const expiresAt = Date.now() + prefetchLifetime
  const remove = () => {
    if (cachedPages.get(categoryId)?.page === page) cachedPages.delete(categoryId)
  }
  const ready = page.then(async result => {
    if (cachedPages.get(categoryId)?.page !== page) return
    const covers = (result.data ?? []).map(video => mediaUrl(video.cover)).filter(Boolean).slice(0, 6)
    await Promise.all(covers.map(cover => prefetchCover(cover, expiresAt)))
  }, remove)

  cachedPages.set(categoryId, { page, ready, expiresAt })
  setTimeout(remove, prefetchLifetime)
  return ready
}

export function loadShortFeedFirstPage(categoryId: number | null) {
  const cached = cachedPages.get(categoryId)
  if (cached && Date.now() < cached.expiresAt) {
    return cached.page.catch(() => requestFirstPage(categoryId))
  }
  return requestFirstPage(categoryId)
}
