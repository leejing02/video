// Shared harness for the long-video E2E tests (/long, /long/category/:id, /video/:id).
//
// Every request is answered here; nothing reaches a real server:
//   /api/**             → mocked JSON (categories, long-video lists with pages, details,
//                         我的视频, short-video list); state can switch failures and delays on
//   /e2e-long-cover/**  → covers drawn in memory as SVG ("broken" → 404, "slow" → delayed)
//   /e2e-long-media/**  → fixtures/media/test-landscape.mp4 with HTTP Range support
//   other hosts         → aborted and recorded in `blocked`
// The login state is a fixed fake token in localStorage; no account is created.
import { readFileSync } from 'node:fs'
import { expect, type Page } from '@playwright/test'

export const MP4 = readFileSync(new URL('./media/test-landscape.mp4', import.meta.url))
export const MEDIA_URL = '/e2e-long-media/test-landscape.mp4'

export const LONG_CATEGORIES = [
  { id: 1, name: '影视剧' },
  { id: 2, name: '纪录片' },
  { id: 3, name: '教育' },
]
const LABEL: Record<string, string> = { '': '全部', '1': '影视剧', '2': '纪录片', '3': '教育' }

export type CoverKind = 'none' | 'portrait' | 'landscape' | 'broken' | 'slow'
export interface LongVideo {
  id: number
  user_id: number
  category_id: number
  type: 'long'
  title: string
  description: string | null
  cover: string | null
  url: string
  duration: number
  views: number
  likes: number
  comments_count: number
  published_at: string
  user: { id: number; name: string; username: string; avatar: null } | null
}

export function longVideo(id: number, categoryId = 1, o: { title?: string; cover?: CoverKind; url?: string; duration?: number } = {}): LongVideo {
  const cover = o.cover ?? 'none'
  return {
    id, user_id: 1, category_id: categoryId, type: 'long',
    title: o.title ?? `${LABEL[String(categoryId)] ?? '长'}视频 ${id}`,
    description: '不应显示的简介',
    cover: cover === 'none' ? null : `/e2e-long-cover/${cover}-${id}.svg`,
    url: o.url ?? MEDIA_URL,
    duration: o.duration ?? 1200,
    views: 1234, likes: 56, comments_count: 7,
    published_at: '2026-11-01T08:00:00+08:00',
    user: { id: 1, name: '管理员', username: 'admin', avatar: null },
  }
}

/** Mutable mock state; tests may change it between steps (e.g. recover from a failure). */
export interface LongState {
  categories: { id: number; name: string }[]
  /** Explicit list per key: `${categoryId}` for page 1, `${categoryId}:${page}` for later pages ('' = all). */
  lists: Record<string, LongVideo[]>
  /** Total per category key; defaults to the size of the explicit list(s). */
  totals: Record<string, number>
  shortVideos: unknown[]
  categoriesFail: boolean
  videosFail: boolean
  failPage: number | null
  delayMs: Record<string, number>
  brokenMedia: boolean
}

export interface LongHarness {
  state: LongState
  /** pathname + search of every /api request, in order. */
  calls: string[]
  /** Requests to other hosts, which are aborted. */
  blocked: string[]
  pageErrors: string[]
}

/** Default data: categories 1 and 3 have two pages of 30 videos, 2 is empty, '' (all) has two pages. */
function defaultLists() {
  const lists: Record<string, LongVideo[]> = {}
  const totals: Record<string, number> = {}
  for (const key of ['', '1', '3']) {
    const base = (Number(key) || 9) * 1000
    for (const pageNo of [1, 2]) {
      lists[pageNo === 1 ? key : `${key}:${pageNo}`] = Array.from({ length: 30 }, (_, i) => longVideo(base + (pageNo - 1) * 30 + i + 1, Number(key) || 1, { title: `${LABEL[key]}视频 ${base + (pageNo - 1) * 30 + i + 1}` }))
    }
    totals[key] = 60
  }
  lists['2'] = []
  totals['2'] = 0
  return { lists, totals }
}

