package repos

import (
	"database/sql"
	"errors"
	"strings"

	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type ShortVideoRepo struct{ DB *sqlx.DB }

func NewShortVideoRepo(db *sqlx.DB) *ShortVideoRepo { return &ShortVideoRepo{DB: db} }

type ListShortVideoFilter struct {
	CategoryID int64
	UserID     int64
	Keyword    string
	Limit      int
	Offset     int
	IncludeAll bool
}

func (r *ShortVideoRepo) List(f ListShortVideoFilter) ([]models.ShortVideoWithRelations, int64, error) {
	// 	conds := []string{}
	conds := []string{"v.type = 'short'"}

	if !f.IncludeAll {
		conds = append(conds, "v.status = 'published'", "v.audit_status = 'approved'")
	}
	args := []any{}
	if f.CategoryID > 0 {
		conds = append(conds, "v.category_id = ?")
		args = append(args, f.CategoryID)
	}
	if f.UserID > 0 {
		conds = append(conds, "v.user_id = ?")
		args = append(args, f.UserID)
	}
	if f.Keyword != "" {
		conds = append(conds, "v.title LIKE ?")
		args = append(args, "%"+f.Keyword+"%")
	}
	where := "1=1"
	if len(conds) > 0 {
		where = strings.Join(conds, " AND ")
	}

	if f.Limit <= 0 || f.Limit > 100 {
		f.Limit = 20
	}

	var total int64
	if err := r.DB.Get(&total, "SELECT COUNT(*) FROM videos v WHERE "+where, args...); err != nil {
		return nil, 0, err
	}

	rows := []struct {
		models.ShortVideo
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`

		CID  int64   `db:"c_id"`
		CNm  string  `db:"c_name"`
		CSlg string  `db:"c_slug"`
		CIc  *string `db:"c_icon"`
	}{}
	q := `
		SELECT
			v.id, v.user_id, v.category_id, v.title, v.description, v.cover, v.url,
			v.duration, v.views, v.likes, v.comments_count, v.favorites, v.shares,
			v.status, v.audit_status, v.published_at, v.created_at,
			u.id  AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar,
			c.id  AS c_id, c.name AS c_name, c.slug AS c_slug, c.icon AS c_icon
		FROM videos v
		JOIN users      u ON u.id = v.user_id
		JOIN categories c ON c.id = v.category_id
		WHERE ` + where + `
		ORDER BY v.published_at DESC, v.id DESC
		LIMIT ? OFFSET ?`
	args = append(args, f.Limit, f.Offset)
	if err := r.DB.Select(&rows, q, args...); err != nil {
		return nil, 0, err
	}

	out := make([]models.ShortVideoWithRelations, 0, len(rows))
	for _, row := range rows {
		out = append(out, models.ShortVideoWithRelations{
			ShortVideo: row.ShortVideo,
			User: &models.PublicUser{
				ID: row.UID, Name: row.UNm, Username: row.USnm, Avatar: row.UAv,
			},
			Category: &models.ShortCategory{
				ID: row.CID, Name: row.CNm, Slug: row.CSlg, Icon: row.CIc,
			},
		})
	}
	return out, total, nil
}

func (r *ShortVideoRepo) FindByID(id int64) (*models.ShortVideoWithRelations, error) {
	row := struct {
		models.ShortVideo
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`

		CID  int64   `db:"c_id"`
		CNm  string  `db:"c_name"`
		CSlg string  `db:"c_slug"`
		CIc  *string `db:"c_icon"`
	}{}
	q := `
		SELECT
			v.id, v.user_id, v.category_id, v.title, v.description, v.cover, v.url,
			v.duration, v.views, v.likes, v.comments_count, v.favorites, v.shares,
			v.status, v.audit_status, v.published_at, v.created_at,
			u.id  AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar,
			c.id  AS c_id, c.name AS c_name, c.slug AS c_slug, c.icon AS c_icon
		FROM videos v
		JOIN users u ON u.id = v.user_id
		JOIN categories c ON c.id = v.category_id
		WHERE v.type = 'short' and v.id = ? LIMIT 1`
	if err := r.DB.Get(&row, q, id); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	return &models.ShortVideoWithRelations{
		ShortVideo: row.ShortVideo,
		User: &models.PublicUser{
			ID: row.UID, Name: row.UNm, Username: row.USnm, Avatar: row.UAv,
		},
		Category: &models.ShortCategory{
			ID: row.CID, Name: row.CNm, Slug: row.CSlg, Icon: row.CIc,
		},
	}, nil
}

func (r *ShortVideoRepo) IncrementViews(id int64) error {
	_, err := r.DB.Exec(`UPDATE videos SET views = views + 1 WHERE id = ?`, id)
	return err
}

// ToggleLike 返回 (liked, count)
func (r *ShortVideoRepo) ToggleLike(userID, videoID int64) (bool, int64, error) {
	return r.toggleInteraction(userID, videoID, "like")
}

// ToggleFavorite 返回 (favorited, count)
func (r *ShortVideoRepo) ToggleFavorite(userID, videoID int64) (bool, int64, error) {
	return r.toggleInteraction(userID, videoID, "favorite")
}

func (r *ShortVideoRepo) toggleInteraction(userID, videoID int64, kind string) (bool, int64, error) {
	tx, err := r.DB.Beginx()
	if err != nil {
		return false, 0, err
	}
	defer tx.Rollback()

	var existed int
	if err := tx.Get(&existed, `SELECT COUNT(*) FROM short_video_likes WHERE user_id = ? AND video_id = ? AND kind = ?`, userID, videoID, kind); err != nil {
		return false, 0, err
	}
	toggled := existed == 0
	col := "likes"
	if kind == "favorite" {
		col = "favorites"
	}
	if toggled {
		if _, err := tx.Exec(`INSERT INTO short_video_likes (user_id, video_id, kind, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())`, userID, videoID, kind); err != nil {
			return false, 0, err
		}
		if _, err := tx.Exec(`UPDATE videos SET `+col+` = `+col+` + 1 WHERE id = ?`, videoID); err != nil {
			return false, 0, err
		}
	} else {
		if _, err := tx.Exec(`DELETE FROM short_video_likes WHERE user_id = ? AND video_id = ? AND kind = ?`, userID, videoID, kind); err != nil {
			return false, 0, err
		}
		if _, err := tx.Exec(`UPDATE videos SET `+col+` = GREATEST(`+col+` - 1, 0) WHERE id = ?`, videoID); err != nil {
			return false, 0, err
		}
	}
	var count int64
	if err := tx.Get(&count, `SELECT `+col+` FROM videos WHERE id = ?`, videoID); err != nil {
		return false, 0, err
	}
	return toggled, count, tx.Commit()
}

func (r *ShortVideoRepo) IsLiked(userID, videoID int64) (bool, error) {
	var count int
	if err := r.DB.Get(&count, `SELECT COUNT(*) FROM short_video_likes WHERE user_id = ? AND video_id = ? AND kind = 'like'`, userID, videoID); err != nil {
		return false, err
	}
	return count > 0, nil
}

func (r *ShortVideoRepo) IsFavorited(userID, videoID int64) (bool, error) {
	var count int
	if err := r.DB.Get(&count, `SELECT COUNT(*) FROM short_video_likes WHERE user_id = ? AND video_id = ? AND kind = 'favorite'`, userID, videoID); err != nil {
		return false, err
	}
	return count > 0, nil
}

func (r *ShortVideoRepo) IncrementShares(id int64) error {
	_, err := r.DB.Exec(`UPDATE videos SET shares = shares + 1 WHERE id = ?`, id)
	return err
}

// === Comments ===

func (r *ShortVideoRepo) CreateComment(c *models.ShortVideoComment) error {
	q := `INSERT INTO short_video_comments (user_id, video_id, parent_id, content, audit_status, created_at, updated_at)
          VALUES (?, ?, ?, ?, 'approved', NOW(), NOW())`
	res, err := r.DB.Exec(q, c.UserID, c.VideoID, c.ParentID, c.Content)
	if err != nil {
		return err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	c.ID = id
	if _, err := r.DB.Exec(`UPDATE videos SET comments_count = comments_count + 1 WHERE id = ?`, c.VideoID); err != nil {
		return err
	}
	return nil
}

func (r *ShortVideoRepo) ListComments(videoID int64, limit, offset int) ([]models.ShortVideoCommentWithUser, error) {
	if limit <= 0 || limit > 50 {
		limit = 20
	}
	rows := []struct {
		models.ShortVideoComment
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`
	}{}
	q := `
		SELECT c.*,
		       u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
		FROM short_video_comments c
		JOIN users u ON u.id = c.user_id
		WHERE c.video_id = ? AND c.parent_id IS NULL
		ORDER BY c.created_at DESC
		LIMIT ? OFFSET ?`
	if err := r.DB.Select(&rows, q, videoID, limit, offset); err != nil {
		return nil, err
	}
	out := make([]models.ShortVideoCommentWithUser, 0, len(rows))
	for _, row := range rows {
		out = append(out, models.ShortVideoCommentWithUser{
			ShortVideoComment: row.ShortVideoComment,
			User: &models.PublicUser{
				ID: row.UID, Name: row.UNm, Username: row.USnm, Avatar: row.UAv,
			},
			Replies: []models.ShortVideoCommentWithUser{},
		})
	}

	// Fetch replies
	for i := range out {
		replies := []models.ShortVideoCommentWithUser{}
		replyRows := []struct {
			models.ShortVideoComment
			UID  int64   `db:"u_id"`
			UNm  string  `db:"u_name"`
			USnm string  `db:"u_username"`
			UAv  *string `db:"u_avatar"`
		}{}
		rq := `
			SELECT c.*,
			       u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
			FROM short_video_comments c
			JOIN users u ON u.id = c.user_id
			WHERE c.parent_id = ?
			ORDER BY c.created_at ASC`
		if err := r.DB.Select(&replyRows, rq, out[i].ID); err != nil {
			return nil, err
		}
		for _, rr := range replyRows {
			replies = append(replies, models.ShortVideoCommentWithUser{
				ShortVideoComment: rr.ShortVideoComment,
				User: &models.PublicUser{
					ID: rr.UID, Name: rr.UNm, Username: rr.USnm, Avatar: rr.UAv,
				},
			})
		}
		out[i].Replies = replies
	}
	return out, nil
}

// === Categories ===

func (r *ShortVideoRepo) ListCategories() ([]models.ShortCategory, error) {
	var cats []models.ShortCategory
	err := r.DB.Select(&cats, `
		SELECT id, name, slug, parent_id, icon, cover, description, sort, is_active
		FROM categories
		WHERE type = 'short' AND is_active = 1
		ORDER BY sort ASC, id ASC`)
	return cats, err
}
