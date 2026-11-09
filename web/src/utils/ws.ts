type Listener = (data: any) => void

export class WsClient {
  private ws: WebSocket | null = null
  private listeners = new Map<string, Listener[]>()
  private timer: ReturnType<typeof setTimeout> | null = null
  private generation = 0
  private retries = 0
  private stopped = true
  private roomId = 0
  private token = ''
  private baseURL = ''

  connect(roomId: number, token: string, baseURL: string) {
    this.disconnect()
    this.roomId = roomId
    this.token = token
    this.baseURL = baseURL
    this.stopped = false
    this.retries = 0
    this.openSocket(this.generation)
  }

  disconnect() {
    this.stopped = true
    this.generation++
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
    const previous = this.ws
    this.ws = null
    previous?.close(1000, 'client disconnect')
  }

  on(event: string, cb: Listener) {
    const list = this.listeners.get(event) ?? []
    list.push(cb)
    this.listeners.set(event, list)
    return () => this.off(event, cb)
  }

  off(event: string, cb: Listener) {
    this.listeners.set(event, (this.listeners.get(event) ?? []).filter(item => item !== cb))
  }

  reconnect() {
    if (!this.stopped) this.connect(this.roomId, this.token, this.baseURL)
  }

  private openSocket(generation: number) {
    if (this.stopped || generation !== this.generation) return
    const url = new URL('/ws/chat', this.baseURL)
    url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:'
    url.searchParams.set('room_id', String(this.roomId))
    url.searchParams.set('token', this.token)
    let socket: WebSocket
    try { socket = new WebSocket(url.toString()) }
    catch { this.retry(generation); return }
    this.ws = socket
    const current = () => !this.stopped && generation === this.generation && this.ws === socket
    socket.onopen = () => {
      if (!current()) return
      this.retries = 0
      this.emit('open', null)
    }
    socket.onmessage = event => {
      if (!current()) return
      try {
        const message = JSON.parse(event.data)
        if (typeof message.event === 'string') this.emit(message.event, message.data)
      } catch { /* Ignore invalid envelopes; do not disrupt the connection. */ }
    }
    socket.onerror = () => { if (current()) this.emit('error', null) }
    socket.onclose = event => {
      if (!current()) return
      this.emit('close', event.code)
      this.retry(generation)
    }
  }

  private retry(generation: number) {
    if (this.stopped || generation !== this.generation) return
    const delay = Math.min(30000, 1000 * 2 ** Math.min(5, this.retries++))
    this.timer = setTimeout(() => { this.timer = null; this.openSocket(generation) }, delay)
  }

  private emit(event: string, data: any) {
    this.listeners.get(event)?.forEach(cb => cb(data))
  }
}

export const globalWs = new WsClient()
