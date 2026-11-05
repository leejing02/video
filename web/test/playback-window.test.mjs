import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
const source = readFileSync(new URL('../src/utils/playback-window.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText
const { playbackCoverWindow } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)

test('preload six posters starting at the playing video, including at the end of a category', () => {
  const videos = Array.from({ length: 30 }, (_, id) => id)
  assert.deepEqual(playbackCoverWindow(videos, 0), [0, 1, 2, 3, 4, 5])
  assert.deepEqual(playbackCoverWindow(videos, 10), [10, 11, 12, 13, 14, 15])
  assert.deepEqual(playbackCoverWindow(videos, 29), [24, 25, 26, 27, 28, 29])
})
test('small or empty categories never preload nonexistent entries', () => {
  assert.deepEqual(playbackCoverWindow([], 0), [])
  assert.deepEqual(playbackCoverWindow([1, 2, 3], 2), [1, 2, 3])
})
