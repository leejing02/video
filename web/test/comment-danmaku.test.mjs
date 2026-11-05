import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
const source = readFileSync(new URL('../src/utils/comment-danmaku.ts', import.meta.url), 'utf8')
const js = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ES2020 } }).outputText
const { commentDanmaku } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)

test('comment overlay omits blank and duplicate records and bounds long emoji content', () => {
  assert.deepEqual(commentDanmaku([
    { id: 1, content: '  ' }, { id: 2, content: ' hello ' },
    { id: 2, content: 'duplicate' }, { id: 3, content: '😀'.repeat(100) },
  ]), [{ id: 2, text: 'hello' }, { id: 3, text: '😀'.repeat(80) }])
})
test('large threads have a bounded overlay and empty videos have no fabricated text', () => {
  assert.equal(commentDanmaku(Array.from({ length: 1000 }, (_,id) => ({id,content:'comment'}))).length,12)
  assert.deepEqual(commentDanmaku([]),[])
})