const svg = (seed: number, landscape: boolean) => {
  const [w, h] = landscape ? [640, 360] : [400, 600]
  const hue = (seed * 47) % 360
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="100%" height="100%" fill="hsl(${hue},55%,50%)"/></svg>`
}

export async function setupLong(page: Page, o: Partial<LongState> & { blockReturnKey?: boolean } = {}): Promise<LongHarness> {
  const defaults = defaultLists()
  const state: LongState = {
    categories: LONG_CATEGORIES, lists: defaults.lists, totals: defaults.totals, shortVideos: [],
    categoriesFail: false, videosFail: false, failPage: null, delayMs: {}, brokenMedia: false,
    ...o,
  }
  if (o.lists && !o.totals) {
    state.totals = {}
    for (const [key, items] of Object.entries(o.lists)) {
      const base = key.split(':')[0]
      state.totals[base] = (state.totals[base] ?? 0) + items.length
    }
  }
  const harness: LongHarness = { state, calls: [], blocked: [], pageErrors: [] }
  page.on('pageerror', error => harness.pageErrors.push(error.message))

  await page.addInitScript(block => {
    localStorage.setItem('auth_token', 'e2e-token')
    localStorage.setItem('auth_user', JSON.stringify({ id: 1, name: '测试用户', username: 'tester', email: 'tester@example.com' }))
    if (block) {
      // Simulates a full storage quota for the long-list return state only.
      const setItem = Storage.prototype.setItem
      Storage.prototype.setItem = function (key: string, value: string) {
        if (key === 'long-list-return') throw new DOMException('quota', 'QuotaExceededError')
        return setItem.call(this, key, value)
      }
    }
  }, !!o.blockReturnKey)

  await page.route(url => url.hostname !== '127.0.0.1' && url.hostname !== 'localhost', route => {
    harness.blocked.push(route.request().url())
    return route.abort()
  })
  await page.route(url => url.pathname.startsWith('/e2e-long-cover/'), async route => {
    const name = new URL(route.request().url()).pathname.split('/').pop() ?? ''
    const seed = Number(name.replace(/\D/g, ''))
    if (name.startsWith('broken')) return route.fulfill({ status: 404, body: '' })
    if (name.startsWith('slow')) await new Promise(resolve => setTimeout(resolve, 4000))
    return route.fulfill({ contentType: 'image/svg+xml', body: svg(seed, name.startsWith('landscape')) })
  })
  await page.route(url => url.pathname.startsWith('/e2e-long-media/'), route => {
    if (state.brokenMedia || !route.request().url().endsWith('/test-landscape.mp4')) return route.fulfill({ status: 404, body: '' })
    // Byte ranges like a static file server, so the browser can seek.
    const range = /bytes=(\d*)-(\d*)/.exec(route.request().headers()['range'] ?? '')
    if (!range) return route.fulfill({ status: 200, contentType: 'video/mp4', body: MP4, headers: { 'Accept-Ranges': 'bytes' } })
    const start = range[1] ? Number(range[1]) : 0
    const end = range[2] ? Math.min(Number(range[2]), MP4.length - 1) : MP4.length - 1
    return route.fulfill({
      status: 206, contentType: 'video/mp4', body: MP4.subarray(start, end + 1),
      headers: { 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${start}-${end}/${MP4.length}` },
    })
  })
  await page.route(url => url.pathname.startsWith('/api/'), async route => {
    const url = new URL(route.request().url())
    harness.calls.push(url.pathname + url.search)
    if (url.pathname === '/api/categories') {
      if (state.categoriesFail) return route.fulfill({ status: 500, json: { error: 'mock failure' } })
      return route.fulfill({ json: state.categories.map(c => ({ ...c, slug: 's' + c.id, kind: 'long', icon: null, cover: null, sort: c.id, is_active: true })) })
    }
    if (url.pathname === '/api/videos') {
      const cid = url.searchParams.get('category_id') ?? ''
      const pageNo = Number(url.searchParams.get('page') ?? '1')
      if (state.delayMs[cid]) await new Promise(resolve => setTimeout(resolve, state.delayMs[cid]))
      if (state.videosFail || state.failPage === pageNo) return route.fulfill({ status: 500, json: { error: 'mock failure' } })
      const items = state.lists[pageNo === 1 ? cid : `${cid}:${pageNo}`] ?? []
      return route.fulfill({ json: { data: items, total: state.totals[cid] ?? items.length, page: pageNo, per_page: 30 } })
    }
    const detail = url.pathname.match(/^\/api\/videos\/(\d+)$/)
    if (detail) {
      const id = Number(detail[1])
      const known = Object.values(state.lists).flat().find(v => v.id === id)
      return route.fulfill({ json: known ?? longVideo(id, 1, { title: `详情视频 ${id}` }) })
    }
    if (url.pathname === '/api/me/videos') {
      return route.fulfill({ json: { data: [longVideo(7001, 1, { title: '我的视频 7001' }), longVideo(7002, 1, { title: '我的视频 7002' })], total: 2, page: 1, per_page: 20 } })
    }
    if (url.pathname.startsWith('/api/short-videos')) {
      return route.fulfill({ json: { data: state.shortVideos, total: state.shortVideos.length, page: 1, per_page: 30 } })
    }
    return route.fulfill({ json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } })
  })
  return harness
}

