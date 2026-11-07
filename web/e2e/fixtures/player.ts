// Shared harness for /player.html UI regression tests.
//
// Every request is routed through one dispatcher:
//   /api/**          → fixed JSON mocks; anything not mocked fails the test
//   /storage/e2e/**  → covers generated in memory (solid-colour PNGs of a given size)
//   /e2e-media/**    → 404 (these tests do not depend on video playback)
//   /long, /short    → a stub list page, so leaving the player loads no real app
//   other hosts      → blocked and fail the test
// No production API, database, account or image is ever contacted.
import { test as base, expect, type Locator, type Page, type Route } from '@playwright/test'
import { decodePng, pixelAt, solidPng } from './png'

export type Rgb = [number, number, number]
export interface CoverSpec { width: number; height: number; color?: Rgb }

export interface FakeVideo {
  id: number
  title: string
  description: null
  url: string
  cover: string
  cover_fallback: null
  duration: number
  views: number
  likes: number
  favorites: number
  shares: number
  comments_count: number
  user: { id: number; name: string; username: string; email: string; avatar: null; bio: null; phone: null; role: string }
}

interface Mocks {
  videos: Map<number, FakeVideo>
  commentsEnabled: boolean
  unmocked: string[]
  external: string[]
  pageErrors: string[]
  holdMain?: Promise<void>
}

export const WHITE: Rgb = [255, 255, 255]
const DEFAULT_COVER: Rgb = [40, 90, 160]

export function coverUrl({ width, height, color = DEFAULT_COVER }: CoverSpec) {
  return `/storage/e2e/cover-${width}x${height}-${color.join('-')}.png`
}

export function makeVideo(id: number, cover: CoverSpec = { width: 720, height: 1280 }): FakeVideo {
  return {
    id,
    title: `E2E 视频 ${id}`,
    description: null,
    url: `/e2e-media/video-${id}.mp4`,
    cover: coverUrl(cover),
    cover_fallback: null,
    duration: 12,
    views: 0,
    likes: 3,
    favorites: 1,
    shares: 0,
    comments_count: 0,
    user: { id: 7000 + id, name: 'E2E 作者', username: 'e2e_author', email: 'author@e2e.invalid', avatar: null, bio: null, phone: null, role: 'user' },
  }
}

const GUEST = {
  token: 'e2e-guest-token',
  user: { id: 90001, name: 'e2eguest01', username: 'guest_e2e', email: 'guest-e2e@guest.invalid', avatar: null, bio: null, phone: null, role: 'user' },
}

const covers = new Map<string, Buffer>()
function coverPng(path: string) {
  const match = path.match(/^\/storage\/e2e\/cover-(\d+)x(\d+)-(\d+)-(\d+)-(\d+)\.png$/)
  if (!match) return null
  let png = covers.get(path)
  if (!png) {
    const [, w, h, r, g, b] = match.map(Number)
    png = solidPng(w, h, [r, g, b])
    covers.set(path, png)
  }
  return png
}

