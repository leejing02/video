import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import ts from 'typescript'
async function moduleAt(path) {
 const js=ts.transpileModule(readFileSync(new URL(path,import.meta.url),'utf8'),{compilerOptions:{module:ts.ModuleKind.ES2020,target:ts.ScriptTarget.ES2020}}).outputText
 return import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`)
}
const {postLoginPath,viewportFrame}=await moduleAt('../src/utils/mobile-shell.ts')
test('login replacement only accepts internal destinations and never returns to login',()=>{
 for(const input of [undefined,'/login','/login?next=/chat','//outside.test','https://outside.test','/\\outside.test','/'])assert.equal(postLoginPath(input),'/chat')
 assert.equal(postLoginPath('/long?category=2'),'/long?category=2')
})
test('keyboard viewport fits the visible area without collapsing to a minimum page height',()=>{
 assert.deepEqual(viewportFrame(844,390,0),{height:390,top:0})
 assert.deepEqual(viewportFrame(844,390,95),{height:390,top:95})
 assert.deepEqual(viewportFrame(844,undefined,undefined),{height:844,top:0})
})
