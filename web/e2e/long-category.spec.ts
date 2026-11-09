// Long-video category home (/long) and category list (/long/category/:id).
// All data comes from fixtures/long-video.ts; nothing reaches a real server.
import { devices, expect, test } from '@playwright/test'
import {
  frames, header, homeCards, listCalls, longVideo, nav, posters, scrollArea, setupLong, tiles,
  type CoverKind,
} from './fixtures/long-video'

test.describe('分类首页 /long', () => {
  test('标题「长视频」居中且无菜单按钮；双列 16:9 封面；名称 18px/600/居中；点击进入对应列表', async ({ page }) => {
    const h = await setupLong(page)
    await page.goto('/long')
    await expect(homeCards(page)).toHaveCount(3)
    await expect(page.getByRole('button', { name: '打开我的页面' })).toHaveCount(0)
    await expect(header(page).locator('button')).toHaveCount(1)
    const vw = page.viewportSize()!.width
    const title = (await header(page).locator('.feed-tabs .selected').boundingBox())!
    expect(Math.abs(title.x + title.width / 2 - vw / 2), 'title centre offset (px)').toBeLessThan(1)

    const name = homeCards(page).first().locator('.long-category-name')
    await expect(name).toHaveText('影视剧')
    expect(await name.evaluate(el => { const c = getComputedStyle(el); return [c.fontSize, c.fontWeight, c.textAlign, c.whiteSpace] }))
      .toEqual(['18px', '600', 'center', 'normal'])
    const a = (await homeCards(page).nth(0).locator('.long-category-cover').boundingBox())!
    const b = (await homeCards(page).nth(1).locator('.long-category-cover').boundingBox())!
    const nameBox = (await name.boundingBox())!
    expect(Math.abs(nameBox.x + nameBox.width / 2 - (a.x + a.width / 2))).toBeLessThan(1)
    expect(Math.abs(a.width / a.height - 16 / 9)).toBeLessThan(0.02)
    expect(Math.abs(a.y - b.y)).toBeLessThan(1)
    expect(b.x).toBeGreaterThan(a.x + a.width)
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
    expect(listCalls(h).filter(c => c.startsWith('/api/videos'))).toEqual([])

    await homeCards(page).filter({ hasText: '教育' }).click()
    await expect(page).toHaveURL(/\/long\/category\/3$/)
    await expect(tiles(page).first()).toContainText('教育视频 3001')
  })

  test('长分类名称换行显示、不截断，同排封面顶部对齐', async ({ page }) => {
    await setupLong(page, { categories: [{ id: 1, name: '影视剧' }, { id: 2, name: '纪录片与自然地理人文历史专题合集' }, { id: 3, name: '教育' }] })
    await page.goto('/long')
    await expect(homeCards(page)).toHaveCount(3)
    const long = homeCards(page).nth(1).locator('.long-category-name')
    await expect(long).toHaveText('纪录片与自然地理人文历史专题合集')
    const oneLine = await homeCards(page).first().locator('.long-category-name').evaluate(el => el.getBoundingClientRect().height)
    const m = await long.evaluate(el => ({ h: el.getBoundingClientRect().height, sw: el.scrollWidth, cw: el.clientWidth }))
    expect(m.h, 'wrapped to at least two lines').toBeGreaterThan(oneLine * 1.6)
    expect(m.sw, 'not cut off').toBeLessThanOrEqual(m.cw)
    const top0 = (await homeCards(page).nth(0).locator('.long-category-cover').boundingBox())!.y
    const top1 = (await homeCards(page).nth(1).locator('.long-category-cover').boundingBox())!.y
    expect(Math.abs(top0 - top1)).toBeLessThan(1)
  })
})

