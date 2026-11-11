-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （似た商品まとめ：代行者が手動で付ける「まとめ名」）
ALTER TABLE shop_entries
  ADD COLUMN group_label VARCHAR(200) NULL COMMENT '似た商品まとめ: 代行者が付けたまとめ名' AFTER note;
