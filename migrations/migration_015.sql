-- Migration 015: b2b_products.is_featured kolonu
-- Bayi dashboard'unda 'Kampanyalı Ürünler' slider'ında gösterilecek ürünleri belirler.
-- Admin ürün ekleme/düzenleme ekranındaki 'Kampanyalı' tikinden kontrol edilir.

ALTER TABLE `b2b_products`
  ADD COLUMN IF NOT EXISTS `is_featured` tinyint(1) NOT NULL DEFAULT 0
  COMMENT '1: bayi dashboard kampanya slider''ında öne çıkar'
  AFTER `is_active`;

-- İndeks (slider sorgusu hızlı çalışsın)
CREATE INDEX IF NOT EXISTS `idx_featured` ON `b2b_products` (`is_featured`, `is_active`);
