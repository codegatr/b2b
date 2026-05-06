-- Migration 016: b2b_products.barcode kolonu
-- QR/Barkod tarama sistemi için ürünleri EAN-13, EAN-8, UPC, Code-128
-- veya manuel barkodla eşleştirir. NULL ise sadece SKU/ID ile eşleşir.

ALTER TABLE `b2b_products`
  ADD COLUMN IF NOT EXISTS `barcode` varchar(64) DEFAULT NULL
  COMMENT 'EAN-13, EAN-8, UPC, Code-128 vb. barkod numarası'
  AFTER `sku`;

-- Hızlı arama için indeks (tarama anında lookup)
CREATE INDEX IF NOT EXISTS `idx_barcode` ON `b2b_products` (`barcode`);