function json(route: Route, body: unknown, status = 200) {
  return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

function mockApi(route: Route, url: URL, mocks: Mocks) {
  const method = route.request().method()
  const path = url.pathname
  if (method === 'GET' && path === '/api/video-comments/settings') return json(route, { enabled: mocks.commentsEnabled })
  if (method === 'GET' && path === '/api/efv-media') return json(route, { image_origin: null, cipher: null, key: null, iv: null })
  const match = path.match(/^\/api\/short-videos\/(\d+)(\/interactions|\/comments)?$/)
  const video = match ? mocks.videos.get(Number(match[1])) : undefined
  if (method === 'GET' && video) {
    if (!match![2]) return json(route, { video, liked: false, favorited: false })
    if (match![2] === '/interactions') {
      const { likes, favorites, comments_count, shares } = video
      return json(route, { likes, favorites, comments_count, shares, liked: false, favorited: false })
    }
    return json(route, { data: [], total: 0, page: 1, per_page: 20 })
  }
  mocks.unmocked.push(`${method} ${path}${url.search}`)
  return json(route, { error: 'not mocked in e2e' }, 501)
}

async function dispatch(route: Route, origin: string, mocks: Mocks) {
  const request = route.request()
  const url = new URL(request.url())
  if (url.origin !== origin) {
    mocks.external.push(`${request.method()} ${request.url()}`)
    return route.abort('blockedbyclient')
  }
  const path = url.pathname
  if (path.startsWith('/api/')) return mockApi(route, url, mocks)
  if (path.startsWith('/storage/e2e/')) {
    const png = coverPng(path)
    return png ? route.fulfill({ contentType: 'image/png', body: png }) : route.fulfill({ status: 404, body: '' })
  }
  if (path.startsWith('/e2e-media/')) return route.fulfill({ status: 404, body: '' })
  if (request.isNavigationRequest() && (path === '/long' || path === '/short')) {
    return route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>e2e list stub</title><main id="e2e-list-stub">list</main>' })
  }
  if (mocks.holdMain && path === '/src/main.ts') await mocks.holdMain
  return route.continue()
}

export const test = base.extend<{ mocks: Mocks }>({
  mocks: [async ({ page, baseURL }, use) => {
    const origin = new URL(baseURL!).origin
    const mocks: Mocks = { videos: new Map(), commentsEnabled: false, unmocked: [], external: [], pageErrors: [] }
    page.on('pageerror', error => mocks.pageErrors.push(String(error)))
    await page.route('**/*', route => dispatch(route, origin, mocks))
    await use(mocks)
    expect(mocks.unmocked, 'API requests without a mock').toEqual([])
    expect(mocks.external, 'requests to other hosts').toEqual([])
    expect(mocks.pageErrors, 'uncaught page errors').toEqual([])
  }, { auto: true }],
})
export { expect }

export interface OpenOptions {
  items: FakeVideo[]
  index?: number
  comments?: boolean
  /** Hold /src/main.ts so the page stays in its pre-Vue state until the returned release() is called. */
  holdApp?: boolean
}

/**
 * Opens the player the way the list does: the clicked feed is saved in sessionStorage
 * and player.html is loaded with ?id=&entry=. The visitor is a seeded fake guest.
 */
export async function openPlayer(page: Page, mocks: Mocks, { items, index = 0, comments = false, holdApp = false }: OpenOptions) {
  mocks.commentsEnabled = comments
  for (const video of items) mocks.videos.set(video.id, video)
  const id = items[index].id
  await page.addInitScript(({ items, id, guest }) => {
    if (location.pathname !== '/player.html') return
    localStorage.setItem('auth_token', guest.token)
    localStorage.setItem('auth_user', JSON.stringify(guest.user))
    localStorage.setItem('auth_guest_expires', new Date(Date.now() + 86_400_000).toISOString())
    if (sessionStorage.getItem('e2e-seeded')) return
    const cover = items.find(item => item.id === id)?.cover
    sessionStorage.setItem('short-playback-feed', JSON.stringify({ id, entry: 'e2e', created: Date.now(), items, cover, path: '/long', discovery: { top: 0 } }))
    sessionStorage.setItem('e2e-seeded', '1')
  }, { items, id, guest: GUEST })

  let release = () => {}
  if (holdApp) mocks.holdMain = new Promise<void>(resolve => { release = resolve })
  await page.goto(`/player.html?id=${id}&entry=e2e`, { waitUntil: holdApp ? 'commit' : 'load' })
  if (!holdApp) await waitForPlayer(page, items.length)
  return { release }
}

export async function waitForPlayer(page: Page, count: number) {
  await expect(page.locator('.short-player')).toBeVisible()
  await expect(page.locator('section[data-idx]')).toHaveCount(count)
}

export function section(page: Page, idx: number): Locator {
  return page.locator(`section[data-idx="${idx}"]`)
}

/** The scroll-snap feed: the parent of the video sections. */
export function feed(page: Page): Locator {
  return section(page, 0).locator('xpath=..')
}

/** Index of the video the player treats as current (the only section without `inert`). */
export async function activeIndex(page: Page) {
  return page.locator('section[data-idx]').evaluateAll(sections => {
    const live = sections.filter(el => !el.hasAttribute('inert'))
    return live.length === 1 ? Number((live[0] as HTMLElement).dataset.idx) : -live.length
  })
}

/** Waits until the feed has settled on `idx`: snapped exactly to that screen and marked current. */
export async function expectSettledOn(page: Page, idx: number) {
  const list = feed(page)
  await expect.poll(async () => {
    const { top, height } = await list.evaluate(el => ({ top: el.scrollTop, height: el.clientHeight }))
    return Math.abs(top - idx * height) <= 1 ? await activeIndex(page) : `scrollTop ${top} (screen ${height})`
  }, { message: `feed should settle on video ${idx}` }).toBe(idx)
}

/**
 * Moves the feed by `screens` (positive = next video) and leaves the final position to scroll-snap.
 * Chromium: real wheel input over the feed, one full screen per step.
 * WebKit: Playwright's mobile WebKit has neither wheel nor touch-scroll input, so the feed is
 * scrolled 60% of a screen programmatically and CSS scroll-snap must finish the move.
 */
export async function swipeScreens(page: Page, screens: number) {
  const viewport = page.viewportSize()!
  if (page.context().browser()?.browserType().name() === 'webkit') {
    await feed(page).evaluate((el, screens) => el.scrollBy({ top: screens * el.clientHeight * 0.6, behavior: 'instant' }), screens)
    return
  }
  await page.mouse.move(viewport.width / 2, viewport.height / 2)
  await page.mouse.wheel(0, screens * viewport.height)
}

export async function waitForCover(page: Page, idx: number) {
  const img = section(page, idx).locator('img.short-playback-cover')
  await expect(img).toHaveCount(1)
  await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0 && el.style.height !== ''),
    { message: `cover of video ${idx} should load and be fitted` }).toBe(true)
  return img
}

