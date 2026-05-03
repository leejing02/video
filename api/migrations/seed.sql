-- =====================================================
-- 种子数据：admin / alice / bob 三个用户 + 分类 + 视频 + 全局聊天室
-- 密码全部是 "password" 的 bcrypt（cost=10）
--   $2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2
-- 注意：这条 hash 同时被 PHP / Go bcrypt 兼容（$2y / $2a 互通）
-- =====================================================
USE `video_platform`;

INSERT INTO `users` (`id`, `name`, `username`, `email`, `password`, `role`, `is_active`, `created_at`, `updated_at`) VALUES
  (1, '管理员', 'admin', 'admin@example.com', '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'admin', 1, NOW(), NOW()),
  (2, 'Alice',  'alice', 'alice@example.com', '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'user',  1, NOW(), NOW()),
  (3, 'Bob',    'bob',   'bob@example.com',   '$2b$10$WKQ0xHmHfh/UOydHHUpOL.5yaYzjdg7rD8D6IEGmPlMNQz9uCoVz2', 'user',  1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO `categories` (`name`, `slug`, `kind`, `icon`, `sort`, `is_active`, `created_at`, `updated_at`) VALUES
  ('影视剧', 'movie',     'long',  'film.fill',     1, 1, NOW(), NOW()),
  ('纪录片', 'doc',       'long',  'play.tv.fill',  2, 1, NOW(), NOW()),
  ('教育',   'edu',       'long',  'book.fill',     3, 1, NOW(), NOW()),
  ('搞笑',   'fun',       'short', 'face.smiling',  1, 1, NOW(), NOW()),
  ('美食',   'food',      'short', 'fork.knife',    2, 1, NOW(), NOW()),
  ('旅行',   'travel',    'short', 'airplane',      3, 1, NOW(), NOW()),
  ('科技',   'tech',      'short', 'cpu',           4, 1, NOW(), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

-- 长视频（uploader = alice, category = 影视剧 假定 id=1）
INSERT INTO `videos` (`user_id`, `category_id`, `type`, `title`, `description`, `url`, `duration`, `status`, `published_at`, `created_at`, `updated_at`) VALUES
  (2, 1, 'long', '示例长视频 1', '长视频示例 #1', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', NOW(), NOW(), NOW()),
  (2, 1, 'long', '示例长视频 2', '长视频示例 #2', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', NOW(), NOW(), NOW()),
  (2, 1, 'long', '示例长视频 3', '长视频示例 #3', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/BigBuckBunny.mp4', 600, 'published', NOW(), NOW(), NOW());

-- 短视频（uploader = bob, category = 搞笑 id=4）
INSERT INTO `videos` (`user_id`, `category_id`, `type`, `title`, `description`, `url`, `duration`, `status`, `published_at`, `created_at`, `updated_at`) VALUES
  (3, 4, 'short', '示例短视频 1', '搞笑短视频 #1', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', NOW(), NOW(), NOW()),
  (3, 4, 'short', '示例短视频 2', '搞笑短视频 #2', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', NOW(), NOW(), NOW()),
  (3, 4, 'short', '示例短视频 3', '搞笑短视频 #3', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', NOW(), NOW(), NOW()),
  (3, 4, 'short', '示例短视频 4', '搞笑短视频 #4', 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4', 30, 'published', NOW(), NOW(), NOW());

-- 全局聊天室
INSERT INTO `chat_rooms` (`id`, `name`, `slug`, `description`, `kind`, `is_active`, `owner_id`, `created_at`, `updated_at`)
  VALUES (1, '广场', 'global-square', '所有人都能进的全局群聊', 'global', 1, 1, NOW(), NOW())
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

UPDATE `chat_rooms` SET last_message_at = NOW() WHERE kind = 'global';
