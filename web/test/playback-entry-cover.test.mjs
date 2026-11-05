import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import vm from 'node:vm'

const html = readFileSync(new URL('../player.html', import.meta.url), 'utf8')
const script = html.match(/<script>\s*([\s\S]*?)<\/script>/)[1]
function boot(overrides = {}, unavailable = false) {
  const layers = []
  const saved = { id: 10, entry: 'current', created: Date.now(), cover: 'data:image/webp;base64,AQID', ...overrides }
  vm.runInNewContext(script, {
    URLSearchParams, Date,
    location: { search: '?id=10&entry=current' },
    sessionStorage: { getItem() { if (unavailable) throw new Error('blocked'); return JSON.stringify(saved) } },
    document: {
      createElement: () => ({ setAttribute() {}, append(image) { this.image = image }, remove() {} }),
      body: { append(layer) { layers.push(layer) } },
    },
    Image: class {},
    setTimeout() {},
  })
  return layers
}

test('clicked cover is painted by plain HTML before Vue startup', () => {
  assert.ok(html.indexOf('<script>') < html.indexOf('src="/src/main.ts"'))
  const layers = boot()
  assert.equal(layers.length, 1)
  assert.equal(layers[0].id, 'playback-entry-cover')
  assert.equal(layers[0].image.src, 'data:image/webp;base64,AQID')
  assert.equal(layers[0].image.decoding, 'sync')
})

test('stale or different playback entries never show an unrelated cover', () => {
  for (const overrides of [{ id: 11 }, { entry: 'old' }, { returning: true }, { created: Date.now() - 600000 }, { cover: 'javascript:alert(1)' }]) {
    assert.equal(boot(overrides).length, 0)
  }
  assert.equal(boot({}, true).length, 0)
})
