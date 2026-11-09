// Filament stores public disk keys, while the Go API returns those keys unchanged.
export function mediaUrl(value?: string | null): string {
  const path = value?.trim()
  if (!path) return ''
  if (/^https?:\/\//i.test(path)) return path
  if (/^[a-z][a-z0-9+.-]*:/i.test(path)) return ''
  if (path.startsWith('/')) return path
  return path.startsWith('storage/') ? `/${path}` : `/storage/${path}`
}
