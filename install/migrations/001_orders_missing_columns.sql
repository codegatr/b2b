-- Migration: b2b_orders tablosuna eksik kolonlar eklendi
-- Tarih: 2026-04-08

ALTER TABLE `b2b_orders`
  ADD COLUMN IF NOT EXISTS `cancel_requested`    tinyint(1)   DEFAULT 0          AFTER `admin_note`,
  ADD COLUMN IF NOT EXISTS `cancel_requested_at` datetime     DEFAULT NULL        AFTER `cancel_requested`,
  ADD COLUMN IF NOT EXISTS `cancel_reason`       text         DEFAULT NULL        AFTER `cancel_requested_at`,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_by`  int(11)      DEFAULT NULL        AFTER `cancel_reason`,
  ADD COLUMN IF NOT EXISTS `cancel_reviewed_at`  datetime     DEFAULT NULL        AFTER `cancel_reviewed_by`,
  ADD COLUMN IF NOT EXISTS `cargo_company`        varchar(100) DEFAULT NULL        AFTER `cancel_reviewed_at`,
  ADD COLUMN IF NOT EXISTS `tracking_number`      varchar(100) DEFAULT NULL        AFTER `cargo_company`,
  ADD COLUMN IF NOT EXISTS `delivered_at`         datetime     DEFAULT NULL        AFTER `tracking_number`;
