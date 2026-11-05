package repos

import (
	"github.com/jmoiron/sqlx"
	"github.com/yourname/video-api/internal/models"
	"github.com/yourname/video-api/internal/videocache"
)

type CategoryRepo struct {
	DB    *sqlx.DB
	Cache *videocache.Cache
}

func NewCategoryRepo(db *sqlx.DB) *CategoryRepo { return &CategoryRepo{DB: db} }

// 真实 categories 表是"统一分类表"，用 type 列区分语义：
//
//	type='video' → 长视频分类（前端约定 kind='long'）
//	type='short' → 短视频分类（前端约定 kind='short'）
//	type='live'  → 直播
//	type='chat'  → 聊天室
//
// 历史遗留的 kind 列（varchar(30) nullable）现在全是 NULL，
// 用 CASE 把 type 映射回前端期望的 kind 字符串。
//
// 显式列 SELECT 避免拉到 struct 没有的 type/parent_id/level/path/description。
const categoryCols = `id, name, slug,
		CASE type
			WHEN 'video' THEN 'long'
			WHEN 'short' THEN 'short'
			ELSE COALESCE(kind, type)
		END AS kind,
		icon, cover, sort, is_active, created_at, updated_at`

// 前端约定 kind=long/short → DB type=video/short
func mapKindToType(kind string) string {
	switch kind {
	case "long":
		return "video"
	case "short":
		return "short"
	case "live", "chat":
		return kind
	}
	return ""
}

// List 列出分类，可按前端 kind 过滤（long/short/...）
func (r *CategoryRepo) List(kind string) ([]models.Category, error) {
	return videocache.Load(r.Cache, "categories:"+mapKindToType(kind), func() ([]models.Category, error) { return r.listDB(kind) })
}

func (r *CategoryRepo) listDB(kind string) ([]models.Category, error) {
	out := []models.Category{}
	typeFilter := mapKindToType(kind)

	if typeFilter == "" {
		err := r.DB.Select(&out, `
			SELECT `+categoryCols+`
			FROM categories
			WHERE is_active = 1
			ORDER BY sort ASC, id ASC
		`)
		return out, err
	}
	err := r.DB.Select(&out, `
		SELECT `+categoryCols+`
		FROM categories
		WHERE is_active = 1 AND type = ?
		ORDER BY sort ASC, id ASC
	`, typeFilter)
	return out, err
}

func (r *CategoryRepo) FindByID(id int64) (*models.Category, error) {
	var c models.Category
	err := r.DB.Get(&c, `SELECT `+categoryCols+` FROM categories WHERE id = ?`, id)
	if err != nil {
		return nil, err
	}
	return &c, nil
}
