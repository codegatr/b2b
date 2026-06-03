-- ════════════════════════════════════════════════════════════════════
-- migration_027.sql — Ciro Primi (Komisyon) Sistemi
-- ════════════════════════════════════════════════════════════════════
-- Her bayi için aylık alış tutarı üzerinden komisyon hesaplanır.
-- Admin oran girer, aylık otomatik uyarı + manuel yansıt akışı.

-- Bayi tablosuna ciro prim oranı ekle
ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `commission_rate` DECIMAL(5,2) DEFAULT 0
    COMMENT 'Aylık ciro primi yüzdesi (örn: 2.50 = %2.5)';

ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `commission_min_amount` DECIMAL(14,2) DEFAULT 0
    COMMENT 'Minimum alış tutarı - aşılırsa prim hesaplanır';

ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `commission_notes` TEXT DEFAULT NULL
    COMMENT 'Ciro primi ile ilgili özel notlar (admin)';

-- Aylık ciro primi hesaplama tablosu
CREATE TABLE IF NOT EXISTS `b2b_dealer_commissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dealer_id` INT NOT NULL,
  `period_year`  INT NOT NULL COMMENT 'Hangi yıl (örn: 2026)',
  `period_month` INT NOT NULL COMMENT 'Hangi ay (1-12)',

  -- Hesap detayları (dondurulur)
  `total_purchases` DECIMAL(14,2) DEFAULT 0 COMMENT 'O ay toplam alış (teslim edilen siparişler)',
  `order_count`     INT DEFAULT 0 COMMENT 'O ay sipariş adedi',
  `commission_rate` DECIMAL(5,2) DEFAULT 0 COMMENT 'Uygulanan oran (hesaplama anında)',
  `min_amount`      DECIMAL(14,2) DEFAULT 0 COMMENT 'Min eşik (hesaplama anında)',
  `commission_amount` DECIMAL(14,2) DEFAULT 0 COMMENT 'Hesaplanan prim tutarı',

  -- Durum
  `status` VARCHAR(16) DEFAULT 'taslak' COMMENT 'taslak | yansitildi | iptal',
  `applied_at` DATETIME DEFAULT NULL COMMENT 'Cari hesaba yansıtıldı',
  `applied_by` INT DEFAULT NULL COMMENT 'Yansıtan admin ID',
  `ledger_id`  INT DEFAULT NULL COMMENT 'b2b_ledger referansı',

  -- Meta
  `notes` TEXT DEFAULT NULL,
  `calculated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `calculated_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY `uniq_dealer_period` (`dealer_id`, `period_year`, `period_month`),
  KEY `idx_period` (`period_year`, `period_month`),
  KEY `idx_status` (`status`),
  KEY `idx_dealer` (`dealer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_027_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_027_done');
