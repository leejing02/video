// Package videocache caches raw public metadata, never signed playback URLs or user state.
package videocache

import (
	"context"
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"sync"
	"sync/atomic"
	"time"
)

const TTL = 90 * time.Second
const maxEntries = 128
const maxItemBytes = 512 * 1024
const activeWindow = 10 * time.Minute

type Remote interface {
	Get(context.Context, string) ([]byte, error)
	Set(context.Context, string, []byte, time.Duration) error
}

type record struct {
	Expires time.Time       `json:"expires"`
	Data    json.RawMessage `json:"data"`
}
type entry struct {
	mu       sync.Mutex
	record   atomic.Pointer[record]
	load     func() ([]byte, error)
	lastUsed time.Time // guarded by Cache.mu
}
type Cache struct {
	mu             sync.Mutex
	entries        map[string]*entry
	dir, namespace string
	remote         Remote
	now            func() time.Time
}

func New(dir, namespace string, remote Remote) *Cache {
	if dir != "" {
		if err := os.MkdirAll(dir, 0700); err != nil {
			log.Printf("video cache: local directory unavailable: %v", err)
		}
	}
	return &Cache{entries: make(map[string]*entry), dir: dir, namespace: namespace, remote: remote, now: time.Now}
}

// Load decodes a new copy on every hit so response-time URL rewriting cannot poison the cache.
func Load[T any](c *Cache, key string, loader func() (T, error)) (T, error) {
	if c == nil {
		return loader()
	}
	bytes, err := c.get(key, func() ([]byte, error) {
		value, err := loader()
		if err != nil {
			return nil, err
		}
		return json.Marshal(value)
	})
	var value T
	if err != nil {
		return value, err
	}
	err = json.Unmarshal(bytes, &value)
	return value, err
}

func (c *Cache) key(key string) string {
	return fmt.Sprintf("video-metadata:v1:%x", sha256.Sum256([]byte(c.namespace+":"+key)))
}
func (c *Cache) path(key string) string {
	return filepath.Join(c.dir, key[len("video-metadata:v1:"):]+".json")
}
func valid(r record, now time.Time) bool {
	return now.Before(r.Expires) && len(r.Data) > 0 && json.Valid(r.Data)
}

func (c *Cache) get(key string, loader func() ([]byte, error)) ([]byte, error) {
	key = c.key(key)
	c.mu.Lock()
	e := c.entries[key]
	if e == nil {
		if len(c.entries) >= maxEntries {
			var oldest string
			for k, item := range c.entries {
				if oldest == "" || item.lastUsed.Before(c.entries[oldest].lastUsed) {
					oldest = k
				}
			}
			delete(c.entries, oldest)
			if c.dir != "" {
				_ = os.Remove(c.path(oldest))
			}
		}
		e = &entry{load: loader}
		c.entries[key] = e
	}
	e.lastUsed = c.now()
	c.mu.Unlock()
	// Fresh local hits must never wait for a scheduled database refresh.
	if r := e.record.Load(); r != nil && valid(*r, c.now()) {
		return r.Data, nil
	}
	// Requests for one key share the same cold load; unrelated keys remain independent.
	e.mu.Lock()
	defer e.mu.Unlock()
	if r := e.record.Load(); r != nil && valid(*r, c.now()) {
		return r.Data, nil
	}
	if c.dir != "" {
		if data, err := os.ReadFile(c.path(key)); err == nil {
			var r record
			if json.Unmarshal(data, &r) == nil && valid(r, c.now()) {
				e.record.Store(&r)
				return r.Data, nil
			}
		}
	}
	if c.remote != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
		data, err := c.remote.Get(ctx, key)
		cancel()
		var r record
		if err == nil && json.Unmarshal(data, &r) == nil && valid(r, c.now()) {
			e.record.Store(&r)
			c.writeLocal(key, r)
			return r.Data, nil
		}
	}
	return c.refresh(key, e)
}

func (c *Cache) refresh(key string, e *entry) ([]byte, error) {
	data, err := e.load()
	if err != nil {
		return nil, err
	} // Never extend stale data after a failed refresh.
	if len(data) > maxItemBytes {
		return data, nil
	}
	r := record{Expires: c.now().Add(TTL), Data: data}
	encoded, _ := json.Marshal(r)
	if c.remote != nil {
		ctx, cancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
		err := c.remote.Set(ctx, key, encoded, TTL)
		cancel()
		if err != nil {
			log.Printf("video cache: Redis write failed: %v", err)
		}
	}
	c.writeLocal(key, r)
	e.record.Store(&r)
	return data, nil
}

func (c *Cache) writeLocal(key string, r record) {
	if c.dir == "" {
		return
	}
	data, _ := json.Marshal(r)
	file, err := os.CreateTemp(c.dir, ".video-cache-*")
	if err != nil {
		log.Printf("video cache: local write failed: %v", err)
		return
	}
	defer os.Remove(file.Name())
	_, err = file.Write(data)
	closeErr := file.Close()
	if err == nil {
		err = closeErr
	}
	if err == nil {
		err = os.Rename(file.Name(), c.path(key))
	}
	if err != nil {
		log.Printf("video cache: local commit failed: %v", err)
	}
}

// Refresh rebuilds recently requested queries from MySQL, then writes Redis and local storage.
// It runs independently of HTTP requests; a busy key is left to its in-flight request.
func (c *Cache) Refresh() {
	c.mu.Lock()
	jobs := make(map[string]*entry)
	for key, e := range c.entries {
		if c.now().Sub(e.lastUsed) <= activeWindow {
			jobs[key] = e
		}
	}
	c.mu.Unlock()
	refreshed, failed := 0, 0
	for key, e := range jobs {
		if !e.mu.TryLock() {
			continue
		}
		_, err := c.refresh(key, e)
		e.mu.Unlock()
		if err != nil {
			failed++
			log.Printf("video cache: refresh failed: %v", err)
		} else {
			refreshed++
		}
	}
	// Remove abandoned files left by restarts; Redis has its own TTL.
	if c.dir != "" {
		files, _ := filepath.Glob(filepath.Join(c.dir, "*.json"))
		for _, file := range files {
			if info, err := os.Stat(file); err == nil && c.now().Sub(info.ModTime()) > activeWindow {
				_ = os.Remove(file)
			}
		}
	}
	log.Printf("video cache: refresh complete keys=%d failed=%d", refreshed, failed)
}
func (c *Cache) Run(ctx context.Context) {
	ticker := time.NewTicker(time.Minute)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			c.Refresh()
		}
	}
}
