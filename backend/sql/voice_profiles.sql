-- Private reference recordings for the official XiaoZhi voice-cloning workflow.
CREATE TABLE IF NOT EXISTS puffsqueeze_voice_profiles (
  id CHAR(32) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  reference_text TEXT NOT NULL,
  language VARCHAR(5) NOT NULL,
  audio_wav MEDIUMBLOB NOT NULL,
  duration_seconds DECIMAL(6,2) NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'sample_ready',
  official_voice_name VARCHAR(80) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_voice_owner (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
