-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （依頼者の入力項目の設定、サイズ、SNSアカウント名）
ALTER TABLE shop_sessions
  ADD COLUMN form_fields TEXT NULL COMMENT '依頼者の入力項目の設定（JSON）';
ALTER TABLE shop_entries
  ADD COLUMN requester_sns VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'SNSアカウント名（@なし）' AFTER requester_name,
  ADD COLUMN size          VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'サイズ' AFTER color;
