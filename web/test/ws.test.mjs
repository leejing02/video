import {test} from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import ts from 'typescript'
const source=ts.transpileModule(readFileSync(new URL('../src/utils/ws.ts',import.meta.url),'utf8'),{compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.ES2020}}).outputText
const {WsClient}=await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`)
const sockets=[]
class FakeSocket {
 constructor(url){this.url=url;sockets.push(this)}
 close(){this.closed=true;this.onclose?.({code:1000})}
}
globalThis.WebSocket=FakeSocket

test('old connection callbacks cannot deliver stale room messages or close the new subscription',()=>{
 const client=new WsClient();const received=[];let opened=0
 client.on('message.sent',data=>received.push(data));client.on('open',()=>opened++)
 client.connect(7,'fixture','https://example.test');const old=sockets.at(-1)
 client.connect(8,'fixture','https://example.test');const current=sockets.at(-1)
 old.onmessage({data:JSON.stringify({event:'message.sent',data:'old'})});old.onopen()
 current.onopen();current.onmessage({data:JSON.stringify({event:'message.sent',data:'new'})})
 assert.deepEqual(received,['new']);assert.equal(opened,1)
 client.disconnect();current.onmessage({data:JSON.stringify({event:'message.sent',data:'after destroy'})})
 assert.deepEqual(received,['new'])
})
test('user notification subscription uses secure websocket and room zero',()=>{
 const client=new WsClient();client.connect(0,'fixture','https://example.test')
 const url=new URL(sockets.at(-1).url);assert.equal(url.protocol,'wss:');assert.equal(url.searchParams.get('room_id'),'0')
 client.disconnect()
})
