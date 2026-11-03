package repos

import (
	"errors"
	"fmt"
	"github.com/yourname/video-api/internal/models"
)

var ErrInvalidPeer = errors.New("cannot start this conversation")

type Conversation struct {
	models.ChatRoom
	LastMessage string `db:"last_message" json:"last_message"`
	UnreadCount int    `db:"unread_count" json:"unread_count"`
	MemberCount int    `db:"member_count" json:"member_count"`
}

func (r *ChatRepo) Conversations(uid int64) ([]Conversation, error) {
	out := []Conversation{}
	err := r.DB.Select(&out, `SELECT r.id,
 CASE WHEN r.kind='direct' THEN COALESCE((SELECT u.name FROM chat_room_user p JOIN users u ON u.id=p.user_id WHERE p.chat_room_id=r.id AND p.user_id<>? LIMIT 1), r.name) ELSE r.name END AS name,
 r.slug,r.description,r.cover,r.kind,r.is_active,r.owner_id,r.last_message_at,r.created_at,r.updated_at,
 COALESCE((SELECT LEFT(m.content,120) FROM chat_messages m WHERE m.chat_room_id=r.id ORDER BY m.id DESC LIMIT 1),'') AS last_message,
 (SELECT COUNT(*) FROM chat_messages m WHERE m.chat_room_id=r.id AND m.user_id<>? AND m.created_at>COALESCE(pu.last_read_at,'1970-01-01')) AS unread_count,
 (SELECT COUNT(*) FROM chat_room_user p WHERE p.chat_room_id=r.id) AS member_count
 FROM chat_rooms r LEFT JOIN chat_room_user pu ON pu.chat_room_id=r.id AND pu.user_id=?
 WHERE r.is_active=1 AND (r.kind IN ('global','group') OR pu.user_id IS NOT NULL)
 ORDER BY r.last_message_at DESC,r.id DESC`, uid, uid, uid)
	return out, err
}

func directSlug(a, b int64) string {
	if a > b {
		a, b = b, a
	}
	return fmt.Sprintf("dm-%d-%d", a, b)
}

// Direct creates both memberships atomically; the unique slug serializes concurrent requests.
func (r *ChatRepo) Direct(uid, peer, contextRoom int64) (*models.ChatRoom, error) {
	if uid <= 0 || peer <= 0 || uid == peer {
		return nil, ErrInvalidPeer
	}
	var allowed int
	err := r.DB.Get(&allowed, `SELECT COUNT(*) FROM users u JOIN chat_rooms r ON r.id=? AND r.is_active=1 AND r.kind IN ('global','group')
 WHERE u.id=? AND u.is_active=1 AND (EXISTS(SELECT 1 FROM chat_room_user p WHERE p.chat_room_id=r.id AND p.user_id=u.id)
 OR EXISTS(SELECT 1 FROM chat_messages m WHERE m.chat_room_id=r.id AND m.user_id=u.id))`, contextRoom, peer)
	if err != nil {
		return nil, err
	}
	if allowed == 0 {
		return nil, ErrInvalidPeer
	}
	tx, err := r.DB.Beginx()
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	result, err := tx.Exec(`INSERT INTO chat_rooms (name,slug,kind,is_active,owner_id,created_at,updated_at) VALUES ('私聊',?,'direct',1,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)`, directSlug(uid, peer), uid)
	if err != nil {
		return nil, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return nil, err
	}
	var room models.ChatRoom
	if err = tx.Get(&room, `SELECT `+chatRoomCols+` FROM chat_rooms WHERE id=?`, id); err != nil {
		return nil, err
	}
	if room.Kind != "direct" || !room.IsActive {
		return nil, ErrInvalidPeer
	}
	_, err = tx.Exec(`INSERT INTO chat_room_user (chat_room_id,user_id,role,muted,joined_at,created_at,updated_at)
 VALUES (?,?,'member',0,NOW(),NOW(),NOW()),(?,?,'member',0,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id)`, id, uid, id, peer)
	if err != nil {
		return nil, err
	}
	if err = tx.Commit(); err != nil {
		return nil, err
	}
	return &room, nil
}

func (r *ChatRepo) Recipients(roomID int64) ([]int64, error) {
	ids := []int64{}
	err := r.DB.Select(&ids, `SELECT user_id FROM chat_room_user WHERE chat_room_id=?`, roomID)
	return ids, err
}

// MessagesAfter returns ascending IDs so paged reconnect recovery never skips a gap.
func (r *ChatRepo) MessagesAfter(roomID, afterID int64, limit int) ([]models.ChatMessageWithUser, error) {
	return r.messages(roomID, 0, afterID, limit, true)
}
