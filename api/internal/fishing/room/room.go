package room

import (
	"log"
	"sync"
	"time"

	"github.com/yourname/video-api/internal/fishing/fish"
	"github.com/yourname/video-api/internal/fishing/protocol"
	"github.com/yourname/video-api/internal/fishing/rtp"
)

const (
	tickInterval    = 33 * time.Millisecond // ~30 fps
	maxFish         = 20
	spawnEvery      = 5   // ticks between spawns
	playerSyncEvery = 90  // ticks (~3 s)
	staleCheckEvery = 300 // ticks (~10 s)
	staleTimeout    = 90 * time.Second
	startBalance    = 1000
)

// Player holds server-side state for one connected player.
type Player struct {
	ID            int64
	Balance       int
	Send          chan []byte // outbound messages; closed on eviction/leave
	LastHeartbeat time.Time   // updated on every PING; stale if too old
}

// FireEvent is an inbound FIRE command from a player.
// Set FishID for Phase 2 (server-synced fish).
// Set FishTypeID for Phase 1 (client-rendered fish, server does economy only).
type FireEvent struct {
	PlayerID       int64
	BulletLevel    int
	FishID         string
	FishTypeID     int
	AccuracyFactor float64
}

// Room is a single game room running its own tick loop.
type Room struct {
	ID string

	mu      sync.RWMutex
	fish    map[string]*fish.Fish
	players map[int64]*Player

	incoming chan FireEvent
	done     chan struct{}
	tick     int64
}

func newRoom(id string) *Room {
	return &Room{
		ID:       id,
		fish:     make(map[string]*fish.Fish),
		players:  make(map[int64]*Player),
		incoming: make(chan FireEvent, 256),
		done:     make(chan struct{}),
	}
}

// Join adds a player and returns their Player handle.
// Calling Join for an already-joined player returns the existing handle.
func (r *Room) Join(playerID int64) *Player {
	r.mu.Lock()
	defer r.mu.Unlock()
	if p, ok := r.players[playerID]; ok {
		return p
	}
	p := &Player{
		ID:            playerID,
		Balance:       startBalance,
		Send:          make(chan []byte, 64),
		LastHeartbeat: time.Now(),
	}
	r.players[playerID] = p
	return p
}

// Heartbeat records that the player is alive. Called on every PING received.
func (r *Room) Heartbeat(playerID int64) {
	r.mu.Lock()
	if p, ok := r.players[playerID]; ok {
		p.LastHeartbeat = time.Now()
	}
	r.mu.Unlock()
}

// Leave removes the player and closes their send channel.
func (r *Room) Leave(playerID int64) {
	r.mu.Lock()
	defer r.mu.Unlock()
	if p, ok := r.players[playerID]; ok {
		r.evict(p)
	}
}

// evict tears down a player slot. Caller must hold r.mu.
func (r *Room) evict(p *Player) {
	close(p.Send)
	delete(r.players, p.ID)
}

// Fire enqueues a FIRE command from a player.
func (r *Room) Fire(ev FireEvent) {
	select {
	case r.incoming <- ev:
	default:
		// drop under extreme load
	}
}

// Snapshot returns current fish and player states for ENTER_ROOM.
func (r *Room) Snapshot() ([]protocol.FishState, []protocol.PlayerState) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	fs := make([]protocol.FishState, 0, len(r.fish))
	for _, f := range r.fish {
		fs = append(fs, toFishState(f))
	}
	ps := make([]protocol.PlayerState, 0, len(r.players))
	for _, p := range r.players {
		ps = append(ps, protocol.PlayerState{ID: p.ID, Balance: p.Balance})
	}
	return fs, ps
}

// Run is the room's tick goroutine; call in a dedicated goroutine.
func (r *Room) Run() {
	ticker := time.NewTicker(tickInterval)
	defer ticker.Stop()
	for {
		select {
		case <-r.done:
			return
		case ev := <-r.incoming:
			r.processFire(ev)
		case <-ticker.C:
			r.tick++
			r.advanceFish()
			r.broadcastFishSync()
			if r.tick%playerSyncEvery == 0 {
				r.broadcastPlayerSync()
			}
			if r.tick%staleCheckEvery == 0 {
				r.evictStale()
			}
		}
	}
}

func (r *Room) Close() { close(r.done) }

