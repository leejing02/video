import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createCipheriv, randomBytes } from 'node:crypto'
import ts from 'typescript'

const path = new URL('../src/utils/efv-image.ts', import.meta.url)
const source = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2020, module: ts.ModuleKind.ES2020 } }).outputText
const { decodeEfvImage, isEfvImageUrl } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)

const webp = Buffer.concat([Buffer.from('RIFF'), Buffer.alloc(4), Buffer.from('WEBPVP8 '), randomBytes(200)])
const bytes = buffer => buffer.buffer.slice(buffer.byteOffset, buffer.byteOffset + buffer.byteLength)
const decoded = async (data, config = null) => {
  const blob = await decodeEfvImage(bytes(data), config)
  return blob && { type: blob.type, data: Buffer.from(await blob.arrayBuffer()) }
}

test('only EFV movie cover URLs are fetched from the image domain', () => {
  assert.ok(isEfvImageUrl('https://tupian.example/videos/202611/05/6aec7c9ff8bd243144f7b719/cover.webp?v=56e89aae0f40'))
  assert.ok(!isEfvImageUrl('http://tupian.example/videos/202611/05/6aec7c9ff8bd243144f7b719/cover.webp'))
  assert.ok(!isEfvImageUrl('/storage/shorts/covers/efv-abc.webp'))
  assert.ok(!isEfvImageUrl('https://tupian.example/videos/202611/05/6aec7c9ff8bd243144f7b719/../x/cover.webp'))
})

test('plain, AES and XOR encoded covers decode to the original image', async () => {
  assert.deepEqual(await decoded(webp), { type: 'image/webp', data: webp })

  for (const [cipher, keyLength] of [['aes-256-ctr', 32], ['aes-128-ctr', 16], ['aes-256-cbc', 32]]) {
    const key = randomBytes(keyLength)
    const iv = randomBytes(16)
    const c = createCipheriv(cipher, key, iv)
    const encrypted = Buffer.concat([c.update(webp), c.final()])
    const config = { image_origin: 'https://tupian.example', cipher, key: key.toString('hex'), iv: iv.toString('hex') }
    assert.deepEqual(await decoded(encrypted, config), { type: 'image/webp', data: webp }, cipher)
  }

  // An IV whose low 64 bits overflow must still match OpenSSL's 128-bit counter.
  const key = randomBytes(32)
  const iv = Buffer.from('00'.repeat(8) + 'ff'.repeat(8), 'hex')
  const c = createCipheriv('aes-256-ctr', key, iv)
  const overflow = Buffer.concat([c.update(webp), c.final()])
  assert.deepEqual(await decoded(overflow, { image_origin: null, cipher: 'aes-256-ctr', key: key.toString('hex'), iv: iv.toString('hex') }), { type: 'image/webp', data: webp })

  const xor = Buffer.from(webp)
  for (let i = 0; i < 9; i++) xor[i] ^= 0x12
  assert.deepEqual(await decoded(xor), { type: 'image/webp', data: webp })
})

test('undecodable bytes are rejected so the local copy can be shown', async () => {
  const config = { image_origin: null, cipher: 'aes-256-ctr', key: '00'.repeat(32), iv: '00'.repeat(16) }
  assert.equal(await decoded(Buffer.from('<html>404</html>'), config), null)
})
