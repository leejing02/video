package repos

import (
	"database/sql"
	"errors"

	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type ChatRepo struct{ DB *sqlx.DB }

func NewChatRepo(db *sqlx.DB) *ChatRepo { return &ChatRepo{DB: db} }

// GlobalRoom 拿全局聊天室
func (r *ChatRepo) GlobalRoom() (*models.ChatRoom, error) {
	var room models.ChatRoom
	err := r.DB.Get(&room, `SELECT * FROM chat_rooms WHERE kind = 'global' AND is_active = 1 LIMIT 1`)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &room, err
}

func (r *ChatRepo) RoomByID(id int64) (*models.ChatRoom, error) {
	var room models.ChatRoom
	err := r.DB.Get(&room, `SELECT * FROM chat_rooms WHERE id = ?`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &room, err
}

// UserRooms 当前用户能进的所有房间
func (r *ChatRepo) UserRooms(userID int64) ([]models.ChatRoom, error) {
	out := []models.ChatRoom{}
	err := r.DB.Select(&out, `
		SELECT DISTINCT r.* FROM chat_rooms r
		LEFT JOIN chat_room_user pu ON pu.chat_room_id = r.id AND pu.user_id = ?
		WHERE r.is_active = 1 AND (r.kind = 'global' OR pu.user_id = ?)
		ORDER BY r.last_message_at DESC, r.id DESC
	`, userID, userID)
	return out, err
}

func (r *ChatRepo) IsMember(roomID, userID int64) (bool, error) {
	var n int
	err := r.DB.Get(&n, `SELECT COUNT(*) FROM chat_room_user WHERE chat_room_id = ? AND user_id = ?`, roomID, userID)
	return n > 0, err
}

func (r *ChatRepo) Join(roomID, userID int64) error {
	_, err := r.DB.Exec(`
		INSERT INTO chat_room_user (chat_room_id, user_id, role, muted, joined_at, created_at, updated_at)
		VALUES (?, ?, 'member', 0, NOW(), NOW(), NOW())
		ON DUPLICATE KEY UPDATE joined_at = VALUES(joined_at), updated_at = NOW()
	`, roomID, userID)
	return err
}

func (r *ChatRepo) MarkRead(roomID, userID int64) error {
	_, err := r.DB.Exec(`
		INSERT INTO chat_room_user (chat_room_id, user_id, role, muted, last_read_at, created_at, updated_at)
		VALUES (?, ?, 'member', 0, NOW(), NOW(), NOW())
		ON DUPLICATE KEY UPDATE last_read_at = NOW(), updated_at = NOW()
	`, roomID, userID)
	return err
}

// Messages 历史消息（游标 before_id）
func (r *ChatRepo) Messages(roomID int64, beforeID int64, limit int) ([]models.ChatMessageWithUser, error) {
	if limit <= 0 || limit > 100 {
		limit = 30
	}
	type row struct {
		models.ChatMessage
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`
	}
	rows := []row{}
	q := `
		SELECT m.*, u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
		FROM chat_messages m JOIN users u ON u.id = m.user_id
		WHERE m.chat_room_id = ?`
	args := []any{roomID}
	if beforeID > 0 {
		q += " AND m.id < ?"
		args = append(args, beforeID)
	}
	q += " ORDER BY m.id DESC LIMIT ?"
	args = append(args, limit)
	if err := r.DB.Select(&rows, q, args...); err != nil {
		return nil, err
	}

	out := make([]models.ChatMessageWithUser, 0, len(rows))
	// 倒序变正序，更符合时间线
	for i := len(rows) - 1; i >= 0; i-- {
		rr := rows[i]
		out = append(out, models.ChatMessageWithUser{
			ChatMessage: rr.ChatMessage,
			User: &models.PublicUser{
				ID: rr.UID, Name: rr.UNm, Username: rr.USnm, Avatar: rr.UAv,
			},
		})
	}
	return out, nil
}

func (r *ChatRepo) CreateMessage(m *models.ChatMessage) error {
	tx, err := r.DB.Beginx()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	res, err := tx.NamedExec(`
		INSERT INTO chat_messages
			(chat_room_id, user_id, type, content, reply_to_id, attachment_url, meta, created_at, updated_at)
		VALUES
			(:chat_room_id, :user_id, :type, :content, :reply_to_id, :attachment_url, :meta, NOW(), NOW())
	`, m)
	if err != nil {
		return err
	}
	id, _ := res.LastInsertId()
	m.ID = id

	if _, err := tx.Exec(`UPDATE chat_rooms SET last_message_at = NOW() WHERE id = ?`, m.ChatRoomID); err != nil {
		return err
	}
	return tx.Commit()
}

// LoadMessageWithUser 拼装单条消息（广播用）
func (r *ChatRepo) LoadMessageWithUser(id int64) (*models.ChatMessageWithUser, error) {
	row := struct {
		models.ChatMessage
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`
	}{}
	err := r.DB.Get(&row, `
		SELECT m.*, u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
		FROM chat_messages m JOIN users u ON u.id = m.user_id
		WHERE m.id = ?`, id)
	if err != nil {
		return nil, err
	}
	return &models.ChatMessageWithUser{
		ChatMessage: row.ChatMessage,
		User: &models.PublicUser{
			ID: row.UID, Name: row.UNm, Username: row.USnm, Avatar: row.UAv,
		},
	}, nil
}
