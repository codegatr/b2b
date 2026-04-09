-- Migration 005: Duyurulara görsel kolonu ekle
ALTER TABLE `b2b_announcements`
  ADD COLUMN IF NOT EXISTS `image` varchar(255) DEFAULT NULL AFTER `content`;