test.describe('分类列表 /long/category/:id', () => {
  test('没有分类标签栏；保留返回、「长视频」标题和搜索；列表紧贴顶部栏', async ({ page }) => {
    const h = await setupLong(page)
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    await expect(page.locator('.category-strip')).toHaveCount(0)
    for (const label of ['全部', '影视剧', '纪录片', '教育']) await expect(page.getByRole('button', { name: label, exact: true })).toHaveCount(0)
    await expect(header(page).getByRole('button', { name: '返回', exact: true })).toBeVisible()
    await expect(header(page).locator('.feed-tabs .selected')).toHaveText('长视频')
    await expect(header(page).getByRole('button', { name: '搜索视频' })).toBeVisible()
    const head = (await header(page).boundingBox())!
    const area = (await scrollArea(page).boundingBox())!
    expect(Math.abs(area.y - (head.y + head.height))).toBeLessThan(1)
    expect((await tiles(page).first().boundingBox())!.y - area.y).toBeLessThanOrEqual(5)
    expect(h.calls.some(c => c.startsWith('/api/categories')), 'the list does not request categories').toBe(false)
    await expect(nav(page, '分类')).toHaveAttribute('aria-current', 'page')
  })

  test('按 category_id 过滤：影视剧、教育只显示本分类，纪录片为空', async ({ page }) => {
    const h = await setupLong(page)
    for (const [id, label] of [['1', '影视剧'], ['3', '教育']] as const) {
      const from = h.calls.length
      await page.goto('/long/category/' + id)
      await expect(tiles(page)).toHaveCount(30)
      expect((await tiles(page).allInnerTexts()).every(text => text.includes(label + '视频'))).toBe(true)
      expect(listCalls(h, from)).toEqual([`/api/videos?category_id=${id}&page=1&per_page=30&type=long`])
    }
    await page.goto('/long/category/2')
    await expect(page.locator('.feed-empty')).toBeVisible()
    await expect(tiles(page)).toHaveCount(0)
  })

  test('双列竖版 2:3 卡片：间距 4px/6px，同排等高；灰色占位、失败无图标；横图 cover 裁切；保留时长，无作者和点赞', async ({ page }) => {
    const kinds: CoverKind[] = ['portrait', 'landscape', 'none', 'broken', 'slow', 'portrait', 'landscape', 'portrait']
    const items = kinds.map((kind, i) => longVideo(601 + i, 1, { cover: kind, duration: 3601 + i, title: i % 3 ? `影视剧测试视频 ${601 + i}` : `影视剧测试视频 ${601 + i}，标题较长时显示两行` }))
    await setupLong(page, { lists: { '1': items } })
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(8)
    const boxes = await posters(page).evaluateAll(els => els.map(e => { const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height } }))
    expect(new Set(boxes.map(b => Math.round(b.x))).size, 'two columns').toBe(2)
    for (const b of boxes) expect(Math.abs(b.w / b.h - 2 / 3)).toBeLessThan(0.01)
    expect(Math.max(...boxes.map(b => b.h)) - Math.min(...boxes.map(b => b.h))).toBeLessThan(1)
    expect(boxes[1].x - (boxes[0].x + boxes[0].w), 'column gap').toBeCloseTo(4, 0)
    const cards = await tiles(page).evaluateAll(els => els.map(e => { const r = e.getBoundingClientRect(); return { y: r.y, h: r.height } }))
    expect(cards[2].y - (cards[0].y + cards[0].h), 'row gap').toBeCloseTo(6, 0)
    for (let i = 0; i + 1 < cards.length; i += 2) {
      expect(Math.abs(cards[i].h - cards[i + 1].h), 'cards in a row have equal height').toBeLessThan(1)
      expect(Math.abs(boxes[i].y - boxes[i + 1].y), 'covers in a row are top-aligned').toBeLessThan(1)
    }

    await expect(posters(page).nth(0).locator('img')).toHaveJSProperty('complete', true)
    const states = await posters(page).evaluateAll(els => els.map(e => ({
      img: !!e.querySelector('img'), fit: e.querySelector('img') ? getComputedStyle(e.querySelector('img')!).objectFit : null,
      bg: getComputedStyle(e).backgroundColor, icon: !!e.querySelector('.poster-fallback, svg'),
      text: (e.textContent ?? '').replace(/\d+:\d+(:\d+)?/, '').trim(),
    })))
    for (const s of states) expect(s).toMatchObject({ bg: 'rgb(233, 233, 233)', icon: false, text: '' })
    expect(states[2].img, 'no cover → grey only').toBe(false)
    expect(states[3].img, 'broken cover → grey only').toBe(false)
    expect(states[4].img, 'slow cover keeps its grey box while loading').toBe(true)
    expect(states[1].fit).toBe('cover')
    await expect(tiles(page).first().locator('.video-duration')).toHaveText('60:01')
    await expect(page.locator('.video-grid .tile-meta, .video-grid .tile-likes, .video-grid .tile-author')).toHaveCount(0)
  })

  test('没有封面的卡片可以点击进入详情', async ({ page }) => {
    await setupLong(page, { lists: { '1': [longVideo(603, 1, { cover: 'none' })] } })
    await page.goto('/long/category/1')
    await tiles(page).first().click()
    await expect(page).toHaveURL(/\/video\/603$/)
  })

  test('空分类：「暂无视频 / 该分类暂时没有视频」，没有按钮、骨架屏和占位卡片，布局样式不变', async ({ page }) => {
    await setupLong(page)
    await page.goto('/long/category/2')
    const empty = page.locator('.feed-empty')
    await expect(empty.locator('p')).toHaveText('暂无视频')
    await expect(empty.locator('span')).toHaveText('该分类暂时没有视频')
    await expect(empty.locator('button')).toHaveCount(0)
    await expect(page.locator('.video-skeleton')).toHaveCount(0)
    await expect(tiles(page)).toHaveCount(0)
    expect(await empty.evaluate(el => { const c = getComputedStyle(el); return [c.padding, c.textAlign, c.fontSize] })).toEqual(['64px 20px', 'center', '14px'])
    await expect(header(page).locator('.feed-tabs .selected')).toHaveText('长视频')
  })

  test('列表接口失败：显示「加载失败 / 点击重试」，没有骨架屏；恢复后重试正常显示', async ({ page }) => {
    const h = await setupLong(page, { videosFail: true })
    await page.goto('/long/category/1')
    const empty = page.locator('.feed-empty')
    await expect(empty).toHaveText(/加载失败/)
    await expect(empty).not.toHaveText(/暂无视频/)
    await expect(page.locator('.video-grid:not(.invisible) .video-skeleton')).toHaveCount(0)
    h.state.videosFail = false
    await empty.getByRole('button', { name: '点击重试' }).click()
    await expect(tiles(page)).toHaveCount(30)
    await expect(empty).toHaveCount(0)
  })

  test('分类接口失败不影响列表（列表不依赖分类接口）', async ({ page }) => {
    await setupLong(page, { categoriesFail: true })
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    await expect(page.locator('[role=alert]')).toHaveCount(0)
  })

  test('搜索无结果与暂无视频、加载失败互不冲突', async ({ page }) => {
    await setupLong(page)
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    await page.getByRole('button', { name: '搜索视频' }).click()
    const box = page.getByRole('searchbox', { name: '搜索视频标题或作者' })
    await box.fill('不存在的标题')
    await expect(page.locator('.feed-empty')).toHaveText(/没有找到匹配的视频/)
    await expect(page.locator('.feed-empty')).toHaveCount(1)
    await expect(page.locator('.video-grid:not(.invisible) .video-skeleton')).toHaveCount(0)
    await box.fill('1005')
    await expect(tiles(page)).toHaveCount(1)
    await expect(page.locator('.feed-empty')).toHaveCount(0)
  })

  test('「加载更多」失败：保留已有视频并提示重试，重试后追加第二页', async ({ page }) => {
    const h = await setupLong(page, { failPage: 2 })
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    await page.getByRole('button', { name: '加载更多' }).click()
    await expect(page.locator('.feed-empty')).toHaveText(/加载失败/)
    await expect(tiles(page)).toHaveCount(30)
    h.state.failPage = null
    await page.locator('.feed-empty').getByRole('button', { name: '点击重试' }).click()
    await expect(tiles(page)).toHaveCount(60)
    expect(listCalls(h).at(-1)).toBe('/api/videos?category_id=1&page=2&per_page=30&type=long')
  })

  test('快速切换分类（经由首页）：先进入的慢请求返回后不覆盖后进入的分类', async ({ page }) => {
    await setupLong(page, { delayMs: { '1': 1200 } })
    await page.goto('/long')
    const slow = page.waitForResponse(r => r.url().includes('/api/videos?category_id=1'))
    await homeCards(page).filter({ hasText: '影视剧' }).click()
    await expect(page).toHaveURL(/\/long\/category\/1$/)
    await expect(page.locator('.video-grid:not(.invisible) .video-skeleton')).toHaveCount(4)
    await header(page).getByRole('button', { name: '返回', exact: true }).click()
    await homeCards(page).filter({ hasText: '纪录片' }).click()
    await expect(page).toHaveURL(/\/long\/category\/2$/)
    await expect(page.locator('.feed-empty')).toHaveText(/暂无视频/)
    await slow
    await frames(page)
    await expect(page.locator('.feed-empty')).toHaveText(/暂无视频/)
    await expect(tiles(page)).toHaveCount(0)
  })

  test('列表只在内容区滚动，页面不滚动，底部导航固定在可视区域底部', async ({ page }) => {
    await setupLong(page)
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    const vh = page.viewportSize()!.height
    const before = (await page.locator('nav.bottom-nav').boundingBox())!
    const area = (await scrollArea(page).boundingBox())!
    expect(Math.abs(before.y + before.height - vh)).toBeLessThan(1)
    expect(area.y + area.height).toBeLessThanOrEqual(before.y + 1)
    await scrollArea(page).evaluate(el => { el.scrollTop = 1500 })
    await expect.poll(() => scrollArea(page).evaluate(el => el.scrollTop)).toBe(1500)
    expect(await page.evaluate(() => document.scrollingElement!.scrollTop)).toBe(0)
    expect((await page.locator('nav.bottom-nav').boundingBox())!.y).toBe(before.y)
    expect(new Set(await tiles(page).evaluateAll(els => els.map(e => Math.round(e.getBoundingClientRect().x)))).size).toBe(2)
  })
})

