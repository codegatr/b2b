-- Migration 003: SMS Log tablosu
CREATE TABLE IF NOT EXISTS `b2b_sms_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `message` varchar(160) NOT NULL,
  `status` enum('success','error') DEFAULT 'error',
  `response` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
