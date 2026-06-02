-- ════════════════════════════════════════════════════════════════════
-- migration_025.sql — Tahsilat (Payment) Kart Alanları
-- ════════════════════════════════════════════════════════════════════
-- Müşteri kartını farklı bir POS'ta (tedarikçi vs.) çekildiğinde:
--   • Onay Kodu (bayi de görür): slip üzerindeki kod
--   • Nereye Çekildi (SADECE admin görür): hangi POS/tedarikçi
--   • Kart Notu (admin görür): ek bilgi

ALTER TABLE `b2b_payments`
  ADD COLUMN IF NOT EXISTS `card_auth_code` VARCHAR(64) DEFAULT NULL COMMENT 'Slip onay kodu (bayi de görür)';

ALTER TABLE `b2b_payments`
  ADD COLUMN IF NOT EXISTS `card_receiver` VARCHAR(255) DEFAULT NULL COMMENT 'Hangi POS/tedarikçi (SADECE admin görür)';

ALTER TABLE `b2b_payments`
  ADD COLUMN IF NOT EXISTS `card_notes` TEXT DEFAULT NULL COMMENT 'Ek not (admin görür)';

CREATE INDEX IF NOT EXISTS `idx_auth_code` ON `b2b_payments` (`card_auth_code`);

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_025_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_025_done');