/** /api list requests made after index `from` (videos and categories only). */
export const listCalls = (h: LongHarness, from = 0) => h.calls.slice(from).filter(c => c.startsWith('/api/videos?') || c.startsWith('/api/categories'))

export const tiles = (page: Page) => page.locator('.video-grid:not(.invisible) .video-tile')
export const posters = (page: Page) => page.locator('.video-grid:not(.invisible) .video-poster')
export const scrollArea = (page: Page) => page.locator('.discovery-scroll')
export const header = (page: Page) => page.locator('.discovery-header')
export const homeCards = (page: Page) => page.locator('.long-category-card')
export const nav = (page: Page, name: string) => page.locator('nav.bottom-nav').getByRole('button', { name, exact: true })
export const scrollTop = (page: Page) => scrollArea(page).evaluate(el => el.scrollTop)

/** Waits for `count` animation frames (layout settled) without a fixed delay. */
export const frames = (page: Page, count = 2) => page.evaluate(n => new Promise<void>(resolve => {
  const step = (left: number) => left ? requestAnimationFrame(() => step(left - 1)) : resolve()
  step(n)
}), count)

/** Sets the list scroll position once a single grid is on screen. */
export async function scrollListTo(page: Page, top: number) {
  // A previous grid can still be sliding out after navigation; wait for one grid.
  await expect(page.locator('.category-track > .video-grid')).toHaveCount(1)
  await scrollArea(page).evaluate((el, t) => { el.scrollTop = t }, top)
  await expect.poll(() => scrollTop(page)).toBe(top)
}

/** Clicks the first card fully inside the visible part of the list; returns its video id. */
export async function openVisibleVideo(page: Page) {
  const index = await scrollArea(page).evaluate(el => {
    const view = el.getBoundingClientRect()
    return [...el.querySelectorAll<HTMLElement>('.video-grid:not(.invisible) .video-tile')]
      .findIndex(tile => { const r = tile.getBoundingClientRect(); return r.top >= view.top && r.bottom <= view.bottom })
  })
  expect(index, 'a fully visible card').toBeGreaterThanOrEqual(0)
  const label = await tiles(page).nth(index).getAttribute('aria-label')
  const id = Number(/(\d+)$/.exec(label ?? '')?.[1])
  await tiles(page).nth(index).click()
  await expect(page).toHaveURL(new RegExp(`/video/${id}$`))
  await expect(page.locator('h2')).toBeVisible()
  return id
}

/** Checks the restored long list: route, no category strip, items, scroll position, title, tab. */
export async function expectRestoredList(page: Page, o: { categoryId: number; count: number; top: number; firstTitle: RegExp }) {
  await expect(page).toHaveURL(new RegExp(`/long/category/${o.categoryId}$`))
  await expect(page.locator('.category-strip')).toHaveCount(0)
  await expect(tiles(page)).toHaveCount(o.count)
  await expect(tiles(page).first()).toContainText(o.firstTitle)
  await expect.poll(() => scrollTop(page), { timeout: 3000 }).toBe(o.top)
  await expect(header(page).locator('.feed-tabs .selected')).toHaveText('长视频')
  await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
}
