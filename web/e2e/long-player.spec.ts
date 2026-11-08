// LongVideoPlayer on /video/:id, playing fixtures/media/test-landscape.mp4 (8 s, 640×360 H.264).
//
// Scope of the native-API checks: element fullscreen, screen.orientation.lock/unlock and the
// iPhone video.webkitEnterFullscreen are replaced or wrapped by stubs installed below. They
// record calls and simulate success or refusal; they cannot prove that real iPhone Safari
// enters its system player or that a real Android phone rotates. Those need a real device.
import { devices, expect, test, type Page } from '@playwright/test'
import { expectRestoredList, MEDIA_URL, MP4, nav, openVisibleVideo, scrollListTo, setupLong, tiles } from './fixtures/long-video'

type Stubs = {
  blockPlay?: boolean
  fullscreen?: 'ok' | 'reject'
  orientation?: 'ok' | 'reject' | 'missing'
  noNativeFullscreen?: boolean
}

/** Records native calls in window.__calls and simulates the requested outcomes. */
async function installStubs(page: Page, o: Stubs = {}) {
  await page.addInitScript((o: Stubs) => {
    const calls: string[] = []
    ;(window as unknown as { __calls: string[] }).__calls = calls
    if (o.blockPlay) HTMLMediaElement.prototype.play = () => Promise.reject(new DOMException('blocked', 'NotAllowedError'))
    if (o.fullscreen === 'reject') {
      Element.prototype.requestFullscreen = function () { calls.push('requestFullscreen'); return Promise.reject(new TypeError('denied')) }
    } else if (o.fullscreen === 'ok' && Element.prototype.requestFullscreen) {
      const real = Element.prototype.requestFullscreen
      Element.prototype.requestFullscreen = function (options) { calls.push('requestFullscreen'); return real.call(this, options) }
    }
    if (screen.orientation) {
      const lock = o.orientation === 'ok' ? (type: string) => { calls.push('lock:' + type); return Promise.resolve() }
        : o.orientation === 'reject' ? (type: string) => { calls.push('lock-rejected:' + type); return Promise.reject(new DOMException('no', 'NotSupportedError')) }
          : o.orientation === 'missing' ? undefined : null
      if (lock !== null) Object.defineProperty(screen.orientation, 'lock', { configurable: true, value: lock })
      Object.defineProperty(screen.orientation, 'unlock', { configurable: true, value: () => calls.push('unlock') })
    }
    if (o.noNativeFullscreen) {
      Object.defineProperty(HTMLVideoElement.prototype, 'webkitEnterFullscreen', { configurable: true, value: undefined })
    } else if ('webkitEnterFullscreen' in HTMLVideoElement.prototype) {
      Object.defineProperty(HTMLVideoElement.prototype, 'webkitEnterFullscreen', {
        configurable: true,
        value(this: HTMLVideoElement) { calls.push('webkitEnterFullscreen'); this.dispatchEvent(new Event('webkitbeginfullscreen')) },
      })
    }
  }, o)
}

const player = (page: Page) => page.getByTestId('long-video-player')
const bottom = (page: Page) => page.locator('.lvp-bottom')
const video = (page: Page) => page.locator('video.lvp-video')
const calls = (page: Page) => page.evaluate(() => (window as unknown as { __calls: string[] }).__calls)
const button = (page: Page, name: string) => bottom(page).getByRole('button', { name, exact: true })

/** Opens a video page; skips (with the reason) where this browser build cannot decode H.264. */
async function openDetail(page: Page, path = '/video/1001') {
  await page.goto(path)
  await expect(player(page)).toBeVisible()
  const h264 = await video(page).evaluate(v => v.canPlayType('video/mp4; codecs="avc1.4D401E"'))
  test.skip(!h264, 'This browser build cannot decode H.264 MP4')
  await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
}
async function ensurePlaying(page: Page) {
  if (await video(page).evaluate(v => v.paused)) await page.locator('.lvp-center-play').click()
  await expect.poll(() => video(page).evaluate(v => !v.paused && v.currentTime > 0), { timeout: 8_000 }).toBe(true)
}
async function chooseRate(page: Page, label: string) {
  await page.getByRole('button', { name: '播放速度' }).click()
  await page.getByRole('menuitemradio', { name: label }).click()
}

