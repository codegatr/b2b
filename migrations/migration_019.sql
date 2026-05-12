-- Migration 019: Odenmis siparislerin cari borc/alacak hareketlerini kapat.
-- Kart veya onayli tahsilat ile odenen siparislerde borc ve alacak satirlari
-- acik kaldigi icin vadesi gecen hesaplari yanlis sisiyordu.

UPDATE `b2b_ledger` l
JOIN (
  SELECT
    ol.`reference_id` AS `order_id`,
    SUM(ol.`amount`) AS `debt_amount`,
    COALESCE((
      SELECT SUM(p.`amount`)
      FROM `b2b_payments` p
      WHERE p.`order_id` = ol.`reference_id`
        AND p.`status` = 'onaylandi'
    ), 0) AS `paid_amount`
  FROM `b2b_ledger` ol
  WHERE ol.`reference_type` = 'order'
    AND ol.`type` = 'borc'
  GROUP BY ol.`reference_id`
) x ON x.`order_id` = l.`reference_id`
SET l.`is_closed` = 1,
    l.`closed_at` = COALESCE(l.`closed_at`, NOW())
WHERE l.`reference_type` = 'order'
  AND l.`type` = 'borc'
  AND l.`is_closed` = 0
  AND x.`paid_amount` >= x.`debt_amount` - 0.01;

UPDATE `b2b_ledger` l
JOIN `b2b_payments` p ON p.`id` = l.`reference_id`
JOIN `b2b_ledger` ol ON ol.`reference_type` = 'order'
  AND ol.`reference_id` = p.`order_id`
  AND ol.`type` = 'borc'
  AND ol.`is_closed` = 1
SET l.`is_closed` = 1,
    l.`closed_at` = COALESCE(l.`closed_at`, NOW())
WHERE l.`reference_type` = 'payment'
  AND l.`type` = 'alacak'
  AND l.`is_closed` = 0
  AND p.`status` = 'onaylandi';
