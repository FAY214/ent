-- Per-account documents and an append-only history of saved activity.
CREATE TABLE IF NOT EXISTS puffsqueeze_account_sync_locks (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puffsqueeze_account_documents (
  user_id BIGINT UNSIGNED NOT NULL,
  document_key VARCHAR(80) NOT NULL,
  value_json LONGTEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, document_key),
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS puffsqueeze_account_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  request_id CHAR(32) NOT NULL,
  record_type ENUM('document','activity') NOT NULL,
  record_key VARCHAR(80) NOT NULL,
  value_json LONGTEXT NOT NULL,
  client_time VARCHAR(35) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uniq_account_request (user_id, request_id),
  KEY idx_account_time (user_id, id),
  FOREIGN KEY (user_id) REFERENCES puffsqueeze_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
