-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （単価を任意入力にする。空欄＝NULL＝「未定」）
ALTER TABLE shop_entries
  MODIFY COLUMN unit_price INT UNSIGNED NULL COMMENT '4. 単価（円）。NULL=未定';
