-- Migration 007: Bayi başına ödeme yöntemi izinleri
ALTER TABLE `b2b_dealers`
  ADD COLUMN IF NOT EXISTS `payment_methods` varchar(255) DEFAULT 'havale,kredi_karti' AFTER `order_approval`;
