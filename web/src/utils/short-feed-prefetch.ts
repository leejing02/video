import { shortVideoApi } from '@/api/shortVideo'
import { prefetchCover } from './efv-cover-blob'
import { mediaUrl } from './media-url'

const firstPage = { page: 1, per_page: 30 }
const prefetchLifetime = 60_000
let cachedPage: ReturnType<typeof shortVideoApi.list> | undefined
let expiresAt = 0

export function warmShortFeed() {
  if (cachedPage && Date.now() < expiresAt) return
  const request = shortVideoApi.list(firstPage)
  cachedPage = request
  expiresAt = Date.now() + prefetchLifetime

  void request.then(result => {
    if (cachedPage !== request) return
    const covers = (result.data ?? []).map(video => mediaUrl(video.cover)).filter(Boolean).slice(0, 6)
    for (const cover of covers) prefetchCover(cover, expiresAt)
  }, () => {
    if (cachedPage === request) cachedPage = undefined
  })
}

export function loadShortFeedFirstPage() {
  if (cachedPage && Date.now() < expiresAt) {
    return cachedPage.catch(() => shortVideoApi.list(firstPage))
  }
  return shortVideoApi.list(firstPage)
}
