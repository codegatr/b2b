-- Migration 012: b2b_mail_log tablosu
-- sendMail() her gönderim sonucunu (başarı/başarısızlık + cURL hata mesajı)
-- bu tabloya kaydeder. Admin → Sistem Ayarları → E-posta sekmesinde son
-- 10 kayıt gösterilir, SMTP debug için kritik.

CREATE TABLE IF NOT EXISTS `b2b_mail_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `smtp_host` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `recipient` (`recipient`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
