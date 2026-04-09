-- Migration 003: Eksik tablolar oluşturuldu
-- Tarih: 2026-04-09

-- Duyurular
CREATE TABLE IF NOT EXISTS `b2b_announcements` (
  `id`         int(11) NOT NULL AUTO_INCREMENT,
  `title`      varchar(200) NOT NULL,
  `content`    text DEFAULT NULL,
  `type`       enum('bilgi','uyari','onemli') DEFAULT 'bilgi',
  `is_active`  tinyint(1) DEFAULT 1,
  `starts_at`  datetime DEFAULT NULL,
  `ends_at`    datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Destek Talepleri
CREATE TABLE IF NOT EXISTS `b2b_tickets` (
  `id`           int(11) NOT NULL AUTO_INCREMENT,
  `dealer_id`    int(11) NOT NULL,
  `subject`      varchar(200) NOT NULL,
  `message`      text NOT NULL,
  `status`       enum('acik','bekliyor','kapali') DEFAULT 'acik',
  `priority`     enum('dusuk','normal','yuksek') DEFAULT 'normal',
  `admin_reply`  text DEFAULT NULL,
  `replied_by`   int(11) DEFAULT NULL,
  `replied_at`   datetime DEFAULT NULL,
  `created_at`   datetime DEFAULT current_timestamp(),
  `updated_at`   datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `dealer_id` (`dealer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
