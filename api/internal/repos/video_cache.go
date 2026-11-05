package repos

import (
	"encoding/json"

	"github.com/yourname/video-api/internal/videocache"
)

type cachedPage[T any] struct {
	Data  []T
	Total int64
}

func cacheKey(kind string, filter any) string {
	data, _ := json.Marshal(filter)
	return kind + ":" + string(data)
}
func loadVideoPage[T any](cache *videocache.Cache, key string, load func() ([]T, int64, error)) ([]T, int64, error) {
	page, err := videocache.Load(cache, key, func() (cachedPage[T], error) {
		data, total, err := load()
		return cachedPage[T]{Data: data, Total: total}, err
	})
	return page.Data, page.Total, err
}
