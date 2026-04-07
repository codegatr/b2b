-- ============================================================
-- CODEGA B2B Bayi Portalı — Veritabanı Şeması
-- Prefix: b2b_  |  Engine: InnoDB  |  Charset: utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ── Ayarlar ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skey` varchar(100) NOT NULL,
  `sval` text DEFAULT NULL,
  `sgroup` varchar(50) DEFAULT 'general',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admin Kullanıcılar ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('superadmin','admin','staff') DEFAULT 'admin',
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Kategoriler ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ürünler ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `images` text DEFAULT NULL COMMENT 'JSON array',
  `unit` varchar(30) DEFAULT 'Adet',
  `vat_rate` decimal(5,2) DEFAULT 18.00,
  `base_price` decimal(12,2) DEFAULT 0.00 COMMENT 'Liste/referans fiyat',
  `stock` int(11) DEFAULT 0,
  `stock_critical` int(11) DEFAULT 10 COMMENT 'Kritik stok seviyesi',
  `min_order_qty` int(11) DEFAULT 1,
  `max_order_qty` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `parasut_product_id` varchar(100) DEFAULT NULL,
  `weight` decimal(8,3) DEFAULT NULL,
  `desi` decimal(8,2) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `category_id` (`category_id`),
  KEY `sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fiyat Listeleri ───────────────────────────────────────────
