-- Migration 010: b2b_orders.payment_method enum'una 'kredi_karti' ekle
-- Bayi sepetten 'Kredi Kartı ile öde' seçince sipariş bu method ile kaydedilecek.

ALTER TABLE `b2b_orders`
  MODIFY COLUMN `payment_method` enum('acik_hesap','havale_eft','pesin','kredi_karti')
    DEFAULT 'acik_hesap';
