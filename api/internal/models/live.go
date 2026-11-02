package models

import "time"

type LiveCategory struct {
	ID       int64   `db:"id"       json:"id"`
	Name     string  `db:"name"     json:"name"`
	Slug     string  `db:"slug"     json:"slug"`
	Icon     *string `db:"icon"     json:"icon"`
	Cover    *string `db:"cover"    json:"cover"`
	Sort     int     `db:"sort"     json:"sort"`
	IsActive bool    `db:"is_active" json:"is_active"`
}

type LiveStream struct {
	ID         int64     `db:"id"          json:"id"`
	CategoryID int64     `db:"category_id" json:"category_id"`
	Platform   string    `db:"platform"    json:"platform"`
	Title      string    `db:"title"       json:"title"`
	Cover      *string   `db:"cover"       json:"cover"`
	StreamURL  string    `db:"stream_url"  json:"stream_url"`
	Viewers    int64     `db:"viewers"     json:"viewers"`
	Status     string    `db:"status"      json:"status"`
	CreatedAt  time.Time `db:"created_at"  json:"created_at"`
	UpdatedAt  time.Time `db:"updated_at"  json:"updated_at"`
}

type LiveStreamWithCategory struct {
	LiveStream
	Category *LiveCategory `json:"category"`
}
