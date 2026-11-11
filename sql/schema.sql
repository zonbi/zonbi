-- 推し活グッズ 買い物代行まとめアプリ スキーマ
-- 文字コード: utf8mb4（絵文字・旧字体OK）

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

CREATE TABLE IF NOT EXISTS shop_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id    CHAR(16)     NOT NULL COMMENT '共有URL用ID',
  admin_key    CHAR(64)     NOT NULL COMMENT '管理キーのSHA-256',
  owner_user_id INT UNSIGNED NULL COMMENT 'ログインした代行者',
  title        VARCHAR(100) NOT NULL,
  description  TEXT         NULL,
  event_url    VARCHAR(500) NULL COMMENT 'イベントの公式URL',
  deadline     DATETIME     NULL COMMENT '受付締切',
  is_closed    TINYINT(1)   NOT NULL DEFAULT 0,
  show_all     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=依頼一覧を記入者全員に公開',
  created_ip   VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '作成時のIPアドレス',
  created_ua   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作成時のブラウザ情報',
  flag_words   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '検知した危険ワード',
  reviewed_at  DATETIME     NULL     COMMENT '運営者が確認済みにした日時',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_id (public_id),
  KEY idx_owner_user (owner_user_id),
  CONSTRAINT fk_sessions_owner FOREIGN KEY (owner_user_id) REFERENCES shop_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_entries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      INT UNSIGNED NOT NULL,
  requester_name  VARCHAR(50)  NOT NULL COMMENT '1. 誰が',
  item_name       VARCHAR(200) NOT NULL COMMENT '2. 何を（名前）',
  color           VARCHAR(100) NOT NULL DEFAULT '' COMMENT '2. 色',
  variant         VARCHAR(100) NOT NULL DEFAULT '' COMMENT '2. 型',
  quantity        INT UNSIGNED NOT NULL COMMENT '3. 何個',
  unit_price      INT UNSIGNED NULL COMMENT '4. 単価（円）。NULL=未定',
  note            VARCHAR(500) NOT NULL DEFAULT '',
  group_label     VARCHAR(200) NULL COMMENT '似た商品まとめ: 代行者が付けたまとめ名',
  is_purchased    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '代行者用: 購入済み',
  owner_key       CHAR(64)     NOT NULL COMMENT '登録したブラウザのキー(SHA-256)',
  created_ip      VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '登録時のIPアドレス',
  created_ua      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '登録時のブラウザ情報',
  updated_ip      VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最終更新時のIPアドレス',
  flag_words      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '検知した危険ワード',
  reviewed_at     DATETIME     NULL     COMMENT '運営者が確認済みにした日時',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_session (session_id),
  KEY idx_owner (session_id, owner_key),
  CONSTRAINT fk_entries_session FOREIGN KEY (session_id)
    REFERENCES shop_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_reports (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id   INT UNSIGNED NOT NULL,
  reason       VARCHAR(50)  NOT NULL,
  detail       TEXT         NULL,
  reporter_ip  VARCHAR(45)  NOT NULL DEFAULT '',
  status       VARCHAR(10)  NOT NULL DEFAULT 'open' COMMENT 'open / resolved',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_status (status, created_at),
  KEY idx_ip (reporter_ip, created_at),
  CONSTRAINT fk_reports_session FOREIGN KEY (session_id) REFERENCES shop_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
