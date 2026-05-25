-- ════════════════════════════════════════════════════════════════════
-- migration_023.sql — Fatura Numarası alanları
-- ════════════════════════════════════════════════════════════════════
-- Sipariş tablosuna gerçek fatura numarası saklamak için alanlar ekler.
-- parasut_invoice_id zaten var (Paraşüt sayısal ID — örn: 12345678).
-- Yeni: invoice_no (görünür fatura no — örn: SLS2026000123) + meta.

-- Sipariş tablosuna fatura no alanları
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_no` VARCHAR(64) DEFAULT NULL COMMENT 'Görünür fatura no (örn: SLS2026000123)';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_no_source` VARCHAR(16) DEFAULT NULL COMMENT 'parasut | manual';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_no_updated_at` DATETIME DEFAULT NULL;

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_no_updated_by` INT DEFAULT NULL COMMENT 'Hangi admin manuel girdi (NULL = otomatik)';

-- Index for fast lookups
CREATE INDEX IF NOT EXISTS `idx_invoice_no` ON `b2b_orders` (`invoice_no`);

-- Migration başarı kaydı
INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_023_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_023_done');
