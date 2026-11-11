-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （「イベントURL」欄用の列を追加）
ALTER TABLE shop_sessions
  ADD COLUMN event_url VARCHAR(500) NULL COMMENT 'イベントの公式URL' AFTER description;
