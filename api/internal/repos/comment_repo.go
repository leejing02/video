package repos

import (
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type CommentRepo struct{ DB *sqlx.DB }

func NewCommentRepo(db *sqlx.DB) *CommentRepo { return &CommentRepo{DB: db} }

// ListRoots 顶级评论 + 回复（一次性 N+1，演示用）
func (r *CommentRepo) ListRoots(videoID int64, limit, offset int) ([]models.CommentWithUser, int64, error) {
	if limit <= 0 || limit > 100 {
		limit = 20
	}
	type row struct {
		models.Comment
		UID  int64   `db:"u_id"`
		UNm  string  `db:"u_name"`
		USnm string  `db:"u_username"`
		UAv  *string `db:"u_avatar"`
	}

	var total int64
	if err := r.DB.Get(&total, `
		SELECT COUNT(*) FROM comments WHERE video_id = ? AND parent_id IS NULL
	`, videoID); err != nil {
		return nil, 0, err
	}

	roots := []row{}
	if err := r.DB.Select(&roots, `
		SELECT
			c.*,
			u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
		FROM comments c JOIN users u ON u.id = c.user_id
		WHERE c.video_id = ? AND c.parent_id IS NULL
		ORDER BY c.is_pinned DESC, c.created_at DESC
		LIMIT ? OFFSET ?
	`, videoID, limit, offset); err != nil {
		return nil, 0, err
	}
	if len(roots) == 0 {
		return []models.CommentWithUser{}, total, nil
	}

	// 一次性拉所有 replies
	rootIDs := make([]int64, 0, len(roots))
	for _, r := range roots {
		rootIDs = append(rootIDs, r.ID)
	}
	q, args, err := sqlx.In(`
		SELECT
			c.*,
			u.id AS u_id, u.name AS u_name, u.username AS u_username, u.avatar AS u_avatar
		FROM comments c JOIN users u ON u.id = c.user_id
		WHERE c.parent_id IN (?)
		ORDER BY c.created_at ASC
	`, rootIDs)
	if err != nil {
		return nil, 0, err
	}
	q = r.DB.Rebind(q)
	replies := []row{}
	if err := r.DB.Select(&replies, q, args...); err != nil {
		return nil, 0, err
	}

	// 装回去
	build := func(rr row) models.CommentWithUser {
		return models.CommentWithUser{
			Comment: rr.Comment,
			User: &models.PublicUser{
				ID: rr.UID, Name: rr.UNm, Username: rr.USnm, Avatar: rr.UAv,
			},
		}
	}
	repliesByParent := map[int64][]models.CommentWithUser{}
	for _, r := range replies {
		if r.ParentID.Valid {
			repliesByParent[r.ParentID.Int64] = append(repliesByParent[r.ParentID.Int64], build(r))
		}
	}
	out := make([]models.CommentWithUser, 0, len(roots))
	for _, r := range roots {
		c := build(r)
		c.Replies = repliesByParent[r.ID]
		out = append(out, c)
	}
	return out, total, nil
}

func (r *CommentRepo) Create(c *models.Comment) error {
	tx, err := r.DB.Beginx()
	if err != nil {
		return err
	}
	defer tx.Rollback()

	res, err := tx.NamedExec(`
		INSERT INTO comments (user_id, video_id, parent_id, content, likes, is_pinned, created_at, updated_at)
		VALUES (:user_id, :video_id, :parent_id, :content, 0, 0, NOW(), NOW())
	`, c)
	if err != nil {
		return err
	}
	id, _ := res.LastInsertId()
	c.ID = id

	if _, err := tx.Exec(`UPDATE videos SET comments_count = comments_count + 1 WHERE id = ?`, c.VideoID); err != nil {
		return err
	}
	return tx.Commit()
}

func (r *CommentRepo) FindByID(id int64) (*models.Comment, error) {
	var c models.Comment
	if err := r.DB.Get(&c, `SELECT * FROM comments WHERE id = ?`, id); err != nil {
		return nil, err
	}
	return &c, nil
}

func (r *CommentRepo) Delete(id, videoID int64) error {
	tx, err := r.DB.Beginx()
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.Exec(`DELETE FROM comments WHERE id = ?`, id); err != nil {
		return err
	}
	if _, err := tx.Exec(`UPDATE videos SET comments_count = GREATEST(comments_count - 1, 0) WHERE id = ?`, videoID); err != nil {
		return err
	}
	return tx.Commit()
}
