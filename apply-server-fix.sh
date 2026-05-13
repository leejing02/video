#!/usr/bin/env bash
# ============================================================
# 一次性把 short_video 相关修复应用到服务器
# 用法（在服务器上）：
#   cd /www/wwwroot/video
#   bash apply-server-fix.sh
# ============================================================
set -euo pipefail
cd "$(dirname "$0")"

echo "==> 1/4 写 admin/database/migrations/2026_05_12_000000_extend_videos_for_shorts.php"
cat > admin/database/migrations/2026_05_12_000000_extend_videos_for_shorts.php <<'PHP_EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补齐短视频域所需的 DB 结构：
 *   1. videos 表加 favorites / shares 计数列
 *   2. short_video_likes 表（点赞 + 收藏，用 kind 区分）
 *   3. short_video_comments 表（独立于 comments 表，支持 audit_status）
 *
 * 历史背景：这些字段/表过去在某次手工改库时直接在生产 DB 加过，
 * 但对应迁移文件没有进仓库——任何全新部署都缺这些列/表，导致
 * Go API /api/short-* 全线 500。本迁移补全。
 *
 * 幂等：用 hasColumn / hasTable 守门，已存在就跳过。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $t) {
            if (! Schema::hasColumn('videos', 'favorites')) {
                $t->unsignedBigInteger('favorites')->default(0)->after('comments_count');
            }
            if (! Schema::hasColumn('videos', 'shares')) {
                $t->unsignedBigInteger('shares')->default(0)->after('favorites');
            }
        });

        if (! Schema::hasTable('short_video_likes')) {
            Schema::create('short_video_likes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
                $t->enum('kind', ['like', 'favorite'])->default('like');
                $t->timestamps();

                $t->unique(['user_id', 'video_id', 'kind'], 'svl_unique');
                $t->index(['video_id', 'kind']);
            });
        }

        if (! Schema::hasTable('short_video_comments')) {
            Schema::create('short_video_comments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->foreignId('video_id')->constrained('videos')->cascadeOnDelete();
                $t->foreignId('parent_id')->nullable()
                    ->constrained('short_video_comments')->nullOnDelete();
                $t->text('content');
                $t->unsignedBigInteger('likes')->default(0);
                $t->enum('audit_status', ['pending', 'approved', 'rejected'])->default('approved');
                $t->timestamps();

                $t->index(['video_id', 'parent_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('short_video_comments');
        Schema::dropIfExists('short_video_likes');

        Schema::table('videos', function (Blueprint $t) {
            if (Schema::hasColumn('videos', 'shares')) {
                $t->dropColumn('shares');
            }
            if (Schema::hasColumn('videos', 'favorites')) {
                $t->dropColumn('favorites');
            }
        });
    }
};
PHP_EOF

echo "==> 2/4 写 admin/app/Filament/Resources/ShortVideoCategoryResource.php"
cat > admin/app/Filament/Resources/ShortVideoCategoryResource.php <<'PHP_EOF'
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShortVideoCategoryResource\Pages;
use App\Domains\Video\Models\Category;
use App\Domains\Video\Models\ShortVideoCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 短视频分类（categories.type='short' 的子集）。
 * Model 上有 global scope，查询自动加 type='short'，无需重复 where。
 */
class ShortVideoCategoryResource extends CategoryResource
{
    protected static ?string $model = ShortVideoCategory::class;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationIcon  = 'heroicon-o-folder-open';
    protected static ?string $navigationGroup = '内容管理';
    protected static ?string $navigationLabel = '短视频分类';
    protected static ?string $modelLabel      = '短视频分类';
    protected static ?int $navigationSort     = 22;
    protected static ?string $slug            = 'short-categories';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('name')->label('名称')->required(),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('保存后自动生成'),
                Forms\Components\Select::make('parent_id')
                    ->label('父分类')
                    ->options(fn () => Category::query()
                        ->where('type', Category::TYPE_SHORT)
                        ->where('is_active', true)
                        ->orderBy('sort')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->placeholder('— 顶级 —'),
                Forms\Components\TextInput::make('sort')->label('排序')->numeric()->default(0),
                Forms\Components\TextInput::make('icon')
                    ->label('图标（CDN url 或 SF Symbol 名）')
                    ->placeholder('face.smiling'),
                Forms\Components\Toggle::make('is_active')->label('启用')->default(true),
            ]),
            Forms\Components\FileUpload::make('cover')
                ->label('封面图')
                ->image()
                ->directory('categories/short'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('封面')->square(),
                Tables\Columns\TextColumn::make('name')->label('名称')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->label('Slug')->copyable()->toggleable(),
                Tables\Columns\TextColumn::make('parent.name')->label('父分类')->placeholder('—'),
                Tables\Columns\TextColumn::make('short_videos_count')
                    ->counts('shortVideos')
                    ->label('短视频数')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort')->label('排序')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('启用'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('启用'),
            ])
            ->reorderable('sort')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListShortVideoCategories::route('/'),
            'create' => Pages\CreateShortVideoCategory::route('/create'),
            'edit'   => Pages\EditShortVideoCategory::route('/{record}/edit'),
        ];
    }
}
PHP_EOF

