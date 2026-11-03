-- PuffSqueeze AI custom tables for Moodle MySQL database.
-- Table names below intentionally do not use mdl_ prefix.

CREATE TABLE IF NOT EXISTS `puffsqueeze_users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `display_name` VARCHAR(120) NOT NULL,
  `password_hash` VARCHAR(255) NULL,
  `plain_password` VARCHAR(255) NOT NULL DEFAULT '',
  `moodle_user_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email` (`email`),
  KEY `idx_moodle_user_id` (`moodle_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_device_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `device_id` VARCHAR(64) NOT NULL,
  `strike_level` ENUM('low','medium','high','unknown') NOT NULL DEFAULT 'unknown',
  `strike_value` DECIMAL(8,2) NULL,
  `raw_data_json` JSON NULL,
  `event_time` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `event_time`),
  CONSTRAINT `fk_ps_device_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_chat_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `message_text` TEXT NOT NULL,
  `ai_reply_text` TEXT NOT NULL,
  `strike_level` ENUM('low','medium','high','unknown') NOT NULL DEFAULT 'unknown',
  `device_data_json` JSON NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_ps_chat_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_usage_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `action_detail` JSON NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_action_time` (`user_id`, `action_type`, `created_at`),
  CONSTRAINT `fk_ps_usage_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_stress_records` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `stress_index` DECIMAL(5,2) NOT NULL,
  `source` VARCHAR(40) NOT NULL DEFAULT 'device',
  `event_time` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_event_time` (`user_id`, `event_time`),
  CONSTRAINT `fk_ps_stress_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_user_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `age` TINYINT UNSIGNED NULL,
  `gender` VARCHAR(20) NULL,
  `signature` VARCHAR(255) NULL,
  `avatar_url` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_id` (`user_id`),
  CONSTRAINT `fk_ps_profile_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `puffsqueeze_connect_posts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `content_text` TEXT NULL,
  `media_type` ENUM('none','image','video') NOT NULL DEFAULT 'none',
  `media_mime` VARCHAR(100) NULL,
  `media_data` LONGTEXT NULL,
  `media_url` VARCHAR(500) NULL,
  `visibility` ENUM('public') NOT NULL DEFAULT 'public',
  `published_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_visibility_published` (`visibility`, `published_at`),
  KEY `idx_user_published` (`user_id`, `published_at`),
  CONSTRAINT `fk_ps_connect_posts_user` FOREIGN KEY (`user_id`) REFERENCES `puffsqueeze_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
