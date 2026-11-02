// cmd/gameserver — standalone WebSocket fishing game server
//
// Clients connect via:
//
//	ws://host:PORT/ws?token=JWT
//
// On connect the server sends ENTER_ROOM (1003), then broadcasts
// FISH_SYNC (1004) every tick and PLAYER_SYNC (1005) every ~3 s.
// Clients send FIRE (1001) packets; server replies with HIT (1002).
package main

import (
	"encoding/json"
	"log"
	"net/http"
	"time"

	"github.com/gorilla/websocket"
	"github.com/yourname/video-api/internal/auth"
	"github.com/yourname/video-api/internal/config"
	"github.com/yourname/video-api/internal/fishing/protocol"
	"github.com/yourname/video-api/internal/fishing/room"
)

const (
	writeWait  = 10 * time.Second
	pongWait   = 60 * time.Second
	pingPeriod = (pongWait * 9) / 10
	maxMsgSize = 4 << 10 // 4 KB
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 4096,
	CheckOrigin:     func(r *http.Request) bool { return true },
}

func main() {
	cfg := config.Load()

	mgr := room.NewManager()

	http.HandleFunc("/ws", func(w http.ResponseWriter, r *http.Request) {
		handleWS(w, r, mgr, cfg.JWTSecret)
	})
	http.HandleFunc("/up", func(w http.ResponseWriter, _ *http.Request) {
		w.Write([]byte("ok"))
	})

	addr := ":" + cfg.GamePort()
	log.Printf("gameserver listening on %s", addr)
	if err := http.ListenAndServe(addr, nil); err != nil {
		log.Fatal(err)
	}
}

func handleWS(w http.ResponseWriter, r *http.Request, mgr *room.Manager, jwtSecret string) {
	// 1. Authenticate via query token (WS handshake can't carry Authorization header easily)
	token := r.URL.Query().Get("token")
	claims, err := auth.Parse(jwtSecret, token)
	if err != nil {
		http.Error(w, "unauthorized", http.StatusUnauthorized)
		return
	}
	playerID := claims.UserID

	// 2. Upgrade
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Println("upgrade:", err)
		return
	}
	defer conn.Close()

	// 3. Join the default room
	rm := mgr.Default()
	player := rm.Join(playerID)
	defer rm.Leave(playerID)

	// 4. Send ENTER_ROOM snapshot
	fishStates, playerStates := rm.Snapshot()
	enterPkt, _ := protocol.Encode(protocol.MsgEnterRoom, protocol.EnterRoomData{
		RoomID:  rm.ID,
		SelfID:  playerID,
		Balance: player.Balance,
		Fish:    fishStates,
		Players: playerStates,
	})
	conn.SetWriteDeadline(time.Now().Add(writeWait))
	if err := conn.WriteMessage(websocket.TextMessage, enterPkt); err != nil {
		return
	}

	// 5. writePump — drains player.Send → WS
	go func() {
		ticker := time.NewTicker(pingPeriod)
		defer ticker.Stop()
		for {
			select {
			case msg, ok := <-player.Send:
				conn.SetWriteDeadline(time.Now().Add(writeWait))
				if !ok {
					conn.WriteMessage(websocket.CloseMessage, []byte{})
					return
				}
				if err := conn.WriteMessage(websocket.TextMessage, msg); err != nil {
					return
				}
			case <-ticker.C:
				conn.SetWriteDeadline(time.Now().Add(writeWait))
				if err := conn.WriteMessage(websocket.PingMessage, nil); err != nil {
					return
				}
			}
		}
	}()

	// 6. readPump — WS → room.Fire
	conn.SetReadLimit(maxMsgSize)
	conn.SetReadDeadline(time.Now().Add(pongWait))
	conn.SetPongHandler(func(string) error {
		return conn.SetReadDeadline(time.Now().Add(pongWait))
	})

	for {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return
		}
		var pkt protocol.Packet
		if err := json.Unmarshal(raw, &pkt); err != nil {
			continue
		}
		switch pkt.MsgID {
		case protocol.MsgPing:
			rm.Heartbeat(playerID) // reset stale timer
			// Reply via player.Send so writePump owns the write (no concurrent writes)
			if pong, err := protocol.Encode(protocol.MsgPong, nil); err == nil {
				select {
				case player.Send <- pong:
				default:
				}
			}
		case protocol.MsgFire:
			var fire protocol.FireData
			if err := json.Unmarshal(pkt.Data, &fire); err != nil {
				continue
			}
			rm.Fire(room.FireEvent{
				PlayerID:       playerID,
				BulletLevel:    fire.BulletLevel,
				FishID:         fire.FishID,
				FishTypeID:     fire.FishTypeID,
				AccuracyFactor: fire.AccuracyFactor,
			})
		}
	}
}
