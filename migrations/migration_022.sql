-- Migration 022: Paraşüt — kapsamlı entegrasyon kolonları
-- E-Arşiv/E-Fatura, PDF URL, banka hesabı, cari bakiye senkronu için

-- ─── Siparişlere e-fatura/e-arşiv bilgileri ─────────────────────
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_einvoice_id` VARCHAR(32) DEFAULT NULL
  COMMENT 'Paraşüt e-fatura veya e-arşiv ID (resmileştirilmiş)';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_einvoice_type` VARCHAR(16) DEFAULT NULL
  COMMENT 'Resmi belge türü: e_archive | e_invoice';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_invoice_pdf_url` VARCHAR(512) DEFAULT NULL
  COMMENT 'Resmi fatura PDF URL (signed, geçici - kullanım anında yeniden çekilebilir)';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_invoice_status` VARCHAR(32) DEFAULT NULL
  COMMENT 'Paraşüt fatura durumu: draft | unpaid | partially_paid | paid | overdue | cancelled';

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_synced_at` DATETIME DEFAULT NULL
  COMMENT 'En son Paraşüt ile senkronlanma zamanı';

-- ─── Bayilere cari bakiye senkronu ────────────────────────────
ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `parasut_balance` DECIMAL(14,2) DEFAULT NULL
  COMMENT 'Paraşüt''teki cari bakiye (negatif=bayinin borcu, pozitif=alacağı)';

ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `parasut_balance_updated` DATETIME DEFAULT NULL
  COMMENT 'Cari bakiye en son senkronlandığı zaman';

ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `tax_office` VARCHAR(128) DEFAULT NULL
  COMMENT 'Vergi dairesi (Paraşüt cari oluşturma için)';

-- ─── Yeni settings kayıtları (settings tablosu key/value)
INSERT INTO `b2b_settings` (`setting_key`, `setting_value`)
  SELECT 'parasut_auto_einvoice', '1'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `setting_key`='parasut_auto_einvoice');

INSERT INTO `b2b_settings` (`setting_key`, `setting_value`)
  SELECT 'parasut_einvoice_scenario', 'basic'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `setting_key`='parasut_einvoice_scenario');

INSERT INTO `b2b_settings` (`setting_key`, `setting_value`)
  SELECT 'parasut_collection_account_id', ''
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `setting_key`='parasut_collection_account_id');

INSERT INTO `b2b_settings` (`setting_key`, `setting_value`)
  SELECT 'parasut_save_pdf', '1'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `setting_key`='parasut_save_pdf');

-- ─── Index'ler
CREATE INDEX IF NOT EXISTS `idx_parasut_einvoice` ON `b2b_orders` (`parasut_einvoice_id`);
CREATE INDEX IF NOT EXISTS `idx_parasut_invoice_status` ON `b2b_orders` (`parasut_invoice_status`);
