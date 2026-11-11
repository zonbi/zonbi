-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （代行者の Google ログイン機能用のテーブルと列を追加）
CREATE TABLE IF NOT EXISTS shop_users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  google_sub     VARCHAR(64)  NOT NULL COMMENT 'GoogleアカウントID',
  email          VARCHAR(255) NOT NULL DEFAULT '',
  name           VARCHAR(100) NOT NULL DEFAULT '',
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at  DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_login_tokens (
  token_hash  CHAR(64)     NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY idx_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES shop_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE shop_sessions
  ADD COLUMN owner_user_id INT UNSIGNED NULL COMMENT 'ログインした代行者' AFTER admin_key,
  ADD KEY idx_owner_user (owner_user_id),
  ADD CONSTRAINT fk_sessions_owner FOREIGN KEY (owner_user_id) REFERENCES shop_users (id) ON DELETE SET NULL;
