package room

import "sync"

// Manager owns all active rooms. A single default room is always running.
type Manager struct {
	mu    sync.RWMutex
	rooms map[string]*Room
}

func NewManager() *Manager {
	m := &Manager{rooms: make(map[string]*Room)}
	m.create("default")
	return m
}

func (m *Manager) create(id string) *Room {
	r := newRoom(id)
	m.rooms[id] = r
	go r.Run()
	return r
}

// Get returns the room with the given id, if it exists.
func (m *Manager) Get(id string) (*Room, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	r, ok := m.rooms[id]
	return r, ok
}

// Default returns the always-running default room.
func (m *Manager) Default() *Room {
	r, _ := m.Get("default")
	return r
}
