-- Migration 004: Duyuru tablosuna created_by ekle (eksik kolon)
ALTER TABLE `b2b_announcements`
  ADD COLUMN IF NOT EXISTS `created_by` int(11) DEFAULT NULL AFTER `ends_at`;