const BACK = { x: 12, y: 12, width: 44, height: 44 }

export async function expectBackButtonInPlace(page: Page) {
  const back = page.getByRole('button', { name: '返回视频列表' })
  await expect(back).toHaveCount(1)
  await expect(back).toBeVisible()
  const box = (await back.boundingBox())!
  expect(box.x).toBeCloseTo(BACK.x, 0)
  expect(box.y).toBeCloseTo(BACK.y, 0)
  expect(box.width).toBeCloseTo(BACK.width, 0)
  expect(box.height).toBeCloseTo(BACK.height, 0)
  return back
}

/**
 * The top of the player must stay pure black (#000000):
 * the status-bar strip from player.html exists, is black, sits on top of everything,
 * and the rendered pixels of that strip are black even over a bright cover.
 */
export async function expectTopBlack(page: Page) {
  const viewport = page.viewportSize()!
  const tint = page.locator('#playback-status-tint')
  await expect(tint).toHaveCount(1)
  await expect(tint).toHaveCSS('background-color', 'rgb(0, 0, 0)')
  await expect(tint).toHaveCSS('position', 'fixed')
  await expect(tint).toHaveCSS('z-index', '2147483647')
  const box = (await tint.boundingBox())!
  expect(box.x).toBe(0)
  expect(box.y).toBe(0)
  expect(box.width).toBeCloseTo(viewport.width, 0)
  expect(box.height).toBeGreaterThanOrEqual(12)
  for (const x of [1, viewport.width / 2, viewport.width - 1]) {
    expect(await page.evaluate(([x]) => document.elementFromPoint(x, 2)?.id, [x]), `topmost element at (${x}, 2)`).toBe('playback-status-tint')
  }
  await expect(page.locator('html')).toHaveCSS('background-color', 'rgb(0, 0, 0)')
  await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(0, 0, 0)')

  const shot = decodePng(await page.screenshot({ clip: { x: 0, y: 0, width: viewport.width, height: 12 } }))
  let bright = 0
  for (let y = 0; y < shot.height; y++) {
    for (let x = 0; x < shot.width; x++) {
      if (pixelAt(shot, x, y).some(channel => channel > 8)) bright++
    }
  }
  expect(bright, 'non-black pixels in the top 12px').toBe(0)
}

/** Colour actually rendered at a CSS-pixel position (used to prove a bright cover is underneath). */
export async function renderedColour(page: Page, x: number, y: number): Promise<Rgb> {
  const shot = decodePng(await page.screenshot({ clip: { x, y, width: 1, height: 1 } }))
  return pixelAt(shot, 0, 0)
}
