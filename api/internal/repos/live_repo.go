package repos

import (
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type LiveRepo struct{ DB *sqlx.DB }

func NewLiveRepo(db *sqlx.DB) *LiveRepo { return &LiveRepo{DB: db} }

func (r *LiveRepo) Categories() ([]models.LiveCategory, error) {
	out := []models.LiveCategory{}
	err := r.DB.Select(&out, `
		SELECT id, name, slug, icon, cover, sort, is_active
		FROM categories
		WHERE type = 'live' AND is_active = 1
		  AND EXISTS (
		        SELECT 1 FROM live_streams s
		        WHERE s.category_id = categories.id AND s.status = 'live'
		  )
		ORDER BY sort ASC
	`)
	return out, err
}

func (r *LiveRepo) List(categoryID int64, page, perPage int) ([]models.LiveStreamWithCategory, int64, error) {
	if perPage <= 0 || perPage > 50 {
		perPage = 20
	}
	if page < 1 {
		page = 1
	}
	offset := (page - 1) * perPage

	where := "s.status = 'live'"
	args := []any{}
	if categoryID > 0 {
		where += " AND s.category_id = ?"
		args = append(args, categoryID)
	}

	var total int64
	countArgs := append([]any{}, args...)
	if err := r.DB.Get(&total, "SELECT COUNT(*) FROM live_streams s WHERE "+where, countArgs...); err != nil {
		return nil, 0, err
	}

	type row struct {
		models.LiveStream
		CatID   int64   `db:"cat_id"`
		CatName string  `db:"cat_name"`
		CatSlug string  `db:"cat_slug"`
		CatIcon *string `db:"cat_icon"`
	}
	rows := []row{}
	listArgs := append(args, perPage, offset)
	err := r.DB.Select(&rows, `
		SELECT s.id, s.category_id, s.platform, s.title, s.cover, s.stream_url, s.viewers, s.status,
		       s.created_at, s.updated_at,
		       c.id AS cat_id, c.name AS cat_name, c.slug AS cat_slug, c.icon AS cat_icon
		FROM live_streams s
		JOIN categories c ON c.id = s.category_id
		WHERE `+where+`
		ORDER BY s.viewers DESC
		LIMIT ? OFFSET ?
	`, listArgs...)
	if err != nil {
		return nil, 0, err
	}

	out := make([]models.LiveStreamWithCategory, 0, len(rows))
	for _, r := range rows {
		out = append(out, models.LiveStreamWithCategory{
			LiveStream: r.LiveStream,
			Category: &models.LiveCategory{
				ID:   r.CatID,
				Name: r.CatName,
				Slug: r.CatSlug,
				Icon: r.CatIcon,
			},
		})
	}
	return out, total, nil
}
