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
        // --- 1. videos.favorites / shares ---
        Schema::table('videos', function (Blueprint $t) {
            if (! Schema::hasColumn('videos', 'favorites')) {
                $t->unsignedBigInteger('favorites')->default(0)->after('comments_count');
            }
            if (! Schema::hasColumn('videos', 'shares')) {
                $t->unsignedBigInteger('shares')->default(0)->after('favorites');
            }
        });

        // --- 2. short_video_likes ---
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

        // --- 3. short_video_comments ---
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
