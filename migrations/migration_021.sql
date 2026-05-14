-- Migration 021: Parasut entegrasyon kolonlari ve log tablosu
-- Mevcut kurulumda zaten varsa hicbir sey degismez (IF NOT EXISTS).

-- Bayilere paraşüt müşteri ID
ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `parasut_contact_id` VARCHAR(32) DEFAULT NULL
  COMMENT 'Parasut contact ID (musteri)';

-- Siparişlere paraşüt fatura ID
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `parasut_invoice_id` VARCHAR(32) DEFAULT NULL
  COMMENT 'Parasut sales_invoice ID';

-- Ödemelere paraşüt tahsilat ID
ALTER TABLE `b2b_payments`
  ADD COLUMN IF NOT EXISTS `parasut_payment_id` VARCHAR(32) DEFAULT NULL
  COMMENT 'Parasut payment ID';

-- Ürünlere paraşüt urun ID (yeni - bulk sync için)
ALTER TABLE `b2b_products`
  ADD COLUMN IF NOT EXISTS `parasut_product_id` VARCHAR(32) DEFAULT NULL
  COMMENT 'Parasut product ID';

-- Log tablosu (her API cagrisi loglanir)
CREATE TABLE IF NOT EXISTS `b2b_parasut_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(64) NOT NULL,
  `request` LONGTEXT DEFAULT NULL,
  `response` LONGTEXT DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