test.describe('分类列表：Android 触摸', () => {
  const { defaultBrowserType: _engine, ...pixel7 } = devices['Pixel 7']
  test.use(pixel7)
  // CDP touch input exists only in Chromium; Pixel 7 settings under WebKit would not be Android Chrome.
  test.skip(({ browserName }) => browserName !== 'chromium', 'Android touch check runs in Chromium only')

  test('内容区横向滑动不再切换分类', async ({ page }) => {
    const h = await setupLong(page)
    await page.goto('/long/category/1')
    await expect(tiles(page)).toHaveCount(30)
    const from = h.calls.length
    const box = (await scrollArea(page).boundingBox())!
    const cdp = await page.context().newCDPSession(page)
    const y = box.y + box.height / 2
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: box.x + box.width * 0.8, y }] })
    for (const f of [0.7, 0.55, 0.4, 0.25]) {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: box.x + box.width * f, y }] })
      await frames(page, 1)
    }
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] })
    await frames(page, 10)
    await expect(page).toHaveURL(/\/long\/category\/1$/)
    await expect(tiles(page).first()).toContainText('影视剧视频 1001')
    expect(listCalls(h, from)).toEqual([])
  })
})

test.describe('推荐页 /short 不受影响', () => {
  test('仍有短视频分类标签栏、菜单按钮和原空状态文案，只请求短视频接口', async ({ page }) => {
    const h = await setupLong(page)
    await page.goto('/short')
    await expect(page.locator('.category-strip button')).toHaveText(['全部', '国产', '精选', '抖舞', '猎奇', '99'])
    await expect(page.getByRole('button', { name: '打开我的页面' })).toBeVisible()
    await expect(page.locator('.feed-empty span')).toHaveText('换个分类看看，或稍后再来')
    await expect(page.locator('.video-grid.short-masonry')).toHaveCount(1)
    expect(h.calls.some(c => c.startsWith('/api/short-videos'))).toBe(true)
    expect(h.calls.some(c => c.startsWith('/api/videos') || c.startsWith('/api/categories'))).toBe(false)
  })
})
