-- Migration 002: Eksik kolonlar eklendi
-- Tarih: 2026-04-08

-- Şifre sıfırlama için b2b_dealers
ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `reset_token`   varchar(64)  DEFAULT NULL AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `reset_expires` datetime     DEFAULT NULL AFTER `reset_token`;

-- Şifre sıfırlama için b2b_admin_users
ALTER TABLE `b2b_admin_users`
  ADD COLUMN IF NOT EXISTS `reset_token`   varchar(64)  DEFAULT NULL AFTER `last_login`,
  ADD COLUMN IF NOT EXISTS `reset_expires` datetime     DEFAULT NULL AFTER `reset_token`;

-- Başvuru red nedeni (applications tablosunda admin_note kullanılıyor, reject_reason kaldırıldı)
-- Ödeme red nedeni için payments tablosu admin_note kullanıyor, ek kolon gerekmez