echo "==> 3/4 patch api/internal/models/models.go (追加 Short* 类型)"
# 幂等：如果已经有 ShortCategory 定义就跳过
if grep -q "^type ShortCategory struct" api/internal/models/models.go; then
    echo "    Short* 类型已存在，跳过追加"
else
    cat >> api/internal/models/models.go <<'GO_EOF'

// =====================================================================
// === Short* —— 短视频专用类型 ===
//
// 与真实 DB schema 对齐（2026-05-13 服务器 mysqldump 验证）：
//   categories: id,name,slug,type,kind,parent_id,level,path,icon,cover,description,sort,is_active,...
//   videos:     ...,favorites*,shares*,...   * 由 2026_05_12 migration 添加
// =====================================================================

type ShortCategory struct {
	ID          int64         `db:"id"          json:"id"`
	Name        string        `db:"name"        json:"name"`
	Slug        string        `db:"slug"        json:"slug"`
	ParentID    sql.NullInt64 `db:"parent_id"   json:"parent_id"`
	Icon        *string       `db:"icon"        json:"icon"`
	Cover       *string       `db:"cover"       json:"cover"`
	Description *string       `db:"description" json:"description"`
	Sort        int           `db:"sort"        json:"sort"`
	IsActive    bool          `db:"is_active"   json:"is_active"`
}

type ShortVideo struct {
	ID            int64        `db:"id"             json:"id"`
	UserID        int64        `db:"user_id"        json:"user_id"`
	CategoryID    int64        `db:"category_id"    json:"category_id"`
	Title         string       `db:"title"          json:"title"`
	Description   *string      `db:"description"    json:"description"`
	Cover         *string      `db:"cover"          json:"cover"`
	URL           string       `db:"url"            json:"url"`
	Duration      int          `db:"duration"       json:"duration"`
	Views         int64        `db:"views"          json:"views"`
	Likes         int64        `db:"likes"          json:"likes"`
	CommentsCount int64        `db:"comments_count" json:"comments_count"`
	Favorites     int64        `db:"favorites"      json:"favorites"`
	Shares        int64        `db:"shares"         json:"shares"`
	Status        string       `db:"status"         json:"status"`
	AuditStatus   string       `db:"audit_status"   json:"audit_status"`
	PublishedAt   sql.NullTime `db:"published_at"   json:"published_at"`
	CreatedAt     time.Time    `db:"created_at"     json:"created_at"`
}

type ShortVideoWithRelations struct {
	ShortVideo
	User     *PublicUser    `json:"user"`
	Category *ShortCategory `json:"category"`
}

type ShortVideoComment struct {
	ID        int64         `db:"id"         json:"id"`
	UserID    int64         `db:"user_id"    json:"user_id"`
	VideoID   int64         `db:"video_id"   json:"video_id"`
	ParentID  sql.NullInt64 `db:"parent_id"  json:"parent_id"`
	Content   string        `db:"content"    json:"content"`
	Likes     int           `db:"likes"      json:"likes"`
	CreatedAt time.Time     `db:"created_at" json:"created_at"`
}

type ShortVideoCommentWithUser struct {
	ShortVideoComment
	User    *PublicUser                 `json:"user"`
	Replies []ShortVideoCommentWithUser `json:"replies,omitempty"`
}
GO_EOF
    echo "    已追加"
fi

echo "==> 4/4 写 api/internal/repos/short_video_repo.go (完整覆盖)"
cat > api/internal/repos/short_video_repo.go <<'GO_EOF'
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

// 所有 SELECT 都用真实存在的列名（schema 见 docker exec video-mysql mysqldump --no-data video videos categories）
// v.type='short' 在 List 走 conds，FindByID 单独硬编

func (r *ShortVideoRepo) List(f ListShortVideoFilter) ([]models.ShortVideoWithRelations, int64, error) {
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
	where := strings.Join(conds, " AND ")

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
		WHERE v.type = 'short' AND v.id = ? LIMIT 1`
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

func (r *ShortVideoRepo) ToggleLike(userID, videoID int64) (bool, int64, error) {
	return r.toggleInteraction(userID, videoID, "like")
}

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
		SELECT c.id, c.user_id, c.video_id, c.parent_id, c.content, c.likes, c.created_at,
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
			SELECT c.id, c.user_id, c.video_id, c.parent_id, c.content, c.likes, c.created_at,
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

func (r *ShortVideoRepo) ListCategories() ([]models.ShortCategory, error) {
	var cats []models.ShortCategory
	err := r.DB.Select(&cats, `
		SELECT id, name, slug, parent_id, icon, cover, description, sort, is_active
		FROM categories
		WHERE type = 'short' AND is_active = 1
		ORDER BY sort ASC, id ASC`)
	return cats, err
}
GO_EOF

echo
echo "✅ 全部 4 个文件应用完成"
echo
echo "下一步："
echo "  docker compose --env-file .env.prod -f docker-compose.prod.yml exec admin php artisan migrate --force"
echo "  ./redeploy-api.sh"
