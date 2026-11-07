import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'

const path = new URL('../src/utils/guest-device.ts', import.meta.url)
const source = ts.transpileModule(readFileSync(path, 'utf8'), { compilerOptions: { target: ts.ScriptTarget.ES2020, module: ts.ModuleKind.ES2020 } }).outputText
const { guestDeviceId, isGuestEmail, GUEST_DEVICE_KEY } = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)

function memoryStorage() {
  const data = new Map()
  return { getItem: k => data.get(k) ?? null, setItem: (k, v) => data.set(k, String(v)), data }
}
const DAY = 24 * 60 * 60 * 1000

test('the same browser keeps its guest device id for 30 days, then gets a new one', () => {
  const storage = memoryStorage()
  const start = Date.UTC(2026, 10, 7)
  const id = guestDeviceId(storage, start)
  assert.match(id, /^[A-Za-z0-9-]{32,64}$/)
  assert.equal(guestDeviceId(storage, start + 29 * DAY), id)
  const renewed = guestDeviceId(storage, start + 30 * DAY)
  assert.notEqual(renewed, id)
  assert.equal(JSON.parse(storage.getItem(GUEST_DEVICE_KEY)).created, start + 30 * DAY)
})

test('unreadable or tampered device ids are replaced', () => {
  const storage = memoryStorage()
  storage.setItem(GUEST_DEVICE_KEY, '{bad')
  assert.match(guestDeviceId(storage, 1000), /^[A-Za-z0-9-]{32,64}$/)
  storage.setItem(GUEST_DEVICE_KEY, JSON.stringify({ id: 'x', created: 1000 }))
  assert.notEqual(guestDeviceId(storage, 1000), 'x')
})

test('guest accounts are recognised by their reserved email domain', () => {
  assert.equal(isGuestEmail('guest-abc@guest.invalid'), true)
  assert.equal(isGuestEmail('bob@example.com'), false)
  assert.equal(isGuestEmail(null), false)
})
