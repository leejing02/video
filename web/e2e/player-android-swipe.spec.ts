import { devices } from '@playwright/test'
import { test, expect, makeVideo, openPlayer, feed, expectSettledOn } from './fixtures/player'

test.use({ ...devices['Pixel 7'] })

test.skip(({ browserName }) => browserName !== 'chromium', 'Pixel 7 Android touch emulation uses Chromium CDP; WebKit represents iOS.')

test('安卓快速滑动一次只切换一个视频', async ({ page, mocks }) => {
  await openPlayer(page, mocks, { items: Array.from({ length: 8 }, (_, i) => makeVideo(100 + i)) })
  await expectSettledOn(page, 0)
  const cdp = await page.context().newCDPSession(page)
  const box = await feed(page).boundingBox()
  if (!box) throw new Error('播放器不可见')
  const x = box.x + box.width * 0.5
  const top = box.y + box.height * 0.05
  const bottom = box.y + box.height * 0.95
  const swipe = async (startY: number, endY: number) => {
    const start = Date.now()
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] })
    for (let step = 1; step <= 4; step++) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: startY + (endY - startY) * step / 4 }] })
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
    expect(Date.now() - start, 'touch gesture should be fast').toBeLessThan(300)
  }
  const expectStable = async (index: number) => {
    await expectSettledOn(page, index)
    const stable = await feed(page).evaluate((el, expected) => new Promise<boolean>(resolve => {
      const start = performance.now()
      const sample = () => {
        const active = Array.from(el.querySelectorAll('section[data-idx]')).filter(section => !section.hasAttribute('inert'))
        if (Math.abs(el.scrollTop - expected * el.clientHeight) > 1 || active.length !== 1 || Number((active[0] as HTMLElement).dataset.idx) !== expected) return resolve(false)
        if (performance.now() - start >= 1500) return resolve(true)
        requestAnimationFrame(sample)
      }
      requestAnimationFrame(sample)
    }), index)
    expect(stable, `video ${index} should remain settled for 1.5 seconds`).toBe(true)
  }
  await swipe(bottom, top)
  await expectStable(1)
  await swipe(top, bottom)
  await expectStable(0)
})
