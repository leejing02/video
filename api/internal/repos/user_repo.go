package repos

import (
	"database/sql"
	"errors"

	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
)

type UserRepo struct{ DB *sqlx.DB }

func NewUserRepo(db *sqlx.DB) *UserRepo { return &UserRepo{DB: db} }

func (r *UserRepo) Create(u *models.User) error {
	res, err := r.DB.NamedExec(`
		INSERT INTO users (name, username, email, password, role, is_active, created_at, updated_at)
		VALUES (:name, :username, :email, :password, :role, :is_active, NOW(), NOW())
	`, u)
	if err != nil {
		return err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return err
	}
	u.ID = id
	return nil
}

func (r *UserRepo) FindByEmail(email string) (*models.User, error) {
	var u models.User
	err := r.DB.Get(&u, `SELECT * FROM users WHERE email = ? LIMIT 1`, email)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &u, err
}

func (r *UserRepo) FindByID(id int64) (*models.User, error) {
	var u models.User
	err := r.DB.Get(&u, `SELECT * FROM users WHERE id = ?`, id)
	if errors.Is(err, sql.ErrNoRows) {
		return nil, nil
	}
	return &u, err
}

func (r *UserRepo) UsernameExists(username string) (bool, error) {
	var n int
	err := r.DB.Get(&n, `SELECT COUNT(*) FROM users WHERE username = ?`, username)
	return n > 0, err
}

func (r *UserRepo) EmailExists(email string) (bool, error) {
	var n int
	err := r.DB.Get(&n, `SELECT COUNT(*) FROM users WHERE email = ?`, email)
	return n > 0, err
}

func (r *UserRepo) UpdateProfile(id int64, name, bio, avatar, phone *string) error {
	_, err := r.DB.Exec(`
		UPDATE users SET
			name   = COALESCE(?, name),
			bio    = COALESCE(?, bio),
			avatar = COALESCE(?, avatar),
			phone  = COALESCE(?, phone),
			updated_at = NOW()
		WHERE id = ?
	`, name, bio, avatar, phone, id)
	return err
}

// AgeSeconds is how long ago the user was created, measured by the database clock.
func (r *UserRepo) AgeSeconds(id int64) (int64, error) {
	var age int64
	err := r.DB.Get(&age, `SELECT COALESCE(TIMESTAMPDIFF(SECOND, created_at, NOW()), 0) FROM users WHERE id = ?`, id)
	return age, err
}

// RetireGuest disables an expired guest and frees its device-derived email/username
// so the same device can be given a new identity. Its messages keep their author.
func (r *UserRepo) RetireGuest(id int64) error {
	_, err := r.DB.Exec(`
		UPDATE users SET
			email = CONCAT('guest-expired-', id, '@guest.invalid'),
			username = CONCAT('guest_x', id),
			is_active = 0,
			updated_at = NOW()
		WHERE id = ? AND email LIKE '%@guest.invalid'
	`, id)
	return err
}
