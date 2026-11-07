// C. 上下滑动 — the feed is a native scroll-snap list (one video per screen).
//
// Real-iPhone only: momentum/snap feel of a finger swipe in Safari, and whether the
// next video starts with sound after the first tap (1fc2f3a).
import {
  expect, expectBackButtonInPlace, expectSettledOn, feed, makeVideo, openPlayer, section, test, swipeScreens,
} from './fixtures/player'

const three = () => [makeVideo(11), makeVideo(12), makeVideo(13)]

test.describe('C. 上下滑动', () => {
  test('上滑切到下一个视频并整屏吸附，返回按钮位置不变', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: three() })
    await expectSettledOn(page, 0)
    await swipeScreens(page, 1)
    await expectSettledOn(page, 1)
    await expect(section(page, 0)).toHaveAttribute('inert', '')
    await expect(section(page, 2)).toHaveAttribute('inert', '')
    await expect(section(page, 1)).not.toHaveAttribute('inert', '')
    await expectBackButtonInPlace(page)
  })

  test('下滑回到上一个视频', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: three() })
    await swipeScreens(page, 1)
    await expectSettledOn(page, 1)
    await swipeScreens(page, -1)
    await expectSettledOn(page, 0)
    await expectBackButtonInPlace(page)
  })

  test('边界：第一个视频继续下滑不越界', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: three() })
    await expectSettledOn(page, 0)
    await swipeScreens(page, -1)
    // Scroll to the far edge is already reached; wait for a frame and confirm nothing moved.
    await page.evaluate(() => new Promise(requestAnimationFrame))
    await expectSettledOn(page, 0)
    expect(await feed(page).evaluate(el => el.scrollTop)).toBe(0)
  })

  test('边界：最后一个视频继续上滑不越界', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: three(), index: 2 })
    await expectSettledOn(page, 2)
    await swipeScreens(page, 1)
    await page.evaluate(() => new Promise(requestAnimationFrame))
    await expectSettledOn(page, 2)
    const { top, max } = await feed(page).evaluate(el => ({ top: el.scrollTop, max: el.scrollHeight - el.clientHeight }))
    expect(top).toBeCloseTo(max, 0)
  })

  test('从列表中间的视频进入：初始定位到该视频', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: three(), index: 1 })
    await expectSettledOn(page, 1)
    await expectBackButtonInPlace(page)
  })

  test('真实触摸手势上滑（Chromium 触摸滚动）', async ({ page, mocks, browserName }, info) => {
    test.skip(browserName !== 'chromium', 'Playwright WebKit has no touch-scroll input; WebKit scrolling is covered by the programmatic scroll-snap tests and the real-iPhone check.')
    await openPlayer(page, mocks, { items: three() })
    await expect(feed(page)).toBeVisible()
    await expectSettledOn(page, 0)
    const nextFrame = () => page.evaluate(() => new Promise<void>(resolve => requestAnimationFrame(() => resolve())))
    await nextFrame()
    await nextFrame()

    // Passive, observe-only listeners: they never call preventDefault/stopPropagation.
    await page.evaluate(() => {
      const list = document.querySelector('section[data-idx]')!.parentElement!
      const diag = { touchstart: 0, touchmove: 0, touchend: 0, touchcancel: 0, touchmovePrevented: false, scroll: 0 }
      const count = (event: Event) => {
        if (event.type === 'scroll') diag.scroll++
        else diag[event.type as 'touchstart' | 'touchmove' | 'touchend' | 'touchcancel']++
      }
      // defaultPrevented is final only after every listener ran, so read it in the bubble phase on window.
      const prevented = (event: Event) => { if (event.defaultPrevented) diag.touchmovePrevented = true }
      const types = ['touchstart', 'touchmove', 'touchend', 'touchcancel'] as const
      types.forEach(type => document.addEventListener(type, count, { capture: true, passive: true }))
      window.addEventListener('touchmove', prevented, { passive: true })
      list.addEventListener('scroll', count, { passive: true })
      const w = window as unknown as { __touchDiag: { data: typeof diag; cleanup: () => void } }
      w.__touchDiag = {
        data: diag,
        cleanup: () => {
          types.forEach(type => document.removeEventListener(type, count, { capture: true }))
          window.removeEventListener('touchmove', prevented)
          list.removeEventListener('scroll', count)
        },
      }
    })
    type TouchDiag = { touchstart: number; touchmove: number; touchend: number; touchcancel: number; touchmovePrevented: boolean; scroll: number }
    const diagnostics = () => page.evaluate(() => {
      const data = (window as unknown as { __touchDiag: { data: TouchDiag } }).__touchDiag.data
      const list = document.querySelector('section[data-idx]')!.parentElement!
      const live = [...document.querySelectorAll<HTMLElement>('section[data-idx]')].filter(el => !el.hasAttribute('inert'))
      return { ...data, scrollTop: list.scrollTop, screen: list.clientHeight, activeIndex: live.length === 1 ? Number(live[0].dataset.idx) : -live.length }
    })

    try {
      // One finger from the lower-middle of the screen (away from the bottom controls) upwards
      // by 400px in 20 steps. Each CDP call resolves once the browser has taken the event,
      // and a rendered frame passes before the next one.
      const cdp = await page.context().newCDPSession(page)
      const x = 195
      const startY = 520
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] })
      await nextFrame()
      for (let step = 1; step <= 20; step++) {
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: startY - step * 20 }] })
        await nextFrame()
      }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
      await nextFrame()

      // 1. The touch events reached the page and nothing cancelled them.
      const received = await diagnostics()
      expect(received.touchstart, 'touchstart events received').toBe(1)
      expect(received.touchmove, 'touchmove events received').toBeGreaterThanOrEqual(10)
      expect(received.touchend, 'touchend events received').toBe(1)
      expect(received.touchcancel, 'touchcancel events').toBe(0)
      expect(received.touchmovePrevented, 'a touchmove was defaultPrevented').toBe(false)

      // 2. The feed scrolled and snapped to the second video.
      await expectSettledOn(page, 1)
      await expectBackButtonInPlace(page)
      info.annotations.push({ type: 'touch-diagnostics', description: JSON.stringify(await diagnostics()) })
    } catch (error) {
      await info.attach('touch-diagnostics', { body: JSON.stringify(await diagnostics(), null, 2), contentType: 'application/json' })
      throw error
    } finally {
      await page.evaluate(() => (window as unknown as { __touchDiag?: { cleanup: () => void } }).__touchDiag?.cleanup())
    }
  })
})