test.describe('页面内播放', () => {
  test('测试素材：H.264 640×360 可解码，Range 请求返回 206，跳转后继续播放', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(2)
    expect(await video(page).evaluate(v => [v.videoWidth, v.videoHeight, Math.round(v.duration)])).toEqual([640, 360, 8])
    const range = await page.evaluate(async url => {
      const response = await fetch(url, { headers: { Range: 'bytes=0-99' } })
      return { status: response.status, range: response.headers.get('content-range'), bytes: (await response.arrayBuffer()).byteLength }
    }, MEDIA_URL)
    expect(range).toEqual({ status: 206, range: `bytes 0-99/${MP4.length}`, bytes: 100 })
    await ensurePlaying(page)
    await video(page).evaluate(v => new Promise<void>(resolve => { v.addEventListener('seeked', () => resolve(), { once: true }); v.currentTime = 5 }))
    await expect.poll(() => video(page).evaluate(v => !v.paused && v.currentTime > 5.3), { timeout: 5_000 }).toBe(true)
  })

  test('playsinline / webkit-playsinline；16:9 区域 contain；播放时不进入任何全屏；点击画面显示/隐藏控制条', async ({ page, browserName }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    expect(await video(page).getAttribute('playsinline')).not.toBeNull()
    expect(await video(page).getAttribute('webkit-playsinline')).not.toBeNull()
    expect(await video(page).evaluate(el => getComputedStyle(el).objectFit)).toBe('contain')
    const box = (await player(page).boundingBox())!
    expect(Math.abs(box.width / box.height - 16 / 9)).toBeLessThan(0.02)
    await ensurePlaying(page)
    expect(await page.evaluate(() => !!document.fullscreenElement)).toBe(false)
    if (browserName === 'webkit') {
      expect(await video(page).evaluate(el => (el as unknown as { webkitDisplayingFullscreen?: boolean }).webkitDisplayingFullscreen ?? false)).toBe(false)
    }
    expect(await calls(page)).toEqual([])
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
    await video(page).click()
    await expect(bottom(page)).toBeHidden()
    await video(page).click()
    await expect(bottom(page)).toBeVisible()
  })

  test('播放/暂停、时间显示、进度拖动（含越界截断）、倍速切换', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    await ensurePlaying(page)
    await expect(page.getByTestId('lvp-duration')).toHaveText('0:08')
    await button(page, '暂停').click()
    await expect.poll(() => video(page).evaluate(v => v.paused)).toBe(true)
    await expect(button(page, '播放')).toBeVisible()
    const slider = page.getByRole('slider', { name: '播放进度' })
    await slider.evaluate(el => {
      const input = el as HTMLInputElement
      input.value = '4.8'
      input.dispatchEvent(new Event('input', { bubbles: true }))
      input.dispatchEvent(new Event('change', { bubbles: true }))
    })
    await expect.poll(() => video(page).evaluate(v => v.currentTime)).toBeGreaterThan(4.2)
    await expect(page.getByTestId('lvp-current')).toHaveText('0:04')
    await slider.evaluate(el => { const input = el as HTMLInputElement; input.max = '999'; input.value = '999'; input.dispatchEvent(new Event('input', { bubbles: true })) })
    expect(await video(page).evaluate(v => v.currentTime <= v.duration + 0.01)).toBe(true)
    await chooseRate(page, '1.5×')
    expect(await video(page).evaluate(v => v.playbackRate)).toBe(1.5)
    await expect(page.getByRole('button', { name: '播放速度' })).toHaveText('1.5×')
    await page.getByRole('button', { name: '播放速度' }).click()
    await expect(page.getByRole('menuitemradio', { name: '1.5×' })).toHaveAttribute('aria-checked', 'true')
    await page.getByRole('menuitemradio', { name: '1.0×' }).click()
    expect(await video(page).evaluate(v => v.playbackRate)).toBe(1)
  })

  test('播放结束显示「重新播放」，点击后从头播放', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    await ensurePlaying(page)
    await video(page).evaluate(v => { v.currentTime = Math.max(0, v.duration - 0.3) })
    await expect(page.getByRole('button', { name: '重新播放' })).toBeVisible({ timeout: 8_000 })
    await page.getByRole('button', { name: '重新播放' }).click()
    await expect.poll(() => video(page).evaluate(v => !v.paused && v.currentTime < 2)).toBe(true)
  })

  test('视频 404：显示错误与「重试」，进度条禁用、总时长 --:--；恢复后重试可加载', async ({ page }) => {
    const h = await setupLong(page, { brokenMedia: true })
    await installStubs(page)
    await page.goto('/video/1001')
    await expect(page.locator('.lvp [role=alert]')).toBeVisible({ timeout: 10_000 })
    await expect(page.locator('.lvp-message')).toContainText(/无法播放|加载失败/)
    await expect(page.getByRole('slider', { name: '播放进度' })).toBeDisabled()
    await expect(page.getByTestId('lvp-duration')).toHaveText('--:--')
    h.state.brokenMedia = false
    await page.getByRole('button', { name: '重试' }).click()
    await expect(page.locator('.lvp [role=alert]')).toHaveCount(0)
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
    await expect(page.getByTestId('lvp-duration')).toHaveText('0:08')
  })

  test('自动播放被浏览器拒绝：保持暂停、显示播放按钮，不静音、不报错', async ({ page }) => {
    await setupLong(page)
    await installStubs(page, { blockPlay: true })
    await openDetail(page)
    await expect(page.locator('.lvp-center-play')).toBeVisible()
    expect(await video(page).evaluate(v => [v.paused, v.muted])).toEqual([true, false])
    await expect(page.locator('.lvp [role=alert]')).toHaveCount(0)
    await expect(bottom(page)).toBeVisible()
  })

  test('控制条：播放约 3 秒后自动隐藏；暂停或打开倍速菜单时不隐藏', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    await ensurePlaying(page)
    await video(page).evaluate(v => { v.loop = true }) // the 8 s clip must outlast the waits below
    await video(page).click()
    await video(page).click()
    await expect(bottom(page)).toBeVisible()
    await expect(bottom(page)).toBeHidden({ timeout: 4_500 })
    await video(page).click()
    await page.getByRole('button', { name: '播放速度' }).click()
    // Checking that a 3 s timer does NOT fire needs that time to pass.
    await page.waitForTimeout(3_500)
    await expect(bottom(page)).toBeVisible()
    await page.getByRole('menuitemradio', { name: '1.0×' }).click()
    await button(page, '暂停').click()
    await page.waitForTimeout(3_500)
    await expect(bottom(page)).toBeVisible()
  })

  test('从列表进入详情、播放、返回：恢复列表与滚动位置', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await page.goto('/long/category/3')
    await expect(tiles(page)).toHaveCount(30)
    await scrollListTo(page, 1200)
    await openVisibleVideo(page)
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
    await ensurePlaying(page)
    await page.getByRole('button', { name: '返回', exact: true }).click()
    await expectRestoredList(page, { categoryId: 3, count: 30, top: 1200, firstTitle: /教育视频 3001/ })
  })

  test('从「我的视频」进入详情并播放，底部仍只高亮「我」', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await page.goto('/me')
    await page.getByRole('button', { name: '播放：我的视频 7001' }).click()
    await expect(player(page)).toBeVisible()
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
    await ensurePlaying(page)
    await expect(nav(page, '我')).toHaveAttribute('aria-current', 'page')
    await expect(page.locator('nav.bottom-nav [aria-current="page"]')).toHaveCount(1)
  })
})

