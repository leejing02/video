package repos

import (
	"database/sql"
	"errors"
	"strings"

	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type VideoRepo struct{ DB *sqlx.DB }

func NewVideoRepo(db *sqlx.DB) *VideoRepo { return &VideoRepo{DB: db} }

type ListVideoFilter struct {
	Type       string // long / short
	CategoryID int64
	Keyword    string
	UserID     int64 // 0 = 不过滤
	Limit      int
	Offset     int
}

// List 公共列表（仅 published）
func (r *VideoRepo) List(f ListVideoFilter) ([]models.VideoWithRelations, int64, error) {
	conds := []string{"v.status = 'published'"}
	args := []any{}
	if f.Type == "long" || f.Type == "short" {
		conds = append(conds, "v.type = ?")
		args = append(args, f.Type)
	}
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
	where := strings.Join(conds, " AND ")

	if f.Limit <= 0 || f.Limit > 100 {
		f.Limit = 20
	}

	// 总数
	var total int64
	if err := r.DB.Get(&total, "SELECT COUNT(*) FROM videos v WHERE "+where, args...); err != nil {
		return nil, 0, err
	}

	// 列表 + JOIN
	rows := []struct {
		models.Video
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`

		CID  int64   `db:"c_id"`
		CNm  string  `db:"c_name"`
		CSlg string  `db:"c_slug"`
		CKnd string  `db:"c_kind"`
		CIc  *string `db:"c_icon"`
		CCv  *string `db:"c_cover"`
	}{}
	q := `
		SELECT
			v.*,
			u.id  AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar,
			c.id  AS c_id, c.name AS c_name, c.slug AS c_slug, c.kind AS c_kind,
			c.icon AS c_icon, c.cover AS c_cover
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

	out := make([]models.VideoWithRelations, 0, len(rows))
	for _, r := range rows {
		out = append(out, models.VideoWithRelations{
			Video: r.Video,
			User: &models.PublicUser{
				ID: r.UID, Name: r.UNm, Username: r.USnm, Avatar: r.UAv,
			},
			Category: &models.Category{
				ID: r.CID, Name: r.CNm, Slug: r.CSlg, Kind: r.CKnd,
				Icon: r.CIc, Cover: r.CCv,
			},
		})
	}
	return out, total, nil
}

func (r *VideoRepo) FindByID(id int64) (*models.VideoWithRelations, error) {
	row := struct {
		models.Video
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`

		CID  int64   `db:"c_id"`
		CNm  string  `db:"c_name"`
		CSlg string  `db:"c_slug"`
		CKnd string  `db:"c_kind"`
	}{}
	q := `
		SELECT
			v.*,
			u.id  AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar,
			c.id  AS c_id, c.name AS c_name, c.slug AS c_slug, c.kind AS c_kind
		FROM videos v
		JOIN users u ON u.id = v.user_id
		JOIN categories c ON c.id = v.category_id
		WHERE v.id = ? LIMIT 1`
	if err := r.DB.Get(&row, q, id); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return nil, nil
		}
		return nil, err
	}
	return &models.VideoWithRelations{
		Video: row.Video,
		User: &models.PublicUser{
			ID: row.UID, Name: row.UNm, Username: row.USnm, Avatar: row.UAv,
		},
		Category: &models.Category{
			ID: row.CID, Name: row.CNm, Slug: row.CSlg, Kind: row.CKnd,
		},
	}, nil
}

func (r *VideoRepo) IncrementViews(id int64) error {
	_, err := r.DB.Exec(`UPDATE videos SET views = views + 1 WHERE id = ?`, id)
	return err
}

// ToggleLike 返回 (liked, likes)
func (r *VideoRepo) ToggleLike(userID, videoID int64) (bool, int64, error) {
	tx, err := r.DB.Beginx()
	if err != nil {
		return false, 0, err
	}
	defer tx.Rollback()

	var existed int
	if err := tx.Get(&existed, `SELECT COUNT(*) FROM video_likes WHERE user_id = ? AND video_id = ?`, userID, videoID); err != nil {
		return false, 0, err
	}
	liked := existed == 0
	if liked {
		if _, err := tx.Exec(`INSERT INTO video_likes (user_id, video_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())`, userID, videoID); err != nil {
			return false, 0, err
		}
		if _, err := tx.Exec(`UPDATE videos SET likes = likes + 1 WHERE id = ?`, videoID); err != nil {
			return false, 0, err
		}
	} else {
		if _, err := tx.Exec(`DELETE FROM video_likes WHERE user_id = ? AND video_id = ?`, userID, videoID); err != nil {
			return false, 0, err
		}
		if _, err := tx.Exec(`UPDATE videos SET likes = GREATEST(likes - 1, 0) WHERE id = ?`, videoID); err != nil {
			return false, 0, err
		}
	}
	var likes int64
	if err := tx.Get(&likes, `SELECT likes FROM videos WHERE id = ?`, videoID); err != nil {
		return false, 0, err
	}
	return liked, likes, tx.Commit()
}
