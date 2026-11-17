-- 既に schema.sql でテーブルを作成済みの場合に1回だけ実行してください。
-- （代行する商品の登録＝商品カタログ）
CREATE TABLE IF NOT EXISTS shop_products (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id      INT UNSIGNED NOT NULL,
  name            VARCHAR(200) NOT NULL,
  description     VARCHAR(500) NOT NULL DEFAULT '',
  price           INT UNSIGNED NULL COMMENT '定価（税込・円）。NULL=未定',
  color_options   VARCHAR(500) NOT NULL DEFAULT '' COMMENT '色の選択肢（カンマ区切り）',
  size_options    VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'サイズの選択肢',
  shape_options   VARCHAR(500) NOT NULL DEFAULT '' COMMENT '形の選択肢',
  max_per_person  INT UNSIGNED NULL COMMENT '1人あたりの上限個数',
  image           VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'uploads/products/ 内のファイル名',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order      INT          NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_session (session_id, sort_order),
  CONSTRAINT fk_products_session FOREIGN KEY (session_id) REFERENCES shop_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE shop_entries
  ADD COLUMN product_id INT UNSIGNED NULL COMMENT '登録商品から選んだ場合の商品ID' AFTER session_id,
  ADD KEY idx_product (product_id);

ALTER TABLE shop_sessions
  ADD COLUMN allow_free_items TINYINT(1) NOT NULL DEFAULT 1 COMMENT '登録商品以外の依頼も受け付ける';
