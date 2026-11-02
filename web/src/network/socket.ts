// Typed WebSocket client with automatic reconnect and heartbeat.
//
// Usage:
//   const sock = new GameSocketClient({ url: 'ws://...', token: jwt })
//   await sock.connect()
//   sock.on(MsgID.HIT, (data: HitData) => { ... })
//   sock.send(MsgID.FIRE, { bullet_level: 3, fish_type_id: 4 })

export const MsgID = {
  FIRE:        1001,
  HIT:         1002,
  ENTER_ROOM:  1003,
  FISH_SYNC:   1004,
  PLAYER_SYNC: 1005,
  PING:        9001,
  PONG:        9002,
} as const

export type MsgIDValue = (typeof MsgID)[keyof typeof MsgID]

export interface Packet<T = unknown> {
  msg_id: number
  data:   T
}

// ── typed payloads ────────────────────────────────────────────────────────────

export interface FirePayload {
  bullet_level:    number
  fish_type_id:    number
  accuracy_factor?: number
}

export interface HitData {
  player_id: number
  fish_id:   string
  type_id:   number
  reward:    number
  balance:   number
}

export interface FishState {
  id:      string
  type_id: number
  x:       number
  y:       number
  angle:   number
}

export interface EnterRoomData {
  room_id:  string
  self_id:  number
  balance:  number
  fish:     FishState[]
  players:  { id: number; balance: number }[]
}

export interface FishSyncData {
  tick: number
  fish: FishState[]
}

export interface PlayerSyncData {
  players: { id: number; balance: number }[]
}

// ── client ────────────────────────────────────────────────────────────────────

type Handler<T = unknown> = (data: T) => void

export interface SocketOptions {
  url:                string
  token:              string
  pingIntervalMs?:    number   // default 30 000
  pongTimeoutMs?:     number   // default 90 000  (3 missed pings)
  maxReconnectDelay?: number   // default 30 000
}

export class GameSocketClient {
  private ws:      WebSocket | null = null
  private readonly url:   string
  private readonly token: string
  private readonly pingInterval:    number
  private readonly pongTimeout:     number
  private readonly maxReconnectDelay: number

  private destroyed         = false
  private reconnectAttempts = 0
  private lastPongAt        = 0

  private heartbeatTimer?: ReturnType<typeof setInterval>
  private reconnectTimer?:  ReturnType<typeof setTimeout>

  private readonly handlers = new Map<number, Set<Handler>>()

  get connected(): boolean {
    return this.ws?.readyState === WebSocket.OPEN
  }

  constructor(opts: SocketOptions) {
    this.url               = opts.url
    this.token             = opts.token
    this.pingInterval      = opts.pingIntervalMs    ?? 30_000
    this.pongTimeout       = opts.pongTimeoutMs     ?? 90_000
    this.maxReconnectDelay = opts.maxReconnectDelay ?? 30_000
  }

  connect(): Promise<void> {
    return this._doConnect()
  }

  send<T = unknown>(msgId: number, data: T): void {
    if (this.ws?.readyState !== WebSocket.OPEN) return
    this.ws.send(JSON.stringify({ msg_id: msgId, data }))
  }

  /** Register a typed handler; returns an unsubscribe fn. */
  on<T = unknown>(msgId: number, handler: Handler<T>): () => void {
    if (!this.handlers.has(msgId)) this.handlers.set(msgId, new Set())
    this.handlers.get(msgId)!.add(handler as Handler)
    return () => this.handlers.get(msgId)?.delete(handler as Handler)
  }

  /** Convenience: send a FIRE packet. */
  fire(payload: FirePayload): void {
    this.send(MsgID.FIRE, payload)
  }

  /** Tear down the socket and stop all timers. */
  destroy(): void {
    this.destroyed = true
    clearTimeout(this.reconnectTimer)
    this._stopHeartbeat()
    this.ws?.close()
  }

  // ── private ──────────────────────────────────────────────────────────────

  private _doConnect(): Promise<void> {
    return new Promise((resolve, reject) => {
      const ws = new WebSocket(`${this.url}?token=${encodeURIComponent(this.token)}`)
      this.ws = ws

      ws.onopen = () => {
        this.reconnectAttempts = 0
        this.lastPongAt = Date.now()
        this._startHeartbeat()
        resolve()
      }

      ws.onerror = () => {
        // onclose fires after onerror; reject here for the initial connect Promise
        reject(new Error('WebSocket connection failed'))
      }

      ws.onclose = () => {
        this._stopHeartbeat()
        if (!this.destroyed) this._scheduleReconnect()
      }

      ws.onmessage = ({ data }) => this._dispatch(data as string)
    })
  }

  private _dispatch(raw: string): void {
    let pkt: Packet
    try { pkt = JSON.parse(raw) } catch { return }

    if (pkt.msg_id === MsgID.PONG) {
      this.lastPongAt = Date.now()
      return
    }

    const set = this.handlers.get(pkt.msg_id)
    if (set) for (const h of set) h(pkt.data)
  }

  private _startHeartbeat(): void {
    this.lastPongAt = Date.now()
    this.heartbeatTimer = setInterval(() => {
      if (Date.now() - this.lastPongAt > this.pongTimeout) {
        // server is unreachable — force close triggers reconnect
        this.ws?.close()
        return
      }
      this.send(MsgID.PING, null)
    }, this.pingInterval)
  }

  private _stopHeartbeat(): void {
    clearInterval(this.heartbeatTimer)
  }

  private _scheduleReconnect(): void {
    const delay = Math.min(1_000 * 2 ** this.reconnectAttempts, this.maxReconnectDelay)
    this.reconnectAttempts++
    this.reconnectTimer = setTimeout(() => {
      this._doConnect().catch(() => {/* next onclose will retry */})
    }, delay)
  }
}
