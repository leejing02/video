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

// Fill reads or populates a key without overwriting a concurrent invalidation.
func (r *Redis) Fill(ctx context.Context, key string, ttl time.Duration, load func() ([]byte, error)) ([]byte, error) {
	var data []byte
	err := r.client.Watch(ctx, func(tx *redis.Tx) error {
		cached, err := tx.Get(ctx, key).Bytes()
		if err != nil && err != redis.Nil {
			return err
		}
		if len(cached) > 0 {
			data = cached
			return nil
		}
		data, err = load()
		if err != nil {
			return err
		}
		_, err = tx.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
			pipe.Set(ctx, key, data, ttl)
			return nil
		})
		return err
	}, key)
	return data, err
}

func (r *Redis) Close() error { return r.client.Close() }
