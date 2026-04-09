-- Migration 002: Kapalı ledger kayıtları temizliği
-- Siparişi silinmiş ama is_closed=1 olan kayıtları sil
-- (reference_id'si artık var olmayan sipariş kaydı)
DELETE FROM `b2b_ledger`
WHERE `reference_type` = 'order'
  AND `is_closed` = 1
  AND `reference_id` NOT IN (SELECT `id` FROM `b2b_orders`);
