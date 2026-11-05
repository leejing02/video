import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const source = readFileSync(new URL('../src/utils/single-flight.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText
const { singleFlight } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)

test('timer, socket and resume share one request until it finishes', async () => {
  let release
  let calls = 0
  const run = singleFlight(() => { calls++; return new Promise(resolve => { release = resolve }) })
  const first = run()
  assert.equal(run(), first)
  assert.equal(run(), first)
  await Promise.resolve()
  assert.equal(calls, 1)
  release()
  await first
  const next = run()
  await Promise.resolve()
  assert.equal(calls, 2)
  release()
  await next
})

test('a failed recovery does not leave future requests locked', async () => {
  let calls = 0
  const run = singleFlight(async () => { if (++calls === 1) throw new Error('offline') })
  await assert.rejects(run(), /offline/)
  await run()
  assert.equal(calls, 2)
})