test.describe('Android：页面元素全屏（Fullscreen API 与方向锁定为 stub）', () => {
  const { defaultBrowserType: _engine, ...pixel7 } = devices['Pixel 7']
  test.use(pixel7)
  // Element fullscreen and screen.orientation.lock exist in Chromium; Pixel 7 settings under
  // WebKit would not be Android Chrome.
  test.skip(({ browserName }) => browserName !== 'chromium', 'Android fullscreen path runs in Chromium only')

  test('全屏成功并锁定横屏：隐藏底部导航；退出后同一 video 保留进度、播放状态和倍速', async ({ page }) => {
    await setupLong(page)
    await installStubs(page, { fullscreen: 'ok', orientation: 'ok' })
    await openDetail(page)
    await ensurePlaying(page)
    await chooseRate(page, '1.25×')
    await video(page).evaluate(v => { (window as unknown as { __video: HTMLVideoElement }).__video = v })
    await page.setViewportSize({ width: 839, height: 412 })
    await button(page, '全屏').click()
    await expect.poll(() => page.evaluate(() => document.fullscreenElement?.getAttribute('data-testid'))).toBe('long-video-player')
    expect(await calls(page)).toEqual(['requestFullscreen', 'lock:landscape'])
    await expect(page.locator('nav.bottom-nav')).toHaveCount(0)
    await expect(page.locator('.lvp-top .lvp-title')).toHaveText('影视剧视频 1001')
    await expect(page.locator('.lvp-top').getByRole('button', { name: '退出全屏' })).toBeVisible()
    const box = (await player(page).boundingBox())!
    expect([box.width, box.height]).toEqual([839, 412])
    const before = await video(page).evaluate(v => v.currentTime)
    await page.locator('.lvp-top').getByRole('button', { name: '退出全屏' }).click()
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false)
    await page.setViewportSize({ width: 412, height: 839 })
    await expect(page.locator('nav.bottom-nav')).toBeVisible()
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
    expect(await calls(page)).toContain('unlock')
    const after = await video(page).evaluate(v => ({
      same: v === (window as unknown as { __video: HTMLVideoElement }).__video, t: v.currentTime, paused: v.paused, rate: v.playbackRate, src: v.currentSrc,
    }))
    expect(after.same, 'the video element is not recreated').toBe(true)
    expect(after.t).toBeGreaterThanOrEqual(before)
    expect(after).toMatchObject({ paused: false, rate: 1.25 })
    expect(after.src).toContain(MEDIA_URL)
  })

  for (const orientation of ['reject', 'missing'] as const) {
    test(`横屏锁定${orientation === 'reject' ? '被拒绝' : '不可用'}：仍然全屏，不提示错误`, async ({ page }) => {
      await setupLong(page)
      await installStubs(page, { fullscreen: 'ok', orientation })
      await openDetail(page)
      await button(page, '全屏').click()
      await expect.poll(() => page.evaluate(() => document.fullscreenElement?.getAttribute('data-testid'))).toBe('long-video-player')
      await expect(page.locator('.lvp-toast')).toHaveCount(0)
      await page.evaluate(() => document.exitFullscreen())
      await expect(page.locator('nav.bottom-nav')).toBeVisible()
    })
  }

  test('Fullscreen API 被拒绝：提示「无法进入全屏」，页面内继续播放', async ({ page }) => {
    await setupLong(page)
    await installStubs(page, { fullscreen: 'reject', orientation: 'ok' })
    await openDetail(page)
    await ensurePlaying(page)
    await button(page, '全屏').click()
    await expect(page.locator('.lvp-toast')).toHaveText('无法进入全屏')
    expect(await calls(page)).toEqual(['requestFullscreen'])
    await expect(page.locator('nav.bottom-nav')).toBeVisible()
    expect(await video(page).evaluate(v => v.paused)).toBe(false)
  })

  test('全屏中离开详情页：退出全屏、解除方向锁定、恢复底部导航；事件监听已移除', async ({ page }) => {
    const h = await setupLong(page)
    await installStubs(page, { fullscreen: 'ok', orientation: 'ok' })
    await page.goto('/long/category/1')
    await tiles(page).nth(3).click()
    await expect(player(page)).toBeVisible()
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
    await button(page, '全屏').click()
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true)
    await page.goBack()
    await expect(page).toHaveURL(/\/long\/category\/1$/)
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false)
    await expect(page.locator('nav.bottom-nav')).toBeVisible()
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
    expect(await calls(page)).toContain('unlock')
    await page.evaluate(() => { document.dispatchEvent(new Event('fullscreenchange')); document.dispatchEvent(new Event('fullscreenerror')) })
    await expect(page.locator('nav.bottom-nav')).toBeVisible()
    await expect(page.locator('video')).toHaveCount(0)
    expect(h.pageErrors).toEqual([])
  })

  test('从「我的视频」进入详情、进入并退出全屏后，底部仍只高亮「我」', async ({ page }) => {
    await setupLong(page)
    await installStubs(page, { fullscreen: 'ok', orientation: 'ok' })
    await page.goto('/me')
    await page.getByRole('button', { name: '播放：我的视频 7001' }).click()
    await expect.poll(() => video(page).evaluate(v => v.readyState), { timeout: 10_000 }).toBeGreaterThanOrEqual(1)
    await button(page, '全屏').click()
    await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true)
    await page.evaluate(() => document.exitFullscreen())
    await expect(page.locator('nav.bottom-nav')).toBeVisible()
    await expect(nav(page, '我')).toHaveAttribute('aria-current', 'page')
    await expect(page.locator('nav.bottom-nav [aria-current="page"]')).toHaveCount(1)
  })
})

