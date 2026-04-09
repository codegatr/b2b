-- Migration 001: Eksik kolonlar + veri düzeltmesi
-- Güncelleme Merkezi'nden otomatik çalışır

-- b2b_orders: sipariş iptal kolonları
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `cancel_requested`    tinyint(1) DEFAULT 0 AFTER `admin_note`,
  ADD COLUMN IF NOT EXISTS `cancel_reason`       text DEFAULT NULL AFTER `cancel_requested`,
  ADD COLUMN IF NOT EXISTS `cancel_requested_at` datetime DEFAULT NULL AFTER `cancel_reason`,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_by`  int(11) DEFAULT NULL AFTER `cancel_requested_at`,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_at`  datetime DEFAULT NULL AFTER `cancel_reviewed_by`;

-- b2b_products: kısa açıklama
ALTER TABLE `b2b_products`
  ADD COLUMN IF NOT EXISTS `short_description` varchar(255) DEFAULT NULL AFTER `description`;

-- b2b_tickets: destek talepleri
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

-- b2b_announcements: duyurular
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

-- Veri düzeltmesi: iptal edilmiş siparişlerin açık ledger kayıtlarını kapat
UPDATE `b2b_ledger` SET `is_closed`=1
WHERE `reference_type`='order'
  AND `reference_id` IN (SELECT `id` FROM `b2b_orders` WHERE `status`='iptal')
  AND `is_closed`=0;
