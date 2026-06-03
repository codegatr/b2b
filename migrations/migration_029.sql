-- ════════════════════════════════════════════════════════════════════
-- migration_029.sql — Sipariş Fatura PDF Saklama
-- ════════════════════════════════════════════════════════════════════
-- Bayi panelinde fatura PDF'i indirebilsin diye dosya yolu saklarız.
-- Admin order detay sayfasından PDF yükler.

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_pdf_path` VARCHAR(500) DEFAULT NULL
    COMMENT 'Fatura PDF dosya yolu (uploads/invoices/...)';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_pdf_uploaded_at` DATETIME DEFAULT NULL;

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `invoice_pdf_uploaded_by` INT DEFAULT NULL;

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_029_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_029_done');
