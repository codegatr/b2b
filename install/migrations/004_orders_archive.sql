-- Migration 004: Sipariş arşiv kolonu
ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `is_archived` tinyint(1) DEFAULT 0 AFTER `updated_at`,
  ADD COLUMN IF NOT EXISTS `archived_by` int(11) DEFAULT NULL AFTER `is_archived`,
  ADD COLUMN IF NOT EXISTS `archived_at` datetime DEFAULT NULL AFTER `archived_by`;
