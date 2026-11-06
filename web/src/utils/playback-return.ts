// iPhone playback runs in its own black document (player.html). Returning to the
// list is a cross-document navigation, which used to show the player fading to
// black and then the list reloading. To keep it seamless, the list is captured as
// static HTML when playback opens; the player draws it under the shrinking video
// and index.html paints it again until Vue has restored the real list.

export const RETURN_SNAPSHOT_KEY = 'short-playback-return'
export const REPLICA_ID = 'playback-return-replica'

export interface ReturnSnapshot {
  entry: string
  html: string
  styles: string[]
  frame: { top: number; left: number; width: number; height: number }
  top: number
}

/** Serialize the visible app (list page) with blob covers swapped for placeholders. */
export function captureReturnSnapshot(app: HTMLElement, entry: string, ratios: Record<string, string>): ReturnSnapshot {
  const clone = app.cloneNode(true) as HTMLElement
  clone.querySelectorAll('script, video, .video-grid.invisible').forEach(node => node.remove())
  clone.querySelectorAll<HTMLImageElement>('img').forEach(image => {
    const src = image.getAttribute('src') || ''
    if (!src.startsWith('blob:')) return
    image.removeAttribute('src')
    const id = image.dataset.videoCover
    if (id) {
      image.dataset.replicaCover = id
      // Keep the measured height even when no cover bytes are available.
      if (ratios[id]) image.style.aspectRatio = ratios[id]
    }
  })
  const frame = app.getBoundingClientRect()
  return {
    entry,
    html: clone.innerHTML,
    styles: [...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')].map(link => link.href),
    frame: { top: frame.top, left: frame.left, width: frame.width, height: frame.height },
    top: app.querySelector<HTMLElement>('.discovery-scroll')?.scrollTop ?? 0,
  }
}

export function readReturnSnapshot(entry?: string | null): ReturnSnapshot | null {
  try {
    const saved = JSON.parse(sessionStorage.getItem(RETURN_SNAPSHOT_KEY) || 'null')
    if (!saved || typeof saved.html !== 'string' || !Array.isArray(saved.styles)) return null
    return entry && saved.entry !== entry ? null : saved
  } catch {
    return null
  }
}

function ensureStyles(hrefs: string[]) {
  const loaded = new Set([...document.querySelectorAll<HTMLLinkElement>('link[rel="stylesheet"]')].map(link => link.href))
  const pending: Promise<void>[] = []
  for (const href of hrefs) {
    if (!href.startsWith(location.origin + '/') || loaded.has(href)) continue
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    // Same href form as Vite's CSS preloader, so it does not add the sheet twice.
    link.href = new URL(href).pathname
    pending.push(new Promise(resolve => { link.onload = link.onerror = () => resolve() }))
    document.head.append(link)
  }
  return Promise.all(pending)
}

/**
 * Draw the captured list as a fixed layer inside `parent` (first child, so the
 * player painted after it stays on top); resolves once its styles are ready.
 */
export async function renderReturnSnapshot(snapshot: ReturnSnapshot, covers: Record<string, string>, parent: HTMLElement) {
  document.getElementById(REPLICA_ID)?.remove()
  const layer = document.createElement('div')
  layer.id = REPLICA_ID
  layer.setAttribute('aria-hidden', 'true')
  layer.inert = true
  const { top, left, width, height } = snapshot.frame
  layer.style.cssText = `position:fixed;top:${top}px;left:${left}px;width:${width}px;height:${height}px;z-index:0;background:#fff;overflow:hidden;pointer-events:none;visibility:hidden;color-scheme:light;color:#161616`
  const template = document.createElement('template')
  template.innerHTML = snapshot.html
  template.content.querySelectorAll('script').forEach(node => node.remove())
  template.content.querySelectorAll<HTMLImageElement>('img[data-replica-cover]').forEach(image => {
    const cover = covers[image.dataset.replicaCover!]
    if (typeof cover === 'string' && cover.startsWith('data:image/')) image.src = cover
  })
  layer.append(template.content)
  parent.prepend(layer)
  await ensureStyles(snapshot.styles)
  const scroller = layer.querySelector<HTMLElement>('.discovery-scroll')
  if (scroller) scroller.scrollTop = snapshot.top
  layer.style.visibility = 'visible'
  return layer
}

/**
 * The current video's card inside the drawn layer. If it is scrolled out of view
 * (the viewer swiped to another video), scroll the layer to it and return the new
 * list position so the real list can restore to the same place.
 */
export function replicaCardRect(layer: HTMLElement, id: number | undefined): { rect?: DOMRect; top?: number } {
  if (!id) return {}
  const poster = layer.querySelector<HTMLElement>(`img[data-video-cover="${id}"]`)
  if (!poster) return {}
  const scroller = layer.querySelector<HTMLElement>('.discovery-scroll')
  let rect = poster.getBoundingClientRect()
  if (scroller) {
    const view = scroller.getBoundingClientRect()
    if (rect.top < view.top || rect.bottom > view.bottom) {
      scroller.scrollTop += rect.top - (view.top + Math.max(0, (view.height - rect.height) / 2))
      rect = poster.getBoundingClientRect()
    }
  }
  return rect.width > 0 && rect.height > 0 ? { rect, top: scroller?.scrollTop } : {}
}

/** Like the reference app: the list starts dimmed and brightens while the video shrinks. */
export function fadeReturnDim(layer: HTMLElement, duration: number) {
  let dim = layer.querySelector<HTMLElement>(':scope > .playback-return-dim')
  if (!dim) {
    dim = document.createElement('div')
    dim.className = 'playback-return-dim'
    dim.style.cssText = 'position:absolute;inset:0;background:#000;opacity:0;pointer-events:none;z-index:1'
    layer.append(dim)
  }
  dim.animate?.([{ opacity: .55 }, { opacity: 0 }], { duration, easing: 'ease-out', fill: 'forwards' })
}

export function removeReturnReplica() {
  document.getElementById(REPLICA_ID)?.remove()
}
