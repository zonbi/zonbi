-- 推し活グッズ 買い物代行まとめアプリ スキーマ
-- 文字コード: utf8mb4（絵文字・旧字体OK）

CREATE TABLE IF NOT EXISTS shop_sessions (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id    CHAR(16)     NOT NULL COMMENT '共有URL用ID',
  admin_key    CHAR(64)     NOT NULL COMMENT '管理キーのSHA-256',
  title        VARCHAR(100) NOT NULL,
  description  TEXT         NULL,
  event_url    VARCHAR(500) NULL COMMENT 'イベントの公式URL',
  deadline     DATETIME     NULL COMMENT '受付締切',
  is_closed    TINYINT(1)   NOT NULL DEFAULT 0,
  show_all     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=依頼一覧を記入者全員に公開',
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_public_id (public_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shop_entries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      INT UNSIGNED NOT NULL,
  requester_name  VARCHAR(50)  NOT NULL COMMENT '1. 誰が',
  item_name       VARCHAR(200) NOT NULL COMMENT '2. 何を（名前）',
  color           VARCHAR(100) NOT NULL DEFAULT '' COMMENT '2. 色',
  variant         VARCHAR(100) NOT NULL DEFAULT '' COMMENT '2. 型',
  quantity        INT UNSIGNED NOT NULL COMMENT '3. 何個',
  unit_price      INT UNSIGNED NOT NULL COMMENT '4. 単価（円）',
  note            VARCHAR(500) NOT NULL DEFAULT '',
  is_purchased    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '代行者用: 購入済み',
  owner_key       CHAR(64)     NOT NULL COMMENT '登録したブラウザのキー(SHA-256)',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_session (session_id),
  KEY idx_owner (session_id, owner_key),
  CONSTRAINT fk_entries_session FOREIGN KEY (session_id)
    REFERENCES shop_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
