-- =====================================================
-- 种子数据（与新 schema 对齐 - 2026-05-14）：
--   3 个 C 端用户 + 1 个后台管理员
--   长视频/短视频分类（用 type 列区分）
--   长/短视频示例
--   全局聊天室（slug='global', type='public'）
--
-- 密码全部是 "password" 的 bcrypt（cost=10）
--   $2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2
-- 这条 hash 同时被 PHP / Go bcrypt 兼容（$2y / $2a / $2b 互通）
-- =====================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `video_platform`;

-- ============ C 端用户 ============
INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
  (1, '管理员', 'admin', 'admin@example.com', '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'admin', 1, NOW(), NOW()),
  (2, 'Alice',  'alice', 'alice@example.com', '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'user',  1, NOW(), NOW()),
  (3, 'Bob',    'bob',   'bob@example.com',   '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'user',  1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============ 后台管理员 ============
INSERT INTO `admin_users` (`id`, `name`, `email`, `password`, `is_active`, `created_at`, `updated_at`) VALUES
  (1, '超级管理员', 'admin@example.com', '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============ 分类 ============
-- 新 schema 用 type 列区分语义：'video' = 长视频，'short' = 短视频
INSERT INTO `categories` (`id`, `name`, `slug`, `type`, `icon`, `sort`, `is_active`, `created_at`, `updated_at`) VALUES
  (1, '影视剧', 'cat-movie',  'video', 'film.fill',    1, 1, NOW(), NOW()),
  (2, '纪录片', 'cat-doc',    'video', 'play.tv.fill', 2, 1, NOW(), NOW()),
  (3, '教育',   'cat-edu',    'video', 'book.fill',    3, 1, NOW(), NOW()),
  (4, '搞笑',   'cat-fun',    'short', 'face.smiling', 1, 1, NOW(), NOW()),
  (5, '美食',   'cat-food',   'short', 'fork.knife',   2, 1, NOW(), NOW()),
  (6, '旅行',   'cat-travel', 'short', 'airplane',     3, 1, NOW(), NOW()),
  (7, '科技',   'cat-tech',   'short', 'cpu',          4, 1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

-- ============ 视频 ============
-- 长视频（uploader = alice, category = 影视剧 id=1）
INSERT INTO `videos` (`user_id`, `category_id`, `type`, `title`, `description`, `url`, `duration`, `status`, `audit_status`, `published_at`, `created_at`, `updated_at`) VALUES
  (2, 1, 'long', '示例长视频 1', '长视频示例 #1', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', 'approved', NOW(), NOW(), NOW()),
  (2, 1, 'long', '示例长视频 2', '长视频示例 #2', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', 'approved', NOW(), NOW(), NOW()),
  (2, 1, 'long', '示例长视频 3', '长视频示例 #3', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', 'approved', NOW(), NOW(), NOW());

-- 短视频
INSERT INTO `videos` (`user_id`, `category_id`, `type`, `title`, `description`, `url`, `duration`, `status`, `audit_status`, `published_at`, `created_at`, `updated_at`) VALUES
  (3, 4, 'short', '示例短视频 1', '搞笑短视频 #1', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', 'approved', NOW(), NOW(), NOW()),
  (3, 4, 'short', '示例短视频 2', '搞笑短视频 #2', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', 'approved', NOW(), NOW(), NOW()),
  (3, 5, 'short', '示例短视频 3', '美食短视频 #3', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', 'approved', NOW(), NOW(), NOW()),
  (3, 6, 'short', '示例短视频 4', '旅行短视频 #4', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', 'approved', NOW(), NOW(), NOW());

-- ============ 全局聊天室 ============
-- slug='global' 标识全局广场（Go ChatRepo.GlobalRoom 按 slug 查）
-- kind='global' 是 chat_rooms.kind ENUM 的实际值
INSERT INTO `chat_rooms` (`id`, `name`, `slug`, `description`, `kind`, `is_active`, `owner_id`, `created_at`, `updated_at`) VALUES
  (1, '广场', 'global', '所有人都能进的全局群聊', 'global', 1, 1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

-- pivot
INSERT IGNORE INTO `chat_room_user` (`chat_room_id`, `user_id`, `role`, `joined_at`, `created_at`, `updated_at`) VALUES
  (1, 1, 'admin',  NOW(), NOW(), NOW()),
  (1, 2, 'member', NOW(), NOW(), NOW()),
  (1, 3, 'member', NOW(), NOW(), NOW());

-- 几条欢迎消息
INSERT INTO `chat_messages` (`chat_room_id`, `user_id`, `type`, `content`, `created_at`, `updated_at`) VALUES
  (1, 1, 'system', '欢迎来到广场，请文明发言～', NOW(), NOW()),
  (1, 2, 'text',   '大家好，我是 Alice 👋', NOW(), NOW()),
  (1, 3, 'text',   'Hi Alice，今天看什么视频？', NOW(), NOW());

UPDATE `chat_rooms` SET last_message_at = NOW() WHERE slug = 'global';
