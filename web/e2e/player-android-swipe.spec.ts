import { devices } from '@playwright/test'
import { test, expect, makeVideo, openPlayer, feed, expectSettledOn } from './fixtures/player'

test.use({ ...devices['Pixel 7'], browserName: 'chromium' })

test('安卓快速滑动一次只切换一个视频', async ({ page, mocks }) => {
  await openPlayer(page, mocks, { items: Array.from({ length: 8 }, (_, i) => makeVideo(100 + i)) })
  await expectSettledOn(page, 0)
  const cdp = await page.context().newCDPSession(page)
  const box = await feed(page).boundingBox()
  if (!box) throw new Error('播放器不可见')
  const x = box.x + box.width * 0.5
  const y = box.y + box.height * 0.8
  await cdp.send('Input.synthesizeScrollGesture', {
    x, y, yDistance: -box.height * 1.8, speed: 9000, gestureSourceType: 'touch',
  })
  await page.waitForTimeout(1500)
  const result = await feed(page).evaluate(el => ({ top: el.scrollTop, height: el.clientHeight, index: Math.round(el.scrollTop / el.clientHeight) }))
  console.log('安卓快速滑动落点', JSON.stringify(result))
  expect(result.index).toBe(1)
  await cdp.send('Input.synthesizeScrollGesture', {
    x, y: box.y + box.height * 0.2, yDistance: box.height * 1.8, speed: 9000, gestureSourceType: 'touch',
  })
  await page.waitForTimeout(1500)
  const back = await feed(page).evaluate(el => Math.round(el.scrollTop / el.clientHeight))
  console.log('安卓反向滑动落点', back)
  expect(back).toBe(0)
})
