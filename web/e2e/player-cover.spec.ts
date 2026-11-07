// D. 封面比例 — expected behaviour vs. what the player currently does.
//
// Expected (docs/UI-RULES.md: covers keep the image's own proportions, no cropping):
// the displayed cover has the image's aspect ratio (±1%), fits inside the screen and is centred.
//
// Current implementation (ShortVideoPlayer fitMediaWidth + object-fill, player.html entry layer):
// the width always fills the screen and the height is capped at the screen height. Covers taller
// than the screen's own ratio are therefore stretched sideways (defects D1 player, D2 entry layer).
// Every case checks loading, visibility, no cropping and centring normally. Only the aspect-ratio
// check differs: known-defect cases assert that the defect is still present (deviation > 1%), so the
// test fails — and asks for the assertion to be updated — once the player is fixed. That tracking
// assertion is not an acceptance standard; stretched covers are never treated as correct.
//
// Real-iPhone only: EFV-encrypted covers from the real image domain, and the hand-over from
// cover to the first video frame.
import { expect, makeVideo, openPlayer, section, test, waitForCover, waitForPlayer, type CoverSpec } from './fixtures/player'
import type { Locator, Page, TestInfo } from '@playwright/test'

// The iPhone 13 profile's page area is 390×664 (Safari with its toolbars), ratio ≈ 0.587.
// Covers wider than that fit by width; narrower ones are where the current code stretches.
const STRETCH = '比屏幕可视区更瘦长的封面被横向拉伸到全屏宽（宽度固定 100%、object-fit: fill），实际拉宽比例见测试注释'
const CASES: { name: string; cover: CoverSpec; defect?: string }[] = [
  { name: '横版 16:9（1280×720）', cover: { width: 1280, height: 720 } },
  { name: '竖版 3:4（720×960）', cover: { width: 720, height: 960 } },
  { name: '竖版 9:16（720×1280）', cover: { width: 720, height: 1280 }, defect: `缺陷 D1：${STRETCH}` },
  { name: '超长 3:10（720×2400）', cover: { width: 720, height: 2400 }, defect: `缺陷 D1：${STRETCH}` },
]

async function measure(img: Locator, area: Locator) {
  const natural = await img.evaluate((el: HTMLImageElement) => ({ width: el.naturalWidth, height: el.naturalHeight }))
  const box = (await img.boundingBox())!
  const bounds = (await area.boundingBox())!
  return {
    natural,
    box,
    bounds,
    naturalRatio: natural.width / natural.height,
    shownRatio: box.width / box.height,
  }
}

function report(info: TestInfo, label: string, m: Awaited<ReturnType<typeof measure>>) {
  info.annotations.push({
    type: 'measured',
    description: `${label}: image ${m.natural.width}×${m.natural.height} (ratio ${m.naturalRatio.toFixed(4)}), ` +
      `shown ${m.box.width.toFixed(1)}×${m.box.height.toFixed(1)} (ratio ${m.shownRatio.toFixed(4)}, ` +
      `${((m.shownRatio / m.naturalRatio - 1) * 100).toFixed(1)}% wider) in ${m.bounds.width}×${m.bounds.height}`,
  })
}

type Measured = Awaited<ReturnType<typeof measure>>
const deviation = (m: Measured) => Math.abs(m.shownRatio / m.naturalRatio - 1)

/** Checks every cover must pass: visible, nothing cropped, centred. */
function expectFittedAndCentred(m: Measured) {
  expect(m.box.width).toBeGreaterThan(0)
  expect(m.box.height).toBeGreaterThan(0)
  expect(m.box.width).toBeLessThanOrEqual(m.bounds.width + 0.5)
  expect(m.box.height).toBeLessThanOrEqual(m.bounds.height + 0.5)
  expect(m.box.x + m.box.width / 2).toBeCloseTo(m.bounds.x + m.bounds.width / 2, 0)
  expect(m.box.y + m.box.height / 2).toBeCloseTo(m.bounds.y + m.bounds.height / 2, 0)
}

/** Expected behaviour: the cover keeps the image's own aspect ratio (±1%). */
function expectOwnAspectRatio(m: Measured) {
  expect(deviation(m), 'aspect ratio deviation').toBeLessThanOrEqual(0.01)
}

/**
 * DEFECT TRACKING ONLY — not an acceptance standard.
 * D1/D2 are real, unfixed UI defects: these covers are stretched sideways. This assertion only
 * confirms the defect is still there. Once the player is fixed it fails; then remove `defect`
 * from the case so it uses expectOwnAspectRatio (deviation ≤ 1%).
 */
function expectKnownStretchDefect(m: Measured, defect: string) {
  expect(deviation(m), `${defect} — no longer reproduces. The defect looks fixed: ` +
    'remove `defect` from this case so it asserts deviation ≤ 1% (expectOwnAspectRatio).').toBeGreaterThan(0.01)
}

async function playerCover(page: Page) {
  const img = await waitForCover(page, 0)
  return measure(img, section(page, 0))
}

test.describe('D. 封面比例：期望行为（按原比例、不裁切、居中）', () => {
  for (const { name, cover, defect } of CASES) {
    test(`播放器封面 ${name}`, async ({ page, mocks }, info) => {
      await openPlayer(page, mocks, { items: [makeVideo(21, cover), makeVideo(22)] })
      const m = await playerCover(page)
      report(info, 'player cover', m)
      expectFittedAndCentred(m)
      if (defect) expectKnownStretchDefect(m, defect) // tracking only, see expectKnownStretchDefect
      else expectOwnAspectRatio(m)
    })
  }

  for (const { name, cover, defect } of [CASES[1], CASES[2]]) {
    test(`进入时的过渡封面（Vue 启动前绘制）${name}`, async ({ page, mocks }, info) => {
      const { release } = await openPlayer(page, mocks, { items: [makeVideo(31, cover), makeVideo(32)], holdApp: true })
      // The app module is held back, so this is the pre-Vue state.
      await expect(page.locator('.short-player')).toHaveCount(0)
      const img = page.locator('#playback-entry-cover img')
      await expect(img).toHaveAttribute('src', makeVideo(31, cover).cover)
      await expect.poll(() => img.evaluate((el: HTMLImageElement) => el.complete && el.naturalWidth > 0)).toBe(true)
      const m = await measure(img, page.locator('#playback-entry-cover'))
      report(info, 'entry cover', m)
      expectFittedAndCentred(m)
      if (defect) expectKnownStretchDefect(m, defect.replace('D1', 'D2') + '（player.html 过渡层同样 object-fit: fill）') // tracking only
      else expectOwnAspectRatio(m)
      release()
    })
  }
})

test.describe('D. 封面比例：当前实际行为（记录数值，只断言确定正确的部分）', () => {
  for (const { name, cover } of CASES) {
    test(`播放器封面 ${name}：已加载、在屏幕内、居中`, async ({ page, mocks }, info) => {
      await openPlayer(page, mocks, { items: [makeVideo(41, cover), makeVideo(42)] })
      const m = await playerCover(page)
      report(info, 'player cover (current)', m)
      expectFittedAndCentred(m)
    })
  }

  test('过渡封面在播放器封面就绪后移除', async ({ page, mocks }) => {
    const { release } = await openPlayer(page, mocks, { items: [makeVideo(51), makeVideo(52)], holdApp: true })
    await expect(page.locator('#playback-entry-cover')).toHaveCount(1)
    release()
    await waitForPlayer(page, 2)
    await waitForCover(page, 0)
    await expect(page.locator('#playback-entry-cover')).toHaveCount(0)
  })
})
