-- Migration 011: b2b_orders.payment_method enum'dan VARCHAR'a çevir
-- Enum gelecekte yeni yöntem eklendiğinde migration gerektirmesin diye
-- VARCHAR yapıyoruz. Mevcut değerler (acik_hesap, havale_eft, pesin,
-- kredi_karti) korunur, ALTER MODIFY string olarak yansır.

ALTER TABLE `b2b_orders`
  MODIFY COLUMN `payment_method` varchar(20) NOT NULL DEFAULT 'acik_hesap';
