package repos

import (
	"database/sql"
	"errors"

	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type BaziRepo struct{ DB *sqlx.DB }

func NewBaziRepo(db *sqlx.DB) *BaziRepo { return &BaziRepo{DB: db} }

func (r *BaziRepo) Save(userID int64, birthday, gender, token, chartJSON string) error {
	_, err := r.DB.Exec(
		`INSERT INTO bazi_charts (user_id, token, birthday, gender, chart_data, created_at) VALUES (?, ?, ?, ?, ?, NOW())`,
		userID, token, birthday, gender, chartJSON,
	)
	return err
}

func (r *BaziRepo) FindByToken(token string) (*models.BaziChart, error) {
	var c models.BaziChart
	err := r.DB.Get(&c, `SELECT * FROM bazi_charts WHERE token = ? LIMIT 1`, token)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &c, err
}
