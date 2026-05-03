-- ===========================================================
-- 与 Laravel admin 完全一致的表结构（手动建库版本）
-- 字符集统一 utf8mb4
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------- categories --------
CREATE TABLE IF NOT EXISTS `categories` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL,
  `slug` VARCHAR(80) NOT NULL UNIQUE,
  `kind` ENUM('long','short','both') NOT NULL DEFAULT 'both',
  `icon` VARCHAR(255) NULL,
  `cover` VARCHAR(500) NULL,
  `sort` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_kind_active` (`kind`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------- videos --------
CREATE TABLE IF NOT EXISTS `videos` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `type` ENUM('long','short') NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `cover` VARCHAR(500) NULL,
  `url` VARCHAR(500) NOT NULL,
  `duration` INT UNSIGNED NOT NULL DEFAULT 0,
  `views` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `likes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `comments_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
  `published_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_type_status_pub` (`type`, `status`, `published_at`),
  KEY `idx_cat_status` (`category_id`, `status`),
  CONSTRAINT `fk_videos_user`     FOREIGN KEY (`user_id`)     REFERENCES `users` (`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_videos_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------- comments --------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------- chat_rooms --------
CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(120) NOT NULL UNIQUE,
  `description` TEXT NULL,
  `cover` VARCHAR(500) NULL,
  `kind` ENUM('global','group','direct') NOT NULL DEFAULT 'group',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `owner_id` BIGINT UNSIGNED NULL,
  `last_message_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  KEY `idx_kind_active` (`kind`, `is_active`),
  CONSTRAINT `fk_room_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------- video_likes --------
CREATE TABLE IF NOT EXISTS `video_likes` (
  `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `video_id` BIGINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  UNIQUE KEY `uk_user_video` (`user_id`, `video_id`),
  CONSTRAINT `fk_vl_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`)  ON DELETE CASCADE,
  CONSTRAINT `fk_vl_video` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