-- Her bayi bir veya birden fazla fiyat listesine atanır.
-- Ürün bazında fiyat override yapılabilir.
CREATE TABLE IF NOT EXISTS `b2b_price_lists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'TRY',
  `discount_percent` decimal(5,2) DEFAULT 0.00 COMMENT 'Global iskonto oranı',
  `is_default` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fiyat Listesi Kalemleri ───────────────────────────────────
-- Ürün bazında özel fiyat — NULL ise liste iskontosu uygulanır
CREATE TABLE IF NOT EXISTS `b2b_price_list_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `price_list_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price` decimal(12,2) NOT NULL COMMENT 'KDV hariç fiyat',
  `discount_percent` decimal(5,2) DEFAULT NULL COMMENT 'Ürün bazında ek iskonto',
  `min_order_qty` int(11) DEFAULT NULL COMMENT 'Bu liste için min. sipariş',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `list_product` (`price_list_id`, `product_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bayiler ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_dealers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_code` varchar(50) DEFAULT NULL,
  `type` enum('bireysel','kurumsal') DEFAULT 'kurumsal',
  -- Bireysel alanlar
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `tc_no` varchar(11) DEFAULT NULL,
  -- Kurumsal alanlar
  `company_name` varchar(200) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_number` varchar(11) DEFAULT NULL,
  -- Ortak
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `district` varchar(80) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `iban` varchar(40) DEFAULT NULL,
  `price_list_id` int(11) DEFAULT NULL,
  `credit_limit` decimal(14,2) DEFAULT 0.00 COMMENT 'Açık hesap limiti',
  `payment_term_days` int(11) DEFAULT 30 COMMENT 'Vade gün sayısı',
  `order_approval` enum('manual','auto') DEFAULT 'manual',
  `is_active` tinyint(1) DEFAULT 1,
  `parasut_contact_id` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `price_list_id` (`price_list_id`),
  KEY `dealer_code` (`dealer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bayilik Başvuruları ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('bireysel','kurumsal') DEFAULT 'kurumsal',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `tc_no` varchar(11) DEFAULT NULL,
  `company_name` varchar(200) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_number` varchar(11) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('bekliyor','onaylandi','reddedildi') DEFAULT 'bekliyor',
  `admin_note` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sepet ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) DEFAULT NULL COMMENT 'Sepete eklendiğindeki fiyat',
  `added_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dealer_product` (`dealer_id`, `product_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Siparişler ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_no` varchar(50) NOT NULL,
  `dealer_id` int(11) NOT NULL,
  `price_list_id` int(11) DEFAULT NULL,
  `status` enum('bekliyor','onaylandi','hazirlaniyor','kargoda','teslim_edildi','iptal','iade') DEFAULT 'bekliyor',
  `payment_status` enum('odenmedi','kismi','odendi') DEFAULT 'odenmedi',
  `payment_method` enum('acik_hesap','havale_eft','pesin') DEFAULT 'acik_hesap',
  `subtotal` decimal(14,2) DEFAULT 0.00 COMMENT 'KDV hariç',
  `vat_total` decimal(14,2) DEFAULT 0.00,
  `discount_total` decimal(14,2) DEFAULT 0.00,
  `grand_total` decimal(14,2) DEFAULT 0.00 COMMENT 'KDV dahil toplam',
  `due_date` date DEFAULT NULL COMMENT 'Vade tarihi',
  `shipping_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `parasut_invoice_id` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `dealer_id` (`dealer_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sipariş Kalemleri ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL COMMENT 'Snapshot',
  `product_sku` varchar(100) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL COMMENT 'KDV hariç birim fiyat',
  `vat_rate` decimal(5,2) DEFAULT 18.00,
  `discount_percent` decimal(5,2) DEFAULT 0.00,
  `line_total` decimal(14,2) NOT NULL COMMENT 'KDV dahil satır toplamı',
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cari Hesap (Açık Hesap / Muhasebe) ───────────────────────
CREATE TABLE IF NOT EXISTS `b2b_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) NOT NULL,
  `type` enum('borc','alacak') NOT NULL COMMENT 'borç=borcun arttı, alacak=ödeme aldık',
  `amount` decimal(14,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_type` enum('order','payment','manual','return') DEFAULT 'manual',
  `reference_id` int(11) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `is_closed` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL COMMENT 'admin_user_id',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`),
  KEY `type` (`type`),
  KEY `due_date` (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Ödemeler / Tahsilatlar ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `type` enum('havale','eft','nakit','kredi_karti','mahsup') DEFAULT 'havale',
  `amount` decimal(14,2) NOT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `status` enum('bekliyor','onaylandi','reddedildi') DEFAULT 'bekliyor',
  `receipt_file` varchar(255) DEFAULT NULL COMMENT 'Dekont dosyası',
  `dealer_note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `parasut_payment_id` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`),
  KEY `order_id` (`order_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Stok Geçmişi / Log ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_stock_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `change_type` enum('giris','cikis','iade','duzeltme') NOT NULL,
  `qty_before` int(11) NOT NULL,
  `qty_change` int(11) NOT NULL,
  `qty_after` int(11) NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bildirimler ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) DEFAULT NULL COMMENT 'NULL = admin bildirimi',
  `admin_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` text DEFAULT NULL,
  `url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Paraşüt Log ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_parasut_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `action` varchar(100) NOT NULL,
  `request` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `status` enum('success','error') DEFAULT 'success',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Güncelleme Log ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_update_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_version` varchar(20) DEFAULT NULL,
  `to_version` varchar(20) NOT NULL,
  `status` enum('success','error','rolledback') DEFAULT 'success',
  `note` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Denetim Kaydı ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_type` enum('admin','dealer') DEFAULT 'admin',
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL COMMENT 'JSON',
  `new_values` text DEFAULT NULL COMMENT 'JSON',
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_type_id` (`user_type`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Varsayılan Ayarlar ────────────────────────────────────────
INSERT INTO `b2b_settings` (`skey`, `sval`, `sgroup`) VALUES
('site_name', 'B2B Bayi Portalı', 'general'),
('site_logo', '', 'general'),
('admin_email', '', 'general'),
('smtp_host', '', 'mail'),
('smtp_port', '587', 'mail'),
('smtp_user', '', 'mail'),
('smtp_pass', '', 'mail'),
('smtp_from', '', 'mail'),
('smtp_from_name', 'B2B Sistem', 'mail'),
('order_prefix', 'SIP', 'order'),
('order_auto_approve', '0', 'order'),
('stock_alert_email', '', 'stock'),
('parasut_client_id', '', 'parasut'),
('parasut_client_secret', '', 'parasut'),
('parasut_username', '', 'parasut'),
('parasut_password', '', 'parasut'),
('parasut_company_id', '', 'parasut'),
('parasut_enabled', '0', 'parasut'),
('github_token', '', 'update'),
('github_repo', 'codegatr/b2b', 'update'),
('bank_accounts', '[]', 'payment'),
('vat_default', '18', 'general'),
('currency', 'TRY', 'general');

SET foreign_key_checks = 1;

-- ── Destek Talepleri ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('acik','bekliyor','kapali') DEFAULT 'acik',
  `priority` enum('dusuk','normal','yuksek') DEFAULT 'normal',
  `admin_reply` text DEFAULT NULL,
  `replied_at` datetime DEFAULT NULL,
  `replied_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Duyurular ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `b2b_announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `type` enum('bilgi','uyari','onemli') DEFAULT 'bilgi',
  `is_active` tinyint(1) DEFAULT 1,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sipariş İptal Talebi Kolonları ───────────────────────────
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `cancel_requested`    tinyint(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `cancel_reason`       text DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_requested_at` datetime DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_by`  int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_at`  datetime DEFAULT NULL;
