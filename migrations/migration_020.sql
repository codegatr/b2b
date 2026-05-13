<<<<<<< HEAD
-- Migration 018: Havale/EFT siparisleri icin bekleyen tahsilat olustur.
-- Eski kayitlarda siparis payment_method='havale_eft' olsa bile b2b_payments
-- kaydi acilmadigi icin Tahsilat Yonetimi > Bekleyen sekmesi bos gorunuyordu.

INSERT INTO `b2b_payments`
  (`dealer_id`, `order_id`, `type`, `amount`, `payment_date`, `status`, `dealer_note`, `created_at`)
SELECT
  o.`dealer_id`,
  o.`id`,
  'havale',
  ROUND(o.`grand_total` - COALESCE(SUM(CASE WHEN p.`status` = 'onaylandi' THEN p.`amount` ELSE 0 END), 0), 2) AS `amount`,
  DATE(o.`created_at`),
  'bekliyor',
  'Siparis sirasinda Havale / EFT secildi.',
  NOW()
FROM `b2b_orders` o
LEFT JOIN `b2b_payments` p ON p.`order_id` = o.`id`
WHERE o.`payment_method` = 'havale_eft'
  AND o.`status` NOT IN ('iptal', 'iade')
  AND o.`payment_status` <> 'odendi'
GROUP BY o.`id`, o.`dealer_id`, o.`grand_total`, o.`created_at`
HAVING `amount` > 0.01
   AND SUM(CASE WHEN p.`status` = 'bekliyor' THEN 1 ELSE 0 END) = 0;
=======
-- Migration 018: Sabit tutar fiyat ayarlama (price_adjust)
--
-- KULLANIM:
--   "Liste 1 = Baz Liste + 5 TL"
--   → b2b_price_lists.price_adjust = 5.00
--   → Tüm ürünlere otomatik olarak standart fiyat + 5 TL uygulanır
--
-- HESAPLAMA ÖNCELİĞİ (üstten):
--   1. b2b_price_list_items.price (sabit override)     → varsa direkt kullan
--   2. b2b_price_list_items.discount_percent           → ürün-bazlı yüzde
--   3. b2b_price_list_items.price_adjust               → ürün-bazlı tutar ek/eksilt
--   4. b2b_price_lists.discount_percent (liste geneli) → liste-geneli yüzde
--   5. b2b_price_lists.price_adjust (liste geneli)     → liste-geneli tutar ek/eksilt  ← YENİ
--   6. Standart fiyat (b2b_products.base_price)        → fallback
--
-- Pozitif değer = ek (zam), Negatif değer = indirim

ALTER TABLE `b2b_price_lists`
  ADD COLUMN IF NOT EXISTS `price_adjust` DECIMAL(10,2) DEFAULT NULL
  COMMENT 'Tüm ürünlere uygulanacak sabit tutar ek/eksilt (örn: +5.00 = baz fiyat + 5 TL). NULL = uygulanmaz.';

ALTER TABLE `b2b_price_list_items`
  ADD COLUMN IF NOT EXISTS `price_adjust` DECIMAL(10,2) DEFAULT NULL
  COMMENT 'Ürün-bazlı sabit tutar ek/eksilt — liste genelini override eder. NULL = liste geneli kullanılır.';

>>>>>>> 6d11536 (feat: fiyat listelerinde 'Tutar Ek/İndirim' kuralı — Liste 1 = Baz + 5 TL)
