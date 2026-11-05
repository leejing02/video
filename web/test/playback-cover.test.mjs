import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const source = readFileSync(new URL('../src/utils/playback-cover.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText
const { portablePlaybackCover } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)

test('only a loaded cover is transferred and durable URLs can be reused', async () => {
  assert.equal(await portablePlaybackCover(null), '')
  assert.equal(await portablePlaybackCover({ naturalWidth: 0, currentSrc: 'blob:old' }), '')
  assert.equal(await portablePlaybackCover({ naturalWidth: 100, currentSrc: '/storage/cover.webp' }), '/storage/cover.webp')
})

test('temporary cover bytes survive document unload without recompression', async () => {
  const originalFetch = globalThis.fetch
  const originalReader = globalThis.FileReader
  const bytes = Uint8Array.from([1, 2, 3, 4, 5])
  globalThis.fetch = async () => new Response(new Blob([bytes], { type: 'image/webp' }))
  globalThis.FileReader = class {
    async readAsDataURL(blob) {
      this.result = `data:${blob.type};base64,${Buffer.from(await blob.arrayBuffer()).toString('base64')}`
      this.onload()
    }
  }
  try {
    const cover = await portablePlaybackCover({ naturalWidth: 100, currentSrc: 'blob:old-document' })
    assert.equal(cover, 'data:image/webp;base64,AQIDBAU=')
    globalThis.fetch = async () => { throw new Error('revoked') }
    assert.equal(await portablePlaybackCover({ naturalWidth: 100, currentSrc: cover }), cover)
    assert.equal(await portablePlaybackCover({ naturalWidth: 100, currentSrc: 'blob:revoked' }), '')
  } finally {
    globalThis.fetch = originalFetch
    globalThis.FileReader = originalReader
  }
})
