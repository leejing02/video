package repos

import (
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type CategoryRepo struct{ DB *sqlx.DB }

func NewCategoryRepo(db *sqlx.DB) *CategoryRepo { return &CategoryRepo{DB: db} }

// List 列出分类，可按 kind 过滤（long/short/both 不过滤）
func (r *CategoryRepo) List(kind string) ([]models.Category, error) {
	out := []models.Category{}
	if kind == "" {
		err := r.DB.Select(&out, `
			SELECT * FROM categories
			WHERE is_active = 1
			ORDER BY sort ASC, id ASC
		`)
		return out, err
	}
	err := r.DB.Select(&out, `
		SELECT * FROM categories
		WHERE is_active = 1 AND kind IN (?, 'both')
		ORDER BY sort ASC, id ASC
	`, kind)
	return out, err
}

func (r *CategoryRepo) FindByID(id int64) (*models.Category, error) {
	var c models.Category
	err := r.DB.Get(&c, `SELECT * FROM categories WHERE id = ?`, id)
	if err != nil {
		return nil, err
	}
	return &c, nil
}
