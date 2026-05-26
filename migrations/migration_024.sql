-- ════════════════════════════════════════════════════════════════════
-- migration_024.sql — Paraşüt Ürün Cache Tablosu
-- ════════════════════════════════════════════════════════════════════
-- Paraşüt'ten çekilen 1000+ ürünü her sayfa açılışında tekrar çekmek
-- yerine DB'de cache'liyoruz. Manuel "Senkronize Et" butonuyla
-- yenileniyor. Arama bu cache üzerinde anlık çalışıyor.

CREATE TABLE IF NOT EXISTS `b2b_parasut_cache` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `kind` VARCHAR(16) NOT NULL COMMENT 'products | contacts',
  `parasut_id` VARCHAR(32) NOT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `code` VARCHAR(128) DEFAULT NULL,
  `category_name` VARCHAR(128) DEFAULT NULL,
  `vat_rate` DECIMAL(5,2) DEFAULT 0,
  `list_price` DECIMAL(15,4) DEFAULT NULL,
  `archived` TINYINT(1) DEFAULT 0,
  `raw_data` LONGTEXT DEFAULT NULL COMMENT 'JSON tam attributes',
  `synced_at` DATETIME DEFAULT NULL,
  UNIQUE KEY `uniq_kind_pid` (`kind`, `parasut_id`),
  KEY `idx_kind` (`kind`),
  KEY `idx_name` (`name`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings'e son sync timestamp ve durum
INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'parasut_cache_last_sync_at', '', 'parasut'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='parasut_cache_last_sync_at');

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'parasut_cache_last_sync_status', '', 'parasut'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='parasut_cache_last_sync_status');

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_024_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_024_done');
