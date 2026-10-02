-- ============================================================
--  R&R Sweet Bites  -  MySQL / MariaDB (XAMPP) database
--  For: customer-orders.php, order-confirmation.php,
--       quotation-composer.php
--
--  How to use: phpMyAdmin -> Import -> choose this file -> Go.
--  It creates the database `rr_sweet_bites` if needed.
--
--  WARNING: running it again DROPS and recreates the tables below,
--  so every row in them goes back to the sample data. That makes it
--  handy as a "reset" button while testing.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `rr_sweet_bites`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;
USE `rr_sweet_bites`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `quotation_revisions`;
DROP TABLE IF EXISTS `quotation_items`;
DROP TABLE IF EXISTS `quotations`;
DROP TABLE IF EXISTS `customers`;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
--  customers
-- ------------------------------------------------------------
CREATE TABLE `customers` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL,
  `initials`  VARCHAR(5)   NOT NULL,
  `email`     VARCHAR(160) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  quotations
-- ------------------------------------------------------------
CREATE TABLE `quotations` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_no`         VARCHAR(40)  NOT NULL,
  `reference_id`   VARCHAR(40)  NOT NULL,
  `customer_id`    INT UNSIGNED NOT NULL,
  `cake_type`      VARCHAR(80)  NOT NULL,
  `cake_size`      VARCHAR(60)  NOT NULL,
  `delivery_date`  DATE         NOT NULL,
  `complexity`     VARCHAR(30)  NOT NULL DEFAULT 'Moderate',
  `status`         ENUM('issued','accepted','revision_requested') NOT NULL DEFAULT 'issued',
  `issued_at`      DATE         NOT NULL,
  `valid_until`    DATE         NOT NULL,
  `admin_initials` VARCHAR(5)   NOT NULL DEFAULT 'AB',
  `admin_notes`    TEXT         DEFAULT NULL,
  `accepted_at`    DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotations_ref_no` (`ref_no`),
  KEY `idx_quotations_customer` (`customer_id`),
  CONSTRAINT `fk_quotations_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  quotation_items  (line items shown in the quotation table)
-- ------------------------------------------------------------
CREATE TABLE `quotation_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quotation_id` INT UNSIGNED NOT NULL,
  `description`  VARCHAR(160) NOT NULL,
  `qty`          INT          DEFAULT NULL,          -- NULL = counts as 1
  `unit_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_items_quotation` (`quotation_id`, `sort_order`),
  CONSTRAINT `fk_items_quotation`
    FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  quotation_revisions  ("Request Revision" messages)
-- ------------------------------------------------------------
CREATE TABLE `quotation_revisions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `quotation_id` INT UNSIGNED NOT NULL,
  `message`      TEXT         NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_revisions_quotation` (`quotation_id`),
  CONSTRAINT `fk_revisions_quotation`
    FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  orders
--  stage = order journey tracker:
--    1 Payment Verified, 2 In Production, 3 Quality Check,
--    4 Ready for Delivery/Pick up, 5 Delivered, 6 = everything complete
-- ------------------------------------------------------------
CREATE TABLE `orders` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`            VARCHAR(40)  NOT NULL,
  `quotation_id`        INT UNSIGNED DEFAULT NULL,
  `customer_id`         INT UNSIGNED NOT NULL,
  `title`               VARCHAR(160) NOT NULL,
  `occasion`            VARCHAR(80)  NOT NULL,
  `cake_style`          TEXT         NOT NULL,
  `order_date`          DATE         NOT NULL,
  `est_delivery_date`   DATE         NOT NULL,
  `total_amount`        DECIMAL(10,2) NOT NULL,
  `downpayment_percent` TINYINT UNSIGNED NOT NULL DEFAULT 20,
  `stage`               TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_no` (`order_no`),
  KEY `idx_orders_customer`  (`customer_id`),
  KEY `idx_orders_quotation` (`quotation_id`),
  CONSTRAINT `fk_orders_customer`
    FOREIGN KEY (`customer_id`)  REFERENCES `customers` (`id`),
  CONSTRAINT `fk_orders_quotation`
    FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  payments  (GCash / bank transfer only, proof file required)
-- ------------------------------------------------------------
CREATE TABLE `payments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `method`       ENUM('gcash','bank') NOT NULL,
  `amount`       DECIMAL(10,2) NOT NULL,
  `reference_no` VARCHAR(40)  NOT NULL,
  `proof_file`   VARCHAR(120) NOT NULL,
  `status`       ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_method_ref` (`method`, `reference_no`),
  KEY `idx_payments_order` (`order_id`),
  CONSTRAINT `fk_payments_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  Sample data
-- ============================================================

INSERT INTO `customers` (`full_name`, `initials`, `email`) VALUES
  ('Carla Navarro', 'CN', 'carla@example.com');

INSERT INTO `quotations`
  (`ref_no`, `reference_id`, `customer_id`, `cake_type`, `cake_size`, `delivery_date`,
   `complexity`, `status`, `issued_at`, `valid_until`, `admin_initials`, `admin_notes`)
VALUES
  ('Q-2024-0847', 'RQ-2041', 1, 'Birthday Cake', '8" + 6"', '2026-11-18',
   'Moderate', 'issued', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'AB',
   'Price includes strawberry frosting, chocolate base and vanilla fillings.');

INSERT INTO `quotation_items` (`quotation_id`, `description`, `qty`, `unit_price`, `sort_order`) VALUES
  (1, 'Moist Cake Base', NULL, 3500.00, 1),
  (1, 'Icing',           NULL, 2000.00, 2),
  (1, 'Tier',            2,    2500.00, 3),
  (1, 'Layer',           3,    1000.00, 4),
  (1, 'Size',            NULL, 1500.00, 5);

-- Order 1: RR-2041        -> waiting for the 20% downpayment (stage 1)
-- Order 2: ORD-2024-0423  -> downpayment verified, in production (stage 2)
INSERT INTO `orders`
  (`order_no`, `quotation_id`, `customer_id`, `title`, `occasion`, `cake_style`,
   `order_date`, `est_delivery_date`, `total_amount`, `downpayment_percent`, `stage`)
VALUES
  ('RR-2041', 1, 1, '2-tier Custom Birthday Cake', 'Birthday',
   'Birthday Cake (2 Tier - 8", + 6", 3 Layers) with Strawberry frosting, Chocolate Base, Vanilla fillings.',
   CURDATE(), '2026-11-18', 15000.00, 20, 1),
  ('ORD-2024-0423', NULL, 1, '2-tier Custom Birthday Cake', 'Birthday',
   'Birthday Cake (2 Tier - 8", + 6", 3 Layers) with Strawberry frosting, Chocolate Base, Vanilla fillings.',
   CURDATE(), '2026-11-18', 15000.00, 20, 2);

INSERT INTO `payments`
  (`order_id`, `method`, `amount`, `reference_no`, `proof_file`, `status`, `created_at`, `verified_at`)
VALUES
  (2, 'gcash', 3000.00, '2024042300001', 'seed-placeholder.png', 'verified', NOW(), NOW());
