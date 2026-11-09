export function playbackCoverWindow<T>(items: readonly T[], index: number): T[] {
  const current = Math.max(0, Math.min(index, Math.max(0, items.length - 1)))
  const start = Math.min(current, Math.max(0, items.length - 6))
  return items.slice(start, start + 6)
}
