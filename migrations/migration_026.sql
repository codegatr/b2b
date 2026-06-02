-- ════════════════════════════════════════════════════════════════════
-- migration_026.sql — Sipariş Fatura Kesim Durumu (Billing Status)
-- ════════════════════════════════════════════════════════════════════
-- Admin paneli için: faturayı kesen arkadaş ürünleri "Fatura Kesildi"
-- veya "Bekliyor" olarak işaretleyebilsin. Mevcut parasut_invoice_status
-- Paraşüt'ün kendi durumudur (sent/cancelled/sent_to_archive), bu yeni
-- alan ise admin tarafından manuel takip içindir.

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_billing_status` VARCHAR(16) DEFAULT 'beklemede'
    COMMENT 'beklemede | kesildi - manuel admin takibi';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_billing_updated_at` DATETIME DEFAULT NULL;

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_billing_updated_by` INT DEFAULT NULL;

-- Mevcut faturalı (invoice_no dolu) siparişleri otomatik 'kesildi' yap
UPDATE `b2b_orders`
   SET `invoice_billing_status` = 'kesildi',
       `invoice_billing_updated_at` = NOW()
 WHERE `invoice_no` IS NOT NULL
   AND `invoice_no` != ''
   AND (`invoice_billing_status` IS NULL OR `invoice_billing_status` = 'beklemede');

CREATE INDEX IF NOT EXISTS `idx_invoice_billing_status` ON `b2b_orders` (`invoice_billing_status`);

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_026_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_026_done');
