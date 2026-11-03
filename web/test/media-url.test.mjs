import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
const path = new URL('../src/utils/media-url.ts', import.meta.url)
const source = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2020, module: ts.ModuleKind.ES2020 } }).outputText
const { mediaUrl } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
test('public upload paths use the storage endpoint rather than the current SPA route', () => {
  assert.equal(mediaUrl('chat-rooms/cover.jpg'), '/storage/chat-rooms/cover.jpg')
  assert.equal(mediaUrl('avatars/member.jpg'), '/storage/avatars/member.jpg')
  assert.equal(mediaUrl('categories/short/cover.jpg'), '/storage/categories/short/cover.jpg')
  assert.equal(mediaUrl('shorts/covers/cover.jpg'), '/storage/shorts/covers/cover.jpg')
  assert.equal(mediaUrl('storage/shorts/covers/cover.jpg'), '/storage/shorts/covers/cover.jpg')
  assert.equal(mediaUrl('/storage/cover.jpg'), '/storage/cover.jpg')
})
test('external URLs and existing root assets are preserved, empty or unsafe images are omitted', () => {
  assert.equal(mediaUrl('https://cdn.example.test/cover.jpg'), 'https://cdn.example.test/cover.jpg')
  assert.equal(mediaUrl('/poster/1'), '/poster/1')
  for (const value of [null, undefined, '', 'javascript:alert(1)', 'data:text/html,test']) assert.equal(mediaUrl(value), '')
})
