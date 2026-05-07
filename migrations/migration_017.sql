-- Migration 017: b2b_orders.is_archived + arşiv meta kolonları
-- Tamamlanmış siparişleri (teslim_edildi/iptal/iade) ana listeden çıkarıp
-- ayrı arşiv görünümünde tutabilmek için.

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `is_archived` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Sipariş arşivlendi mi? Liste varsayılan olarak arşivlenmemişleri gösterir.',
  ADD COLUMN IF NOT EXISTS `archived_at` DATETIME DEFAULT NULL
  COMMENT 'Arşivlenme tarihi',
  ADD COLUMN IF NOT EXISTS `archived_by` INT(11) DEFAULT NULL
  COMMENT 'Arşivleyen admin id';

-- Hızlı filtre için indeks
CREATE INDEX IF NOT EXISTS `idx_archived` ON `b2b_orders` (`is_archived`, `created_at`);


