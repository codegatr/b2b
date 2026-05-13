-- Migration 020: Sabit tutar fiyat ayarlama (price_adjust)
--
-- KULLANIM:
--   "Liste 1 = Baz Liste + 5 TL"
--   -> b2b_price_lists.price_adjust = 5.00
--   -> Tum urunlere otomatik olarak standart fiyat + 5 TL uygulanir
--
-- HESAPLAMA ONCELIGI (ustten):
--   1. b2b_price_list_items.price (sabit override)
--   2. b2b_price_list_items.discount_percent
--   3. b2b_price_list_items.price_adjust
--   4. b2b_price_lists.discount_percent (liste geneli yuzde)
--   5. b2b_price_lists.price_adjust (liste geneli tutar)  YENI
--   6. Standart fiyat (b2b_products.base_price) - fallback
--
-- Pozitif deger = ek (zam), Negatif deger = indirim

ALTER TABLE `b2b_price_lists`
  ADD COLUMN IF NOT EXISTS `price_adjust` DECIMAL(10,2) DEFAULT NULL
  COMMENT 'Tum urunlere uygulanacak sabit tutar ek/eksilt. NULL = uygulanmaz.';

ALTER TABLE `b2b_price_list_items`
  ADD COLUMN IF NOT EXISTS `price_adjust` DECIMAL(10,2) DEFAULT NULL
  COMMENT 'Urun-bazli sabit tutar ek/eksilt.';
