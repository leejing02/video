export function postLoginPath(value: unknown): string {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || value.includes('\\')) return '/chat'
  const path = value.split(/[?#]/)[0]
  return path === '/login' || path === '/' ? '/chat' : value
}

export function viewportFrame(innerHeight: number, visibleHeight?: number, offsetTop?: number) {
  return { height: Math.max(1, visibleHeight ?? innerHeight), top: Math.max(0, offsetTop ?? 0) }
}
