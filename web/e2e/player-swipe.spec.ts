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

  test('真实触摸手势上滑（Chromium 触摸滚动）', async ({ page, mocks, browserName }) => {
    test.skip(browserName !== 'chromium', 'Playwright WebKit has no touch-scroll input; WebKit scrolling is covered by the programmatic scroll-snap tests and the real-iPhone check.')
    await openPlayer(page, mocks, { items: three() })
    await expectSettledOn(page, 0)
    const cdp = await page.context().newCDPSession(page)
    await cdp.send('Input.synthesizeScrollGesture', {
      // Start mid-screen, away from the bottom controls (the progress bar is touch-action: none).
      x: 195, y: 520, yDistance: -400, speed: 400, gestureSourceType: 'touch',
    })
    await expectSettledOn(page, 1)
    await expectBackButtonInPlace(page)
  })
})
