package models

import (
	"database/sql"
	"time"
)

// === User ===
type User struct {
	ID              int64        `db:"id"               json:"id"`
	Name            string       `db:"name"             json:"name"`
	Username        string       `db:"username"         json:"username"`
	Email           string       `db:"email"            json:"email"`
	EmailVerifiedAt sql.NullTime `db:"email_verified_at" json:"-"`
	Password        string       `db:"password"         json:"-"`
	Avatar          *string      `db:"avatar"           json:"avatar"`
	Phone           *string      `db:"phone"            json:"phone"`
	Bio             *string      `db:"bio"              json:"bio"`
	Role            string       `db:"role"             json:"role"`
	IsActive        bool         `db:"is_active"        json:"is_active"`
	RememberToken   *string      `db:"remember_token"   json:"-"`
	CreatedAt       time.Time    `db:"created_at"       json:"created_at"`
	UpdatedAt       time.Time    `db:"updated_at"       json:"updated_at"`
}

// === Category ===
type Category struct {
	ID        int64     `db:"id"         json:"id"`
	Name      string    `db:"name"       json:"name"`
	Slug      string    `db:"slug"       json:"slug"`
	Kind      string    `db:"kind"       json:"kind"`
	Icon      *string   `db:"icon"       json:"icon"`
	Cover     *string   `db:"cover"      json:"cover"`
	Sort      int       `db:"sort"       json:"sort"`
	IsActive  bool      `db:"is_active"  json:"is_active"`
	CreatedAt time.Time `db:"created_at" json:"created_at"`
	UpdatedAt time.Time `db:"updated_at" json:"updated_at"`
}

// === Video ===
type Video struct {
	ID            int64        `db:"id"             json:"id"`
	UserID        int64        `db:"user_id"        json:"user_id"`
	CategoryID    int64        `db:"category_id"    json:"category_id"`
	Type          string       `db:"type"           json:"type"`
	Title         string       `db:"title"          json:"title"`
	Description   *string      `db:"description"    json:"description"`
	Cover         *string      `db:"cover"          json:"cover"`
	URL           string       `db:"url"            json:"url"`
	Duration      int          `db:"duration"       json:"duration"`
	Views         int64        `db:"views"          json:"views"`
	Likes         int64        `db:"likes"          json:"likes"`
	CommentsCount int64        `db:"comments_count" json:"comments_count"`
	Status        string       `db:"status"         json:"status"`
	PublishedAt   sql.NullTime `db:"published_at"   json:"published_at"`
	CreatedAt     time.Time    `db:"created_at"     json:"created_at"`
	UpdatedAt     time.Time    `db:"updated_at"     json:"updated_at"`
}

// VideoWithRelations Video + 关联的 user / category（拼装用）
type VideoWithRelations struct {
	Video
	User     *PublicUser `json:"user"`
	Category *Category   `json:"category"`
}

// PublicUser 对外暴露的用户子集
type PublicUser struct {
	ID       int64   `db:"id"       json:"id"`
	Name     string  `db:"name"     json:"name"`
	Username string  `db:"username" json:"username"`
	Avatar   *string `db:"avatar"   json:"avatar"`
}

// === Comment ===
type Comment struct {
	ID        int64         `db:"id"         json:"id"`
	UserID    int64         `db:"user_id"    json:"user_id"`
	VideoID   int64         `db:"video_id"   json:"video_id"`
	ParentID  sql.NullInt64 `db:"parent_id"  json:"parent_id"`
	Content   string        `db:"content"    json:"content"`
	Likes     int           `db:"likes"      json:"likes"`
	IsPinned  bool          `db:"is_pinned"  json:"is_pinned"`
	CreatedAt time.Time     `db:"created_at" json:"created_at"`
	UpdatedAt time.Time     `db:"updated_at" json:"updated_at"`
}

type CommentWithUser struct {
	Comment
	User    *PublicUser       `json:"user"`
	Replies []CommentWithUser `json:"replies,omitempty"`
}

// === ChatRoom ===
type ChatRoom struct {
	ID            int64         `db:"id"               json:"id"`
	Name          string        `db:"name"             json:"name"`
	Slug          string        `db:"slug"             json:"slug"`
	Description   *string       `db:"description"      json:"description"`
	Cover         *string       `db:"cover"            json:"cover"`
	Kind          string        `db:"kind"             json:"kind"`
	IsActive      bool          `db:"is_active"        json:"is_active"`
	OwnerID       sql.NullInt64 `db:"owner_id"         json:"owner_id"`
	LastMessageAt sql.NullTime  `db:"last_message_at"  json:"last_message_at"`
	CreatedAt     time.Time     `db:"created_at"       json:"created_at"`
	UpdatedAt     time.Time     `db:"updated_at"       json:"updated_at"`
}

// === ChatMessage ===
type ChatMessage struct {
	ID            int64         `db:"id"             json:"id"`
	ChatRoomID    int64         `db:"chat_room_id"   json:"chat_room_id"`
	UserID        int64         `db:"user_id"        json:"user_id"`
	Type          string        `db:"type"           json:"type"`
	Content       string        `db:"content"        json:"content"`
	ReplyToID     sql.NullInt64 `db:"reply_to_id"    json:"reply_to_id"`
	AttachmentURL *string       `db:"attachment_url" json:"attachment_url"`
	Meta          *string       `db:"meta"           json:"meta"`
	CreatedAt     time.Time     `db:"created_at"     json:"created_at"`
	UpdatedAt     time.Time     `db:"updated_at"     json:"updated_at"`
}

type ChatMessageWithUser struct {
	ChatMessage
	User *PublicUser `json:"user"`
}
