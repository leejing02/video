package fish

import (
	"fmt"
	"math"
	"math/rand"
	"sync/atomic"
)

// Logical canvas dimensions (server-side coordinate space).
const (
	Width  = 1600.0
	Height = 900.0
)

var idCounter atomic.Int64

func nextID() string {
	return fmt.Sprintf("f%d", idCounter.Add(1))
}

// Fish is a server-side fish entity.
type Fish struct {
	ID     string
	TypeID int
	X, Y   float64 // current position
	VX, VY float64 // velocity per tick (tick ≈ 33 ms)
}

// Tick advances the fish one game tick.
func (f *Fish) Tick() {
	f.X += f.VX
	f.Y += f.VY
}

// OutOfBounds returns true when the fish has left the extended canvas.
func (f *Fish) OutOfBounds() bool {
	return f.X < -150 || f.X > Width+150 || f.Y < -150 || f.Y > Height+150
}

// Angle returns the heading in radians (for client sprite rotation).
func (f *Fish) Angle() float64 {
	return math.Atan2(f.VY, f.VX)
}

// Spawn creates a new fish entering from a random screen edge.
func Spawn() *Fish {
	typeID := weightedTypeID()
	speed := 1.5 + rand.Float64()*2.0 // pixels per tick

	var x, y, vx, vy float64
	switch rand.Intn(4) {
	case 0: // enter from left
		x, y = -50, rand.Float64()*Height
		vx, vy = speed, jitter(speed)
	case 1: // enter from right
		x, y = Width+50, rand.Float64()*Height
		vx, vy = -speed, jitter(speed)
	case 2: // enter from top
		x, y = rand.Float64()*Width, -50
		vx, vy = jitter(speed), speed
	default: // enter from bottom
		x, y = rand.Float64()*Width, Height+50
		vx, vy = jitter(speed), -speed
	}

	return &Fish{ID: nextID(), TypeID: typeID, X: x, Y: y, VX: vx, VY: vy}
}

// jitter returns a small lateral drift relative to speed.
func jitter(speed float64) float64 {
	return (rand.Float64() - 0.5) * speed * 0.4
}

// weightedTypeID picks a fish type; small fish appear more often.
func weightedTypeID() int {
	r := rand.Float64()
	switch {
	case r < 0.25:
		return 1
	case r < 0.45:
		return 2
	case r < 0.60:
		return 3
	case r < 0.72:
		return 4
	case r < 0.82:
		return 5
	case r < 0.88:
		return 6
	case r < 0.92:
		return 7
	case r < 0.95:
		return 8
	case r < 0.97:
		return 9
	case r < 0.985:
		return 10
	case r < 0.995:
		return 11
	default:
		return 12
	}
}
