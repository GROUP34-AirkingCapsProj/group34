
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ac_type_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ac_type_lookup` (
  `ac_type_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ac_type_name` varchar(60) NOT NULL,
  `is_two_piece` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ac_type_id`),
  KEY `ac_type_lookup_ac_type_name_index` (`ac_type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `adjustment_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjustment_details` (
  `adjustment_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `add_quantity` int(11) NOT NULL DEFAULT 0,
  `deduct_quantity` int(11) NOT NULL DEFAULT 0,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`adjustment_detail_id`),
  KEY `adjustment_details_adjustment_id_foreign` (`adjustment_id`),
  KEY `adjustment_details_product_id_foreign` (`product_id`),
  CONSTRAINT `adjustment_details_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  CONSTRAINT `adjustment_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `adjustments` (
  `adjustment_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `adjusted_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `adjustment_number` varchar(50) NOT NULL,
  `adjustment_date` date DEFAULT NULL,
  `adjustment_type` varchar(50) DEFAULT NULL,
  `total_variance_positive` int(11) NOT NULL DEFAULT 0,
  `total_variance_negative` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`adjustment_id`),
  UNIQUE KEY `adjustments_adjustment_number_unique` (`adjustment_number`),
  KEY `adjustments_location_id_foreign` (`location_id`),
  KEY `adjustments_created_by_foreign` (`created_by`),
  KEY `adjustments_adjusted_by_foreign` (`adjusted_by`),
  KEY `adjustments_approved_by_foreign` (`approved_by`),
  KEY `adjustments_status_id_foreign` (`status_id`),
  KEY `adjustments_po_id_foreign` (`po_id`),
  KEY `adjustments_receiving_id_foreign` (`receiving_id`),
  CONSTRAINT `adjustments_adjusted_by_foreign` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  CONSTRAINT `adjustments_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircon_model_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aircon_model_units` (
  `aircon_model_unit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aircon_model_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `quantity` smallint(5) unsigned NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`aircon_model_unit_id`),
  UNIQUE KEY `aircon_model_units_aircon_model_id_product_id_unique` (`aircon_model_id`,`product_id`),
  KEY `aircon_model_units_product_id_foreign` (`product_id`),
  CONSTRAINT `aircon_model_units_aircon_model_id_foreign` FOREIGN KEY (`aircon_model_id`) REFERENCES `aircon_models` (`aircon_model_id`) ON DELETE CASCADE,
  CONSTRAINT `aircon_model_units_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aircon_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `aircon_models` (
  `aircon_model_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `model_name` varchar(191) NOT NULL,
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `ac_type_id` bigint(20) unsigned DEFAULT NULL,
  `capacity_rating` varchar(20) DEFAULT NULL,
  `variant` varchar(100) DEFAULT NULL,
  `is_two_piece` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`aircon_model_id`),
  KEY `aircon_models_model_name_index` (`model_name`),
  KEY `aircon_models_brand_id_foreign` (`brand_id`),
  KEY `aircon_models_ac_type_id_foreign` (`ac_type_id`),
  CONSTRAINT `aircon_models_ac_type_id_foreign` FOREIGN KEY (`ac_type_id`) REFERENCES `ac_type_lookup` (`ac_type_id`) ON DELETE SET NULL,
  CONSTRAINT `aircon_models_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `appliance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appliance_details` (
  `appliance_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL COMMENT 'One-to-one with products',
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `model_code` varchar(100) DEFAULT NULL,
  `capacity_rating` varchar(20) DEFAULT NULL COMMENT '1HP|1.5HP|2HP|2.5HP|3HP|5HP',
  `variant` varchar(100) DEFAULT NULL COMMENT 'e.g. Inverter, Standard',
  `ac_type_id` bigint(20) unsigned DEFAULT NULL,
  `unit_role` varchar(20) DEFAULT NULL COMMENT 'indoor | outdoor | complete',
  `color` varchar(50) DEFAULT NULL,
  `warranty_period_months` int(11) NOT NULL DEFAULT 0,
  `warranty_value` int(10) unsigned NOT NULL DEFAULT 0,
  `warranty_unit` varchar(10) NOT NULL DEFAULT 'month',
  `is_serialized` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Always 1 — each unit has its own barcode/serial',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`appliance_detail_id`),
  UNIQUE KEY `appliance_details_product_id_unique` (`product_id`) COMMENT 'Enforces one-to-one with products',
  KEY `appliance_details_brand_id_foreign` (`brand_id`),
  KEY `appliance_details_ac_type_id_foreign` (`ac_type_id`),
  CONSTRAINT `appliance_details_ac_type_id_foreign` FOREIGN KEY (`ac_type_id`) REFERENCES `ac_type_lookup` (`ac_type_id`) ON DELETE SET NULL,
  CONSTRAINT `appliance_details_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  CONSTRAINT `appliance_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=213 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Appliance-only fields. Populated when products.product_type = appliance.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_trail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_trail` (
  `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` bigint(20) unsigned DEFAULT NULL,
  `old_values` longtext DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `audit_trail_user_id_index` (`user_id`),
  KEY `audit_trail_table_name_index` (`table_name`),
  KEY `audit_trail_created_at_index` (`created_at`),
  CONSTRAINT `audit_trail_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3707 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `barcode_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `barcode_scans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `barcode` varchar(191) NOT NULL,
  `scan_mode` varchar(64) NOT NULL COMMENT 'receive-po | issue-out | transfer | adjustment',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `barcode_scans_user_id_scanned_at_index` (`user_id`,`scanned_at`),
  KEY `barcode_scans_product_id_foreign` (`product_id`),
  KEY `fk_barcode_scans_transfer` (`transfer_id`),
  CONSTRAINT `barcode_scans_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `barcode_scans_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_barcode_scans_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `brand_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `brand_lookup` (
  `brand_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `brand_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`brand_id`),
  UNIQUE KEY `brand_lookup_brand_name_unique` (`brand_name`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `budget_allocations` (
  `budget_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `period_label` varchar(100) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `committed` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of approved PRs not yet completed',
  `spent` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of completed POs',
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_note` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`budget_id`),
  UNIQUE KEY `budget_location_category_period_unique` (`location_id`,`category_id`,`period_start`),
  KEY `budget_allocations_category_id_foreign` (`category_id`),
  KEY `budget_allocations_created_by_foreign` (`created_by`),
  KEY `budget_allocations_verified_by_foreign` (`verified_by`),
  CONSTRAINT `budget_allocations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `budget_allocations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `budget_allocations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `budget_allocations_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `budget_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `budget_transactions` (
  `txn_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` bigint(20) unsigned NOT NULL,
  `txn_type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `pr_id` bigint(20) unsigned DEFAULT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `performed_by` bigint(20) unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`txn_id`),
  KEY `budget_transactions_po_id_foreign` (`po_id`),
  KEY `budget_transactions_performed_by_foreign` (`performed_by`),
  KEY `budget_transactions_budget_id_txn_type_index` (`budget_id`,`txn_type`),
  KEY `budget_transactions_pr_id_txn_type_index` (`pr_id`,`txn_type`),
  CONSTRAINT `budget_transactions_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budget_allocations` (`budget_id`),
  CONSTRAINT `budget_transactions_performed_by_foreign` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `budget_transactions_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `budget_transactions_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `capacity_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `capacity_lookup` (
  `capacity_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `capacity_value` varchar(20) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`capacity_id`),
  KEY `capacity_lookup_scope_idx` (`capacity_value`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `category_lookup` (
  `category_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `category_type` varchar(50) NOT NULL COMMENT 'appliance | consumable | accessory',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_lookup_category_name_unique` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `customer_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(50) DEFAULT NULL,
  `customer_type` varchar(100) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `tin` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT NULL,
  `outstanding_balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`customer_id`),
  KEY `customers_status_id_foreign` (`status_id`),
  CONSTRAINT `customers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delivery_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery_receipts` (
  `dr_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dr_number` varchar(50) NOT NULL,
  `sales_id` bigint(20) unsigned DEFAULT NULL,
  `issuance_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `dr_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`dr_id`),
  UNIQUE KEY `delivery_receipts_dr_number_unique` (`dr_number`),
  KEY `delivery_receipts_sales_id_foreign` (`sales_id`),
  KEY `delivery_receipts_issued_by_foreign` (`issued_by`),
  KEY `delivery_receipts_status_id_foreign` (`status_id`),
  KEY `delivery_receipts_issuance_id_foreign` (`issuance_id`),
  KEY `delivery_receipts_customer_id_foreign` (`customer_id`),
  CONSTRAINT `delivery_receipts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_receipts_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_receipts_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_receipts_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE SET NULL,
  CONSTRAINT `delivery_receipts_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `incident_evidence` (
  `evidence_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `requirement` varchar(40) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `photo_source` varchar(20) DEFAULT NULL,
  `skipped_reason` text DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`evidence_id`),
  KEY `incident_evidence_incident_id_requirement_index` (`incident_id`,`requirement`),
  CONSTRAINT `incident_evidence_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incident_reports` (`incident_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `incident_items` (
  `item_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `expected_product_id` bigint(20) unsigned DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `unit_role` varchar(10) NOT NULL DEFAULT 'single',
  `is_inbound` tinyint(1) NOT NULL DEFAULT 0,
  `model_code` varchar(100) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `expected_quantity` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'quarantined',
  `outcome` varchar(30) DEFAULT NULL,
  `outcome_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `incident_items_quarantine_idx` (`status`,`batch_id`),
  KEY `incident_items_incident_id_index` (`incident_id`),
  KEY `incident_items_product_id_index` (`product_id`),
  KEY `incident_items_expected_product_id_foreign` (`expected_product_id`),
  CONSTRAINT `incident_items_expected_product_id_foreign` FOREIGN KEY (`expected_product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `incident_items_incident_id_foreign` FOREIGN KEY (`incident_id`) REFERENCES `incident_reports` (`incident_id`),
  CONSTRAINT `incident_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incident_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `incident_reports` (
  `incident_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `incident_number` varchar(40) NOT NULL,
  `source_type` varchar(20) NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `related_issuance_id` bigint(20) unsigned DEFAULT NULL,
  `related_transfer_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `expected_zone` varchar(100) DEFAULT NULL,
  `actual_zone` varchar(100) DEFAULT NULL,
  `last_known_location` varchar(150) DEFAULT NULL,
  `suspected_cause` varchar(20) DEFAULT NULL,
  `root_cause` varchar(20) DEFAULT NULL,
  `return_direction` varchar(20) DEFAULT NULL,
  `return_reason` varchar(150) DEFAULT NULL,
  `return_counterparty` varchar(20) DEFAULT NULL,
  `return_reason_code` varchar(30) DEFAULT NULL,
  `dealer_name` varchar(150) DEFAULT NULL,
  `dealer_phone` varchar(40) DEFAULT NULL,
  `dealer_mobile` varchar(40) DEFAULT NULL,
  `dealer_email` varchar(150) DEFAULT NULL,
  `branch_address` varchar(255) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `customer_contact` varchar(40) DEFAULT NULL,
  `site_address` varchar(255) DEFAULT NULL,
  `purchased_at` date DEFAULT NULL,
  `received_at_dor` date DEFAULT NULL,
  `inspected_by` bigint(20) unsigned DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `inspection_findings` text DEFAULT NULL,
  `checked_by_asc` tinyint(1) NOT NULL DEFAULT 0,
  `signed_by_representative` varchar(150) DEFAULT NULL,
  `signed_by_branch_manager` varchar(150) DEFAULT NULL,
  `signed_at` date DEFAULT NULL,
  `rejection_note` text DEFAULT NULL,
  `reason` varchar(30) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `narrative` text DEFAULT NULL,
  `occurred_at` datetime DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `photo_source` varchar(20) DEFAULT NULL,
  `reported_by` bigint(20) unsigned NOT NULL,
  `status_id` bigint(20) unsigned NOT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `adjustment_id` bigint(20) unsigned DEFAULT NULL,
  `correction_id` bigint(20) unsigned DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`incident_id`),
  UNIQUE KEY `incident_reports_incident_number_unique` (`incident_number`),
  KEY `incident_source_idx` (`source_type`,`source_id`),
  KEY `incident_loc_status_idx` (`location_id`,`status_id`),
  KEY `incident_batch_status_idx` (`batch_id`,`status_id`),
  KEY `incident_reports_reported_by_index` (`reported_by`),
  KEY `incident_reports_product_id_foreign` (`product_id`),
  KEY `incident_reports_serial_id_foreign` (`serial_id`),
  KEY `incident_reports_status_id_foreign` (`status_id`),
  KEY `incident_reports_reviewed_by_foreign` (`reviewed_by`),
  KEY `incident_reports_adjustment_id_foreign` (`adjustment_id`),
  KEY `incident_reports_correction_id_foreign` (`correction_id`),
  KEY `incident_related_issuance_idx` (`related_issuance_id`),
  CONSTRAINT `incident_reports_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_correction_id_foreign` FOREIGN KEY (`correction_id`) REFERENCES `scan_corrections` (`correction_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  CONSTRAINT `incident_reports_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `incident_reports_related_issuance_id_foreign` FOREIGN KEY (`related_issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_reported_by_foreign` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `incident_reports_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL,
  CONSTRAINT `incident_reports_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `installation_material_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `installation_material_details` (
  `consumable_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL COMMENT 'One-to-one with products',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `pieces_per_package` int(10) unsigned DEFAULT NULL,
  `package_unit` varchar(50) DEFAULT NULL COMMENT 'e.g. Box, Drum, Carton',
  `is_serialized` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Always 0 — tracked by quantity only',
  `warranty_value` int(10) unsigned NOT NULL DEFAULT 0,
  `warranty_unit` varchar(10) NOT NULL DEFAULT 'month',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`consumable_detail_id`),
  UNIQUE KEY `consumable_details_product_id_unique` (`product_id`) COMMENT 'Enforces one-to-one with products',
  KEY `consumable_details_unit_id_foreign` (`unit_id`),
  CONSTRAINT `consumable_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `consumable_details_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `unit_lookup` (`unit_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=135 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Consumable-only fields. Populated when products.product_type = consumable.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory` (
  `inventory_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `packages_on_hand` int(10) unsigned NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 0,
  `last_updated` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `inventory_location_product_unique` (`location_id`,`product_id`),
  KEY `inventory_product_id_foreign` (`product_id`),
  KEY `inventory_status_id_foreign` (`status_id`),
  CONSTRAINT `inventory_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1065 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_batch_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_batch_movements` (
  `batch_movement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `movement_type` varchar(20) NOT NULL,
  `issuance_id` bigint(20) unsigned DEFAULT NULL,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `from_location_id` bigint(20) unsigned NOT NULL,
  `to_location_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `serial_number` varchar(255) DEFAULT NULL,
  `performed_by` bigint(20) unsigned DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`batch_movement_id`),
  KEY `inventory_batch_movements_batch_id_moved_at_index` (`batch_id`,`moved_at`),
  KEY `inventory_batch_movements_transfer_id_product_id_index` (`transfer_id`,`product_id`),
  KEY `inventory_batch_movements_issuance_id_product_id_index` (`issuance_id`,`product_id`),
  CONSTRAINT `inventory_batch_movements_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_batches` (
  `batch_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `quantity_remaining` int(11) NOT NULL DEFAULT 0,
  `received_at` timestamp NULL DEFAULT NULL,
  `zone_label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`batch_id`),
  KEY `inventory_batches_fifo_idx` (`product_id`,`location_id`,`received_at`),
  KEY `inventory_batches_location_id_foreign` (`location_id`),
  KEY `inventory_batches_receiving_id_foreign` (`receiving_id`),
  CONSTRAINT `inventory_batches_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_batches_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_batches_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_scan_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_scan_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `transaction_type` varchar(32) NOT NULL,
  `action` varchar(16) NOT NULL COMMENT 'accepted | rejected',
  `reason` varchar(500) DEFAULT NULL,
  `barcode` varchar(191) NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `to_location_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_delta` int(11) NOT NULL DEFAULT 0,
  `serial_number` varchar(191) DEFAULT NULL,
  `meta` longtext DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ise_product_id_foreign` (`product_id`),
  KEY `ise_location_id_foreign` (`location_id`),
  KEY `ise_to_location_id_foreign` (`to_location_id`),
  KEY `ise_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `ise_transaction_type_created_at` (`transaction_type`,`created_at`),
  KEY `ise_po_id_location_product_idx` (`po_id`,`location_id`,`product_id`),
  CONSTRAINT `ise_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `ise_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `ise_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `ise_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `ise_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=113 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `issuance_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuance_details` (
  `issuance_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuance_id` bigint(20) unsigned DEFAULT NULL,
  `issuance_set_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `aircon_model_id` bigint(20) unsigned DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL COMMENT 'Appliances only — the scanned serial',
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `zone_label` varchar(100) DEFAULT NULL,
  `stock_before` int(10) unsigned DEFAULT NULL,
  `quantity_deducted` int(10) unsigned DEFAULT NULL,
  `stock_after` int(10) unsigned DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price_at_issue` decimal(12,2) DEFAULT NULL,
  `quantity_issued` int(11) NOT NULL DEFAULT 0,
  `quantity_requested` int(11) NOT NULL DEFAULT 0,
  `quantity_short` int(11) NOT NULL DEFAULT 0,
  `quantity_unit` varchar(30) DEFAULT NULL COMMENT 'unit | piece | meter | roll | kg | liter',
  `quantity_in_pieces` int(11) NOT NULL DEFAULT 0 COMMENT 'Actual pieces deducted from inventory',
  `unit_price_per_piece` decimal(10,4) NOT NULL DEFAULT 0.0000 COMMENT 'unit_price / pieces_per_package for consumables',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `quantity_returned` int(11) NOT NULL DEFAULT 0,
  `condition_issued` varchar(50) DEFAULT NULL,
  `condition_returned` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`issuance_detail_id`),
  KEY `issuance_details_issuance_id_foreign` (`issuance_id`),
  KEY `issuance_details_product_id_foreign` (`product_id`),
  KEY `issuance_details_receiving_id_foreign` (`receiving_id`),
  KEY `issuance_details_issuance_set_id_index` (`issuance_set_id`),
  KEY `issuance_details_batch_id_index` (`batch_id`),
  KEY `issuance_details_aircon_model_id_foreign` (`aircon_model_id`),
  CONSTRAINT `issuance_details_aircon_model_id_foreign` FOREIGN KEY (`aircon_model_id`) REFERENCES `aircon_models` (`aircon_model_id`) ON DELETE SET NULL,
  CONSTRAINT `issuance_details_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  CONSTRAINT `issuance_details_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  CONSTRAINT `issuance_details_issuance_set_id_foreign` FOREIGN KEY (`issuance_set_id`) REFERENCES `issuance_sets` (`issuance_set_id`) ON DELETE CASCADE,
  CONSTRAINT `issuance_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `issuance_details_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1691 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `issuance_sets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuance_sets` (
  `issuance_set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuance_id` bigint(20) unsigned NOT NULL,
  `set_id` bigint(20) unsigned NOT NULL,
  `set_quantity` int(11) NOT NULL DEFAULT 1,
  `set_name_snapshot` varchar(191) NOT NULL,
  `was_modified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`issuance_set_id`),
  KEY `issuance_sets_issuance_id_index` (`issuance_id`),
  KEY `issuance_sets_set_id_index` (`set_id`),
  CONSTRAINT `issuance_sets_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  CONSTRAINT `issuance_sets_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `issuances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `issuances` (
  `issuance_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `dr_number` varchar(50) DEFAULT NULL,
  `external_reference` varchar(100) DEFAULT NULL,
  `issuance_date` date DEFAULT NULL,
  `issuance_type` varchar(50) DEFAULT NULL,
  `selection_mode` varchar(20) DEFAULT NULL,
  `planning_completed_at` timestamp NULL DEFAULT NULL,
  `discount_type` varchar(30) DEFAULT NULL COMMENT 'none | business | senior | custom',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Before discount',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `issued_to_user_id` bigint(20) unsigned DEFAULT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `issued_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`issuance_id`),
  UNIQUE KEY `issuances_dr_number_unique` (`dr_number`),
  KEY `issuances_location_id_foreign` (`location_id`),
  KEY `issuances_issued_to_user_id_foreign` (`issued_to_user_id`),
  KEY `issuances_issued_by_foreign` (`issued_by`),
  KEY `issuances_approved_by_foreign` (`approved_by`),
  KEY `issuances_status_id_foreign` (`status_id`),
  KEY `issuances_customer_id_foreign` (`customer_id`),
  KEY `issuances_external_reference_index` (`external_reference`),
  CONSTRAINT `issuances_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_issued_to_user_id_foreign` FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `issuances_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=339 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `item_serial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `item_serial` (
  `serial_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `scanned_by` bigint(20) unsigned DEFAULT NULL,
  `serial_number` varchar(100) NOT NULL,
  `serial_type` varchar(50) DEFAULT NULL COMMENT 'received | issued | transferred',
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`serial_id`),
  UNIQUE KEY `item_serial_serial_number_unique` (`serial_number`),
  KEY `item_serial_product_id_foreign` (`product_id`),
  KEY `item_serial_location_id_foreign` (`location_id`),
  KEY `item_serial_status_id_foreign` (`status_id`),
  KEY `item_serial_receiving_id_foreign` (`receiving_id`),
  KEY `item_serial_scanned_by_foreign` (`scanned_by`),
  KEY `fk_item_serial_transfer` (`transfer_id`),
  CONSTRAINT `fk_item_serial_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_scanned_by_foreign` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `item_serial_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `locations` (
  `location_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_location_id` bigint(20) unsigned DEFAULT NULL,
  `location_code` varchar(20) NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `location_type` enum('warehouse','showroom','branch_office') NOT NULL DEFAULT 'warehouse',
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `manager_name` varchar(255) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `opening_date` date DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `locations_location_code_unique` (`location_code`),
  KEY `locations_parent_location_id_foreign` (`parent_location_id`),
  KEY `locations_status_id_foreign` (`status_id`),
  KEY `locations_type_idx` (`location_type`),
  CONSTRAINT `locations_parent_location_id_foreign` FOREIGN KEY (`parent_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `locations_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `username_attempt` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `failure_reason` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `login_activities_user_id_index` (`user_id`),
  KEY `login_activities_username_attempt_index` (`username_attempt`),
  KEY `login_activities_ip_address_index` (`ip_address`),
  CONSTRAINT `login_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=610 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nav_bundle_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nav_bundle_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nav_bundle_permissions_bundle_id_permission_id_unique` (`bundle_id`,`permission_id`),
  KEY `nav_bundle_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `nav_bundle_permissions_bundle_id_foreign` FOREIGN KEY (`bundle_id`) REFERENCES `nav_permission_bundles` (`bundle_id`) ON DELETE CASCADE,
  CONSTRAINT `nav_bundle_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nav_permission_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nav_permission_bundles` (
  `bundle_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `route_path` varchar(255) NOT NULL,
  `sidebar_section` varchar(255) NOT NULL,
  `sidebar_icon` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`bundle_id`),
  UNIQUE KEY `nav_permission_bundles_route_path_unique` (`route_path`)
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `code_plain` varchar(40) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `password_reset_codes_user_id_used_at_expires_at_index` (`user_id`,`used_at`,`expires_at`),
  CONSTRAINT `password_reset_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_otps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_token` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `password_reset_otps_verified_token_unique` (`verified_token`),
  KEY `password_reset_otps_email_expires_at_index` (`email`,`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pending_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pending_products` (
  `pending_product_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `barcode` varchar(100) NOT NULL,
  `registration_kind` varchar(24) NOT NULL DEFAULT 'consumable',
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `supply_type` varchar(120) DEFAULT NULL,
  `packaging_unit` varchar(32) DEFAULT NULL,
  `quantity_per_package` decimal(12,2) DEFAULT NULL,
  `generated_name` varchar(255) NOT NULL,
  `appliance_snapshot` longtext DEFAULT NULL CHECK (json_valid(`appliance_snapshot`)),
  `opening_quantity` int(10) unsigned DEFAULT NULL,
  `opening_location_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `final_product_name` varchar(255) DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`pending_product_id`),
  UNIQUE KEY `pending_products_barcode_unique` (`barcode`),
  KEY `pending_products_status_created_at_index` (`status`,`created_at`),
  KEY `pending_products_category_id_foreign` (`category_id`),
  KEY `pending_products_brand_id_foreign` (`brand_id`),
  KEY `pending_products_created_by_foreign` (`created_by`),
  KEY `pending_products_approved_by_foreign` (`approved_by`),
  KEY `pending_products_product_id_foreign` (`product_id`),
  KEY `pending_products_opening_location_id_foreign` (`opening_location_id`),
  CONSTRAINT `pending_products_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_opening_location_id_foreign` FOREIGN KEY (`opening_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `pending_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permission_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permission_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `changed_by` bigint(20) unsigned NOT NULL,
  `target_user_id` bigint(20) unsigned DEFAULT NULL,
  `target_role_id` bigint(20) unsigned DEFAULT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `old_value` tinyint(1) DEFAULT NULL,
  `new_value` tinyint(1) DEFAULT NULL,
  `change_type` varchar(255) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `permission_audit_logs_changed_at_index` (`changed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=402 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `permission_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feature` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permissions_feature_action_unique` (`feature`,`action`)
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=550 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pick_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pick_reservations` (
  `reservation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(20) NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reservation_id`),
  KEY `pick_res_source_idx` (`source_type`,`source_id`),
  KEY `pick_res_avail_idx` (`product_id`,`location_id`,`released_at`),
  KEY `pick_reservations_location_id_foreign` (`location_id`),
  KEY `pick_reservations_batch_id_foreign` (`batch_id`),
  KEY `pick_reservations_serial_id_foreign` (`serial_id`),
  CONSTRAINT `pick_reservations_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  CONSTRAINT `pick_reservations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  CONSTRAINT `pick_reservations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `pick_reservations_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_set_brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_set_brands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `set_id` bigint(20) unsigned NOT NULL,
  `brand_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_set_brands_set_id_brand_id_unique` (`set_id`,`brand_id`),
  KEY `product_set_brands_brand_id_foreign` (`brand_id`),
  CONSTRAINT `product_set_brands_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE CASCADE,
  CONSTRAINT `product_set_brands_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_set_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_set_lines` (
  `set_line_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `set_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `spec_note` varchar(191) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`set_line_id`),
  KEY `product_set_lines_set_id_index` (`set_id`),
  KEY `product_set_lines_product_id_index` (`product_id`),
  CONSTRAINT `product_set_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `product_set_lines_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `product_sets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_sets` (
  `set_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `capacity_rating` varchar(60) DEFAULT NULL,
  `variant` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`set_id`),
  KEY `product_sets_is_active_index` (`is_active`),
  KEY `product_sets_created_by_foreign` (`created_by`),
  KEY `product_sets_updated_by_foreign` (`updated_by`),
  CONSTRAINT `product_sets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `product_sets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_type` enum('appliance','installation_material') NOT NULL DEFAULT 'appliance',
  `barcode` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recommended_stocks` int(11) NOT NULL DEFAULT 0,
  `safety_stock` int(10) unsigned DEFAULT NULL,
  `max_stock_level` int(10) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `products_product_code_unique` (`product_code`),
  KEY `products_product_type_idx` (`product_type`),
  KEY `products_category_id_foreign` (`category_id`),
  KEY `products_status_id_foreign` (`status_id`),
  KEY `products_created_at_product_id_idx` (`created_at`,`product_id`),
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  CONSTRAINT `products_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=350 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shared product fields. Type-specific details in appliance_details or consumable_details.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `profit_loss`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `profit_loss` (
  `profit_loss_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `incident_date` date DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity_lost` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_loss_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`profit_loss_id`),
  KEY `profit_loss_product_id_foreign` (`product_id`),
  KEY `profit_loss_recorded_by_foreign` (`recorded_by`),
  KEY `profit_loss_approved_by_foreign` (`approved_by`),
  KEY `profit_loss_status_id_foreign` (`status_id`),
  CONSTRAINT `profit_loss_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `profit_loss_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_order_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_details` (
  `po_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `aircon_model_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_ordered` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`po_detail_id`),
  KEY `purchase_order_details_po_id_foreign` (`po_id`),
  KEY `purchase_order_details_product_id_foreign` (`product_id`),
  KEY `purchase_order_details_location_id_foreign` (`location_id`),
  KEY `purchase_order_details_aircon_model_id_foreign` (`aircon_model_id`),
  CONSTRAINT `purchase_order_details_aircon_model_id_foreign` FOREIGN KEY (`aircon_model_id`) REFERENCES `aircon_models` (`aircon_model_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_details_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_order_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `po_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pr_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Source Purchase Request (if converted from PR)',
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `po_number` varchar(50) NOT NULL,
  `order_date` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `payment_terms` varchar(50) DEFAULT 'Net 30',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `references_note` text DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Inventory Analyst',
  `approved_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin',
  `verified_by` bigint(20) unsigned DEFAULT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_note` varchar(255) DEFAULT NULL,
  `ship_to_name` varchar(255) DEFAULT NULL COMMENT 'Custom delivery name override for export document',
  `ship_to_address` text DEFAULT NULL COMMENT 'Custom delivery address override for export document',
  `approval_notes` text DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Warehouse Personnel',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`po_id`),
  UNIQUE KEY `purchase_orders_po_number_unique` (`po_number`),
  KEY `purchase_orders_pr_id_foreign` (`pr_id`),
  KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_orders_location_id_foreign` (`location_id`),
  KEY `purchase_orders_created_by_foreign` (`created_by`),
  KEY `purchase_orders_approved_by_foreign` (`approved_by`),
  KEY `purchase_orders_received_by_foreign` (`received_by`),
  KEY `purchase_orders_status_id_foreign` (`status_id`),
  KEY `purchase_orders_verified_by_foreign` (`verified_by`),
  CONSTRAINT `purchase_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_request_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_request_details` (
  `pr_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pr_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `estimated_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`pr_detail_id`),
  KEY `pr_details_pr_id_foreign` (`pr_id`),
  KEY `pr_details_product_id_foreign` (`product_id`),
  KEY `purchase_request_details_location_id_foreign` (`location_id`),
  CONSTRAINT `pr_details_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE CASCADE,
  CONSTRAINT `pr_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_request_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=152 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_requests` (
  `pr_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `budget_id` bigint(20) unsigned DEFAULT NULL,
  `pr_number` varchar(50) NOT NULL,
  `request_date` date DEFAULT NULL,
  `needed_by_date` date DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `preferred_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `estimated_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) unsigned DEFAULT NULL COMMENT '13=Pending 14=Approved 15=Rejected 16=Partially Approved',
  `requested_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Inventory Analyst',
  `approved_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin',
  `approval_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `converted_to_po_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Filled once PR is converted to a PO',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`pr_id`),
  UNIQUE KEY `purchase_requests_pr_number_unique` (`pr_number`),
  KEY `purchase_requests_location_id_foreign` (`location_id`),
  KEY `purchase_requests_budget_id_foreign` (`budget_id`),
  KEY `purchase_requests_requested_by_foreign` (`requested_by`),
  KEY `purchase_requests_approved_by_foreign` (`approved_by`),
  KEY `purchase_requests_status_id_foreign` (`status_id`),
  KEY `purchase_requests_preferred_supplier_id_foreign` (`preferred_supplier_id`),
  KEY `purchase_requests_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `purchase_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budget_allocations` (`budget_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_preferred_supplier_id_foreign` FOREIGN KEY (`preferred_supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_requests_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_return_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_return_details` (
  `pr_return_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_returned` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `condition` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`pr_return_detail_id`),
  KEY `purchase_return_details_purchase_return_id_foreign` (`purchase_return_id`),
  KEY `purchase_return_details_product_id_foreign` (`product_id`),
  KEY `purchase_return_details_serial_id_foreign` (`serial_id`),
  CONSTRAINT `purchase_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_return_details_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`purchase_return_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `purchase_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_returns` (
  `purchase_return_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `return_number` varchar(50) NOT NULL,
  `return_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`purchase_return_id`),
  UNIQUE KEY `purchase_returns_return_number_unique` (`return_number`),
  KEY `purchase_returns_po_id_foreign` (`po_id`),
  KEY `purchase_returns_supplier_id_foreign` (`supplier_id`),
  KEY `purchase_returns_receiving_id_foreign` (`receiving_id`),
  KEY `purchase_returns_status_id_foreign` (`status_id`),
  KEY `purchase_returns_requested_by_foreign` (`requested_by`),
  KEY `purchase_returns_approved_by_foreign` (`approved_by`),
  CONSTRAINT `purchase_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `receiving_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receiving_details` (
  `receiving_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `po_detail_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `aircon_model_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_amount` int(11) NOT NULL DEFAULT 0,
  `prod_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `condition` varchar(100) DEFAULT NULL,
  `scanned_barcode` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`receiving_detail_id`),
  KEY `receiving_details_receiving_id_foreign` (`receiving_id`),
  KEY `receiving_details_po_detail_id_foreign` (`po_detail_id`),
  KEY `receiving_details_product_id_foreign` (`product_id`),
  KEY `rd_scanned_barcode_idx` (`scanned_barcode`),
  KEY `receiving_details_aircon_model_id_foreign` (`aircon_model_id`),
  CONSTRAINT `receiving_details_aircon_model_id_foreign` FOREIGN KEY (`aircon_model_id`) REFERENCES `aircon_models` (`aircon_model_id`) ON DELETE SET NULL,
  CONSTRAINT `receiving_details_po_detail_id_foreign` FOREIGN KEY (`po_detail_id`) REFERENCES `purchase_order_details` (`po_detail_id`) ON DELETE SET NULL,
  CONSTRAINT `receiving_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `receiving_details_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `receivings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receivings` (
  `receiving_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_number` varchar(50) NOT NULL,
  `receiving_date` date DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `total_quantity_received` int(11) NOT NULL DEFAULT 0,
  `total_quantity_damaged` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`receiving_id`),
  UNIQUE KEY `receivings_receiving_number_unique` (`receiving_number`),
  KEY `receivings_po_id_foreign` (`po_id`),
  KEY `receivings_supplier_id_foreign` (`supplier_id`),
  KEY `receivings_location_id_foreign` (`location_id`),
  KEY `receivings_received_by_foreign` (`received_by`),
  CONSTRAINT `receivings_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `receivings_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `report_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `report_runs` (
  `report_run_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_key` varchar(60) NOT NULL,
  `title_snapshot` varchar(150) NOT NULL,
  `format` varchar(10) NOT NULL,
  `parameters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`parameters`)),
  `file_path` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `row_count` int(10) unsigned NOT NULL DEFAULT 0,
  `generated_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `file_pruned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`report_run_id`),
  KEY `report_runs_report_key_index` (`report_key`),
  KEY `report_runs_created_at_index` (`created_at`),
  KEY `report_runs_expires_at_index` (`expires_at`),
  KEY `report_runs_generated_by_foreign` (`generated_by`),
  CONSTRAINT `report_runs_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `restock_recommendations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `restock_recommendations` (
  `recommendation_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `current_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reorder_level` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recommended_quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `priority` varchar(10) NOT NULL DEFAULT 'MEDIUM',
  `reason_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reason_codes`)),
  `avg_daily_demand` decimal(10,3) NOT NULL DEFAULT 0.000,
  `demand_trend` decimal(6,3) DEFAULT NULL,
  `days_of_cover` decimal(8,1) DEFAULT NULL,
  `lead_time_days` decimal(6,1) NOT NULL DEFAULT 7.0,
  `on_order_quantity` int(11) NOT NULL DEFAULT 0,
  `run_at` timestamp NULL DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`recommendation_id`),
  UNIQUE KEY `restock_reco_product_location_unique` (`product_id`,`location_id`),
  KEY `restock_recommendations_product_id_foreign` (`product_id`),
  KEY `restock_recommendations_location_id_foreign` (`location_id`),
  KEY `restock_recommendations_status_id_foreign` (`status_id`),
  CONSTRAINT `restock_recommendations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `restock_recommendations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `restock_recommendations_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3594 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `is_granted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`),
  KEY `role_permissions_permission_id_foreign` (`permission_id`),
  CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=631 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `role_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `roles_role_name_unique` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `sales_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `order_date` date DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status_id` bigint(20) unsigned DEFAULT NULL,
  `sold_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sales_id`),
  UNIQUE KEY `sales_invoice_number_unique` (`invoice_number`),
  KEY `sales_customer_id_foreign` (`customer_id`),
  KEY `sales_location_id_foreign` (`location_id`),
  KEY `sales_payment_status_id_foreign` (`payment_status_id`),
  KEY `sales_sold_by_foreign` (`sold_by`),
  CONSTRAINT `sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_payment_status_id_foreign` FOREIGN KEY (`payment_status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_sold_by_foreign` FOREIGN KEY (`sold_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=271 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sales_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_details` (
  `sales_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`sales_detail_id`),
  KEY `sales_details_sales_id_foreign` (`sales_id`),
  KEY `sales_details_product_id_foreign` (`product_id`),
  CONSTRAINT `sales_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `sales_details_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3567 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scan_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `scan_corrections` (
  `correction_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `adjustment_id` bigint(20) unsigned DEFAULT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `receiving_id` bigint(20) unsigned DEFAULT NULL,
  `original_barcode` varchar(100) DEFAULT NULL COMMENT 'The barcode that was scanned incorrectly',
  `original_product_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Product the barcode was originally matched to',
  `corrected_barcode` varchar(100) DEFAULT NULL COMMENT 'The correct barcode (if barcode changed)',
  `corrected_product_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Product the barcode should have been matched to',
  `correction_type` varchar(50) DEFAULT NULL,
  `quantity_before` int(11) NOT NULL DEFAULT 0,
  `quantity_after` int(11) NOT NULL DEFAULT 0,
  `serial_number_before` varchar(100) DEFAULT NULL,
  `serial_number_after` varchar(100) DEFAULT NULL,
  `receiving_detail_id` bigint(20) unsigned DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `reason` text DEFAULT NULL COMMENT 'Why this correction was made',
  `corrected_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`correction_id`),
  KEY `scan_corrections_adjustment_id_foreign` (`adjustment_id`),
  KEY `scan_corrections_po_id_foreign` (`po_id`),
  KEY `scan_corrections_receiving_id_foreign` (`receiving_id`),
  KEY `scan_corrections_original_product_id_foreign` (`original_product_id`),
  KEY `scan_corrections_corrected_product_id_foreign` (`corrected_product_id`),
  KEY `scan_corrections_receiving_detail_id_foreign` (`receiving_detail_id`),
  KEY `scan_corrections_serial_id_foreign` (`serial_id`),
  KEY `scan_corrections_corrected_by_foreign` (`corrected_by`),
  KEY `scan_corrections_approved_by_foreign` (`approved_by`),
  CONSTRAINT `scan_corrections_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  CONSTRAINT `scan_corrections_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_corrected_by_foreign` FOREIGN KEY (`corrected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_corrected_product_id_foreign` FOREIGN KEY (`corrected_product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_original_product_id_foreign` FOREIGN KEY (`original_product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_receiving_detail_id_foreign` FOREIGN KEY (`receiving_detail_id`) REFERENCES `receiving_details` (`receiving_detail_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  CONSTRAINT `scan_corrections_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `status_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `status_lookup` (
  `status_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status_name` varchar(50) NOT NULL,
  `status_category` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`status_id`),
  UNIQUE KEY `status_lookup_name_category_unique` (`status_name`,`status_category`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stock_parameter_suggestions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_parameter_suggestions` (
  `suggestion_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `current_recommended` int(11) NOT NULL DEFAULT 0,
  `suggested_recommended` int(11) NOT NULL DEFAULT 0,
  `current_safety` int(11) DEFAULT NULL,
  `suggested_safety` int(11) DEFAULT NULL,
  `current_max` int(11) DEFAULT NULL,
  `suggested_max` int(11) DEFAULT NULL,
  `reason_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reason_codes`)),
  `summary` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`suggestion_id`),
  KEY `stock_parameter_suggestions_reviewed_by_foreign` (`reviewed_by`),
  KEY `stock_parameter_suggestions_product_id_status_index` (`product_id`,`status`),
  CONSTRAINT `stock_parameter_suggestions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `stock_parameter_suggestions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1688 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `supplier_product`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_product` (
  `supplier_prod_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `product_price` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'PHP',
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`supplier_prod_id`),
  UNIQUE KEY `supplier_product_sup_prod_unique` (`supplier_id`,`product_id`),
  KEY `supplier_product_product_id_foreign` (`product_id`),
  KEY `supplier_product_status_id_foreign` (`status_id`),
  CONSTRAINT `supplier_product_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `supplier_product_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `supplier_product_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supplier_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `origin` varchar(20) NOT NULL DEFAULT 'Local',
  `region` varchar(100) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`supplier_id`),
  KEY `suppliers_status_id_foreign` (`status_id`),
  CONSTRAINT `suppliers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction_type_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transaction_type_lookup` (
  `transaction_type_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transaction_type_id`),
  UNIQUE KEY `transaction_type_lookup_name_unique` (`transaction_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfer_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transfer_details` (
  `transfer_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_requested` int(11) NOT NULL DEFAULT 0,
  `quantity_transferred` int(11) NOT NULL DEFAULT 0,
  `quantity_scanned` int(11) NOT NULL DEFAULT 0,
  `quantity_received` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_detail_id`),
  KEY `transfer_details_transfer_id_foreign` (`transfer_id`),
  KEY `transfer_details_product_id_foreign` (`product_id`),
  CONSTRAINT `transfer_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `transfer_details_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfer_tracking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transfer_tracking` (
  `tracking_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `status_note` varchar(255) DEFAULT NULL,
  `recorded_at` datetime DEFAULT NULL,
  `recorded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tracking_id`),
  KEY `transfer_tracking_transfer_id_foreign` (`transfer_id`),
  KEY `transfer_tracking_location_id_foreign` (`location_id`),
  KEY `transfer_tracking_recorded_by_foreign` (`recorded_by`),
  CONSTRAINT `transfer_tracking_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `transfer_tracking_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfer_tracking_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `transfers` (
  `transfer_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_request_id` bigint(20) unsigned DEFAULT NULL COMMENT 'NULL = this is a request. Non-null = this is the IST linked to the request.',
  `from_location_id` bigint(20) unsigned DEFAULT NULL,
  `to_location_id` bigint(20) unsigned DEFAULT NULL,
  `intent` varchar(16) NOT NULL DEFAULT 'demand',
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date DEFAULT NULL,
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `prepared_by` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `total_quantity_transferred` int(10) unsigned NOT NULL DEFAULT 0,
  `total_quantity_received` int(10) unsigned NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`transfer_id`),
  UNIQUE KEY `transfers_transfer_number_unique` (`transfer_number`),
  KEY `transfers_from_location_id_foreign` (`from_location_id`),
  KEY `transfers_to_location_id_foreign` (`to_location_id`),
  KEY `transfers_requested_by_foreign` (`requested_by`),
  KEY `transfers_approved_by_foreign` (`approved_by`),
  KEY `transfers_received_by_foreign` (`received_by`),
  KEY `transfers_status_id_foreign` (`status_id`),
  KEY `transfers_transfer_request_id_foreign` (`transfer_request_id`),
  CONSTRAINT `transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_transfer_request_id_foreign` FOREIGN KEY (`transfer_request_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `unit_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_lookup` (
  `unit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unit_name` varchar(50) NOT NULL,
  `unit_abbreviation` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_locations_unique` (`user_id`,`location_id`),
  KEY `user_locations_location_id_foreign` (`location_id`),
  CONSTRAINT `user_locations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  CONSTRAINT `user_locations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_permission_overrides`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_permission_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  `is_granted` tinyint(1) NOT NULL,
  `granted_by` bigint(20) unsigned DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_permission_overrides_user_id_permission_id_index` (`user_id`,`permission_id`),
  KEY `user_permission_overrides_permission_id_foreign` (`permission_id`),
  CONSTRAINT `user_permission_overrides_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `user_permission_overrides_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `recovery_email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Primary assigned location',
  `status_id` bigint(20) unsigned NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `otp_code` varchar(255) DEFAULT NULL,
  `otp_expires_at` timestamp NULL DEFAULT NULL,
  `otp_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `alerts_last_seen_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_recovery_email_unique` (`recovery_email`),
  KEY `users_role_id_foreign` (`role_id`),
  KEY `users_status_id_foreign` (`status_id`),
  KEY `users_location_id_foreign` (`location_id`),
  CONSTRAINT `users_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  CONSTRAINT `users_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `variant_lookup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `variant_lookup` (
  `variant_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variant_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`variant_id`),
  KEY `variant_lookup_scope_idx` (`variant_name`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warranty_claim_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_claim_details` (
  `claim_detail_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claim_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `product_set_id` bigint(20) unsigned DEFAULT NULL,
  `line_source` varchar(20) NOT NULL DEFAULT 'custom',
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity_requested` int(11) NOT NULL DEFAULT 0,
  `quantity_issued` int(11) NOT NULL DEFAULT 1,
  `quantity_in_pieces` int(11) NOT NULL DEFAULT 1,
  `quantity_unit` varchar(50) DEFAULT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`claim_detail_id`),
  KEY `warranty_claim_details_claim_id_foreign` (`claim_id`),
  KEY `warranty_claim_details_product_id_foreign` (`product_id`),
  KEY `warranty_claim_details_product_set_id_foreign` (`product_set_id`),
  CONSTRAINT `warranty_claim_details_claim_id_foreign` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`claim_id`) ON DELETE CASCADE,
  CONSTRAINT `warranty_claim_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  CONSTRAINT `warranty_claim_details_product_set_id_foreign` FOREIGN KEY (`product_set_id`) REFERENCES `product_sets` (`set_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warranty_claim_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_claim_returns` (
  `return_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claim_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `holding_status` varchar(30) NOT NULL DEFAULT 'warranty_holding',
  `received_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `released_at` timestamp NULL DEFAULT NULL,
  `release_disposition` varchar(30) DEFAULT NULL,
  `replacement_warranty_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`return_id`),
  UNIQUE KEY `warranty_claim_returns_claim_id_unique` (`claim_id`),
  KEY `warranty_claim_returns_product_id_foreign` (`product_id`),
  KEY `warranty_claim_returns_location_id_foreign` (`location_id`),
  KEY `warranty_claim_returns_replacement_warranty_id_foreign` (`replacement_warranty_id`),
  KEY `warranty_claim_returns_serial_number_index` (`serial_number`),
  CONSTRAINT `warranty_claim_returns_claim_id_foreign` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`claim_id`) ON DELETE CASCADE,
  CONSTRAINT `warranty_claim_returns_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  CONSTRAINT `warranty_claim_returns_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  CONSTRAINT `warranty_claim_returns_replacement_warranty_id_foreign` FOREIGN KEY (`replacement_warranty_id`) REFERENCES `warranty_records` (`warranty_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warranty_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_claims` (
  `claim_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claim_number` varchar(40) NOT NULL,
  `warranty_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `claim_type` enum('repair','replacement') NOT NULL,
  `notes` text DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `parts_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`claim_id`),
  UNIQUE KEY `warranty_claims_claim_number_unique` (`claim_number`),
  KEY `warranty_claims_warranty_id_foreign` (`warranty_id`),
  KEY `warranty_claims_customer_id_foreign` (`customer_id`),
  KEY `warranty_claims_product_id_foreign` (`product_id`),
  KEY `warranty_claims_location_id_foreign` (`location_id`),
  KEY `warranty_claims_status_id_foreign` (`status_id`),
  KEY `warranty_claims_created_by_foreign` (`created_by`),
  KEY `warranty_claims_serial_number_index` (`serial_number`),
  CONSTRAINT `warranty_claims_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_claims_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_claims_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_claims_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_claims_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_claims_warranty_id_foreign` FOREIGN KEY (`warranty_id`) REFERENCES `warranty_records` (`warranty_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warranty_inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_inventory_movements` (
  `movement_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `claim_id` bigint(20) unsigned NOT NULL,
  `claim_detail_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `movement_type` varchar(40) NOT NULL,
  `quantity` int(11) NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `performed_by` bigint(20) unsigned DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`movement_id`),
  KEY `warranty_inventory_movements_claim_detail_id_foreign` (`claim_detail_id`),
  KEY `warranty_inventory_movements_product_id_foreign` (`product_id`),
  KEY `warranty_inventory_movements_location_id_foreign` (`location_id`),
  KEY `warranty_inventory_movements_claim_id_moved_at_index` (`claim_id`,`moved_at`),
  CONSTRAINT `warranty_inventory_movements_claim_detail_id_foreign` FOREIGN KEY (`claim_detail_id`) REFERENCES `warranty_claim_details` (`claim_detail_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_inventory_movements_claim_id_foreign` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`claim_id`) ON DELETE CASCADE,
  CONSTRAINT `warranty_inventory_movements_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  CONSTRAINT `warranty_inventory_movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `warranty_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_records` (
  `warranty_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuance_id` bigint(20) unsigned DEFAULT NULL,
  `issuance_detail_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `warranty_start_date` date DEFAULT NULL,
  `warranty_end_date` date DEFAULT NULL,
  `warranty_period_months` int(11) NOT NULL DEFAULT 0,
  `warranty_duration_label` varchar(30) DEFAULT NULL COMMENT 'e.g. 2 Years, 6 Months, No Warranty',
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `claim_date` date DEFAULT NULL,
  `claimed_by` bigint(20) unsigned DEFAULT NULL,
  `claim_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`warranty_id`),
  KEY `warranty_records_issuance_id_foreign` (`issuance_id`),
  KEY `warranty_records_issuance_detail_id_foreign` (`issuance_detail_id`),
  KEY `warranty_records_product_id_foreign` (`product_id`),
  KEY `warranty_records_customer_id_foreign` (`customer_id`),
  KEY `warranty_records_status_id_foreign` (`status_id`),
  KEY `warranty_records_claimed_by_foreign` (`claimed_by`),
  KEY `warranty_records_serial_number_index` (`serial_number`),
  CONSTRAINT `warranty_records_claimed_by_foreign` FOREIGN KEY (`claimed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_records_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_records_issuance_detail_id_foreign` FOREIGN KEY (`issuance_detail_id`) REFERENCES `issuance_details` (`issuance_detail_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_records_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  CONSTRAINT `warranty_records_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  CONSTRAINT `warranty_records_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

LOCK TABLES `ac_type_lookup` WRITE;
/*!40000 ALTER TABLE `ac_type_lookup` DISABLE KEYS */;
INSERT INTO `ac_type_lookup` (`ac_type_id`, `ac_type_name`, `is_two_piece`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'Split Type',1,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(2,'Window Type',0,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(3,'Floor Standing',1,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(4,'Cassette',1,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(5,'Ducted',1,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(6,'Portable',0,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL),(7,'Ceiling Mounted',1,'2026-08-28 09:32:42','2026-08-28 09:32:42',NULL);
/*!40000 ALTER TABLE `ac_type_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `brand_lookup` WRITE;
/*!40000 ALTER TABLE `brand_lookup` DISABLE KEYS */;
INSERT INTO `brand_lookup` (`brand_id`, `brand_name`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'Airking','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(2,'Samsung','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(3,'LG','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(4,'Panasonic','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(5,'Sony','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(6,'Whirlpool','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(7,'Condura','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(8,'Hanabishi','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(9,'Imarflex','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(10,'TCL','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(11,'CHiQ','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(12,'Iffalcon','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(13,'Midea','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(14,'Haier','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(15,'Carrier','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(16,'Koppel','2026-06-29 16:00:00','2026-06-29 16:00:00',NULL),(17,'Asample2','2026-08-02 02:46:04','2026-08-02 02:57:42','2026-08-02 02:57:42'),(18,'ADSDA','2026-08-02 06:20:26','2026-08-02 06:20:36','2026-08-02 06:20:36');
/*!40000 ALTER TABLE `brand_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `capacity_lookup` WRITE;
/*!40000 ALTER TABLE `capacity_lookup` DISABLE KEYS */;
INSERT INTO `capacity_lookup` (`capacity_id`, `capacity_value`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'1HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(2,'1.5HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(3,'2HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(4,'2.5HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(5,'0.6HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(6,'0.8HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(7,'3HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(8,'4HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(9,'6HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(10,'7HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(11,'5HP','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL);
/*!40000 ALTER TABLE `capacity_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `category_lookup` WRITE;
/*!40000 ALTER TABLE `category_lookup` DISABLE KEYS */;
INSERT INTO `category_lookup` (`category_id`, `category_name`, `category_type`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'Air Conditioning','appliance','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(2,'Television','appliance','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(3,'Refrigerator','appliance','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(4,'Washing Machine','appliance','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(5,'Small Appliances','appliance','2026-04-22 21:50:17','2026-04-22 21:50:17','2026-07-07 17:33:06'),(6,'Installation Materials','installation_material','2026-04-22 21:50:17','2026-07-07 17:31:34','2026-07-07 17:50:59'),(7,'Copper Tubing','installation_material','2026-04-22 21:50:17','2026-04-22 21:50:17','2026-07-07 17:31:34'),(8,'Accessories','installation_material','2026-04-22 21:50:17','2026-04-22 21:50:17','2026-07-07 17:31:34'),(9,'Water Dispenser','appliance','2026-07-07 17:31:34','2026-07-07 17:31:34',NULL),(10,'Copper Tube','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(11,'PE Foam','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(12,'Rubber Insulation','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(13,'Brackets','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(14,'Consumables','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(15,'Electricals','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(16,'Bolts, Nuts & Small Items','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(17,'Refrigerants','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(18,'PVC Fittings','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(19,'Capacitors','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(20,'Copper Fittings','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59','2026-07-07 18:15:56'),(21,'Basic Tools','installation_material','2026-07-07 17:50:59','2026-07-07 17:50:59',NULL),(22,'Asample1','installation_material','2026-08-02 02:45:10','2026-08-02 02:57:25','2026-08-02 02:57:25');
/*!40000 ALTER TABLE `category_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `status_lookup` WRITE;
/*!40000 ALTER TABLE `status_lookup` DISABLE KEYS */;
INSERT INTO `status_lookup` (`status_id`, `status_name`, `status_category`, `is_active`, `created_at`, `updated_at`) VALUES (1,'Active','user',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(2,'Inactive','user',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(3,'Active','product',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(4,'Inactive','product',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(5,'Active','location',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(6,'Inactive','location',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(7,'Pending','purchase_order',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(8,'Approved','purchase_order',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(9,'Rejected','purchase_order',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(10,'Authorized','purchase_order',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(11,'Completed','purchase_order',1,'2026-05-01 18:51:20','2026-05-01 18:51:20'),(12,'Partially Received','purchase_order',1,'2026-05-01 18:51:20','2026-05-01 18:51:20'),(13,'Pending','purchase_request',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(14,'Approved','purchase_request',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(15,'Rejected','purchase_request',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(16,'Partially Approved','purchase_request',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(17,'In Transit','transfer',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(18,'Received','transfer',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(19,'Pending','adjustment',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(20,'Approved','adjustment',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(21,'Paid','sales',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(22,'Unpaid','sales',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(23,'Partial','sales',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(24,'Active','supplier',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(25,'Inactive','supplier',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(26,'In Stock','inventory',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(27,'Low Stock','inventory',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(28,'Out of Stock','inventory',1,'2026-04-22 21:50:17','2026-04-22 21:50:17'),(29,'Rejected','adjustment',1,'2026-05-13 18:38:03','2026-05-13 18:38:03'),(30,'Pending','issuance',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(31,'Completed','issuance',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(32,'Voided','issuance',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(33,'Active','warranty',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(34,'Expired','warranty',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(35,'Claimed','warranty',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(36,'Active','customer',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(37,'Inactive','customer',1,'2026-05-19 12:54:39','2026-05-19 12:54:39'),(38,'Pending','transfer',1,'2026-05-25 12:14:26','2026-05-25 12:14:26'),(39,'Approved','transfer',1,'2026-05-25 12:14:26','2026-05-25 12:14:26'),(40,'Rejected','transfer',1,'2026-05-25 12:14:26','2026-05-25 12:14:26'),(41,'Partially Received','transfer',1,'2026-05-25 12:14:26','2026-05-25 12:14:26'),(43,'Pending','warranty_claim',1,'2026-06-06 17:39:56','2026-06-06 17:39:56'),(44,'Completed','warranty_claim',1,'2026-06-06 17:39:56','2026-06-06 17:39:56'),(45,'Draft','issuance',1,'2026-07-24 09:38:14','2026-07-24 09:38:14'),(46,'Open','incident',1,'2026-07-25 01:37:20','2026-07-25 01:37:20'),(47,'Resolved','incident',1,'2026-07-25 01:37:20','2026-07-25 01:37:20'),(48,'Dismissed','incident',1,'2026-07-25 01:37:20','2026-07-25 01:37:20'),(49,'Cancelled','issuance',1,'2026-07-26 01:46:45','2026-07-26 01:46:45'),(50,'Missing','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(51,'Defective','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(52,'Returned','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(53,'Awaiting Inspection','incident',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(54,'Quarantined','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(55,'Scrapped','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(56,'Under Repair','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(57,'Awaiting Warranty Claim','inventory',1,'2026-08-28 15:37:19','2026-08-28 15:37:19'),(58,'Awaiting Approval','incident',1,'2026-08-28 15:37:19','2026-08-28 15:37:19');
/*!40000 ALTER TABLE `status_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `transaction_type_lookup` WRITE;
/*!40000 ALTER TABLE `transaction_type_lookup` DISABLE KEYS */;
INSERT INTO `transaction_type_lookup` (`transaction_type_id`, `transaction_name`, `description`, `created_at`, `updated_at`) VALUES (1,'Stock In','Receiving inventory from supplier','2026-04-22 21:50:17','2026-04-22 21:50:17'),(2,'Stock Out','Issuing inventory for operations','2026-04-22 21:50:17','2026-04-22 21:50:17'),(3,'Transfer','Moving stock between locations','2026-04-22 21:50:17','2026-04-22 21:50:17'),(4,'Adjustment','Manual inventory count correction','2026-04-22 21:50:17','2026-04-22 21:50:17'),(5,'Return to Vendor','Returning goods to supplier','2026-04-22 21:50:17','2026-04-22 21:50:17'),(6,'Sales','Customer purchase transaction','2026-04-22 21:50:17','2026-04-22 21:50:17');
/*!40000 ALTER TABLE `transaction_type_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `unit_lookup` WRITE;
/*!40000 ALTER TABLE `unit_lookup` DISABLE KEYS */;
INSERT INTO `unit_lookup` (`unit_id`, `unit_name`, `unit_abbreviation`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'Unit','pc','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(2,'Piece','pc','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(3,'Roll','roll','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(4,'Meter','m','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(5,'Set','set','2026-04-22 21:50:17','2026-07-31 18:50:53','2026-07-31 18:50:53'),(6,'Box','box','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(7,'Kilogram','kg','2026-04-22 21:50:17','2026-04-22 21:50:17',NULL),(8,'Liter','L','2026-04-26 09:31:36','2026-04-26 09:31:36',NULL),(9,'Quarter Roll','1/4','2026-04-26 09:31:36','2026-04-26 09:31:36',NULL),(10,'3/8 Roll','3/8','2026-04-26 09:31:36','2026-04-26 09:31:36',NULL),(11,'Half Roll','1/2','2026-04-26 09:31:36','2026-04-26 09:31:36',NULL),(12,'Foot','ft','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(13,'Can','can','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(14,'Inch','in','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(15,'Pack','pack','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(16,'Drum','drum','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(17,'Carton','ctn','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(18,'Bag','bag','2026-05-22 13:29:31','2026-05-22 13:29:31',NULL),(19,'ABC','abc','2026-07-30 05:59:39','2026-07-30 05:59:39',NULL),(20,'Asample4','sam4','2026-08-02 02:48:28','2026-08-02 02:48:28',NULL);
/*!40000 ALTER TABLE `unit_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `variant_lookup` WRITE;
/*!40000 ALTER TABLE `variant_lookup` DISABLE KEYS */;
INSERT INTO `variant_lookup` (`variant_id`, `variant_name`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,'Morandi Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(2,'EVA Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(3,'Window Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(4,'Window Standard Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(5,'Window Smart Inverter (WiFi)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(6,'Window Standard Inverter (New Model)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(7,'T-Pro IoT Inverter (Wi-Fi)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(8,'CoolPro EcoIn (2025 B2B Exclusive)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(9,'Cool Pro Obsidian+ (Wi-Fi)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(10,'VoxIN (Voice Control + Wi-Fi)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(11,'Floor Standing Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(12,'Ceiling Cassette Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(13,'Ceiling Mounted Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(14,'Titan Gold','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(15,'Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53',NULL),(16,'Floor Standing Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(17,'Celest Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(18,'Celest Pro Full DC Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(19,'Floor Standing Inverter (3P 220V)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(20,'Ceiling Mounted Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(21,'Clean Cool Pro Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(22,'Standard Inverter (Digital S-Inverter)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(23,'WindFree ActivClean','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(24,'Nexus Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(25,'Slim Floor Standing Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(26,'Floor Standing Inverter (1-Phase 220V)','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(27,'Chilled Water','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(28,'R32 Super Inverter - Lunaire','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(29,'Floor Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(30,'Dual Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(31,'Soundbar','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(32,'Digital TV','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(33,'Android Smart TV','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(34,'2K Google TV','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(35,'4K Google TV','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(36,'QLED Google TV','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(37,'QLED Google TV 144Hz','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(38,'1 Door Direct Cool Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(39,'Single Door Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(40,'Single Door Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(41,'2 Door Top Mount Defrost Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(42,'2 Door Top Mount No Frost Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(43,'4 Door Cross Door No Frost Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(44,'2 Door Side-by-Side No Frost Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(45,'Freezer','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(46,'Chest Freezer Non-Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(47,'Upright Freezer Inverter Defrost','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(48,'Twin Tub','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(49,'Top Load Fully Automatic','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(50,'Top Load Fully Automatic Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(51,'Front Load Fully Automatic Inverter','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(52,'Front Load Fully Automatic Inverter Commercial','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(53,'Top Load','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(54,'Bottom Load','2026-07-31 07:39:53','2026-07-31 07:39:53','2026-08-02 05:52:43'),(55,'Asample3','2026-08-02 02:46:34','2026-08-02 02:46:34','2026-08-02 05:52:43'),(56,'Non-inverter','2026-08-02 02:51:27','2026-08-02 02:51:27',NULL);
/*!40000 ALTER TABLE `variant_lookup` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` (`role_id`, `role_name`, `description`, `created_at`, `updated_at`) VALUES (1,'admin','System Administrator — full access','2026-04-22 21:50:17','2026-04-22 21:50:17'),(2,'inventory_analyst','Inventory Analyst — creates PRs and POs','2026-04-22 21:50:17','2026-04-22 21:50:17'),(3,'branch_manager','Branch / Location Manager','2026-04-22 21:50:17','2026-04-22 21:50:17'),(4,'warehouse_personnel','Warehouse Personnel — scan and move stock','2026-04-22 21:50:17','2026-04-22 21:50:17'),(5,'auditor','Auditor — read-only audit access','2026-04-22 21:50:17','2026-04-22 21:50:17');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` (`permission_id`, `feature`, `action`, `description`, `created_at`, `updated_at`) VALUES (1,'user_account_management','read','Read access for user account management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(2,'user_account_management','create','Create access for user account management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(3,'user_account_management','update','Update access for user account management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(4,'user_account_management','archive','Archive access for user account management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(5,'role_assignment','create','Create access for role assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(6,'role_assignment','update','Update access for role assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(7,'role_assignment','archive','Archive access for role assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(8,'category_management','read','Read access for category management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(9,'category_management','create','Create access for category management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(10,'category_management','update','Update access for category management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(11,'category_management','archive','Archive access for category management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(13,'brand_management','create','Create access for brand management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(14,'brand_management','update','Update access for brand management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(15,'brand_management','archive','Archive access for brand management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(19,'location_based_inventory','read','Read access for location based inventory','2026-06-13 07:37:59','2026-06-13 07:37:59'),(20,'location_based_inventory','create','Create access for location based inventory','2026-06-13 07:37:59','2026-06-13 07:37:59'),(21,'location_based_inventory','update','Update access for location based inventory','2026-06-13 07:37:59','2026-06-13 07:37:59'),(22,'location_based_inventory','archive','Archive access for location based inventory','2026-06-13 07:37:59','2026-06-13 07:37:59'),(23,'item_master_management','read','Read access for item master management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(24,'item_master_management','create','Create access for item master management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(25,'item_master_management','update','Update access for item master management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(26,'item_master_management','archive','Archive access for item master management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(27,'item_master_management','approve','Approve access for item master management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(28,'barcode_assignment','create','Create access for barcode assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(29,'barcode_assignment','update','Update access for barcode assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(30,'barcode_assignment','archive','Archive access for barcode assignment','2026-06-13 07:37:59','2026-06-13 07:37:59'),(31,'supplier_management','read','Read access for supplier management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(32,'supplier_management','create','Create access for supplier management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(33,'supplier_management','update','Update access for supplier management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(34,'supplier_management','archive','Archive access for supplier management','2026-06-13 07:37:59','2026-06-13 07:37:59'),(35,'item_supplier_mapping','create','Create access for item supplier mapping','2026-06-13 07:37:59','2026-06-13 07:37:59'),(36,'item_supplier_mapping','update','Update access for item supplier mapping','2026-06-13 07:37:59','2026-06-13 07:37:59'),(37,'purchase_order_creation','read','Read access for purchase order creation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(38,'purchase_order_creation','create','Create access for purchase order creation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(39,'purchase_order_creation','update','Update access for purchase order creation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(40,'purchase_order_creation','archive','Archive access for purchase order creation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(41,'purchase_request_approval','approve','Approve access for purchase request approval','2026-06-13 07:37:59','2026-06-13 07:37:59'),(42,'delivery_receiving','read','Read access for delivery receiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(43,'delivery_receiving','create','Create access for delivery receiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(44,'delivery_receiving','update','Update access for delivery receiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(45,'delivery_receiving','archive','Archive access for delivery receiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(46,'sales_recording','read','Read access for pos sales recording','2026-06-13 07:37:59','2026-06-13 07:37:59'),(47,'sales_recording','create','Create access for pos sales recording','2026-06-13 07:37:59','2026-06-13 07:37:59'),(48,'sales_recording','update','Update access for pos sales recording','2026-06-13 07:37:59','2026-06-13 07:37:59'),(49,'sales_recording','archive','Archive access for pos sales recording','2026-06-13 07:37:59','2026-06-13 07:37:59'),(50,'delivery_receipt_generation','read','Read access for delivery receipt generation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(51,'delivery_receipt_generation','create','Create access for delivery receipt generation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(52,'delivery_receipt_generation','update','Update access for delivery receipt generation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(53,'delivery_receipt_generation','archive','Archive access for delivery receipt generation','2026-06-13 07:37:59','2026-06-13 07:37:59'),(55,'inventory_issuance','create','Create access for inventory issuance','2026-06-13 07:37:59','2026-06-13 07:37:59'),(56,'inventory_issuance','update','Update access for inventory issuance','2026-06-13 07:37:59','2026-06-13 07:37:59'),(57,'inventory_issuance','archive','Archive access for inventory issuance','2026-06-13 07:37:59','2026-06-13 07:37:59'),(58,'inventory_transfers','read','Read access for inventory transfers','2026-06-13 07:37:59','2026-06-13 07:37:59'),(59,'inventory_transfers','create','Create access for inventory transfers','2026-06-13 07:37:59','2026-06-13 07:37:59'),(60,'inventory_transfers','update','Update access for inventory transfers','2026-06-13 07:37:59','2026-06-13 07:37:59'),(61,'inventory_transfers','approve','Approve access for inventory transfers','2026-06-13 07:37:59','2026-06-13 07:37:59'),(62,'inventory_adjustments','read','Read access for inventory adjustments','2026-06-13 07:37:59','2026-06-13 07:37:59'),(63,'inventory_adjustments','create','Create access for inventory adjustments','2026-06-13 07:37:59','2026-06-13 07:37:59'),(64,'inventory_adjustments','update','Update access for inventory adjustments','2026-06-13 07:37:59','2026-06-13 07:37:59'),(65,'inventory_adjustments','archive','Archive access for inventory adjustments','2026-06-13 07:37:59','2026-06-13 07:37:59'),(66,'barcode_inventory_processing','create','Create access for barcode inventory processing','2026-06-13 07:37:59','2026-06-13 07:37:59'),(67,'exception_detection','read','Read access for exception detection','2026-06-13 07:37:59','2026-06-13 07:37:59'),(68,'audit_trail_viewing','read','Read access for audit trail viewing','2026-06-13 07:37:59','2026-06-13 07:37:59'),(69,'audit_trail_viewing','archive','Archive access for audit trail viewing','2026-06-13 07:37:59','2026-06-13 07:37:59'),(70,'archive_viewing','read','Read access for archive viewing','2026-06-13 07:37:59','2026-06-13 07:37:59'),(71,'data_archiving','update','Update access for data archiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(72,'data_archiving','archive','Archive access for data archiving','2026-06-13 07:37:59','2026-06-13 07:37:59'),(73,'purchase_request_creation','read','Read access for purchase request creation','2026-06-13 15:14:09','2026-06-13 15:14:09'),(74,'purchase_request_creation','create','Create access for purchase request creation','2026-06-13 15:14:09','2026-06-13 15:14:09'),(75,'purchase_request_creation','archive','Archive access for purchase request creation','2026-06-13 15:14:09','2026-06-13 15:14:09'),(76,'warranty_records','read','Read access for warranty records','2026-06-13 15:14:09','2026-06-13 15:14:09'),(77,'warranty_records','update','Update access for warranty records','2026-06-13 15:14:09','2026-06-13 15:14:09'),(78,'warranty_claims','read','Read access for warranty claims','2026-06-13 15:14:09','2026-06-13 15:14:09'),(79,'warranty_claims','create','Create access for warranty claims','2026-06-13 15:14:09','2026-06-13 15:14:09'),(80,'warranty_claims','update','Update access for warranty claims','2026-06-13 15:14:09','2026-06-13 15:14:09'),(81,'warranty_claims','approve','Approve access for warranty claims','2026-06-13 15:14:09','2026-06-13 15:14:09'),(82,'report_viewing','read','Read access for report viewing','2026-06-13 21:54:21','2026-06-13 21:54:21'),(83,'notification_panel','read','Read access for notification panel','2026-06-13 21:54:21','2026-06-13 21:54:21'),(84,'po_recommendations','read','Read access for po recommendations','2026-07-05 17:12:26','2026-07-05 17:12:26'),(85,'po_recommendations','create','Create access for po recommendations','2026-07-05 17:12:26','2026-07-05 17:12:26'),(86,'po_recommendations','update','Update access for po recommendations','2026-07-05 17:12:26','2026-07-05 17:12:26'),(87,'budget_verification','read','Read access for budget verification','2026-07-09 09:45:46','2026-07-09 09:45:46'),(88,'budget_verification','verify','Verify access for budget verification','2026-07-09 09:45:46','2026-07-09 09:45:46'),(89,'purchase_order_verification','read','Read access for purchase order verification','2026-07-09 09:45:46','2026-07-09 09:45:46'),(90,'purchase_order_verification','verify','Verify access for purchase order verification','2026-07-09 09:45:46','2026-07-09 09:45:46'),(91,'role_assignment','read','Read access for role assignment','2026-07-13 12:10:38','2026-07-13 12:10:38'),(92,'permission_audit','read','Read access for permission audit','2026-07-13 12:10:38','2026-07-13 12:10:38'),(93,'system_configuration','read','Read access for system configuration','2026-07-13 12:10:38','2026-07-13 12:10:38'),(94,'system_configuration','update','Update access for system configuration','2026-07-13 12:10:38','2026-07-13 12:10:38'),(95,'dashboard_access','read','Read access for dashboard access','2026-07-13 12:10:38','2026-07-13 12:10:38'),(96,'analytics_dashboard','read','Read access for analytics dashboard','2026-07-13 12:10:38','2026-07-13 12:10:38'),(97,'customer_management','read','Read access for customer management','2026-07-13 12:10:38','2026-07-13 12:10:38'),(98,'customer_management','create','Create access for customer management','2026-07-13 12:10:38','2026-07-13 12:10:38'),(99,'customer_management','update','Update access for customer management','2026-07-13 12:10:38','2026-07-13 12:10:38'),(100,'customer_management','archive','Archive access for customer management','2026-07-13 12:10:38','2026-07-13 12:10:38'),(101,'inventory_monitoring','read','Read access for inventory monitoring','2026-07-13 12:10:38','2026-07-13 12:10:38'),(102,'stock_transfers','read','Read access for stock transfers','2026-07-13 12:10:38','2026-07-13 12:10:38'),(103,'budget_verification','create','Create access for budget verification','2026-07-13 12:42:56','2026-07-13 12:42:56'),(104,'budget_verification','update','Update access for budget verification','2026-07-13 12:42:56','2026-07-13 12:42:56'),(105,'budget_verification','archive','Archive access for budget verification','2026-07-13 12:42:56','2026-07-13 12:42:56'),(106,'product_set_management','read','Read access for product set management','2026-07-22 07:38:08','2026-07-22 07:38:08'),(107,'product_set_management','create','Create access for product set management','2026-07-22 07:38:08','2026-07-22 07:38:08'),(108,'product_set_management','update','Update access for product set management','2026-07-22 07:38:08','2026-07-22 07:38:08'),(109,'product_set_management','archive','Archive access for product set management','2026-07-22 07:38:08','2026-07-22 07:38:08'),(110,'inventory_issuance','read','Read access for inventory issuance','2026-07-24 19:10:23','2026-07-24 19:10:23'),(111,'inventory_issuance','view_margin','View_margin access for inventory issuance','2026-07-24 19:10:23','2026-07-24 19:10:23'),(112,'incident_reports','read','Read access for incident reports','2026-07-25 07:28:45','2026-07-25 07:28:45'),(113,'incident_reports','create','Create access for incident reports','2026-07-25 07:28:45','2026-07-25 07:28:45'),(114,'incident_reports','resolve','Resolve access for incident reports','2026-07-25 07:28:45','2026-07-25 07:28:45'),(115,'lookup_management','read','Read access for lookup management','2026-07-30 11:15:44','2026-07-30 11:15:44'),(116,'lookup_management','create','Create access for lookup management','2026-07-30 11:15:44','2026-07-30 11:15:44'),(117,'lookup_management','update','Update access for lookup management','2026-07-30 11:15:44','2026-07-30 11:15:44'),(118,'lookup_management','archive','Archive access for lookup management','2026-07-30 11:15:44','2026-07-30 11:15:44'),(120,'incident_reports','inspect','Inspect access for incident reports','2026-08-11 13:11:35','2026-08-11 13:11:35'),(122,'report_viewing','create','Create access for report viewing','2026-08-16 12:14:54','2026-08-16 12:14:54'),(123,'aircon_model_management','read','Read access for aircon model management','2026-08-28 15:37:19','2026-08-28 15:37:19'),(124,'aircon_model_management','create','Create access for aircon model management','2026-08-28 15:37:19','2026-08-28 15:37:19'),(125,'aircon_model_management','update','Update access for aircon model management','2026-08-28 15:37:19','2026-08-28 15:37:19'),(126,'aircon_model_management','archive','Archive access for aircon model management','2026-08-28 15:37:19','2026-08-28 15:37:19');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_granted`, `created_at`, `updated_at`) VALUES (1,1,1,1,'2026-06-13 07:38:07','2026-06-13 07:38:07'),(2,5,1,0,'2026-06-13 07:38:07','2026-06-29 01:48:10'),(3,3,1,0,'2026-06-13 07:38:07','2026-06-13 17:48:13'),(4,2,1,0,'2026-06-13 07:38:07','2026-06-13 07:38:07'),(5,4,1,0,'2026-06-13 07:38:07','2026-06-23 00:35:17'),(6,1,2,1,'2026-06-13 07:38:07','2026-06-13 07:38:07'),(7,5,2,0,'2026-06-13 07:38:07','2026-06-13 07:38:07'),(8,3,2,0,'2026-06-13 07:38:07','2026-06-13 17:48:13'),(9,2,2,0,'2026-06-13 07:38:07','2026-06-13 07:38:07'),(10,4,2,0,'2026-06-13 07:38:07','2026-06-13 16:05:23'),(11,1,3,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(12,5,3,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(13,3,3,0,'2026-06-13 07:38:08','2026-06-13 16:13:48'),(14,2,3,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(15,4,3,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(16,1,4,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(17,5,4,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(18,3,4,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(19,2,4,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(20,4,4,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(21,1,5,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(22,5,5,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(23,3,5,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(24,2,5,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(25,4,5,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(26,1,6,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(27,5,6,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(28,3,6,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(29,2,6,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(30,4,6,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(31,1,7,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(32,5,7,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(33,3,7,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(34,2,7,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(35,4,7,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(36,1,8,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(37,5,8,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(38,3,8,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(39,2,8,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(40,4,8,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(41,1,9,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(42,5,9,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(43,3,9,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(44,2,9,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(45,4,9,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(46,1,10,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(47,5,10,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(48,3,10,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(49,2,10,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(50,4,10,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(51,1,11,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(52,5,11,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(53,3,11,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(54,2,11,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(55,4,11,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(61,1,13,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(62,5,13,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(63,3,13,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(64,2,13,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(65,4,13,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(66,1,14,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(67,5,14,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(68,3,14,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(69,2,14,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(70,4,14,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(71,1,15,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(72,5,15,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(73,3,15,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(74,2,15,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(75,4,15,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(91,1,19,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(92,5,19,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(93,3,19,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(94,2,19,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(95,4,19,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(96,1,20,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(97,5,20,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(98,3,20,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(99,2,20,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(100,4,20,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(101,1,21,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(102,5,21,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(103,3,21,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(104,2,21,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(105,4,21,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(106,1,22,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(107,5,22,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(108,3,22,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(109,2,22,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(110,4,22,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(111,1,23,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(112,5,23,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(113,3,23,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(114,2,23,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(115,4,23,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(116,1,24,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(117,5,24,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(118,3,24,0,'2026-06-13 07:38:08','2026-06-13 17:30:02'),(119,2,24,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(120,4,24,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(121,1,25,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(122,5,25,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(123,3,25,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(124,2,25,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(125,4,25,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(126,1,26,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(127,5,26,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(128,3,26,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(129,2,26,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(130,4,26,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(131,1,27,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(132,5,27,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(133,3,27,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(134,2,27,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(135,4,27,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(136,1,28,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(137,5,28,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(138,3,28,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(139,2,28,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(140,4,28,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(141,1,29,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(142,5,29,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(143,3,29,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(144,2,29,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(145,4,29,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(146,1,30,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(147,5,30,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(148,3,30,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(149,2,30,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(150,4,30,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(151,1,31,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(152,5,31,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(153,3,31,1,'2026-06-13 07:38:08','2026-07-22 07:38:09'),(154,2,31,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(155,4,31,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(156,1,32,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(157,5,32,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(158,3,32,1,'2026-06-13 07:38:08','2026-07-22 07:38:09'),(159,2,32,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(160,4,32,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(161,1,33,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(162,5,33,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(163,3,33,1,'2026-06-13 07:38:08','2026-07-22 07:38:09'),(164,2,33,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(165,4,33,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(166,1,34,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(167,5,34,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(168,3,34,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(169,2,34,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(170,4,34,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(171,1,35,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(172,5,35,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(173,3,35,1,'2026-06-13 07:38:08','2026-06-13 16:13:48'),(174,2,35,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(175,4,35,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(176,1,36,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(177,5,36,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(178,3,36,1,'2026-06-13 07:38:08','2026-06-13 16:13:48'),(179,2,36,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(180,4,36,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(181,1,37,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(182,5,37,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(183,3,37,1,'2026-06-13 07:38:08','2026-06-13 16:13:48'),(184,2,37,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(185,4,37,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(186,1,38,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(187,5,38,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(188,3,38,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(189,2,38,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(190,4,38,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(191,1,39,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(192,5,39,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(193,3,39,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(194,2,39,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(195,4,39,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(196,1,40,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(197,5,40,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(198,3,40,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(199,2,40,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(200,4,40,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(201,1,41,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(202,5,41,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(203,3,41,0,'2026-06-13 07:38:08','2026-06-13 17:49:07'),(204,2,41,0,'2026-06-13 07:38:08','2026-06-13 16:13:48'),(205,4,41,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(206,1,42,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(207,5,42,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(208,3,42,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(209,2,42,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(210,4,42,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(211,1,43,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(212,5,43,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(213,3,43,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(214,2,43,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(215,4,43,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(216,1,44,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(217,5,44,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(218,3,44,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(219,2,44,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(220,4,44,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(221,1,45,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(222,5,45,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(223,3,45,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(224,2,45,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(225,4,45,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(226,1,46,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(227,5,46,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(228,3,46,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(229,2,46,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(230,4,46,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(231,1,47,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(232,5,47,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(233,3,47,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(234,2,47,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(235,4,47,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(236,1,48,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(237,5,48,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(238,3,48,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(239,2,48,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(240,4,48,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(241,1,49,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(242,5,49,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(243,3,49,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(244,2,49,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(245,4,49,0,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(246,1,50,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(247,5,50,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(248,3,50,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(249,2,50,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(250,4,50,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(251,1,51,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(252,5,51,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(253,3,51,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(254,2,51,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(255,4,51,1,'2026-06-13 07:38:08','2026-06-13 16:05:23'),(256,1,52,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(257,5,52,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(258,3,52,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(259,2,52,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(260,4,52,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(261,1,53,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(262,5,53,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(263,3,53,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(264,2,53,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(265,4,53,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(271,1,55,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(272,5,55,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(273,3,55,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(274,2,55,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(275,4,55,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(276,1,56,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(277,5,56,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(278,3,56,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(279,2,56,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(280,4,56,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(281,1,57,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(282,5,57,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(283,3,57,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(284,2,57,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(285,4,57,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(286,1,58,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(287,5,58,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(288,3,58,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(289,2,58,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(290,4,58,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(291,1,59,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(292,5,59,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(293,3,59,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(294,2,59,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(295,4,59,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(296,1,60,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(297,5,60,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(298,3,60,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(299,2,60,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(300,4,60,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(301,1,61,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(302,5,61,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(303,3,61,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(304,2,61,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(305,4,61,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(306,1,62,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(307,5,62,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(308,3,62,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(309,2,62,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(310,4,62,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(311,1,63,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(312,5,63,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(313,3,63,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(314,2,63,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(315,4,63,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(316,1,64,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(317,5,64,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(318,3,64,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(319,2,64,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(320,4,64,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(321,1,65,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(322,5,65,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(323,3,65,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(324,2,65,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(325,4,65,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(326,1,66,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(327,5,66,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(328,3,66,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(329,2,66,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(330,4,66,1,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(331,1,67,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(332,5,67,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(333,3,67,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(334,2,67,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(335,4,67,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(336,1,68,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(337,5,68,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(338,3,68,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(339,2,68,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(340,4,68,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(341,1,69,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(342,5,69,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(343,3,69,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(344,2,69,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(345,4,69,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(346,1,70,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(347,5,70,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(348,3,70,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(349,2,70,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(350,4,70,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(351,1,71,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(352,5,71,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(353,3,71,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(354,2,71,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(355,4,71,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(356,1,72,1,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(357,5,72,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(358,3,72,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(359,2,72,0,'2026-06-13 07:38:08','2026-06-13 07:38:08'),(360,4,72,0,'2026-06-13 07:38:08','2026-06-13 16:05:24'),(361,1,73,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(362,5,73,0,'2026-06-13 15:14:10','2026-07-22 07:38:09'),(363,3,73,0,'2026-06-13 15:14:10','2026-06-13 17:49:07'),(364,2,73,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(365,4,73,0,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(366,1,74,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(367,5,74,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(368,3,74,0,'2026-06-13 15:14:10','2026-06-13 17:49:07'),(369,2,74,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(370,4,74,0,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(371,1,75,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(372,5,75,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(373,3,75,0,'2026-06-13 15:14:10','2026-06-13 17:49:07'),(374,2,75,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(375,4,75,0,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(376,1,76,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(377,5,76,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(378,3,76,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(379,2,76,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(380,4,76,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(381,1,77,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(382,5,77,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(383,3,77,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(384,2,77,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(385,4,77,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(386,1,78,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(387,5,78,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(388,3,78,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(389,2,78,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(390,4,78,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(391,1,79,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(392,5,79,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(393,3,79,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(394,2,79,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(395,4,79,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(396,1,80,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(397,5,80,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(398,3,80,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(399,2,80,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(400,4,80,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(401,1,81,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(402,5,81,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(403,3,81,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(404,2,81,0,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(405,4,81,1,'2026-06-13 15:14:10','2026-06-13 16:05:24'),(406,1,82,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(407,5,82,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(408,3,82,0,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(409,2,82,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(410,4,82,0,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(411,1,83,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(412,5,83,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(413,3,83,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(414,2,83,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(415,4,83,1,'2026-06-13 21:54:24','2026-06-13 21:54:24'),(416,1,84,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(417,5,84,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(418,3,84,1,'2026-07-05 17:12:26','2026-07-22 07:38:09'),(419,2,84,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(420,4,84,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(421,1,85,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(422,5,85,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(423,3,85,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(424,2,85,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(425,4,85,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(426,1,86,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(427,5,86,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(428,3,86,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(429,2,86,1,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(430,4,86,0,'2026-07-05 17:12:26','2026-07-05 17:12:26'),(431,1,87,1,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(432,5,87,1,'2026-07-09 09:45:46','2026-07-13 12:34:08'),(433,3,87,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(434,2,87,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(435,4,87,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(436,1,88,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(437,5,88,1,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(438,3,88,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(439,2,88,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(440,4,88,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(441,1,89,1,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(442,5,89,1,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(443,3,89,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(444,2,89,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(445,4,89,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(446,1,90,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(447,5,90,1,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(448,3,90,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(449,2,90,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(450,4,90,0,'2026-07-09 09:45:46','2026-07-09 09:45:46'),(451,1,91,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(452,5,91,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(453,3,91,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(454,2,91,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(455,4,91,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(456,1,92,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(457,5,92,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(458,3,92,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(459,2,92,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(460,4,92,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(461,1,93,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(462,5,93,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(463,3,93,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(464,2,93,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(465,4,93,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(466,1,94,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(467,5,94,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(468,3,94,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(469,2,94,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(470,4,94,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(471,1,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(472,5,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(473,3,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(474,2,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(475,4,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(476,1,96,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(477,5,96,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(478,3,96,1,'2026-07-13 12:10:38','2026-07-22 07:38:09'),(479,2,96,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(480,4,96,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(481,1,97,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(482,5,97,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(483,3,97,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(484,2,97,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(485,4,97,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(486,1,98,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(487,5,98,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(488,3,98,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(489,2,98,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(490,4,98,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(491,1,99,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(492,5,99,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(493,3,99,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(494,2,99,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(495,4,99,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(496,1,100,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(497,5,100,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(498,3,100,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(499,2,100,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(500,4,100,0,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(501,1,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(502,5,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(503,3,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(504,2,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(505,4,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(506,1,102,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(507,5,102,1,'2026-07-13 12:10:38','2026-08-28 15:37:20'),(508,3,102,1,'2026-07-13 12:10:38','2026-08-28 15:37:20'),(509,2,102,1,'2026-07-13 12:10:38','2026-08-28 15:37:20'),(510,4,102,1,'2026-07-13 12:10:38','2026-08-28 15:37:20'),(511,1,103,1,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(512,5,103,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(513,3,103,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(514,2,103,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(515,4,103,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(516,1,104,1,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(517,5,104,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(518,3,104,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(519,2,104,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(520,4,104,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(521,1,105,1,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(522,5,105,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(523,3,105,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(524,2,105,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(525,4,105,0,'2026-07-13 12:42:56','2026-07-13 12:42:56'),(526,1,106,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(527,5,106,1,'2026-07-22 07:38:09','2026-07-24 08:20:12'),(528,3,106,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(529,2,106,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(530,4,106,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(531,1,107,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(532,5,107,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(533,3,107,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(534,2,107,0,'2026-07-22 07:38:09','2026-07-24 08:20:12'),(535,4,107,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(536,1,108,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(537,5,108,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(538,3,108,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(539,2,108,0,'2026-07-22 07:38:09','2026-07-24 08:20:12'),(540,4,108,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(541,1,109,1,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(542,5,109,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(543,3,109,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(544,2,109,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(545,4,109,0,'2026-07-22 07:38:09','2026-07-22 07:38:09'),(546,1,110,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(547,5,110,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(548,3,110,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(549,2,110,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(550,4,110,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(551,1,111,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(552,5,111,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(553,3,111,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(554,2,111,1,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(555,4,111,0,'2026-07-24 19:10:23','2026-07-24 19:10:23'),(556,1,112,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(557,5,112,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(558,3,112,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(559,2,112,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(560,4,112,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(561,1,113,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(562,5,113,0,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(563,3,113,1,'2026-07-25 07:28:46','2026-08-03 07:58:59'),(564,2,113,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(565,4,113,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(566,1,114,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(567,5,114,0,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(568,3,114,0,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(569,2,114,1,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(570,4,114,0,'2026-07-25 07:28:46','2026-07-25 07:28:46'),(571,1,115,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(572,5,115,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(573,3,115,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(574,2,115,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(575,4,115,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(576,1,116,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(577,5,116,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(578,3,116,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(579,2,116,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(580,4,116,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(581,1,117,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(582,5,117,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(583,3,117,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(584,2,117,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(585,4,117,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(586,1,118,1,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(587,5,118,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(588,3,118,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(589,2,118,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(590,4,118,0,'2026-07-30 11:15:45','2026-07-30 11:15:45'),(596,1,120,1,'2026-08-11 13:11:35','2026-08-11 13:11:35'),(597,5,120,0,'2026-08-11 13:11:35','2026-08-11 13:11:35'),(598,3,120,1,'2026-08-11 13:11:35','2026-08-11 13:11:35'),(599,2,120,1,'2026-08-11 13:11:35','2026-08-11 13:11:35'),(600,4,120,0,'2026-08-11 13:11:35','2026-08-11 13:11:35'),(606,1,122,1,'2026-08-16 12:14:54','2026-08-16 12:14:54'),(607,5,122,1,'2026-08-16 12:14:54','2026-08-16 12:14:54'),(608,3,122,0,'2026-08-16 12:14:54','2026-08-16 12:14:54'),(609,2,122,1,'2026-08-16 12:14:54','2026-08-16 12:14:54'),(610,4,122,0,'2026-08-16 12:14:54','2026-08-16 12:14:54'),(611,1,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(612,5,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(613,3,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(614,2,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(615,4,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(616,1,124,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(617,5,124,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(618,3,124,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(619,2,124,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(620,4,124,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(621,1,125,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(622,5,125,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(623,3,125,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(624,2,125,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(625,4,125,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(626,1,126,1,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(627,5,126,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(628,3,126,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(629,2,126,0,'2026-08-28 15:37:20','2026-08-28 15:37:20'),(630,4,126,0,'2026-08-28 15:37:20','2026-08-28 15:37:20');
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `nav_permission_bundles` WRITE;
/*!40000 ALTER TABLE `nav_permission_bundles` DISABLE KEYS */;
INSERT INTO `nav_permission_bundles` (`bundle_id`, `name`, `route_path`, `sidebar_section`, `sidebar_icon`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES (3,'Location Management','/admin/branches','ADMINISTRATION','location',NULL,4,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(4,'Archive Management','/admin/archive','ADMINISTRATION','archive',NULL,6,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(5,'Purchase Orders','/admin/draft-po-creator','PROCUREMENT & PLANNING','procurement',NULL,2,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(7,'Supplier Network','/admin/suppliers','PROCUREMENT & PLANNING','supplier',NULL,3,1,'2026-06-13 08:27:07','2026-07-19 02:24:39'),(8,'Item Master','/admin/items','PROCUREMENT & PLANNING','items',NULL,4,1,'2026-06-13 08:27:07','2026-07-19 02:24:39'),(9,'Item Lookups','/admin/categories','PROCUREMENT & PLANNING','category',NULL,5,1,'2026-06-13 08:27:07','2026-07-31 12:09:30'),(10,'Transfer Requests','/admin/transfer-requests','INVENTORY INTELLIGENCE','transfer',NULL,1,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(11,'Interbranch Transfers','/admin/ist','INVENTORY INTELLIGENCE','transfer',NULL,3,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(12,'Transactions','/admin/transactions','SALES & LOGISTICS','globaltransactions',NULL,0,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(13,'Scan Barcode','/admin/inventory-operation','SALES & LOGISTICS','scan',NULL,2,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(14,'Compliance Review','/admin/compliance','COMPLIANCE & SUPPORT','compliance',NULL,0,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(15,'Audit Trail','/admin/audit','COMPLIANCE & SUPPORT','audit',NULL,4,1,'2026-06-13 08:27:07','2026-07-13 12:10:38'),(16,'Purchase Requests','/admin/purchase-requests','PROCUREMENT & PLANNING','approval',NULL,1,1,'2026-06-13 15:14:10','2026-07-13 12:10:38'),(17,'Warranty Records','/admin/warranties','COMPLIANCE & SUPPORT','warranty',NULL,1,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(19,'System Reports','/admin/reports','COMPLIANCE & SUPPORT','reports',NULL,2,1,'2026-06-13 21:54:32','2026-07-13 12:10:38'),(20,'Alerts & Notices','/admin/alerts','COMPLIANCE & SUPPORT','notifications',NULL,3,1,'2026-06-13 21:54:32','2026-07-13 12:10:38'),(21,'Dashboard','/admin/dashboard','ADMINISTRATION','dashboard',NULL,0,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(22,'Analytics Dashboard','/admin/analytics','ADMINISTRATION','analytics',NULL,1,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(23,'Role & Permissions','/admin/roles','ADMINISTRATION','roles',NULL,2,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(24,'Permission Audit','/admin/permission-audit','ADMINISTRATION','audit',NULL,3,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(25,'System Configuration','/admin/config','ADMINISTRATION','config',NULL,5,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(26,'Budget Management','/admin/budget-management','PROCUREMENT & PLANNING','budget',NULL,0,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(28,'Inventory Monitoring','/admin/inventory','INVENTORY INTELLIGENCE','inventory',NULL,0,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(29,'Stock Transfers','/admin/transfers','INVENTORY INTELLIGENCE','transfer',NULL,2,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(30,'Customer Registry','/admin/customers','SALES & LOGISTICS','customers',NULL,1,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(31,'Product Sets / BOQ','/admin/product-sets','PROCUREMENT & PLANNING','items',NULL,5,1,'2026-07-22 07:38:10','2026-07-22 07:38:10'),(32,'Set Management','/admin/sets','PROCUREMENT & PLANNING','sets',NULL,6,1,'2026-07-26 00:19:20','2026-07-26 00:19:20'),(33,'Incident Reports','/admin/incidents','INVENTORY INTELLIGENCE','incident',NULL,4,1,'2026-07-26 00:19:20','2026-07-26 00:19:20'),(35,'Inspections','/admin/inspections','INVENTORY INTELLIGENCE','incident',NULL,5,1,'2026-08-11 20:38:50','2026-08-11 20:38:50'),(36,'Incident Items','/admin/incident-items','INVENTORY INTELLIGENCE','incident',NULL,6,1,'2026-08-11 20:56:25','2026-08-11 20:56:25'),(37,'Aircon Models','/admin/aircon-models','PROCUREMENT & PLANNING','sets',NULL,7,1,'2026-08-28 15:37:20','2026-08-28 15:37:20');
/*!40000 ALTER TABLE `nav_permission_bundles` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `nav_bundle_permissions` WRITE;
/*!40000 ALTER TABLE `nav_bundle_permissions` DISABLE KEYS */;
INSERT INTO `nav_bundle_permissions` (`id`, `bundle_id`, `permission_id`, `is_required`, `created_at`, `updated_at`) VALUES (3,3,19,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(4,4,70,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(5,5,37,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(7,7,31,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(8,8,23,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(10,10,58,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(11,11,58,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(12,12,46,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(13,13,66,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(14,14,67,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(15,15,68,1,'2026-06-13 08:27:07','2026-06-13 08:27:07'),(16,16,73,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(17,17,76,1,'2026-06-13 15:14:10','2026-06-13 15:14:10'),(19,19,82,1,'2026-06-13 21:54:32','2026-06-13 21:54:32'),(20,20,83,1,'2026-06-13 21:54:32','2026-06-13 21:54:32'),(21,21,95,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(22,22,96,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(23,23,91,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(24,24,92,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(25,25,93,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(26,26,87,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(28,28,101,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(29,29,102,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(30,30,97,1,'2026-07-13 12:10:38','2026-07-13 12:10:38'),(31,31,106,1,'2026-07-22 07:38:10','2026-07-22 07:38:10'),(32,32,106,1,'2026-07-26 00:19:20','2026-07-26 00:19:20'),(33,33,112,1,'2026-07-26 00:19:20','2026-07-26 00:19:20'),(34,9,115,1,'2026-07-31 12:09:30','2026-07-31 12:09:30'),(36,35,112,1,'2026-08-11 20:38:50','2026-08-11 20:38:50'),(37,36,112,1,'2026-08-11 20:56:25','2026-08-11 20:56:25'),(38,37,123,1,'2026-08-28 15:37:20','2026-08-28 15:37:20');
/*!40000 ALTER TABLE `nav_bundle_permissions` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` (`id`, `key`, `value`, `created_at`, `updated_at`) VALUES (1,'session_timeout_minutes','60','2026-05-11 10:24:02','2026-08-25 12:20:35'),(2,'max_login_attempts','5','2026-05-11 10:24:02','2026-05-11 10:24:02'),(3,'two_factor_auth_enabled','0','2026-05-11 10:24:03','2026-05-11 13:40:28'),(4,'company_name','Airking Air Conditioning','2026-05-17 20:00:16','2026-05-17 20:00:16'),(5,'company_address','CDO Branch Office, Cogon Cagayan de Oro City, Mis.Or','2026-05-17 20:00:16','2026-07-12 16:58:51'),(6,'company_email','cdoairking@gmail.com','2026-05-17 20:00:16','2026-05-17 20:00:16'),(7,'company_phone','0934324564','2026-05-17 20:00:16','2026-05-17 20:00:16'),(8,'archive_config','{\"purchase_orders\":{\"auto_archive_enabled\":true,\"archive_after_days\":45,\"archivable_statuses\":[9,11],\"retention_days\":365,\"require_reason\":false,\"pinnable\":true},\"purchase_requests\":{\"auto_archive_enabled\":true,\"archive_after_days\":60,\"archivable_statuses\":[14,15,16],\"retention_days\":365,\"require_reason\":false,\"pinnable\":true},\"transfers\":{\"auto_archive_enabled\":true,\"archive_after_days\":90,\"archivable_statuses\":[18],\"retention_days\":365,\"require_reason\":false,\"pinnable\":false},\"adjustments\":{\"auto_archive_enabled\":true,\"archive_after_days\":60,\"archivable_statuses\":[20,29],\"retention_days\":365,\"require_reason\":false,\"pinnable\":false},\"issuances\":{\"auto_archive_enabled\":false,\"archive_after_days\":180,\"archivable_statuses\":[31,32],\"retention_days\":730,\"require_reason\":false,\"pinnable\":false},\"products\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"customers\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"suppliers\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"users\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"locations\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true}}','2026-05-21 15:18:54','2026-05-21 07:49:49'),(9,'archive_notifications','{\"notify_on_auto_archive\": true, \"notify_on_retention_run\": true, \"notify_admins_only\": true}','2026-05-21 15:18:54','2026-05-21 15:18:54'),(10,'archive_schedule','{\"auto_archive_enabled\":true,\"auto_archive_time\":\"04:00\",\"auto_archive_frequency\":\"daily\",\"retention_enabled\":true,\"retention_frequency\":\"weekly\",\"retention_time\":\"03:00\"}','2026-05-21 15:18:54','2026-05-21 07:52:59'),(11,'archive_last_run','{\"ran_at\":\"2026-06-27T12:22:55+00:00\",\"total_archived\":0,\"is_dry_run\":true,\"log_id\":7}','2026-05-21 07:28:30','2026-06-27 04:22:55'),(12,'account_self_service_policy','{\"avatar\":true,\"email\":true,\"phone\":true,\"address\":true}','2026-06-28 22:02:32','2026-06-29 02:12:21');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `locations` WRITE;
/*!40000 ALTER TABLE `locations` DISABLE KEYS */;
INSERT INTO `locations` (`location_id`, `parent_location_id`, `location_code`, `location_name`, `location_type`, `address`, `city`, `province`, `region`, `contact_number`, `email`, `manager_name`, `capacity`, `opening_date`, `status_id`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES (1,NULL,'CDO105','CDO Warehouse A','warehouse','Zone 5, Bulua CDO Mis.Or','Cagayan de Oro','Misamis Oriental','Region X','0934324564','cdoairking@gmail.com','Maam Abegail',5000,'2026-04-24',5,1,'2026-04-24 03:18:22','2026-04-24 03:18:36',NULL),(2,NULL,'CDO102','CDO Main Showroom','showroom','JR Borja','Cagayan de Oro','Misamis Oriental','Region X','0934324564','cdoairking@gmail.com','Maam Abegail',5000,'2026-04-24',NULL,1,'2026-04-24 05:03:39','2026-04-24 05:03:39',NULL),(3,NULL,'WH-CDO-B','CDO Warehouse B','warehouse','Zone 6, Bulua','Cagayan de Oro','Misamis Oriental','Region X','092237544',NULL,NULL,NULL,'2026-07-11',NULL,1,'2026-07-11 04:43:53','2026-07-11 04:43:53',NULL);
/*!40000 ALTER TABLE `locations` ENABLE KEYS */;
UNLOCK TABLES;

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_00_01_create_roles_and_lookups',1),(2,'2026_00_02_create_locations_users_and_user_locations',1),(3,'2026_00_03_create_products_appliance_details_consumable_details',1),(4,'2026_00_04_create_budget_allocations',1),(5,'2026_00_05_create_purchase_requests_and_details',1),(6,'2026_00_06_create_purchase_orders_and_details',1),(7,'2026_00_07_create_receivings_and_returns',1),(8,'2026_00_08_create_inventory_and_transactions',1),(9,'2026_00_09_create_audit_and_security',1),(10,'2025_01_15_000000_update_locations_unified',2),(11,'2025_01_20_000000_add_approved_by_to_purchase_order_details',2),(12,'2026_04_27_000000_add_scan_fields_to_item_serial',2),(13,'2026_05_01_000000_create_user_alert_settings_table',2),(14,'2026_05_02_000000_add_scanned_barcode_to_receiving_details',2),(15,'2026_05_02_000001_add_po_receiving_statuses',2),(16,'2026_05_02_000003_add_packages_on_hand_to_inventory',2),(17,'2026_05_10_000001_create_system_settings_table',3),(18,'2026_05_10_090058_add_otp_fields_to_users_table',3),(19,'2026_05_10_100000_add_max_login_attempts_system_setting',3),(20,'2026_05_10_120000_create_password_reset_codes_table',3),(21,'2026_05_10_130000_add_code_plain_to_password_reset_codes_table',3),(22,'2026_05_10_140000_add_two_factor_auth_system_setting',3),(23,'2026_05_13_000000_extend_budget_period_label_column',4),(24,'2026_05_13_183230_create_scan_corrections_table',5),(25,'2026_05_13_183235_add_po_receiving_to_adjustments',5),(26,'2026_05_16_000000_add_preferred_supplier_to_purchase_requests',6),(27,'2026_05_19_205808_add_columns_to_issuance_details_table',7),(28,'2026_05_19_205808_add_columns_to_issuances_table',7),(29,'2026_05_19_205809_add_issuance_id_to_delivery_receipts',7),(30,'2026_05_19_205809_create_warranty_records_table',7),(31,'2026_05_20_000001_add_warranty_value_unit_columns',8),(32,'2026_05_20_000003_add_claim_fields_to_warranty_records',9),(33,'2026_05_21_090636_add_soft_delete_to_all_tables',10),(37,'2026_05_23_000001_create_product_issuable_units_table',12),(38,'2026_05_23_000002_seed_product_issuable_units',12),(39,'2026_05_26_000001_setup_interbranch_stock_transfer',13),(40,'2026_06_07_000001_create_warranty_claims_tables',14),(41,'2026_06_07_000002_add_warranty_claim_statuses',15),(42,'2026_06_07_000001_guarantee_transfer_statuses',16),(43,'2026_06_07_000002_add_quantity_to_barcode_scans',17),(55,'2026_06_13_100001_create_permissions_table',18),(56,'2026_06_13_100002_create_role_permissions_table',18),(57,'2026_06_13_100003_create_user_permission_overrides_table',18),(58,'2026_06_13_100004_create_permission_audit_logs_table',18),(59,'2026_06_13_100005_create_nav_permission_bundles_table',19),(60,'2026_06_13_100006_create_nav_bundle_permissions_table',19),(61,'2026_06_25_000000_drop_unused_queue_tables',20),(62,'2026_06_28_000000_drop_user_alert_settings_table',21),(63,'2026_06_28_120000_add_avatar_and_address_to_users',22),(64,'2026_07_05_000000_add_products_created_at_index',23),(65,'2026_07_06_000001_extend_restock_recommendations',24),(66,'2026_07_06_000002_create_stock_parameter_suggestions_table',25),(67,'2026_06_25_100000_drop_subcategory_lookup',26),(68,'2026_06_25_110000_replace_appliance_model_id_with_model_code',27),(69,'2026_07_08_000000_rename_consumable_to_installation_material',28),(71,'2026_07_08_100000_create_budget_transactions_table',29),(72,'2026_07_09_000000_add_verification_to_budgets_and_pos',30),(73,'2026_07_10_000000_add_supplier_and_line_location_to_prs',31),(74,'2026_07_10_000001_create_supplier_product_table',32),(75,'2026_07_12_000001_add_location_id_to_purchase_order_details',33),(76,'2026_07_19_000001_restock_recommendations_per_warehouse_unique',34),(77,'2026_07_21_000001_create_inventory_batches_table',35),(78,'2026_07_21_000002_add_receiving_id_to_issuance_details_table',36),(80,'2026_07_21_000003_add_zone_label_to_inventory_batches_table',37),(81,'2026_07_22_000000_transfer_intent_and_nullable_source',38),(86,'2026_07_24_000000_drop_abandoned_product_set_schema',41),(88,'2026_07_24_000001_create_product_sets_tables',42),(89,'2026_07_24_000002_add_issuance_order_columns',43),(90,'2026_07_24_000003_create_issuance_sets_and_reservations',44),(91,'2026_07_24_000004_add_issuance_details_batch_foreign_key',45),(92,'2026_07_24_000005_create_incident_reports_table',46),(93,'2026_07_26_000001_add_issuance_cancelled_status',47),(94,'2026_07_27_000001_redesign_product_sets_multibrand',48),(95,'2026_07_27_000002_add_stock_snapshot_to_issuance_details',49),(96,'2026_07_27_000003_reconcile_inventory_batches',50),(97,'2026_07_27_000004_create_inventory_batch_movements_table',51),(98,'2026_07_27_000006_backfill_missing_issuance_warranties',52),(99,'2026_07_28_000001_add_external_reference_to_issuances_table',53),(100,'2026_07_28_000010_expand_warranty_claim_order_and_holding',53),(101,'2026_07_28_120000_normalize_scan_receiving_numbers',53),(102,'2026_07_30_000001_create_capacity_lookup_table',54),(103,'2026_07_30_000002_create_variant_lookup_table',55),(104,'2026_07_30_000003_create_issuable_unit_preset_table',56),(105,'2026_07_30_000005_make_unit_abbreviation_nullable',57),(106,'2026_07_30_000004_backfill_lookup_tables',58),(107,'2026_08_01_000001_drop_issuable_unit_tables',59),(108,'2026_08_01_000002_normalise_category_type',60),(109,'2026_08_01_000003_archive_descriptor_variants',61),(110,'2026_08_01_000004_slim_lookup_tables',62),(112,'2026_08_03_000001_extend_incident_reports_for_standalone',63),(113,'2026_08_09_000001_add_photo_source_to_incident_reports',64),(114,'2026_08_11_000001_create_stock_holds',65),(115,'2026_08_11_000002_add_cause_fields_to_incident_reports',66),(116,'2026_08_11_000003_migrate_incident_reasons',67),(117,'2026_08_12_000001_create_incident_items',68),(118,'2026_08_12_000002_create_incident_evidence',69),(119,'2026_08_12_000003_add_inspection_fields_to_incident_reports',70),(120,'2026_08_12_000004_migrate_stock_holds_to_incident_items',71),(121,'2026_08_12_000005_drop_incident_dispose_permission',72),(122,'2026_08_13_000001_extend_incidents_for_returns',73),(123,'2026_08_13_000002_widen_incident_item_status',74),(124,'2026_08_03_000001_create_password_reset_otps_table',75),(125,'2026_08_03_010000_restructure_password_reset_otps_for_email_token',75),(126,'2026_08_14_000001_split_awaiting_inspection_status',76),(128,'2026_08_17_000001_create_report_runs_table',77),(130,'2026_08_17_000002_add_report_viewing_create_permission',78),(132,'2026_08_21_000000_add_recovery_email_and_must_change_password_to_users',79),(133,'2026_08_22_000000_make_recovery_email_unique',80),(134,'2026_08_24_000000_add_otp_attempts_to_users',81),(135,'2026_08_25_000001_create_ac_type_lookup_table',82),(136,'2026_08_25_000002_add_ac_fields_to_appliance_details',82),(137,'2026_08_25_000003_backfill_ac_types_from_descriptions',82),(138,'2026_08_25_000004_add_ceiling_mounted_ac_type',82),(139,'2026_08_25_000005_create_aircon_models_tables',82),(140,'2026_08_25_000006_add_aircon_model_id_to_document_details',82),(141,'2026_08_25_000007_retype_ceiling_mounted_aircons',83);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

