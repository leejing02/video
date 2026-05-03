// Package ws —— 简易 WebSocket Hub
//
// 客户端连接 /ws/chat?room_id=N&token=JWT
// 服务端在 chat.send 接口里调用 Hub.Broadcast(roomID, msg) 推送给该房间所有人
package ws

import (
	"encoding/json"
	"log"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// Client 一个 ws 连接 = 一个 Client
type Client struct {
	hub    *Hub
	conn   *websocket.Conn
	send   chan []byte
	roomID int64
	userID int64
}

// Envelope 服务端 → 客户端的消息封皮
type Envelope struct {
	Event string `json:"event"`
	Data  any    `json:"data"`
}

// Hub 维护所有连接，按 roomID 分组广播
type Hub struct {
	mu      sync.RWMutex
	rooms   map[int64]map[*Client]struct{} // roomID -> set
	regCh   chan *Client
	unregCh chan *Client
}

func NewHub() *Hub {
	return &Hub{
		rooms:   make(map[int64]map[*Client]struct{}),
		regCh:   make(chan *Client, 64),
		unregCh: make(chan *Client, 64),
	}
}

func (h *Hub) Run() {
	for {
		select {
		case c := <-h.regCh:
			h.mu.Lock()
			set, ok := h.rooms[c.roomID]
			if !ok {
				set = make(map[*Client]struct{})
				h.rooms[c.roomID] = set
			}
			set[c] = struct{}{}
			h.mu.Unlock()

		case c := <-h.unregCh:
			h.mu.Lock()
			if set, ok := h.rooms[c.roomID]; ok {
				if _, has := set[c]; has {
					delete(set, c)
					close(c.send)
				}
				if len(set) == 0 {
					delete(h.rooms, c.roomID)
				}
			}
			h.mu.Unlock()
		}
	}
}

// Broadcast 给 roomID 的所有连接推一条
func (h *Hub) Broadcast(roomID int64, event string, data any) {
	payload, err := json.Marshal(Envelope{Event: event, Data: data})
	if err != nil {
		log.Println("ws marshal:", err)
		return
	}
	h.mu.RLock()
	defer h.mu.RUnlock()
	set, ok := h.rooms[roomID]
	if !ok {
		return
	}
	for c := range set {
		select {
		case c.send <- payload:
		default:
			// 客户端写阻塞，丢弃此连接
			go func(cc *Client) { h.unregCh <- cc }(c)
		}
	}
}

// Register / Unregister 暴露给 client.go 用
func (h *Hub) register(c *Client)   { h.regCh <- c }
func (h *Hub) unregister(c *Client) { h.unregCh <- c }

// 心跳参数
const (
	writeWait  = 10 * time.Second
	pongWait   = 60 * time.Second
	pingPeriod = (pongWait * 9) / 10
	maxMsgSize = 8 << 10 // 8 KB
)
