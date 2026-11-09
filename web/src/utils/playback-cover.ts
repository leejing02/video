// Object URLs belong to the discovery document and cannot survive its unload.
// Copy already-decoded image bytes, rather than fetching/decrypting the cover again.
export async function portablePlaybackCover(image: HTMLImageElement | null): Promise<string> {
  if (!image?.naturalWidth) return ''
  const source = image.currentSrc || image.src
  if (!source.startsWith('blob:')) return source
  try {
    const response = await fetch(source, { signal: AbortSignal.timeout(1500) })
    if (!response.ok) return ''
    const blob = await response.blob()
    if (!blob.type.startsWith('image/') || !blob.size || blob.size > 3 * 1024 * 1024) return ''
    return await new Promise<string>((resolve) => {
      const reader = new FileReader()
      reader.onload = () => resolve(typeof reader.result === 'string' ? reader.result : '')
      reader.onerror = reader.onabort = () => resolve('')
      reader.readAsDataURL(blob)
    })
  } catch { return '' }
}
