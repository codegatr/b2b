-- ════════════════════════════════════════════════════════════════════
-- migration_028.sql — Manuel Fatura Sistemi (Alış/Satış)
-- ════════════════════════════════════════════════════════════════════
-- Senaryo: Sipariş dışı faturalar
-- - ALIS:  Bayi bize fatura keser (örn: ciro primi karşılığı)
-- - SATIS: Biz bayiye sipariş bağlantısız fatura keseriz (örn: üyelik, hizmet)
-- Otomatik cari hesap entegrasyonu (b2b_ledger).

CREATE TABLE IF NOT EXISTS `b2b_manual_invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `dealer_id` INT NOT NULL,

  -- Yön: ALIS (biz alıyoruz) | SATIS (biz kesiyoruz)
  `direction` VARCHAR(8) NOT NULL DEFAULT 'SATIS'
    COMMENT 'ALIS | SATIS',

  -- Fatura bilgileri
  `invoice_no` VARCHAR(100) DEFAULT NULL COMMENT 'Manuel fatura no',
  `invoice_date` DATE NOT NULL COMMENT 'Fatura tarihi',
  `due_date` DATE DEFAULT NULL COMMENT 'Vade tarihi',

  -- Tutar
  `amount_net`   DECIMAL(14,2) DEFAULT 0 COMMENT 'KDV hariç tutar',
  `vat_rate`     DECIMAL(5,2)  DEFAULT 0 COMMENT 'KDV oranı (örn: 18.00)',
  `vat_amount`   DECIMAL(14,2) DEFAULT 0 COMMENT 'KDV tutarı',
  `amount_gross` DECIMAL(14,2) DEFAULT 0 COMMENT 'KDV dahil toplam',

  -- Açıklama & kategori
  `category` VARCHAR(50) DEFAULT 'diger'
    COMMENT 'ciro_primi | hizmet | kira | uyelik | diger',
  `description` TEXT DEFAULT NULL,

  -- İlişkiler
  `related_commission_id` INT DEFAULT NULL
    COMMENT 'Ciro primi karşılığı kesildi ise b2b_dealer_commissions.id',
  `ledger_id` INT DEFAULT NULL
    COMMENT 'b2b_ledger.id — cari hesaba yansıdı',
  `parasut_invoice_id` VARCHAR(50) DEFAULT NULL
    COMMENT 'Paraşüt entegrasyonu (gelecek)',

  -- Dosya
  `file_path` VARCHAR(500) DEFAULT NULL
    COMMENT 'Yüklenmiş fatura PDF/JPG yolu',

  -- Durum
  `status` VARCHAR(16) DEFAULT 'kayitli'
    COMMENT 'taslak | kayitli | odendi | iptal',

  -- Meta
  `notes` TEXT DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY `idx_dealer` (`dealer_id`),
  KEY `idx_direction` (`direction`),
  KEY `idx_status` (`status`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_related_commission` (`related_commission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`)
  SELECT 'migration_028_done', NOW(), 'system'
  WHERE NOT EXISTS (SELECT 1 FROM `b2b_settings` WHERE `skey`='migration_028_done');
