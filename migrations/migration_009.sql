-- Migration 009: payment_sessions tablosuna kart bilgileri ekle
-- 3DS provizyon adımında cardToken ve cardHolderName parametreleri gerekiyor.
-- Migration 008 zaten çalıştıysa bu ALTER ile kolonlar eklenir, IF NOT EXISTS
-- güvenli. Migration 008 henüz çalışmadıysa bu hata vermez (zaten yeni
-- schema'sıyla yaratılmış olur).

ALTER TABLE `b2b_payment_sessions`
  ADD COLUMN IF NOT EXISTS `card_token` varchar(255) DEFAULT NULL AFTER `installment`,
  ADD COLUMN IF NOT EXISTS `card_holder` varchar(100) DEFAULT NULL AFTER `card_token`;
