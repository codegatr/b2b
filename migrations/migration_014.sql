-- Migration 014: b2b_order_items.delivered_qty kolonu
-- İrsaliye/sevkiyat fişinde "Teslim Miktarı" alanı için.
-- Sipariş edilen miktar (qty) ile teslim edilen miktar (delivered_qty) farklı olabilir
-- (eksik teslimat senaryosu).
-- Default qty ile aynı — tam teslimat varsayılır, eksik teslim edildi ise admin
-- manuel olarak günceller.

ALTER TABLE `b2b_order_items`
  ADD COLUMN IF NOT EXISTS `delivered_qty` decimal(10,2) DEFAULT NULL
  COMMENT 'Teslim edilen miktar — NULL ise henüz teslim edilmedi, qty ile eşitse tam teslim, küçükse eksik'
  AFTER `qty`;
