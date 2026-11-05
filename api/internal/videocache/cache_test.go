package videocache

import (
	"context"
	"errors"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type remoteFake struct {
	data       []byte
	gets, sets int
	fail       bool
}

func (r *remoteFake) Get(context.Context, string) ([]byte, error) {
	r.gets++
	if r.fail {
		return nil, errors.New("offline")
	}
	return r.data, nil
}
func (r *remoteFake) Set(_ context.Context, _ string, b []byte, _ time.Duration) error {
	r.sets++
	if r.fail {
		return errors.New("offline")
	}
	r.data = append([]byte(nil), b...)
	return nil
}

func TestTiersAndCopies(t *testing.T) {
	remote := &remoteFake{}
	dir := t.TempDir()
	c := New(dir, "test", remote)
	calls := 0
	loader := func() (map[string]string, error) { calls++; return map[string]string{"url": "raw"}, nil }
	v, err := Load(c, "video", loader)
	if err != nil {
		t.Fatal(err)
	}
	v["url"] = "signed"
	v, _ = Load(c, "video", loader)
	if v["url"] != "raw" || calls != 1 || remote.gets != 1 || remote.sets != 1 {
		t.Fatalf("unexpected tiers/copy: %v %d %+v", v, calls, remote)
	}
	// A fresh process uses the persisted local copy before consulting Redis.
	_, _ = Load(New(dir, "test", remote), "video", loader)
	if remote.gets != 1 || calls != 1 {
		t.Fatal("disk cache did not precede Redis")
	}
	_, _ = Load(New(t.TempDir(), "test", remote), "video", loader)
	if remote.gets != 2 || calls != 1 {
		t.Fatal("Redis miss did not populate local cache")
	}
}
func TestRefreshAndExpiry(t *testing.T) {
	c := New(t.TempDir(), "test", nil)
	now := time.Now()
	c.now = func() time.Time { return now }
	value := "published"
	fail := false
	calls := 0
	loader := func() (string, error) {
		calls++
		if fail {
			return "", errors.New("database down")
		}
		return value, nil
	}
	_, _ = Load(c, "detail", loader)
	value = "archived"
	c.Refresh()
	got, _ := Load(c, "detail", loader)
	if got != "archived" || calls != 2 {
		t.Fatal("scheduled refresh did not update local data")
	}
	now = now.Add(TTL + time.Second)
	_, err := Load(c, "detail", func() (string, error) { return "", errors.New("database down") })
	// Entries retain the original registered loader, so expiry must query that loader.
	if err != nil || calls != 3 {
		t.Fatal("expired cache not reloaded")
	}
	fail = true
	now = now.Add(TTL + time.Second)
	c.Refresh()
	_, err = Load(c, "detail", loader)
	if err == nil {
		t.Fatal("failed refresh extended stale metadata")
	}

}
func TestConcurrentColdLoadAndOutage(t *testing.T) {
	c := New(t.TempDir(), "test", &remoteFake{fail: true})
	var calls atomic.Int32
	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			v, err := Load(c, "same", func() (int, error) { calls.Add(1); return 42, nil })
			if err != nil || v != 42 {
				t.Errorf("%d %v", v, err)
			}
		}()
	}
	wg.Wait()
	if calls.Load() != 1 {
		t.Fatalf("cold load queried database %d times", calls.Load())
	}
}

func TestFreshHitsDoNotWaitForBackgroundRefresh(t *testing.T) {
	c := New(t.TempDir(), "test", nil)
	var slow atomic.Bool
	started, release := make(chan struct{}), make(chan struct{})
	loader := func() (int, error) {
		if slow.Load() {
			close(started)
			<-release
		}
		return 7, nil
	}
	_, _ = Load(c, "key", loader)
	slow.Store(true)
	done := make(chan struct{})
	go func() { c.Refresh(); close(done) }()
	<-started
	got := make(chan int, 1)
	go func() { v, _ := Load(c, "key", loader); got <- v }()
	select {
	case v := <-got:
		if v != 7 {
			t.Fatal(v)
		}
	case <-time.After(time.Second):
		close(release)
		t.Fatal("fresh local hit blocked on scheduled SQL")
	}
	close(release)
	<-done
}
