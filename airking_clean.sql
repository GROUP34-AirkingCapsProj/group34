-- ============================================================
-- AirKing Inventory System — Clean Database
-- Stack: Laravel 11 + Vue 3 + Inertia + XAMPP / MariaDB
-- Notes:
--   • No data — fresh install ready for php artisan migrate:fresh --seed
--   • branches and warehouses REMOVED — locations table handles all
--     physical places via location_type enum
--   • audit_log and activity_log REMOVED — audit_trail is the single
--     source of truth for all system activity
--   • transactions legacy table REMOVED
--   • inventory FK constraints added
--   • adjustments.adjusted_by is now a proper FK to users
--   • status_lookup unified — one row covers all modules via
--     status_category, names kept generic (Active/Inactive/Pending…)
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- DROP ORDER (children before parents)
-- ============================================================

DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `personal_access_tokens`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `jobs`;

DROP TABLE IF EXISTS `audit_trail`;
DROP TABLE IF EXISTS `login_activities`;

DROP TABLE IF EXISTS `inventory_scan_events`;
DROP TABLE IF EXISTS `barcode_scans`;

DROP TABLE IF EXISTS `transfer_details`;
DROP TABLE IF EXISTS `tbl_transfer_tracking`;
DROP TABLE IF EXISTS `transfers`;

DROP TABLE IF EXISTS `issuance_details`;
DROP TABLE IF EXISTS `issuances`;

DROP TABLE IF EXISTS `adjustment_details`;
DROP TABLE IF EXISTS `adjustments`;

DROP TABLE IF EXISTS `receiving_details`;
DROP TABLE IF EXISTS `receivings`;

DROP TABLE IF EXISTS `purchase_return_details`;
DROP TABLE IF EXISTS `purchase_returns`;

DROP TABLE IF EXISTS `purchase_order_details`;
DROP TABLE IF EXISTS `purchase_orders`;

DROP TABLE IF EXISTS `sales_details`;
DROP TABLE IF EXISTS `delivery_receipts`;
DROP TABLE IF EXISTS `sales`;

DROP TABLE IF EXISTS `restock_recommendations`;
DROP TABLE IF EXISTS `profit_loss`;

DROP TABLE IF EXISTS `item_serial`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `pending_products`;

DROP TABLE IF EXISTS `supplier_product`;
DROP TABLE IF EXISTS `suppliers`;

DROP TABLE IF EXISTS `customers`;

DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `model_lookup`;
DROP TABLE IF EXISTS `subcategory_lookup`;

DROP TABLE IF EXISTS `users`;

DROP TABLE IF EXISTS `locations`;

DROP TABLE IF EXISTS `category_lookup`;
DROP TABLE IF EXISTS `brand_lookup`;
DROP TABLE IF EXISTS `unit_lookup`;
DROP TABLE IF EXISTS `status_lookup`;
DROP TABLE IF EXISTS `roles`;

DROP TABLE IF EXISTS `migrations`;

-- ============================================================
-- LOOKUP / REFERENCE TABLES  (no FKs — seeded first)
-- ============================================================

