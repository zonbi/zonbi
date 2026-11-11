-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （「みんなの依頼を公開」機能用の列を追加）
ALTER TABLE shop_sessions
  ADD COLUMN show_all TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=依頼一覧を記入者全員に公開' AFTER is_closed;
