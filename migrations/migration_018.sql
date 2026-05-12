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
