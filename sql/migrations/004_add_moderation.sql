-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （運営者コンソール・危険ワード検知・通報・登録時の記録 用）
ALTER TABLE shop_sessions
  ADD COLUMN created_ip  VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '作成時のIPアドレス',
  ADD COLUMN created_ua  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '作成時のブラウザ情報',
  ADD COLUMN flag_words  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '検知した危険ワード',
  ADD COLUMN reviewed_at DATETIME     NULL     COMMENT '運営者が確認済みにした日時';

ALTER TABLE shop_entries
  ADD COLUMN created_ip  VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '登録時のIPアドレス',
  ADD COLUMN created_ua  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '登録時のブラウザ情報',
  ADD COLUMN updated_ip  VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '最終更新時のIPアドレス',
  ADD COLUMN flag_words  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '検知した危険ワード',
  ADD COLUMN reviewed_at DATETIME     NULL     COMMENT '運営者が確認済みにした日時';

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
