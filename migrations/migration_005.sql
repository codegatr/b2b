-- Migration 005: Duyurulara kampanya görseli kolonu
ALTER TABLE `b2b_announcements`
  ADD COLUMN IF NOT EXISTS `image` varchar(255) DEFAULT NULL AFTER `content`;
