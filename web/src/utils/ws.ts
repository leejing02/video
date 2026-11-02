type Listener = (data: any) => void

export class WsClient {
  private ws: WebSocket | null = null
  private listeners = new Map<string, Listener[]>()
  private reconnectTimer: number | null = null
  private heartbeatTimer: number | null = null
  private retries = 0
  private destroyed = false
  private roomId = 0
  private token = ''
  private baseURL = ''

  connect(roomId: number, token: string, baseURL: string) {
    this.roomId = roomId
    this.token = token
    this.baseURL = baseURL
    this.destroyed = false
    this.retries = 0
    this.openSocket()
  }

  disconnect() {
    this.destroyed = true
    this.clearTimers()
    this.ws?.close(1000, 'client disconnect')
    this.ws = null
  }

  on(event: string, cb: Listener) {
    const list = this.listeners.get(event) ?? []
    list.push(cb)
    this.listeners.set(event, list)
    return () => this.off(event, cb)
  }

  off(event: string, cb: Listener) {
    const list = this.listeners.get(event)
    if (list) this.listeners.set(event, list.filter(l => l !== cb))
  }

  reconnect() { this.ws?.close() }

  private openSocket() {
    const proto = this.baseURL.startsWith('https') ? 'wss' : 'ws'
    const host = this.baseURL.replace(/^https?:\/\//, '').replace(/\/$/, '')
    const url = `${proto}://${host}/ws/chat?room_id=${this.roomId}&token=${encodeURIComponent(this.token)}`
    try {
      this.ws = new WebSocket(url)
    } catch {
      this.scheduleReconnect()
      return
    }
    this.ws.onopen = () => {
      this.retries = 0
      this.startHeartbeat()
      this.emit('open', null)
    }
    this.ws.onmessage = e => {
      this.startHeartbeat()
      try {
        const msg = JSON.parse(e.data)
        if (msg && typeof msg.event === 'string') this.emit(msg.event, msg.data)
      } catch {}
    }
    this.ws.onerror = () => this.emit('error', null)
    this.ws.onclose = e => {
      this.emit('close', e.code)
      this.clearTimers()
      if (!this.destroyed) this.scheduleReconnect()
    }
  }

  private scheduleReconnect() {
    if (this.destroyed) return
    this.retries++
    const delay = Math.min(30000, 1000 * 2 ** Math.min(5, this.retries))
    this.reconnectTimer = window.setTimeout(() => this.openSocket(), delay)
  }

  private startHeartbeat() {
    if (this.heartbeatTimer) clearTimeout(this.heartbeatTimer)
    this.heartbeatTimer = window.setTimeout(() => {
      try { this.ws?.send(JSON.stringify({ event: 'ping' })) }
      catch { this.reconnect() }
    }, 30000)
  }

  private clearTimers() {
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer)
    if (this.heartbeatTimer) clearTimeout(this.heartbeatTimer)
    this.reconnectTimer = null
    this.heartbeatTimer = null
  }

  private emit(event: string, data: any) {
    this.listeners.get(event)?.forEach(cb => cb(data))
  }
}

export const globalWs = new WsClient()
