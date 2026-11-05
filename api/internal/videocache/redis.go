package videocache

import (
	"context"
	"time"

	"github.com/redis/go-redis/v9"
)

type Redis struct{ client *redis.Client }

func NewRedis(addr, password string, db int) *Redis {
	return &Redis{client: redis.NewClient(&redis.Options{
		Addr: addr, Password: password, DB: db, MaxRetries: -1,
		DialTimeout: 200 * time.Millisecond, ReadTimeout: 200 * time.Millisecond, WriteTimeout: 200 * time.Millisecond,
		ContextTimeoutEnabled: true,
	})}
}
func (r *Redis) Get(ctx context.Context, key string) ([]byte, error) {
	return r.client.Get(ctx, key).Bytes()
}
func (r *Redis) Set(ctx context.Context, key string, data []byte, ttl time.Duration) error {
	return r.client.Set(ctx, key, data, ttl).Err()
}
func (r *Redis) Close() error { return r.client.Close() }
