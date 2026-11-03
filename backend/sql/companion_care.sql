-- Additive migration; existing PuffSqueeze tables and data are preserved.
-- Runtime substitutes MOODLE_TABLE_PREFIX before installing this schema.
CREATE TABLE IF NOT EXISTS puffsqueeze_care_sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_care_session_user (user_id),
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS puffsqueeze_pets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  name VARCHAR(60) NOT NULL DEFAULT '',
  xp INT UNSIGNED NOT NULL DEFAULT 0,
  happiness DECIMAL(6,2) NOT NULL DEFAULT 65,
  fullness DECIMAL(6,2) NOT NULL DEFAULT 55,
  cleanliness DECIMAL(6,2) NOT NULL DEFAULT 70,
  energy DECIMAL(6,2) NOT NULL DEFAULT 65,
  updated_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_owner_kind (owner_id, kind),
  FOREIGN KEY (owner_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puffsqueeze_pet_members (
  pet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL,
  PRIMARY KEY (pet_id, user_id),
  FOREIGN KEY (pet_id) REFERENCES puffsqueeze_pets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puffsqueeze_pet_invites (
  code_hash CHAR(64) NOT NULL PRIMARY KEY,
  pet_id BIGINT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  FOREIGN KEY (pet_id) REFERENCES puffsqueeze_pets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puffsqueeze_pet_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pet_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  request_id VARCHAR(64) NOT NULL,
  action VARCHAR(20) NOT NULL,
  food VARCHAR(20) NOT NULL DEFAULT '',
  xp_gain SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_care_request (user_id, request_id),
  KEY idx_pet_events (pet_id, id),
  FOREIGN KEY (pet_id) REFERENCES puffsqueeze_pets(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

