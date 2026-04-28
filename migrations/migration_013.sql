-- Migration 013: b2b_ledger.closed_at kolonu
-- Audit için: kayıt ne zaman kapatıldığını saklar (is_closed=1 yapılırken NOW() set edilir).

ALTER TABLE `b2b_ledger`
  ADD COLUMN IF NOT EXISTS `closed_at` datetime DEFAULT NULL AFTER `is_closed`;
