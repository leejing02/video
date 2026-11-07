// Covers on the EFV image domain may be encrypted (X-Encode-Mode: xor | aes).
// That header is not exposed cross-origin, so the bytes are identified by their
// image signature: plain first, then the configured AES cipher, then EFV's XOR.

export interface EfvImageConfig {
  image_origin: string | null
  cipher: string | null
  key: string | null
  iv: string | null
}

const efvImagePath = /^https:\/\/[^/?#]+\/videos\/\d{6}\/\d{2}\/[a-f0-9]{24}\/cover\.webp(?:\?[^#]*)?$/

export function isEfvImageUrl(value: string) {
  return efvImagePath.test(value)
}

export function imageType(bytes: Uint8Array): string | null {
  const ascii = (start: number, end: number) => String.fromCharCode(...bytes.subarray(start, end))
  if (bytes.length >= 12 && ascii(0, 4) === 'RIFF' && ascii(8, 12) === 'WEBP') return 'image/webp'
  if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'image/jpeg'
  if (bytes.length >= 8 && bytes[0] === 0x89 && ascii(1, 4) === 'PNG') return 'image/png'
  if (bytes.length >= 6 && ascii(0, 4) === 'GIF8') return 'image/gif'
  return null
}

function hexBytes(hex: string) {
  const out = new Uint8Array(hex.length / 2)
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16)
  return out
}

async function decryptAes(bytes: Uint8Array<ArrayBuffer>, config: EfvImageConfig): Promise<Uint8Array<ArrayBuffer> | null> {
  const { cipher, key, iv } = config
  if (!cipher || !key || !iv || !globalThis.crypto?.subtle) return null
  const cbc = cipher.includes('cbc')
  const name = cbc ? 'AES-CBC' : 'AES-CTR'
  try {
    const cryptoKey = await crypto.subtle.importKey('raw', hexBytes(key), { name }, false, ['decrypt'])
    const params = cbc
      ? { name, iv: hexBytes(iv) }
      // EFV's guide passes length 64; EFV/OpenSSL increment the whole 128-bit block,
      // which only differs when the IV's low 64 bits overflow — 128 matches the server.
      : { name, counter: hexBytes(iv), length: 128 }
    return new Uint8Array(await crypto.subtle.decrypt(params, cryptoKey, bytes))
  } catch {
    return null
  }
}

function decryptXor(bytes: Uint8Array) {
  const out = bytes.slice()
  for (let i = 0; i < Math.min(9, out.length); i++) out[i] ^= 0x12
  return out
}

export async function decodeEfvImage(data: ArrayBuffer, config: EfvImageConfig | null): Promise<Blob | null> {
  const bytes = new Uint8Array(data)
  const candidates: (() => Promise<Uint8Array<ArrayBuffer> | null> | Uint8Array<ArrayBuffer> | null)[] = [
    () => bytes,
    () => (config ? decryptAes(bytes, config) : null),
    () => decryptXor(bytes),
  ]
  for (const candidate of candidates) {
    const plain = await candidate()
    const type = plain && imageType(plain)
    if (plain && type) return new Blob([plain], { type })
  }
  return null
}
