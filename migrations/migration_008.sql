-- Migration 008: 3DS callback fallback için geçici oturum tablosu
-- Bayinin tarayıcısı cross-site POST callback'inde session cookie göndermezse
-- (SameSite kuralları, proxy filtreleme vb.), dealer/sipariş bilgilerini bu
-- tablodan okuruz. Kayıtlar başarılı provizyon sonrası silinir, eski kayıtlar
-- 1 saat sonra cleanup ile temizlenir.

CREATE TABLE IF NOT EXISTS `b2b_payment_sessions` (
  `threeds_session_id` varchar(255) NOT NULL,
  `dealer_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL COMMENT 'Komisyonlu (kartttan çekilecek)',
  `base_amount` decimal(14,2) NOT NULL COMMENT 'Sipariş tutarı (komisyonsuz)',
  `commission` decimal(14,2) DEFAULT 0,
  `commission_rate` decimal(6,2) DEFAULT 0,
  `installment` int(3) DEFAULT 1,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`threeds_session_id`),
  KEY `idx_dealer` (`dealer_id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
