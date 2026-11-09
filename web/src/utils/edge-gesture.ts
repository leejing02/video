// Safari starts its history swipe on touchstart, before a touchmove guard can run.
export function startsAtScreenEdge(x: number, width: number) {
  return width > 0 && (x <= 24 || x >= width - 24)
}

export function installEdgeGestureGuard() {
  document.addEventListener('touchstart', event => {
    if (event.touches.length !== 1 || !event.cancelable) return
    if (startsAtScreenEdge(event.touches[0].clientX, document.documentElement.clientWidth)) {
      event.preventDefault()
    }
  }, { capture: true, passive: false })
}
