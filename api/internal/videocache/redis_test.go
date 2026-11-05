package videocache

import (
	"context"
	"os"
	"testing"
	"time"
)

// Opt-in protocol test against an isolated local Redis, never the production cache.
func TestRedisIntegration(t *testing.T) {
	addr := os.Getenv("VIDEO_CACHE_TEST_REDIS_ADDR")
	if addr == "" {
		t.Skip("set VIDEO_CACHE_TEST_REDIS_ADDR for a real Redis round trip")
	}
	remote := NewRedis(addr, "", 15)
	defer remote.Close()
	c := New(t.TempDir(), t.Name()+time.Now().String(), remote)
	calls := 0
	loader := func() (string, error) { calls++; return "original metadata", nil }
	_, err := Load(c, "detail", loader)
	if err != nil {
		t.Fatal(err)
	}
	// Verify Redis contains the serialized value and its TTL, not just a local hit.
	key := c.key("detail")
	ttl, err := remote.client.TTL(context.Background(), key).Result()
	if err != nil || ttl <= 0 || ttl > TTL {
		t.Fatalf("TTL %v: %v", ttl, err)
	}
	defer remote.client.Del(context.Background(), key)
	next := New(t.TempDir(), c.namespace, remote)
	value, err := Load(next, "detail", loader)
	if err != nil || value != "original metadata" || calls != 1 {
		t.Fatalf("Redis fallback failed: %q %d %v", value, calls, err)
	}
}