test.describe('iPhone：原生视频全屏（webkitEnterFullscreen 为 stub）', () => {
  // video.webkitEnterFullscreen exists only in WebKit; the project already uses iPhone 13.
  test.skip(({ browserName }) => browserName !== 'webkit', 'iPhone native fullscreen path runs in WebKit only')

  test('全屏按钮调用 webkitEnterFullscreen；退出事件后恢复页面控制条，保留进度、播放状态和倍速', async ({ page }) => {
    await setupLong(page)
    await installStubs(page)
    await openDetail(page)
    await ensurePlaying(page)
    await chooseRate(page, '1.5×')
    await button(page, '全屏').click()
    expect(await calls(page)).toEqual(['webkitEnterFullscreen'])
    await expect(page.locator('.lvp-top'), 'no web overlay on top of the system player').toHaveCount(0)
    const before = await video(page).evaluate(v => v.currentTime)
    await video(page).evaluate(v => v.dispatchEvent(new Event('webkitendfullscreen')))
    await expect(bottom(page)).toBeVisible()
    const after = await video(page).evaluate(v => ({ t: v.currentTime, paused: v.paused, rate: v.playbackRate }))
    expect(after.t).toBeGreaterThanOrEqual(before)
    expect(after).toMatchObject({ paused: false, rate: 1.5 })
    await expect(page.getByRole('button', { name: '播放速度' })).toHaveText('1.5×')
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
  })

  test('没有任何全屏能力时不显示全屏按钮，页面内仍可播放', async ({ page }) => {
    await setupLong(page)
    await installStubs(page, { noNativeFullscreen: true })
    await openDetail(page)
    await expect(button(page, '全屏')).toHaveCount(0)
    await ensurePlaying(page)
  })
})
