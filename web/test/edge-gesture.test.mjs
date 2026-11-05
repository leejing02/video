import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const source = readFileSync(new URL('../src/utils/edge-gesture.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText
const { startsAtScreenEdge, installEdgeGestureGuard } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)

test('both screen edges are guarded while ordinary page gestures stay available', () => {
  for (const x of [0, 12, 24, 366, 380, 390]) assert.equal(startsAtScreenEdge(x, 390), true)
  for (const x of [25, 120, 250, 365]) assert.equal(startsAtScreenEdge(x, 390), false)
})

test('history swipe is cancelled on touchstart without stopping custom touch handlers', () => {
  const previous = globalThis.document
  const document = Object.assign(new EventTarget(), { documentElement: { clientWidth: 390 } })
  globalThis.document = document
  try {
    installEdgeGestureGuard()
    let delivered = 0
    document.addEventListener('touchstart', () => { delivered++ })
    const touch = (x, count = 1) => {
      const event = new Event('touchstart', { cancelable: true })
      Object.defineProperty(event, 'touches', { value: Array.from({ length: count }, () => ({ clientX: x })) })
      document.dispatchEvent(event)
      return event.defaultPrevented
    }
    assert.equal(touch(388), true)
    assert.equal(touch(2), true)
    assert.equal(touch(180), false)
    assert.equal(touch(2, 2), false)
    assert.equal(delivered, 4)
  } finally { globalThis.document = previous }
})
