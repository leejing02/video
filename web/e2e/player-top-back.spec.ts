// A. 黑色顶部  B. 返回按钮  — /player.html (iPhone standalone player)
//
// Real-iPhone only (cannot be emulated here, verify on iOS 26 Safari):
//   - the status bar colour Safari samples from the page, and its change back to white on return
//   - safe-area insets: here env(safe-area-inset-top) is 0, so the strip is 12px and the button sits at y=12
//   - the zoom-back animation's look (no black/white flash) and Safari's own edge-swipe gesture
import {
  WHITE, expect, expectBackButtonInPlace, expectSettledOn, expectTopBlack, makeVideo, openPlayer,
  renderedColour, section, test, waitForCover, swipeScreens,
} from './fixtures/player'

// Covers exactly the screen's shape (390×844 → 780×1688), so bright pixels reach the top edge
// unless the black strip is on top of them.
const brightFeed = () => [1, 2, 3].map(id => makeVideo(id, { width: 780, height: 1688, color: WHITE }))

test.describe('A. 黑色顶部', () => {
  test('初始进入：状态栏黑条、页面背景与 meta 为黑色，白色封面也不透出', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: brightFeed() })
    await waitForCover(page, 0)
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#000000')
    await expect(page.locator('meta[name="apple-mobile-web-app-status-bar-style"]')).toHaveAttribute('content', 'black')
    await expect(page.locator('.short-player')).toHaveCSS('background-color', 'rgb(0, 0, 0)')
    // The cover really is white right below the strip, so the black strip is what keeps the top black.
    expect(await renderedColour(page, 200, 20)).toEqual(WHITE)
    await expectTopBlack(page)
  })

  test('滑动到下一个视频后顶部仍为黑色', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: brightFeed() })
    await waitForCover(page, 0)
    await swipeScreens(page, 1)
    await expectSettledOn(page, 1)
    await waitForCover(page, 1)
    expect(await renderedColour(page, 200, 20)).toEqual(WHITE)
    await expectTopBlack(page)
  })

  test('打开分享面板后顶部仍为黑色', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: brightFeed() })
    await waitForCover(page, 0)
    await section(page, 0).getByRole('button', { name: '分享视频' }).click()
    await expect(page.getByRole('dialog', { name: '分享视频' })).toBeVisible()
    await expectTopBlack(page)
  })

  test('打开评论面板后顶部仍为黑色', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: brightFeed(), comments: true })
    await waitForCover(page, 0)
    const open = section(page, 0).getByRole('button', { name: '查看评论', exact: true })
    await expect(open).toBeVisible()
    await open.click()
    await expect(page.locator('.player-sheet-mask')).toBeVisible()
    await expectTopBlack(page)
  })
})

test.describe('B. 返回按钮', () => {
  test('可见、44×44、左上 12px、圆形、白色「‹」带阴影，无焦点外框', async ({ page, mocks }, info) => {
    await openPlayer(page, mocks, { items: [makeVideo(1), makeVideo(2)] })
    const back = await expectBackButtonInPlace(page)
    await expect(back).toBeEnabled()
    await expect(back).toHaveText('‹')
    await expect(back).toBeFocused()
    await expect(back).toHaveCSS('position', 'absolute')
    // Background: docs/UI-RULES.md says a #0006 round backing, but dca1572 (2026-11-05,
    // "use transparent playback top controls") made it transparent with a text shadow on purpose.
    // The test follows the shipped commit; the doc/code mismatch is reported as D3 for a decision.
    info.annotations.push({ type: 'discrepancy', description: 'D3: UI-RULES 写返回按钮底色 #0006，代码（dca1572）为透明底 + 文字阴影' })
    await expect(back).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)')
    await expect(back).toHaveCSS('text-shadow', /rgba\(0, 0, 0, 0\.6\d*\) 0px 1px 4px/)
    await expect(back).toHaveCSS('border-radius', '50%')
    await expect(back).toHaveCSS('color', 'rgb(255, 255, 255)')
    await expect(back).toHaveCSS('font-size', '36px')
    await expect(back).toHaveCSS('outline-style', 'none')
  })

  test('处于最上层：按钮圆形触控区内不被视频或其他层遮挡（滑动前后）', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: [makeVideo(1), makeVideo(2)] })
    // Centre (34, 34) plus four points 15px out: inside the 44px circle (radius 22).
    const hits = () => page.evaluate(() => [[34, 34], [19, 34], [49, 34], [34, 19], [34, 49]].map(([x, y]) =>
      document.elementFromPoint(x, y)?.closest('.player-close') != null))
    await waitForCover(page, 0)
    expect(await hits()).toEqual([true, true, true, true, true])
    await swipeScreens(page, 1)
    await expectSettledOn(page, 1)
    await expectBackButtonInPlace(page)
    expect(await hits()).toEqual([true, true, true, true, true])
  })

  test('点击返回（减少动画）：回到打开播放的列表 /long，并标记返回状态', async ({ page, mocks }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await openPlayer(page, mocks, { items: [makeVideo(1), makeVideo(2)] })
    await page.getByRole('button', { name: '返回视频列表' }).click()
    await expect(page).toHaveURL(/\/long$/)
    await expect(page.locator('#e2e-list-stub')).toBeVisible()
    const saved = await page.evaluate(() => JSON.parse(sessionStorage.getItem('short-playback-feed') || 'null'))
    expect(saved?.returning).toBe(true)
  })

  test('点击返回（正常动画）：先进入缩回状态，再回到 /long', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: [makeVideo(1), makeVideo(2)] })
    await page.getByRole('button', { name: '返回视频列表' }).click()
    await expect(page.locator('.short-player')).toHaveAttribute('data-returning', 'true')
    await expect(page).toHaveURL(/\/long$/)
  })

  test('左边缘右滑返回（触摸事件）', async ({ page, mocks }) => {
    await openPlayer(page, mocks, { items: [makeVideo(1), makeVideo(2)] })
    await waitForCover(page, 0)
    const supported = await page.evaluate(() => {
      try { return !!new TouchEvent('touchstart', { touches: [new Touch({ identifier: 0, target: document.body, clientX: 0, clientY: 0 })] }) } catch { return false }
    })
    test.skip(!supported, 'This browser build cannot construct Touch objects (Playwright WebKit); covered in Chromium and the real-iPhone check.')
    await page.evaluate(async () => {
      const target = document.querySelector('section[data-idx="0"]')!
      const touch = (x: number) => new Touch({ identifier: 1, target, clientX: x, clientY: 400 })
      const send = (type: string, x: number) => target.dispatchEvent(new TouchEvent(type, {
        bubbles: true, cancelable: true,
        touches: type === 'touchend' ? [] : [touch(x)],
        targetTouches: type === 'touchend' ? [] : [touch(x)],
        changedTouches: [touch(x)],
      }))
      send('touchstart', 10)
      for (let x = 30; x <= 250; x += 20) {
        await new Promise(requestAnimationFrame)
        send('touchmove', x)
      }
      send('touchend', 250)
    })
    await expect(page).toHaveURL(/\/long$/)
  })
})