// evictStale closes connections for players whose last heartbeat is too old.
// Closing p.Send causes the writePump in gameserver to send a CloseMessage
// and return, which in turn causes conn.ReadMessage() in the readPump to fail,
// cleanly unwinding the entire handleWS goroutine chain.
func (r *Room) evictStale() {
	r.mu.Lock()
	defer r.mu.Unlock()
	now := time.Now()
	for _, p := range r.players {
		if now.Sub(p.LastHeartbeat) > staleTimeout {
			log.Printf("room %s: evicting stale player %d (silent for %.0fs)",
				r.ID, p.ID, now.Sub(p.LastHeartbeat).Seconds())
			r.evict(p)
		}
	}
}

// --- internal ---

func (r *Room) processFire(ev FireEvent) {
	r.mu.Lock()
	defer r.mu.Unlock()

	p, ok := r.players[ev.PlayerID]
	if !ok {
		return
	}
	cost := rtp.BulletCosts[ev.BulletLevel]
	if cost == 0 || p.Balance < cost {
		return
	}
	p.Balance -= cost

	// Phase 1 hybrid: client sends fish_type_id, no server fish tracking needed
	if ev.FishID == "" && ev.FishTypeID > 0 {
		ft, ok := fish.Types[ev.FishTypeID]
		if !ok {
			return
		}
		hit := rtp.CheckHit(float64(ev.BulletLevel), float64(ft.Coin), ev.AccuracyFactor)
		reward := 0
		if hit {
			reward = ft.Coin
			p.Balance += reward
		}
		r.unicast(p, protocol.MsgHit, protocol.HitData{
			PlayerID: ev.PlayerID,
			TypeID:   ev.FishTypeID,
			Reward:   reward,
			Balance:  p.Balance,
		})
		return
	}

	// Phase 2: server-tracked fish
	f, ok := r.fish[ev.FishID]
	if !ok {
		// fish already gone — bullet wasted
		r.unicast(p, protocol.MsgHit, protocol.HitData{
			PlayerID: ev.PlayerID,
			FishID:   ev.FishID,
			Reward:   0,
			Balance:  p.Balance,
		})
		return
	}

	ft := fish.Types[f.TypeID]
	hit := rtp.CheckHit(float64(ev.BulletLevel), float64(ft.Coin), ev.AccuracyFactor)
	reward := 0
	if hit {
		reward = ft.Coin
		p.Balance += reward
		delete(r.fish, ev.FishID)
	}

	r.unicast(p, protocol.MsgHit, protocol.HitData{
		PlayerID: ev.PlayerID,
		FishID:   ev.FishID,
		TypeID:   f.TypeID,
		Reward:   reward,
		Balance:  p.Balance,
	})
}

func (r *Room) advanceFish() {
	r.mu.Lock()
	defer r.mu.Unlock()
	for id, f := range r.fish {
		f.Tick()
		if f.OutOfBounds() {
			delete(r.fish, id)
		}
	}
	if len(r.fish) < maxFish && r.tick%spawnEvery == 0 {
		f := fish.Spawn()
		r.fish[f.ID] = f
	}
}

func (r *Room) broadcastFishSync() {
	r.mu.RLock()
	fs := make([]protocol.FishState, 0, len(r.fish))
	for _, f := range r.fish {
		fs = append(fs, toFishState(f))
	}
	r.mu.RUnlock()

	r.broadcast(protocol.MsgFishSync, protocol.FishSyncData{Tick: r.tick, Fish: fs})
}

func (r *Room) broadcastPlayerSync() {
	r.mu.RLock()
	ps := make([]protocol.PlayerState, 0, len(r.players))
	for _, p := range r.players {
		ps = append(ps, protocol.PlayerState{ID: p.ID, Balance: p.Balance})
	}
	r.mu.RUnlock()

	r.broadcast(protocol.MsgPlayerSync, protocol.PlayerSyncData{Players: ps})
}

func (r *Room) broadcast(msgID int, data any) {
	raw, err := protocol.Encode(msgID, data)
	if err != nil {
		log.Println("room broadcast encode:", err)
		return
	}
	r.mu.RLock()
	defer r.mu.RUnlock()
	for _, p := range r.players {
		select {
		case p.Send <- raw:
		default:
		}
	}
}

func (r *Room) unicast(p *Player, msgID int, data any) {
	raw, err := protocol.Encode(msgID, data)
	if err != nil {
		return
	}
	select {
	case p.Send <- raw:
	default:
	}
}

func toFishState(f *fish.Fish) protocol.FishState {
	return protocol.FishState{
		ID:     f.ID,
		TypeID: f.TypeID,
		X:      f.X,
		Y:      f.Y,
		Angle:  f.Angle(),
	}
}
