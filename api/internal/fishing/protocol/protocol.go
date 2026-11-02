package protocol

import "encoding/json"

// Wire message IDs
const (
	MsgFire       = 1001
	MsgHit        = 1002
	MsgEnterRoom  = 1003
	MsgFishSync   = 1004
	MsgPlayerSync = 1005
	MsgError      = 9000
	MsgPing       = 9001
	MsgPong       = 9002
)

// Packet is the universal wire envelope.
type Packet struct {
	MsgID int             `json:"msg_id"`
	Data  json.RawMessage `json:"data"`
}

// Encode marshals a Packet with typed data into bytes.
func Encode(msgID int, data any) ([]byte, error) {
	raw, err := json.Marshal(data)
	if err != nil {
		return nil, err
	}
	return json.Marshal(Packet{MsgID: msgID, Data: raw})
}

// --- client → server ---

// FireData is the payload for MsgFire.
// Phase 1 (hybrid): client sends FishTypeID; FishID is empty.
// Phase 2 (full server-sync): client sends FishID from FISH_SYNC.
type FireData struct {
	BulletLevel    int     `json:"bullet_level"`
	FishID         string  `json:"fish_id,omitempty"`
	FishTypeID     int     `json:"fish_type_id,omitempty"`
	AccuracyFactor float64 `json:"accuracy_factor,omitempty"`
}

// --- server → client ---

// HitData is the payload for MsgHit (unicast to the shooter).
type HitData struct {
	PlayerID int64  `json:"player_id"`
	FishID   string `json:"fish_id"`
	TypeID   int    `json:"type_id"`
	Reward   int    `json:"reward"`
	Balance  int    `json:"balance"`
}

// EnterRoomData is sent once on join (MsgEnterRoom).
type EnterRoomData struct {
	RoomID  string        `json:"room_id"`
	SelfID  int64         `json:"self_id"`
	Balance int           `json:"balance"`
	Fish    []FishState   `json:"fish"`
	Players []PlayerState `json:"players"`
}

// FishState is a snapshot of one fish's position.
type FishState struct {
	ID     string  `json:"id"`
	TypeID int     `json:"type_id"`
	X      float64 `json:"x"`
	Y      float64 `json:"y"`
	Angle  float64 `json:"angle"` // radians, for client rendering
}

// FishSyncData is broadcast every tick (MsgFishSync).
type FishSyncData struct {
	Tick int64       `json:"tick"`
	Fish []FishState `json:"fish"`
}

// PlayerState is one player's public info.
type PlayerState struct {
	ID      int64 `json:"id"`
	Balance int   `json:"balance"`
}

// PlayerSyncData is broadcast periodically (MsgPlayerSync).
type PlayerSyncData struct {
	Players []PlayerState `json:"players"`
}

// ErrorData is sent to the client on bad input.
type ErrorData struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}