CREATE TABLE `roles` (
  `role_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_name`   VARCHAR(50)     NOT NULL,
  `description` TEXT            DEFAULT NULL,
  `created_at`  TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `roles_role_name_unique` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────
-- status_lookup
--   status_category helps filter by context in code.
--   Keep names generic enough to share across modules.
-- ─────────────────────────────────────────────────────────
CREATE TABLE `status_lookup` (
  `status_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status_name`     VARCHAR(50)     NOT NULL,
  `status_category` VARCHAR(50)     NOT NULL,
  `is_active`       TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`status_id`),
  UNIQUE KEY `status_lookup_name_category_unique` (`status_name`, `status_category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unit_lookup` (
  `unit_id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_name`         VARCHAR(50)     NOT NULL,
  `unit_abbreviation` VARCHAR(10)     NOT NULL,
  `created_at`        TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`        TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`unit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `brand_lookup` (
  `brand_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_name` VARCHAR(100)    NOT NULL,
  `description` TEXT           DEFAULT NULL,
  `created_at` TIMESTAMP       NULL DEFAULT NULL,
  `updated_at` TIMESTAMP       NULL DEFAULT NULL,
  `deleted_at` TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`brand_id`),
  UNIQUE KEY `brand_lookup_brand_name_unique` (`brand_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `category_lookup` (
  `category_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_name` VARCHAR(100)    NOT NULL,
  `category_type` VARCHAR(50)     NOT NULL COMMENT 'product | accessory | consumable',
  `description`   TEXT            DEFAULT NULL,
  `created_at`    TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP       NULL DEFAULT NULL,
  `deleted_at`    TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_lookup_category_name_unique` (`category_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subcategory_lookup` (
  `subcategory_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`      BIGINT UNSIGNED NOT NULL,
  `subcategory_name` VARCHAR(100)    NOT NULL,
  `is_serialized`    TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`       TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`subcategory_id`),
  UNIQUE KEY `subcategory_lookup_cat_name_unique` (`category_id`, `subcategory_name`),
  CONSTRAINT `subcategory_lookup_category_id_foreign`
    FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_type_lookup` (
  `transaction_type_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_name`    VARCHAR(50)     NOT NULL,
  `description`         TEXT            DEFAULT NULL,
  `created_at`          TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`          TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`transaction_type_id`),
  UNIQUE KEY `transaction_type_lookup_name_unique` (`transaction_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LOCATIONS  (replaces branches + warehouses)
--   location_type: 'warehouse' | 'showroom' | 'branch_office'
--   parent_location_id: allows a showroom to belong to a
--   main warehouse hub if needed (nullable = top-level)
-- ============================================================

CREATE TABLE `locations` (
  `location_id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_location_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'Self-ref for sub-locations',
  `location_code`      VARCHAR(20)     NOT NULL,
  `location_name`      VARCHAR(255)    NOT NULL,
  `location_type`      ENUM('warehouse','showroom','branch_office') NOT NULL DEFAULT 'warehouse',
  `address`            TEXT            DEFAULT NULL,
  `city`               VARCHAR(100)    DEFAULT NULL,
  `province`           VARCHAR(100)    DEFAULT NULL,
  `region`             VARCHAR(100)    DEFAULT NULL,
  `contact_number`     VARCHAR(50)     DEFAULT NULL,
  `email`              VARCHAR(255)    DEFAULT NULL,
  `manager_name`       VARCHAR(255)    DEFAULT NULL,
  `capacity`           INT             DEFAULT NULL,
  `opening_date`       DATE            DEFAULT NULL,
  `status_id`          BIGINT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`         TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `locations_location_code_unique` (`location_code`),
  KEY `locations_parent_location_id_foreign` (`parent_location_id`),
  KEY `locations_status_id_foreign` (`status_id`),
  KEY `locations_type_idx` (`location_type`),
  CONSTRAINT `locations_parent_location_id_foreign`
    FOREIGN KEY (`parent_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `locations_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS
-- ============================================================

CREATE TABLE `users` (
  `user_id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`            VARCHAR(255)    NOT NULL,
  `last_name`             VARCHAR(255)    NOT NULL,
  `username`              VARCHAR(255)    NOT NULL,
  `email`                 VARCHAR(255)    NOT NULL,
  `phone`                 VARCHAR(50)     DEFAULT NULL,
  `role_id`               BIGINT UNSIGNED NOT NULL,
  `location_id`           BIGINT UNSIGNED DEFAULT NULL COMMENT 'Primary assigned location',
  `status_id`             BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `email_verified_at`     TIMESTAMP       NULL DEFAULT NULL,
  `password_hash`         VARCHAR(255)    NOT NULL,
  `remember_token`        VARCHAR(100)    DEFAULT NULL,
  `assigned_location_ids` LONGTEXT        DEFAULT NULL COMMENT 'JSON array of extra locations',
  `allowed_panels`        LONGTEXT        DEFAULT NULL COMMENT 'JSON array of panel keys',
  `created_at`            TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`            TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `users_email_unique`    (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_role_id_foreign`     (`role_id`),
  KEY `users_status_id_foreign`   (`status_id`),
  KEY `users_location_id_foreign` (`location_id`),
  CONSTRAINT `users_role_id_foreign`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `users_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`),
  CONSTRAINT `users_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PRODUCTS
-- ============================================================

CREATE TABLE `model_lookup` (
  `model_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id`       BIGINT UNSIGNED DEFAULT NULL,
  `subcategory_id` BIGINT UNSIGNED DEFAULT NULL,
  `model_code`     VARCHAR(100)    NOT NULL,
  `variant`        VARCHAR(100)    DEFAULT NULL,
  `capacity`       VARCHAR(50)     DEFAULT NULL,
  `status_id`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`     TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`model_id`),
  UNIQUE KEY `model_lookup_brand_model_unique` (`brand_id`, `model_code`),
  KEY `model_lookup_subcategory_id_foreign` (`subcategory_id`),
  KEY `model_lookup_status_id_foreign` (`status_id`),
  CONSTRAINT `model_lookup_brand_id_foreign`
    FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  CONSTRAINT `model_lookup_subcategory_id_foreign`
    FOREIGN KEY (`subcategory_id`) REFERENCES `subcategory_lookup` (`subcategory_id`) ON DELETE SET NULL,
  CONSTRAINT `model_lookup_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (
  `product_id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `unit_id`                 BIGINT UNSIGNED  DEFAULT NULL,
  `category_id`             BIGINT UNSIGNED  DEFAULT NULL,
  `brand_id`                BIGINT UNSIGNED  DEFAULT NULL,
  `model_id`                BIGINT UNSIGNED  DEFAULT NULL,
  `barcode`                 VARCHAR(100)     DEFAULT NULL,
  `product_code`            VARCHAR(50)      NOT NULL,
  `product_name`            VARCHAR(255)     NOT NULL,
  `product_type`            VARCHAR(20)      NOT NULL DEFAULT 'appliance'
                              COMMENT 'appliance | consumable',
  `capacity_rating`         VARCHAR(20)      DEFAULT NULL
                              COMMENT '1HP | 1.5HP | 2HP | 2.5HP | 3HP | 5HP',
  `variant`                 VARCHAR(100)     DEFAULT NULL,
  `description`             TEXT             DEFAULT NULL,
  `pieces_per_package`      INT UNSIGNED     DEFAULT NULL
                              COMMENT 'For consumables — units per package',
  `unit_price`              DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `cost_price`              DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
  `warranty_period_months`  INT              NOT NULL DEFAULT 0,
  `recommended_stocks`      INT              NOT NULL DEFAULT 0,
  `safety_stock`            INT UNSIGNED     DEFAULT NULL,
  `max_stock_level`         INT UNSIGNED     DEFAULT NULL,
  `quantity`                INT              NOT NULL DEFAULT 0,
  `status_id`               BIGINT UNSIGNED  DEFAULT NULL,
  `created_at`              TIMESTAMP        NULL DEFAULT NULL,
  `updated_at`              TIMESTAMP        NULL DEFAULT NULL,
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `products_product_code_unique` (`product_code`),
  KEY `products_unit_id_foreign`     (`unit_id`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_brand_id_foreign`    (`brand_id`),
  KEY `products_model_id_foreign`    (`model_id`),
  KEY `products_status_id_foreign`   (`status_id`),
  CONSTRAINT `products_unit_id_foreign`
    FOREIGN KEY (`unit_id`) REFERENCES `unit_lookup` (`unit_id`) ON DELETE SET NULL,
  CONSTRAINT `products_category_id_foreign`
    FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `products_brand_id_foreign`
    FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  CONSTRAINT `products_model_id_foreign`
    FOREIGN KEY (`model_id`) REFERENCES `model_lookup` (`model_id`) ON DELETE SET NULL,
  CONSTRAINT `products_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SUPPLIERS & CUSTOMERS
-- ============================================================

CREATE TABLE `suppliers` (
  `supplier_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_name`  VARCHAR(255)    NOT NULL,
  `contact_person` VARCHAR(255)    DEFAULT NULL,
  `contact_number` VARCHAR(20)     DEFAULT NULL,
  `email`          VARCHAR(100)    DEFAULT NULL,
  `address`        TEXT            DEFAULT NULL,
  `origin`         VARCHAR(20)     NOT NULL DEFAULT 'Local'
                     COMMENT 'Local | International',
  `region`         VARCHAR(100)    DEFAULT NULL,
  `tin`            VARCHAR(50)     DEFAULT NULL,
  `status_id`      BIGINT UNSIGNED DEFAULT NULL,
  `notes`          TEXT            DEFAULT NULL,
  `created_at`     TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP       NULL DEFAULT NULL,
  `deleted_at`     TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`supplier_id`),
  KEY `suppliers_status_id_foreign` (`status_id`),
  CONSTRAINT `suppliers_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `supplier_product` (
  `supplier_prod_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id`      BIGINT UNSIGNED NOT NULL,
  `product_id`       BIGINT UNSIGNED NOT NULL,
  `product_price`    DECIMAL(10,2)   DEFAULT NULL,
  `currency`         VARCHAR(10)     NOT NULL DEFAULT 'PHP',
  `status_id`        BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`       TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`supplier_prod_id`),
  UNIQUE KEY `supplier_product_sup_prod_unique` (`supplier_id`, `product_id`),
  KEY `supplier_product_product_id_foreign`  (`product_id`),
  KEY `supplier_product_status_id_foreign`   (`status_id`),
  CONSTRAINT `supplier_product_supplier_id_foreign`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_product_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_product_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `customer_id`        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `customer_code`      VARCHAR(50)      DEFAULT NULL,
  `customer_type`      VARCHAR(100)     DEFAULT NULL COMMENT 'Ordinary | Business',
  `customer_name`      VARCHAR(255)     DEFAULT NULL,
  `contact_number`     VARCHAR(20)      DEFAULT NULL,
  `email`              VARCHAR(100)     DEFAULT NULL,
  `company_name`       VARCHAR(255)     DEFAULT NULL,
  `address`            TEXT             DEFAULT NULL,
  `city`               VARCHAR(100)     DEFAULT NULL,
  `province`           VARCHAR(100)     DEFAULT NULL,
  `region`             VARCHAR(100)     DEFAULT NULL,
  `tin`                VARCHAR(100)     DEFAULT NULL,
  `notes`              TEXT             DEFAULT NULL,
  `credit_limit`       DECIMAL(12,2)    DEFAULT NULL,
  `outstanding_balance` DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `status_id`          BIGINT UNSIGNED  DEFAULT NULL,
  `created_at`         TIMESTAMP        NULL DEFAULT NULL,
  `updated_at`         TIMESTAMP        NULL DEFAULT NULL,
  `deleted_at`         TIMESTAMP        NULL DEFAULT NULL,
  PRIMARY KEY (`customer_id`),
  KEY `customers_status_id_foreign` (`status_id`),
  CONSTRAINT `customers_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INVENTORY
-- ============================================================

CREATE TABLE `inventory` (
  `inventory_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`       BIGINT UNSIGNED NOT NULL,
  `product_id`        BIGINT UNSIGNED NOT NULL,
  `status_id`         BIGINT UNSIGNED DEFAULT NULL,
  `quantity_on_hand`  INT             NOT NULL DEFAULT 0,
  `available_quantity` INT            NOT NULL DEFAULT 0,
  `reorder_level`     INT             NOT NULL DEFAULT 0,
  `condition`         VARCHAR(50)     DEFAULT NULL,
  `last_updated`      DATETIME        DEFAULT NULL,
  `created_at`        TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`        TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `inventory_location_product_unique` (`location_id`, `product_id`),
  KEY `inventory_product_id_foreign`  (`product_id`),
  KEY `inventory_status_id_foreign`   (`status_id`),
  CONSTRAINT `inventory_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `item_serial` (
  `serial_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    BIGINT UNSIGNED DEFAULT NULL,
  `location_id`   BIGINT UNSIGNED DEFAULT NULL,
  `serial_number` VARCHAR(100)    NOT NULL,
  `serial_type`   VARCHAR(50)     DEFAULT NULL COMMENT 'received | issued | transferred',
  `status_id`     BIGINT UNSIGNED DEFAULT NULL,
  `created_at`    TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`    TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`serial_id`),
  UNIQUE KEY `item_serial_serial_number_unique` (`serial_number`),
  KEY `item_serial_product_id_foreign`  (`product_id`),
  KEY `item_serial_location_id_foreign` (`location_id`),
  KEY `item_serial_status_id_foreign`   (`status_id`),
  CONSTRAINT `item_serial_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pending_products` (
  `pending_product_id`   BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `barcode`              VARCHAR(100)     NOT NULL,
  `registration_kind`    VARCHAR(24)      NOT NULL DEFAULT 'consumable'
                           COMMENT 'consumable | appliance',
  `category_id`          BIGINT UNSIGNED  DEFAULT NULL,
  `brand_id`             BIGINT UNSIGNED  DEFAULT NULL,
  `supply_type`          VARCHAR(120)     DEFAULT NULL,
  `packaging_unit`       VARCHAR(32)      DEFAULT NULL,
  `quantity_per_package` DECIMAL(12,2)    DEFAULT NULL,
  `generated_name`       VARCHAR(255)     NOT NULL,
  `appliance_snapshot`   LONGTEXT         DEFAULT NULL
                           CHECK (json_valid(`appliance_snapshot`)),
  `opening_quantity`     INT UNSIGNED     DEFAULT NULL,
  `opening_location_id`  BIGINT UNSIGNED  DEFAULT NULL,
  `created_by`           BIGINT UNSIGNED  DEFAULT NULL,
  `status`               VARCHAR(24)      NOT NULL DEFAULT 'pending'
                           COMMENT 'pending | approved | rejected',
  `final_product_name`   VARCHAR(255)     DEFAULT NULL,
  `unit_price`           DECIMAL(12,2)    DEFAULT NULL,
  `cost_price`           DECIMAL(12,2)    DEFAULT NULL,
  `approved_by`          BIGINT UNSIGNED  DEFAULT NULL,
  `approved_at`          TIMESTAMP        NULL DEFAULT NULL,
  `product_id`           BIGINT UNSIGNED  DEFAULT NULL,
  `created_at`           TIMESTAMP        NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP        NULL DEFAULT NULL,
  PRIMARY KEY (`pending_product_id`),
  UNIQUE KEY `pending_products_barcode_unique` (`barcode`),
  KEY `pending_products_status_created_at_index`    (`status`, `created_at`),
  KEY `pending_products_category_id_foreign`        (`category_id`),
  KEY `pending_products_brand_id_foreign`           (`brand_id`),
  KEY `pending_products_created_by_foreign`         (`created_by`),
  KEY `pending_products_approved_by_foreign`        (`approved_by`),
  KEY `pending_products_product_id_foreign`         (`product_id`),
  KEY `pending_products_opening_location_id_foreign`(`opening_location_id`),
  CONSTRAINT `pending_products_category_id_foreign`
    FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_brand_id_foreign`
    FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_opening_location_id_foreign`
    FOREIGN KEY (`opening_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PURCHASE ORDERS
-- ============================================================

CREATE TABLE `purchase_orders` (
  `po_id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `supplier_id`            BIGINT UNSIGNED DEFAULT NULL,
  `location_id`            BIGINT UNSIGNED DEFAULT NULL,
  `po_number`              VARCHAR(50)     NOT NULL,
  `order_date`             DATE            DEFAULT NULL,
  `expected_delivery_date` DATE            DEFAULT NULL,
  `total_amount`           DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `grand_total`            DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `status_id`              BIGINT UNSIGNED DEFAULT NULL,
  `ordered_by`             BIGINT UNSIGNED DEFAULT NULL,
  `created_by`             BIGINT UNSIGNED DEFAULT NULL,
  `approved_by`            BIGINT UNSIGNED DEFAULT NULL,
  `approval_notes`         TEXT            DEFAULT NULL,
  `received_by`            BIGINT UNSIGNED DEFAULT NULL,
  `created_at`             TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`             TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`po_id`),
  UNIQUE KEY `purchase_orders_po_number_unique` (`po_number`),
  KEY `purchase_orders_supplier_id_foreign`  (`supplier_id`),
  KEY `purchase_orders_location_id_foreign`  (`location_id`),
  KEY `purchase_orders_ordered_by_foreign`   (`ordered_by`),
  KEY `purchase_orders_created_by_foreign`   (`created_by`),
  KEY `purchase_orders_approved_by_foreign`  (`approved_by`),
  KEY `purchase_orders_received_by_foreign`  (`received_by`),
  KEY `purchase_orders_status_id_foreign`    (`status_id`),
  CONSTRAINT `purchase_orders_supplier_id_foreign`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_ordered_by_foreign`
    FOREIGN KEY (`ordered_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_received_by_foreign`
    FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_order_details` (
  `po_detail_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_id`            BIGINT UNSIGNED DEFAULT NULL,
  `product_id`       BIGINT UNSIGNED DEFAULT NULL,
  `quantity_ordered` INT             NOT NULL DEFAULT 0,
  `unit_price`       DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `subtotal`         DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `approved_by`      BIGINT UNSIGNED DEFAULT NULL,
  `created_at`       TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`       TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`po_detail_id`),
  KEY `purchase_order_details_po_id_foreign`      (`po_id`),
  KEY `purchase_order_details_product_id_foreign` (`product_id`),
  KEY `purchase_order_details_approved_by_foreign` (`approved_by`),
  CONSTRAINT `purchase_order_details_po_id_foreign`
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_details_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RECEIVINGS
-- ============================================================

CREATE TABLE `receivings` (
  `receiving_id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_id`                    BIGINT UNSIGNED DEFAULT NULL,
  `supplier_id`              BIGINT UNSIGNED DEFAULT NULL,
  `location_id`              BIGINT UNSIGNED DEFAULT NULL,
  `receiving_number`         VARCHAR(50)     NOT NULL,
  `receiving_date`           DATE            DEFAULT NULL,
  `received_by`              BIGINT UNSIGNED DEFAULT NULL,
  `total_quantity_received`  INT             NOT NULL DEFAULT 0,
  `total_quantity_damaged`   INT             NOT NULL DEFAULT 0,
  `created_at`               TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`               TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`receiving_id`),
  UNIQUE KEY `receivings_receiving_number_unique` (`receiving_number`),
  KEY `receivings_po_id_foreign`        (`po_id`),
  KEY `receivings_supplier_id_foreign`  (`supplier_id`),
  KEY `receivings_location_id_foreign`  (`location_id`),
  KEY `receivings_received_by_foreign`  (`received_by`),
  CONSTRAINT `receivings_po_id_foreign`
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_supplier_id_foreign`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_received_by_foreign`
    FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `receiving_details` (
  `receiving_detail_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receiving_id`        BIGINT UNSIGNED DEFAULT NULL,
  `po_detail_id`        BIGINT UNSIGNED DEFAULT NULL,
  `product_id`          BIGINT UNSIGNED DEFAULT NULL,
  `quantity_amount`     INT             NOT NULL DEFAULT 0,
  `prod_price`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `condition`           VARCHAR(100)    DEFAULT NULL,
  `created_at`          TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`          TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`receiving_detail_id`),
  KEY `receiving_details_receiving_id_foreign`  (`receiving_id`),
  KEY `receiving_details_po_detail_id_foreign`  (`po_detail_id`),
  KEY `receiving_details_product_id_foreign`    (`product_id`),
  CONSTRAINT `receiving_details_receiving_id_foreign`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE CASCADE,
  CONSTRAINT `receiving_details_po_detail_id_foreign`
    FOREIGN KEY (`po_detail_id`) REFERENCES `purchase_order_details` (`po_detail_id`) ON DELETE SET NULL,
  CONSTRAINT `receiving_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PURCHASE RETURNS
-- ============================================================

CREATE TABLE `purchase_returns` (
  `purchase_return_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `po_id`              BIGINT UNSIGNED DEFAULT NULL,
  `supplier_id`        BIGINT UNSIGNED DEFAULT NULL,
  `receiving_id`       BIGINT UNSIGNED DEFAULT NULL,
  `pr_number`          VARCHAR(50)     NOT NULL,
  `return_date`        DATE            DEFAULT NULL,
  `reason`             TEXT            DEFAULT NULL,
  `total_amount`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `status_id`          BIGINT UNSIGNED DEFAULT NULL,
  `requested_by`       BIGINT UNSIGNED DEFAULT NULL,
  `approved_by`        BIGINT UNSIGNED DEFAULT NULL,
  `created_at`         TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`         TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`purchase_return_id`),
  UNIQUE KEY `purchase_returns_pr_number_unique` (`pr_number`),
  KEY `purchase_returns_po_id_foreign`          (`po_id`),
  KEY `purchase_returns_supplier_id_foreign`    (`supplier_id`),
  KEY `purchase_returns_receiving_id_foreign`   (`receiving_id`),
  KEY `purchase_returns_status_id_foreign`      (`status_id`),
  KEY `purchase_returns_requested_by_foreign`   (`requested_by`),
  KEY `purchase_returns_approved_by_foreign`    (`approved_by`),
  CONSTRAINT `purchase_returns_po_id_foreign`
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_supplier_id_foreign`
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_receiving_id_foreign`
    FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_requested_by_foreign`
    FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `purchase_return_details` (
  `pr_detail_id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_return_id` BIGINT UNSIGNED DEFAULT NULL,
  `product_id`         BIGINT UNSIGNED DEFAULT NULL,
  `serial_id`          BIGINT UNSIGNED DEFAULT NULL,
  `quantity_returned`  INT             NOT NULL DEFAULT 0,
  `unit_cost`          DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `subtotal`           DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `condition`          VARCHAR(100)    DEFAULT NULL,
  `created_at`         TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`         TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`pr_detail_id`),
  KEY `purchase_return_details_purchase_return_id_foreign` (`purchase_return_id`),
  KEY `purchase_return_details_product_id_foreign`         (`product_id`),
  KEY `purchase_return_details_serial_id_foreign`          (`serial_id`),
  CONSTRAINT `purchase_return_details_purchase_return_id_foreign`
    FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`purchase_return_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_return_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_return_details_serial_id_foreign`
    FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ISSUANCES  (internal stock out)
-- ============================================================

CREATE TABLE `issuances` (
  `issuance_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`          BIGINT UNSIGNED DEFAULT NULL,
  `issuance_date`        DATE            DEFAULT NULL,
  `issuance_type`        VARCHAR(50)     DEFAULT NULL,
  `purpose`              TEXT            DEFAULT NULL,
  `issued_to_user_id`    BIGINT UNSIGNED DEFAULT NULL,
  `expected_return_date` DATE            DEFAULT NULL,
  `actual_return_date`   DATE            DEFAULT NULL,
  `issued_by`            BIGINT UNSIGNED DEFAULT NULL,
  `approved_by`          BIGINT UNSIGNED DEFAULT NULL,
  `status_id`            BIGINT UNSIGNED DEFAULT NULL,
  `created_at`           TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`issuance_id`),
  KEY `issuances_location_id_foreign`       (`location_id`),
  KEY `issuances_issued_to_user_id_foreign` (`issued_to_user_id`),
  KEY `issuances_issued_by_foreign`         (`issued_by`),
  KEY `issuances_approved_by_foreign`       (`approved_by`),
  KEY `issuances_status_id_foreign`         (`status_id`),
  CONSTRAINT `issuances_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_issued_to_user_id_foreign`
    FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_issued_by_foreign`
    FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `issuance_details` (
  `issuance_detail_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `issuance_id`        BIGINT UNSIGNED DEFAULT NULL,
  `product_id`         BIGINT UNSIGNED DEFAULT NULL,
  `quantity_issued`    INT             NOT NULL DEFAULT 0,
  `quantity_returned`  INT             NOT NULL DEFAULT 0,
  `condition_issued`   VARCHAR(50)     DEFAULT NULL,
  `condition_returned` VARCHAR(50)     DEFAULT NULL,
  `created_at`         TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`         TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`issuance_detail_id`),
  KEY `issuance_details_issuance_id_foreign` (`issuance_id`),
  KEY `issuance_details_product_id_foreign`  (`product_id`),
  CONSTRAINT `issuance_details_issuance_id_foreign`
    FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  CONSTRAINT `issuance_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TRANSFERS
-- ============================================================

CREATE TABLE `transfers` (
  `transfer_id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_location_id`          BIGINT UNSIGNED DEFAULT NULL,
  `to_location_id`            BIGINT UNSIGNED DEFAULT NULL,
  `transfer_number`           VARCHAR(50)     NOT NULL,
  `transfer_date`             DATE            DEFAULT NULL,
  `requested_by`              BIGINT UNSIGNED DEFAULT NULL,
  `approved_by`               BIGINT UNSIGNED DEFAULT NULL,
  `received_by`               BIGINT UNSIGNED DEFAULT NULL,
  `status_id`                 BIGINT UNSIGNED DEFAULT NULL,
  `total_quantity_transferred` INT UNSIGNED   NOT NULL DEFAULT 0,
  `total_quantity_received`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`                TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`                TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  UNIQUE KEY `transfers_transfer_number_unique` (`transfer_number`),
  KEY `transfers_from_location_id_foreign` (`from_location_id`),
  KEY `transfers_to_location_id_foreign`   (`to_location_id`),
  KEY `transfers_requested_by_foreign`     (`requested_by`),
  KEY `transfers_approved_by_foreign`      (`approved_by`),
  KEY `transfers_received_by_foreign`      (`received_by`),
  KEY `transfers_status_id_foreign`        (`status_id`),
  CONSTRAINT `transfers_from_location_id_foreign`
    FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_to_location_id_foreign`
    FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_requested_by_foreign`
    FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_received_by_foreign`
    FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transfer_details` (
  `transfer_detail_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_id`         BIGINT UNSIGNED DEFAULT NULL,
  `product_id`          BIGINT UNSIGNED DEFAULT NULL,
  `quantity_transferred` INT            NOT NULL DEFAULT 0,
  `quantity_received`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `created_at`          TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`          TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_detail_id`),
  KEY `transfer_details_transfer_id_foreign` (`transfer_id`),
  KEY `transfer_details_product_id_foreign`  (`product_id`),
  CONSTRAINT `transfer_details_transfer_id_foreign`
    FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE,
  CONSTRAINT `transfer_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tbl_transfer_tracking` (
  `tracking_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transfer_id`  BIGINT UNSIGNED DEFAULT NULL,
  `location_id`  BIGINT UNSIGNED DEFAULT NULL,
  `status_note`  VARCHAR(255)    DEFAULT NULL,
  `recorded_at`  DATETIME        DEFAULT NULL,
  `recorded_by`  BIGINT UNSIGNED DEFAULT NULL,
  `created_at`   TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`   TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`tracking_id`),
  KEY `tbl_transfer_tracking_transfer_id_foreign` (`transfer_id`),
  KEY `tbl_transfer_tracking_location_id_foreign` (`location_id`),
  KEY `tbl_transfer_tracking_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `tbl_transfer_tracking_transfer_id_foreign`
    FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE,
  CONSTRAINT `tbl_transfer_tracking_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `tbl_transfer_tracking_recorded_by_foreign`
    FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ADJUSTMENTS
-- ============================================================

CREATE TABLE `adjustments` (
  `adjustment_id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id`               BIGINT UNSIGNED DEFAULT NULL,
  `created_by`                BIGINT UNSIGNED DEFAULT NULL,
  `adjusted_by`               BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK — was varchar before, now fixed',
  `approved_by`               BIGINT UNSIGNED DEFAULT NULL,
  `adjustment_number`         VARCHAR(50)     NOT NULL,
  `adjustment_date`           DATE            DEFAULT NULL,
  `adjustment_type`           VARCHAR(50)     DEFAULT NULL,
  `total_variance_positive`   INT             NOT NULL DEFAULT 0,
  `total_variance_negative`   INT             NOT NULL DEFAULT 0,
  `notes`                     TEXT            DEFAULT NULL,
  `status_id`                 BIGINT UNSIGNED DEFAULT NULL,
  `created_at`                TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`                TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`adjustment_id`),
  UNIQUE KEY `adjustments_adjustment_number_unique` (`adjustment_number`),
  KEY `adjustments_location_id_foreign`  (`location_id`),
  KEY `adjustments_created_by_foreign`   (`created_by`),
  KEY `adjustments_adjusted_by_foreign`  (`adjusted_by`),
  KEY `adjustments_approved_by_foreign`  (`approved_by`),
  KEY `adjustments_status_id_foreign`    (`status_id`),
  CONSTRAINT `adjustments_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_created_by_foreign`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_adjusted_by_foreign`
    FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `adjustment_details` (
  `adjustment_detail_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `adjustment_id`        BIGINT UNSIGNED DEFAULT NULL,
  `product_id`           BIGINT UNSIGNED DEFAULT NULL,
  `add_quantity`         INT             NOT NULL DEFAULT 0,
  `deduct_quantity`      INT             NOT NULL DEFAULT 0,
  `reason`               TEXT            DEFAULT NULL,
  `created_at`           TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`adjustment_detail_id`),
  KEY `adjustment_details_adjustment_id_foreign` (`adjustment_id`),
  KEY `adjustment_details_product_id_foreign`    (`product_id`),
  CONSTRAINT `adjustment_details_adjustment_id_foreign`
    FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  CONSTRAINT `adjustment_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SALES
-- ============================================================

CREATE TABLE `sales` (
  `sales_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id`       BIGINT UNSIGNED DEFAULT NULL,
  `location_id`       BIGINT UNSIGNED DEFAULT NULL,
  `invoice_number`    VARCHAR(50)     NOT NULL,
  `order_date`        DATE            DEFAULT NULL,
  `sale_date`         DATE            DEFAULT NULL,
  `due_date`          DATE            DEFAULT NULL,
  `total_amount`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `payment_method`    VARCHAR(50)     DEFAULT NULL,
  `amount_paid`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `balance_due`       DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `payment_status_id` BIGINT UNSIGNED DEFAULT NULL,
  `sold_by`           BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`        TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`sales_id`),
  UNIQUE KEY `sales_invoice_number_unique` (`invoice_number`),
  KEY `sales_customer_id_foreign`       (`customer_id`),
  KEY `sales_location_id_foreign`       (`location_id`),
  KEY `sales_payment_status_id_foreign` (`payment_status_id`),
  KEY `sales_sold_by_foreign`           (`sold_by`),
  CONSTRAINT `sales_customer_id_foreign`
    FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_payment_status_id_foreign`
    FOREIGN KEY (`payment_status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_sold_by_foreign`
    FOREIGN KEY (`sold_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sales_details` (
  `sales_detail_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_id`        BIGINT UNSIGNED DEFAULT NULL,
  `product_id`      BIGINT UNSIGNED DEFAULT NULL,
  `quantity`        INT             NOT NULL DEFAULT 0,
  `unit_price`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `subtotal`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `created_at`      TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`      TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`sales_detail_id`),
  KEY `sales_details_sales_id_foreign`   (`sales_id`),
  KEY `sales_details_product_id_foreign` (`product_id`),
  CONSTRAINT `sales_details_sales_id_foreign`
    FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE CASCADE,
  CONSTRAINT `sales_details_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `delivery_receipts` (
  `dr_id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dr_number`  VARCHAR(50)     NOT NULL,
  `sales_id`   BIGINT UNSIGNED DEFAULT NULL,
  `issued_by`  BIGINT UNSIGNED DEFAULT NULL,
  `status_id`  BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP       NULL DEFAULT NULL,
  `updated_at` TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`dr_id`),
  UNIQUE KEY `delivery_receipts_dr_number_unique` (`dr_number`),
  KEY `delivery_receipts_sales_id_foreign`   (`sales_id`),
  KEY `delivery_receipts_issued_by_foreign`  (`issued_by`),
  KEY `delivery_receipts_status_id_foreign`  (`status_id`),
  CONSTRAINT `delivery_receipts_sales_id_foreign`
    FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_receipts_issued_by_foreign`
    FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_receipts_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PROFIT / LOSS  &  RESTOCK RECOMMENDATIONS
-- ============================================================

CREATE TABLE `profit_loss` (
  `profit_loss_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`        BIGINT UNSIGNED DEFAULT NULL,
  `reference_type`    VARCHAR(50)     DEFAULT NULL,
  `transaction_date`  DATE            DEFAULT NULL,
  `incident_date`     DATE            DEFAULT NULL,
  `serial_number`     VARCHAR(100)    DEFAULT NULL,
  `quantity_lost`     INT             NOT NULL DEFAULT 0,
  `unit_cost`         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `total_loss_amount` DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
  `recorded_by`       BIGINT UNSIGNED DEFAULT NULL,
  `approved_by`       BIGINT UNSIGNED DEFAULT NULL,
  `status_id`         BIGINT UNSIGNED DEFAULT NULL,
  `created_at`        TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`        TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`profit_loss_id`),
  KEY `profit_loss_product_id_foreign`  (`product_id`),
  KEY `profit_loss_recorded_by_foreign` (`recorded_by`),
  KEY `profit_loss_approved_by_foreign` (`approved_by`),
  KEY `profit_loss_status_id_foreign`   (`status_id`),
  CONSTRAINT `profit_loss_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_recorded_by_foreign`
    FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_approved_by_foreign`
    FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `restock_recommendations` (
  `recommendation_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`           BIGINT UNSIGNED DEFAULT NULL,
  `location_id`          BIGINT UNSIGNED DEFAULT NULL,
  `current_stock`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `reorder_level`        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `recommended_quantity` DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
  `reason`               VARCHAR(255)    DEFAULT NULL,
  `status_id`            BIGINT UNSIGNED DEFAULT NULL,
  `created_at`           TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`recommendation_id`),
  KEY `restock_recommendations_product_id_foreign`  (`product_id`),
  KEY `restock_recommendations_location_id_foreign` (`location_id`),
  KEY `restock_recommendations_status_id_foreign`   (`status_id`),
  CONSTRAINT `restock_recommendations_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `restock_recommendations_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `restock_recommendations_status_id_foreign`
    FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BARCODE SCANNING
-- ============================================================

CREATE TABLE `barcode_scans` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `product_id`  BIGINT UNSIGNED NOT NULL,
  `barcode`     VARCHAR(191)    NOT NULL,
  `scan_mode`   VARCHAR(64)     NOT NULL COMMENT 'receive-po | issue-out | transfer | adjustment',
  `scanned_at`  TIMESTAMP       NULL DEFAULT NULL,
  `created_at`  TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `barcode_scans_user_id_scanned_at_index` (`user_id`, `scanned_at`),
  KEY `barcode_scans_product_id_foreign`       (`product_id`),
  CONSTRAINT `barcode_scans_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `barcode_scans_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_scan_events` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL,
  `transaction_type` VARCHAR(32)     NOT NULL,
  `action`           VARCHAR(16)     NOT NULL COMMENT 'accepted | rejected',
  `reason`           VARCHAR(500)    DEFAULT NULL,
  `barcode`          VARCHAR(191)    NOT NULL,
  `product_id`       BIGINT UNSIGNED DEFAULT NULL,
  `po_id`            BIGINT UNSIGNED DEFAULT NULL,
  `location_id`      BIGINT UNSIGNED DEFAULT NULL,
  `to_location_id`   BIGINT UNSIGNED DEFAULT NULL,
  `quantity_delta`   INT             NOT NULL DEFAULT 0,
  `serial_number`    VARCHAR(191)    DEFAULT NULL,
  `meta`             LONGTEXT        DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at`       TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`       TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_scan_events_product_id_foreign`            (`product_id`),
  KEY `inventory_scan_events_location_id_foreign`           (`location_id`),
  KEY `inventory_scan_events_to_location_id_foreign`        (`to_location_id`),
  KEY `inventory_scan_events_user_id_created_at_index`      (`user_id`, `created_at`),
  KEY `inventory_scan_events_transaction_type_created_at`   (`transaction_type`, `created_at`),
  KEY `inventory_scan_events_po_id_location_id_product_idx` (`po_id`, `location_id`, `product_id`),
  CONSTRAINT `inventory_scan_events_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_scan_events_product_id_foreign`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_scan_events_location_id_foreign`
    FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_scan_events_to_location_id_foreign`
    FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_scan_events_po_id_foreign`
    FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AUDIT TRAIL  (single authoritative table)
-- ============================================================

CREATE TABLE `audit_trail` (
  `audit_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(100)    NOT NULL COMMENT 'login | logout | create | update | delete | password_reset',
  `table_name`  VARCHAR(100)    DEFAULT NULL,
  `record_id`   BIGINT UNSIGNED DEFAULT NULL,
  `old_values`  LONGTEXT        DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values`  LONGTEXT        DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address`  VARCHAR(45)     DEFAULT NULL,
  `user_agent`  VARCHAR(500)    DEFAULT NULL,
  `created_at`  TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`  TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `audit_trail_user_id_index`    (`user_id`),
  KEY `audit_trail_table_name_index` (`table_name`),
  KEY `audit_trail_created_at_index` (`created_at`),
  CONSTRAINT `audit_trail_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_activities` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`          BIGINT UNSIGNED DEFAULT NULL,
  `username_attempt` VARCHAR(100)    DEFAULT NULL,
  `success`          TINYINT(1)      NOT NULL DEFAULT 0,
  `failure_reason`   VARCHAR(255)    DEFAULT NULL,
  `ip_address`       VARCHAR(45)     DEFAULT NULL,
  `user_agent`       TEXT            DEFAULT NULL,
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `login_activities_user_id_index`          (`user_id`),
  KEY `login_activities_username_attempt_index` (`username_attempt`),
  KEY `login_activities_ip_address_index`       (`ip_address`),
  CONSTRAINT `login_activities_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- LARAVEL FRAMEWORK TABLES
-- ============================================================

CREATE TABLE `migrations` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(255) NOT NULL,
  `batch`     INT          NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email`      VARCHAR(255) NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id`            VARCHAR(255)    NOT NULL,
  `user_id`       BIGINT UNSIGNED DEFAULT NULL,
  `ip_address`    VARCHAR(45)     DEFAULT NULL,
  `user_agent`    TEXT            DEFAULT NULL,
  `payload`       LONGTEXT        NOT NULL,
  `last_activity` INT             NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index`       (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `personal_access_tokens` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255)    NOT NULL,
  `tokenable_id`   BIGINT UNSIGNED NOT NULL,
  `name`           TEXT            NOT NULL,
  `token`          VARCHAR(64)     NOT NULL,
  `abilities`      TEXT            DEFAULT NULL,
  `last_used_at`   TIMESTAMP       NULL DEFAULT NULL,
  `expires_at`     TIMESTAMP       NULL DEFAULT NULL,
  `created_at`     TIMESTAMP       NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index`
      (`tokenable_type`, `tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key`        VARCHAR(255) NOT NULL,
  `value`      MEDIUMTEXT   NOT NULL,
  `expiration` INT          NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key`        VARCHAR(255) NOT NULL,
  `owner`      VARCHAR(255) NOT NULL,
  `expiration` INT          NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue`        VARCHAR(255)    NOT NULL,
  `payload`      LONGTEXT        NOT NULL,
  `attempts`     TINYINT UNSIGNED NOT NULL,
  `reserved_at`  INT UNSIGNED    DEFAULT NULL,
  `available_at` INT UNSIGNED    NOT NULL,
  `created_at`   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id`             VARCHAR(255) NOT NULL,
  `name`           VARCHAR(255) NOT NULL,
  `total_jobs`     INT          NOT NULL,
  `pending_jobs`   INT          NOT NULL,
  `failed_jobs`    INT          NOT NULL,
  `failed_job_ids` LONGTEXT     NOT NULL,
  `options`        MEDIUMTEXT   DEFAULT NULL,
  `cancelled_at`   INT          DEFAULT NULL,
  `created_at`     INT          NOT NULL,
  `finished_at`    INT          DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`       VARCHAR(255)    NOT NULL,
  `connection` TEXT            NOT NULL,
  `queue`      TEXT            NOT NULL,
  `payload`    LONGTEXT        NOT NULL,
  `exception`  LONGTEXT        NOT NULL,
  `failed_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA  (minimum required for the system to boot)
-- ============================================================

-- Roles
INSERT INTO `roles` (`role_name`, `description`, `created_at`, `updated_at`) VALUES
('admin',              'System Administrator — full access',         NOW(), NOW()),
('inventory_analyst',  'Inventory Analyst — view and report',        NOW(), NOW()),
('branch_manager',     'Branch / Location Manager',                  NOW(), NOW()),
('warehouse_personnel','Warehouse Personnel — scan and move stock',   NOW(), NOW()),
('auditor',            'Auditor — read-only audit access',           NOW(), NOW());

-- Status lookup  (category scoped)
INSERT INTO `status_lookup` (`status_name`, `status_category`, `is_active`, `created_at`, `updated_at`) VALUES
('Active',            'user',             1, NOW(), NOW()),
('Inactive',          'user',             1, NOW(), NOW()),
('Active',            'product',          1, NOW(), NOW()),
('Inactive',          'product',          1, NOW(), NOW()),
('Active',            'location',         1, NOW(), NOW()),
('Inactive',          'location',         1, NOW(), NOW()),
('Pending',           'purchase_order',   1, NOW(), NOW()),
('Approved',          'purchase_order',   1, NOW(), NOW()),
('Rejected',          'purchase_order',   1, NOW(), NOW()),
('Pending Approval',  'purchase_order',   1, NOW(), NOW()),
('Authorized',        'purchase_order',   1, NOW(), NOW()),
('In Transit',        'transfer',         1, NOW(), NOW()),
('Received',          'transfer',         1, NOW(), NOW()),
('Pending',           'adjustment',       1, NOW(), NOW()),
('Approved',          'adjustment',       1, NOW(), NOW()),
('Paid',              'sales',            1, NOW(), NOW()),
('Unpaid',            'sales',            1, NOW(), NOW()),
('Partial',           'sales',            1, NOW(), NOW()),
('Active',            'supplier',         1, NOW(), NOW()),
('Inactive',          'supplier',         1, NOW(), NOW()),
('In Stock',          'inventory',        1, NOW(), NOW()),
('Low Stock',         'inventory',        1, NOW(), NOW()),
('Out of Stock',      'inventory',        1, NOW(), NOW());

-- Unit lookup
INSERT INTO `unit_lookup` (`unit_name`, `unit_abbreviation`, `created_at`, `updated_at`) VALUES
('Unit',      'pc',    NOW(), NOW()),
('Piece',     'pc',    NOW(), NOW()),
('Roll',      'roll',  NOW(), NOW()),
('Meter',     'm',     NOW(), NOW()),
('Set',       'set',   NOW(), NOW()),
('Box',       'box',   NOW(), NOW()),
('Kilogram',  'kg',    NOW(), NOW());

-- Brand lookup
INSERT INTO `brand_lookup` (`brand_name`, `created_at`, `updated_at`) VALUES
('Airking',   NOW(), NOW()),
('Samsung',   NOW(), NOW()),
('LG',        NOW(), NOW()),
('Panasonic', NOW(), NOW()),
('Sony',      NOW(), NOW()),
('Whirlpool', NOW(), NOW()),
('Condura',   NOW(), NOW()),
('Hanabishi', NOW(), NOW()),
('Imarflex',  NOW(), NOW()),
('TCL',       NOW(), NOW());

-- Category lookup
INSERT INTO `category_lookup` (`category_name`, `category_type`, `description`, `created_at`, `updated_at`) VALUES
('Air Conditioning', 'product',    'Air conditioning units',       NOW(), NOW()),
('Television',       'product',    'TVs and displays',             NOW(), NOW()),
('Refrigerator',     'product',    'Refrigerators and freezers',   NOW(), NOW()),
('Washing Machine',  'product',    'Washing machines',             NOW(), NOW()),
('Small Appliances', 'product',    'Small home appliances',        NOW(), NOW()),
('AC Parts',         'consumable', 'Air conditioning spare parts', NOW(), NOW()),
('Copper Tubing',    'consumable', 'Copper tubing and fittings',   NOW(), NOW()),
('Accessories',      'accessory',  'General accessories',          NOW(), NOW());

-- Transaction type lookup
INSERT INTO `transaction_type_lookup` (`transaction_name`, `description`, `created_at`, `updated_at`) VALUES
('Stock In',        'Receiving inventory from supplier',   NOW(), NOW()),
('Stock Out',       'Issuing inventory for operations',    NOW(), NOW()),
('Transfer',        'Moving stock between locations',      NOW(), NOW()),
('Adjustment',      'Manual inventory count correction',   NOW(), NOW()),
('Return to Vendor','Returning goods to supplier',         NOW(), NOW()),
('Sales',           'Customer purchase transaction',       NOW(), NOW());

-- Default admin user (password: Admin@1234 — bcrypt)
-- IMPORTANT: Change password immediately after first login
INSERT INTO `users`
  (`first_name`, `last_name`, `username`, `email`, `role_id`, `status_id`,
   `password_hash`, `created_at`, `updated_at`)
VALUES
  ('System', 'Admin', 'admin', 'admin@airking.com', 1, 1,
   '$2y$12$uGpGxZzt1cFxpn6y8DSiDuiJlgs9PZQQcjrhXLt/gh7fW431jY/jm',
   NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
