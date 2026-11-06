-- ===========================================================
-- 与生产 DB schema 完全一致（2026-05-14 mysqldump 校对）
-- 字符集统一 utf8mb4 unicode_ci
-- ===========================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `video_platform`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `video_platform`;

-- -------- users --------
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL,
  `username` VARCHAR(40) NOT NULL UNIQUE,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `email_verified_at` TIMESTAMP NULL,
  `password` VARCHAR(255) NOT NULL,
  `avatar` VARCHAR(500) NULL,
  `phone` VARCHAR(32) NULL UNIQUE,
  `bio` TEXT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- admin_users --------
-- 后台 Filament 专用账号表（与 C 端 users 表物理隔离）
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `email_verified_at` TIMESTAMP NULL,
  `password` VARCHAR(255) NOT NULL,
  `avatar` VARCHAR(500) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `remember_token` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- admin_password_reset_tokens --------
CREATE TABLE IF NOT EXISTS `admin_password_reset_tokens` (
  `email` VARCHAR(120) PRIMARY KEY,
  `token` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- categories --------
-- 统一分类表：用 type 列区分语义
--   type='video' → 长视频分类（前端约定 kind='long'）
--   type='short' → 短视频分类
--   type='live'  → 直播
--   type='chat'  → 聊天室分类
-- 历史 kind 列保留作向后兼容，新数据填 NULL。
-- 支持 parent_id 父子层级。
CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL,
  `slug` VARCHAR(80) NOT NULL UNIQUE,
  `type` ENUM('video','short','live','chat') NOT NULL,
  `kind` VARCHAR(30) NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `path` VARCHAR(500) NULL,
  `icon` VARCHAR(255) NULL,
  `cover` VARCHAR(255) NULL,
  `description` TEXT NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_type_active_sort` (`type`, `is_active`, `sort`),
  KEY `idx_type` (`type`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- videos --------
CREATE TABLE IF NOT EXISTS `videos` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('long','short') NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `cover` VARCHAR(255) NULL,
  `cover_source` VARCHAR(255) NULL COMMENT 'EFV 封面1 路径（图片域名上的加密文件）',
  `url` VARCHAR(255) NOT NULL,
  `md5` VARCHAR(32) NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `duration` INT UNSIGNED NOT NULL DEFAULT 0,
  `views` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `likes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `favorites` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `shares` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
  `audit_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `audit_note` TEXT NULL,
  `audited_by` BIGINT UNSIGNED NULL,
  `audited_at` TIMESTAMP NULL,
  `published_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_type_status_pub` (`type`, `status`, `published_at`),
  KEY `idx_cat_status` (`category_id`, `status`),
  KEY `idx_type` (`type`),
  KEY `idx_audit_status` (`audit_status`),
  KEY `idx_md5` (`md5`),
  KEY `idx_audited_by` (`audited_by`),
  CONSTRAINT `fk_videos_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_videos_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_videos_audited`  FOREIGN KEY (`audited_by`)  REFERENCES `users` (`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- comments （长视频评论）--------
CREATE TABLE IF NOT EXISTS `comments` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `video_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `content` TEXT NOT NULL,
  `likes` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_video_created` (`video_id`, `created_at`),
  KEY `idx_parent` (`parent_id`),
  CONSTRAINT `fk_cmt_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_cmt_video`  FOREIGN KEY (`video_id`)  REFERENCES `videos` (`id`)   ON DELETE CASCADE,
  CONSTRAINT `fk_cmt_parent` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- short_video_likes （短视频点赞 + 收藏）--------
CREATE TABLE IF NOT EXISTS `short_video_likes` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `video_id` BIGINT UNSIGNED NOT NULL,
  `kind` ENUM('like','favorite') NOT NULL DEFAULT 'like',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_svl` (`user_id`, `video_id`, `kind`),
  KEY `idx_video_kind` (`video_id`, `kind`),
  CONSTRAINT `fk_svl_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_svl_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- short_video_comments （短视频评论，独立于 comments 表）--------
CREATE TABLE IF NOT EXISTS `short_video_comments` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `video_id` BIGINT UNSIGNED NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `content` TEXT NOT NULL,
  `likes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `audit_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_video_parent` (`video_id`, `parent_id`),
  CONSTRAINT `fk_svc_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)                ON DELETE CASCADE,
  CONSTRAINT `fk_svc_video`  FOREIGN KEY (`video_id`)  REFERENCES `videos` (`id`)               ON DELETE CASCADE,
  CONSTRAINT `fk_svc_parent` FOREIGN KEY (`parent_id`) REFERENCES `short_video_comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- chat_rooms --------
-- type='public' 公开（全局聊天用 slug='global' 标识）
-- type='group'  群组
CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `cover` VARCHAR(255) NULL,
  `kind` ENUM('global','group','direct') NOT NULL DEFAULT 'group',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `owner_id` BIGINT UNSIGNED NULL,
  `last_message_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_kind_active` (`kind`, `is_active`),
  KEY `idx_owner` (`owner_id`),
  CONSTRAINT `fk_room_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- chat_room_user pivot --------
CREATE TABLE IF NOT EXISTS `chat_room_user` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `chat_room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role` ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  `muted` TINYINT(1) NOT NULL DEFAULT 0,
  `joined_at` TIMESTAMP NULL,
  `last_read_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_room_user` (`chat_room_id`, `user_id`),
  CONSTRAINT `fk_pivot_room` FOREIGN KEY (`chat_room_id`) REFERENCES `chat_rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pivot_user` FOREIGN KEY (`user_id`)      REFERENCES `users` (`id`)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- chat_messages --------
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `chat_room_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('text','image','video','system') NOT NULL DEFAULT 'text',
  `content` TEXT NOT NULL,
  `reply_to_id` BIGINT UNSIGNED NULL,
  `attachment_url` VARCHAR(500) NULL,
  `meta` JSON NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_room_created` (`chat_room_id`, `created_at`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_msg_room`  FOREIGN KEY (`chat_room_id`) REFERENCES `chat_rooms` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_msg_user`  FOREIGN KEY (`user_id`)      REFERENCES `users` (`id`)         ON DELETE CASCADE,
  CONSTRAINT `fk_msg_reply` FOREIGN KEY (`reply_to_id`)  REFERENCES `chat_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- video_likes （长视频点赞）--------
CREATE TABLE IF NOT EXISTS `video_likes` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `video_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_user_video` (`user_id`, `video_id`),
  CONSTRAINT `fk_vl_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_vl_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- comment_likes --------
CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `comment_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_user_comment` (`user_id`, `comment_id`),
  CONSTRAINT `fk_cl_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_cl_comment` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- live_streams --------
CREATE TABLE IF NOT EXISTS `live_streams` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `platform` VARCHAR(20) NOT NULL DEFAULT 'bilibili',
  `title` VARCHAR(255) NOT NULL,
  `cover` VARCHAR(500) NULL,
  `stream_url` VARCHAR(500) NOT NULL,
  `viewers` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('live','offline') NOT NULL DEFAULT 'live',
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  INDEX `idx_category_id` (`category_id`),
  INDEX `idx_viewers` (`viewers`),
  INDEX `idx_platform_status` (`platform`, `status`),
  CONSTRAINT `fk_ls_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------- bazi_charts --------
CREATE TABLE IF NOT EXISTS `bazi_charts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(16) NOT NULL,
  `birthday` VARCHAR(20) NOT NULL,
  `gender` VARCHAR(10) NOT NULL,
  `chart_data` JSON NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bazi_token` (`token`),
  KEY `idx_bazi_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
