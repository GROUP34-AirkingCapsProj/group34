-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 27, 2026 at 02:54 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `airkingv5`
--

-- --------------------------------------------------------

--
-- Table structure for table `adjustments`
--

CREATE TABLE `adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `adjusted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `adjustment_number` varchar(50) NOT NULL,
  `adjustment_date` date DEFAULT NULL,
  `adjustment_type` varchar(50) DEFAULT NULL,
  `total_variance_positive` int(11) NOT NULL DEFAULT 0,
  `total_variance_negative` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adjustment_details`
--

CREATE TABLE `adjustment_details` (
  `adjustment_detail_id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `add_quantity` int(11) NOT NULL DEFAULT 0,
  `deduct_quantity` int(11) NOT NULL DEFAULT 0,
  `reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `appliance_details`
--

CREATE TABLE `appliance_details` (
  `appliance_detail_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL COMMENT 'One-to-one with products',
  `brand_id` bigint(20) UNSIGNED DEFAULT NULL,
  `model_code` varchar(100) DEFAULT NULL,
  `capacity_rating` varchar(20) DEFAULT NULL COMMENT '1HP|1.5HP|2HP|2.5HP|3HP|5HP',
  `variant` varchar(100) DEFAULT NULL COMMENT 'e.g. Inverter, Standard',
  `color` varchar(50) DEFAULT NULL,
  `warranty_period_months` int(11) NOT NULL DEFAULT 0,
  `warranty_value` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `warranty_unit` varchar(10) NOT NULL DEFAULT 'month',
  `is_serialized` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Always 1 — each unit has its own barcode/serial',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Appliance-only fields. Populated when products.product_type = appliance.';

--
-- Dumping data for table `appliance_details`
--

INSERT INTO `appliance_details` (`appliance_detail_id`, `product_id`, `brand_id`, `model_code`, `capacity_rating`, `variant`, `color`, `warranty_period_months`, `warranty_value`, `warranty_unit`, `is_serialized`, `created_at`, `updated_at`) VALUES
(4, 7, 11, 'CSD-10DA', '1HP', 'Morandi Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(5, 8, 11, 'CSD-15DA', '1.5HP', 'Morandi Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(6, 9, 11, 'CSD-20DA', '2HP', 'Morandi Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(7, 10, 11, 'CSD-25DA', '2.5HP', 'Morandi Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(8, 11, 11, 'CSD-10DC', '1HP', 'EVA Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(9, 12, 11, 'CSD-15DC', '1.5HP', 'EVA Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(10, 13, 10, 'TAC-06CWM/F', '0.6HP', 'Window Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(11, 14, 10, 'TAC-07CWI/UB', '0.8HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(12, 15, 10, 'TAC-09CWI/UB', '1HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(13, 16, 10, 'TAC-12CWI/UB', '1.5HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(14, 17, 10, 'TAC-09CWI/UJE', '1HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(15, 18, 10, 'TAC-12CWI/UJE', '1.5HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(16, 19, 10, 'TAC-18CWI/UJE', '2HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(17, 20, 10, 'TAC-24CWI/UJE', '2.5HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(18, 21, 10, 'TAC-07CWI/UB2', '0.8HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(19, 22, 10, 'TAC-09CWI/UB2', '1HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(20, 23, 10, 'TAC-12CWI/UB2', '1.5HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(21, 24, 10, 'KV09WR-ARF31A', '1HP', 'Window Standard Inverter (New Model)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(22, 25, 10, 'KV12WR-ARF31A', '1.5HP', 'Window Standard Inverter (New Model)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(23, 26, 10, 'TAC-09CWI/UJE2', '1HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(24, 27, 10, 'TAC-12CWI/UJE2', '1.5HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(25, 28, 10, 'TAC-18CWI/UJE2', '2HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(26, 29, 10, 'TAC-24CWI/UJE2', '2.5HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(27, 30, 10, 'TAC-09CSA/MEI', '1HP', 'T-Pro IoT Inverter (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(28, 31, 10, 'TAC-12CSA/MEI', '1.5HP', 'T-Pro IoT Inverter (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(29, 32, 10, 'TAC-18CSA/MEI', '2HP', 'T-Pro IoT Inverter (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(30, 33, 10, 'TAC-24CSA/MEI', '2.5HP', 'T-Pro IoT Inverter (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(31, 34, 10, 'TAC-10CSD/KEI-S', '1HP', 'CoolPro EcoIn (2025 B2B Exclusive)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(32, 35, 10, 'TAC-13CSD/KEI-S', '1.5HP', 'CoolPro EcoIn (2025 B2B Exclusive)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(33, 36, 10, 'TAC-19CSD/KEI-S', '2HP', 'CoolPro EcoIn (2025 B2B Exclusive)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(34, 37, 10, 'TAC-25CSD/KEI-S', '2.5HP', 'CoolPro EcoIn (2025 B2B Exclusive)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(35, 38, 10, 'TAC-30CSD/KEI-S', '3HP', 'CoolPro EcoIn (2025 B2B Exclusive)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(36, 39, 10, 'TAC-09CSA/BEI', '1HP', 'Cool Pro Obsidian+ (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(37, 40, 10, 'TAC-12CSA/BEI', '1.5HP', 'Cool Pro Obsidian+ (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(38, 41, 10, 'TAC-18CSA/BEI', '2HP', 'Cool Pro Obsidian+ (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(39, 42, 10, 'TAC-24CSA/BEI', '2.5HP', 'Cool Pro Obsidian+ (Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(40, 43, 10, 'TAC-10CSD/WEI', '1HP', 'VoxIN (Voice Control + Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(41, 44, 10, 'TAC-13CSD/WEI', '1.5HP', 'VoxIN (Voice Control + Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(42, 45, 10, 'TAC-19CSD/WEI', '2HP', 'VoxIN (Voice Control + Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(43, 46, 10, 'TAC-25CSD/WEI', '2.5HP', 'VoxIN (Voice Control + Wi-Fi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(44, 47, 10, '28TCFS/JI', '3HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(45, 48, 10, '36TCFS/JI', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(46, 49, 10, '48TCFS/JI', '6HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(47, 50, 10, '28TCFS/JI2', '3HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(48, 51, 10, '60TCFS/JI2', '7HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(49, 52, 10, '24TCHC/MEI2', '2.5HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(50, 53, 10, '36TCHC/MEI2', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(51, 54, 10, '42TCHC/MEI2', '5HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(52, 55, 10, '60TCHC/MEI2', '6HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(53, 56, 10, '36TCHC/CHI2', '4HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(54, 57, 10, '42TCHC/CHI2', '5HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(55, 58, 10, '60TCHC/CHI2', '6HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(56, 59, 10, 'TAC-08CSA-KEI', '0.8HP', 'Titan Gold', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(57, 60, 10, 'TAC-09CSA-KEI', '1HP', 'Titan Gold', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(58, 61, 10, 'TAC-12CSA-KEI', '1.5HP', 'Titan Gold', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(59, 62, 10, 'TAC-18CSA-KEI', '2HP', 'Titan Gold', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(60, 63, 10, 'TAC-22CSA-KEI', '2.5HP', 'Titan Gold', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(61, 64, 12, 'IFAC-09CSD/XAF1', '1HP', 'Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(62, 65, 12, 'IFAC-12CSD/XAF1', '1.5HP', 'Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(63, 66, 12, 'IFAC-18CSD/XAF1', '2HP', 'Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(64, 67, 12, 'IFAC-24CSD/XAF1', '2.5HP', 'Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(65, 68, 13, 'FP-51ARA006HMNV-N5', '0.6HP', 'Window Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(66, 69, 13, 'FP-38AFS036KENV-J5', '4HP', 'Floor Standing Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(67, 70, 13, 'MWWA-08CRFN8', '0.8HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(68, 71, 13, 'MWWA-09CRFN8', '1HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(69, 72, 13, 'MWWA-12CRFN8', '1.5HP', 'Window Standard Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(70, 73, 13, 'FP-51ARA020HEIV-N4', '2HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(71, 74, 13, 'FP-51ARA025HEIV-N4', '2.5HP', 'Window Smart Inverter (WiFi)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(72, 75, 13, 'FP-53AST010KEIV-F4', '1HP', 'Celest Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(73, 76, 13, 'FP-53AST010KEIV-F4-2.5HP', '2.5HP', 'Celest Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(74, 77, 13, 'MSCE-10CRFN8', '1HP', 'Celest Pro Full DC Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(75, 78, 13, 'MSCE-13CRFN8', '1.5HP', 'Celest Pro Full DC Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(76, 79, 13, 'MSCE-19CRFN8', '2HP', 'Celest Pro Full DC Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(77, 80, 13, 'MSCE-22CRFN8', '2.5HP', 'Celest Pro Full DC Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(78, 81, 13, 'MSCE-25CRFN8', '3HP', 'Celest Pro Full DC Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(79, 82, 13, 'MFSI/O-36CDN8-MD3', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(80, 83, 13, 'MFSI/O-52CDN8-MD1', '6HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(81, 84, 13, 'MFSI/O-52CDN8-DD1', '6HP', 'Floor Standing Inverter (3P 220V)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(82, 85, 13, 'MCCI/O-36CDN8-MD3', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(83, 86, 13, 'MCCI/O-52CDN8-MD1', '6HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(84, 87, 13, 'MUCI/O-36CDN8-MD3', '4HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(85, 88, 13, 'MCCI/O-52CDN8-MD1-6HP', '6HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(86, 89, 13, 'FP-42AFC036KENV-N6', '4HP', 'Ceiling Mounted Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(87, 90, 14, 'HSU-09CSV32', '1HP', 'Clean Cool Pro Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(88, 91, 14, 'HSU-13CSV32', '1.5HP', 'Clean Cool Pro Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(89, 92, 14, 'HSU-18CSV32', '2HP', 'Clean Cool Pro Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(90, 93, 14, 'HSU-25CSV32', '2.5HP', 'Clean Cool Pro Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(91, 94, 14, 'HSU-30PSV32', '3HP', 'Clean Cool Pro Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(92, 95, 14, 'AP105S2SK1SA', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(93, 96, 14, 'AP60S4SE1ET', '6HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(94, 97, 14, 'ABH105H1ERG', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(95, 98, 14, 'ABH140K1ERG', '6HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(96, 99, 14, 'AC105S2SH1FA', '4HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(97, 100, 14, 'AC140S2SK1FA', '6HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(98, 101, 2, 'AR09TYHYEWKNTC', '1HP', 'Standard Inverter (Digital S-Inverter)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(99, 102, 2, 'AR12TYHYEWKNTC', '1.5HP', 'Standard Inverter (Digital S-Inverter)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(100, 103, 2, 'AR18TYHYEWKNTC', '2HP', 'Standard Inverter (Digital S-Inverter)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(101, 104, 2, 'AR24TYHYEWKNTC', '2.5HP', 'Standard Inverter (Digital S-Inverter)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(102, 105, 2, 'AR10AYKAGWKXTC', '1HP', 'WindFree ActivClean', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(103, 106, 2, 'AR10AYKAGWKXTC-1.5HP', '1.5HP', 'WindFree ActivClean', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(104, 107, 2, 'AR10AYKAGWKXTC-2HP', '2HP', 'WindFree ActivClean', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(105, 108, 2, 'AR10AYKAGWKXTC-2.5HP', '2.5HP', 'WindFree ActivClean', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(106, 109, 2, 'AC036BNPDKC/TC', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(107, 110, 2, 'AC048BNPDKC/TC', '6HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(108, 111, 2, 'AC100TN4DKC/TC', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(109, 112, 2, 'AC140TN4DKC/TC', '5HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(110, 113, 2, 'AC100TNCDKC/TC', '4HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(111, 114, 2, 'AC140TNCDKC/TC', '5HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(112, 115, 2, 'AC160TNCDKC/TC', '6HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(113, 116, 15, 'FP-53CAC009308', '1HP', 'Nexus Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(114, 117, 15, 'FP-53CAC012308', '1.5HP', 'Nexus Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(115, 118, 15, 'FP-53CAC018308', '2HP', 'Nexus Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(116, 119, 15, 'FP-53CAC024308', '2.5HP', 'Nexus Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(117, 120, 15, '38CL V036308', '4HP', 'Slim Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(118, 121, 15, 'DFE-ASD 04.0HP', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(119, 122, 15, '42DFECAR04HEGS2GN1', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(120, 123, 15, 'DFE-ASD 06.0HP', '6HP', 'Floor Standing Inverter (1-Phase 220V)', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(121, 124, 15, 'FP-53CFE-ASC', '4HP', 'Floor Standing Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(122, 125, 15, 'FP-53CFE600', '6HP', 'Floor Standing Non-Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(123, 126, 15, '53CAV036308-2', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(124, 127, 15, '53CAV055308-2', '5HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(125, 128, 15, '53CUV036308-2', '4HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(126, 129, 15, '53CUV055308-2', '5HP', 'Ceiling Mounted Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(127, 130, 15, '42DFECAR06HEGS2GN', NULL, 'Chilled Water', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(128, 131, 16, 'KV09WM-ARF31D', '1HP', 'R32 Super Inverter - Lunaire', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(129, 132, 16, 'KV12WM-ARF31D', '1.5HP', 'R32 Super Inverter - Lunaire', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(130, 133, 16, 'KV18WM-ARF31D', '2HP', 'R32 Super Inverter - Lunaire', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(131, 134, 16, 'KV24WM-ARF31D', '2.5HP', 'R32 Super Inverter - Lunaire', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(132, 135, 16, 'KV27WM-ARF31D', '3HP', 'R32 Super Inverter - Lunaire', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(133, 136, 16, 'KV36FM-ARF21D', '4HP', 'Floor Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(134, 137, 16, 'KV60FM-ARF21D', '6HP', 'Floor Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(135, 138, 16, 'KV36CC-ARF21D', '4HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(136, 139, 16, 'KV60CC-ARF21D', '6HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(137, 140, 3, 'HS09IBA', '1HP', 'Dual Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(138, 141, 3, 'HS12IBA', '1.5HP', 'Dual Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(139, 142, 3, 'HS18IBA', '2HP', 'Dual Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(140, 143, 3, 'HS24IBA', '2.5HP', 'Dual Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(141, 144, 3, 'ZPNQ36GT3E0', '4HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(142, 145, 3, 'ZPNQ60GT3A0', '6HP', 'Floor Standing Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(143, 146, 3, 'ZTNQ30GNLE0/ENWHASA', '3HP', 'Ceiling Cassette Inverter', 'White', 36, 3, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(144, 147, 10, 'S45H', NULL, 'Soundbar', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(145, 148, 10, 'S55H', NULL, 'Soundbar', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(146, 149, 10, 'Q75H', NULL, 'Soundbar', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(147, 150, 10, 'Q85H', NULL, 'Soundbar', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(148, 151, 10, '32D3400', NULL, 'Digital TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(149, 152, 10, '32S5401AF', NULL, 'Digital TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(150, 153, 10, '32S5402', NULL, 'Android Smart TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(151, 154, 10, '32S5K', NULL, '2K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(152, 155, 10, '40S5400A', NULL, 'Android Smart TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(153, 156, 10, '40S5K', NULL, '2K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(154, 157, 10, '43S5400A', NULL, 'Android Smart TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(155, 158, 10, '43S5K', NULL, '2K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(156, 159, 10, '43V6B', NULL, '4K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(157, 160, 10, '50V6B', NULL, '4K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(158, 161, 10, '55P635', NULL, '4K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(159, 162, 10, '65V6B', NULL, '4K Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(160, 163, 10, '43P7K', NULL, 'QLED Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(161, 164, 10, '50P7K', NULL, 'QLED Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(162, 165, 10, '55P7K', NULL, 'QLED Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(163, 166, 10, '65P7K', NULL, 'QLED Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(164, 167, 10, '75P7K', NULL, 'QLED Google TV', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(165, 168, 10, '85P8K', NULL, 'QLED Google TV 144Hz', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(166, 169, 10, '98P8K', NULL, 'QLED Google TV 144Hz', 'Black', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(167, 170, 7, 'TR-59PHB', NULL, '1 Door Direct Cool Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(168, 171, 7, 'TR-99PHB', NULL, 'Single Door Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(169, 172, 7, 'TR-190INV/DB', NULL, 'Single Door Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(170, 173, 7, 'TRF-118PH', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(171, 174, 7, 'TRF-207PH', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(172, 175, 7, 'TRF-230PH', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(173, 176, 7, 'TRF-230INV/D', NULL, '2 Door Top Mount No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(174, 177, 7, 'TRF-H199INV/N', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(175, 178, 7, 'TRF-H220INV/N', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(176, 179, 7, 'TRF-H260INV/N', NULL, '2 Door Top Mount Defrost Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(177, 180, 7, 'TRF-H360INV/N', NULL, '2 Door Top Mount No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(178, 181, 7, 'TRF-INV430CDSS', NULL, '4 Door Cross Door No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(179, 182, 7, 'TRF-INV436BMSB', NULL, '4 Door Cross Door No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(180, 183, 7, 'TRF-505PH', NULL, '2 Door Side-by-Side No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(181, 184, 7, 'TRF-199 INV/S', NULL, 'Freezer', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(182, 185, 7, 'TRF-210 INV/N', NULL, 'Freezer', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(183, 186, 7, 'TCF-230 INV/D', NULL, 'Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(184, 187, 7, 'TCF-185PUPH', NULL, 'Chest Freezer Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(185, 188, 7, 'TCF-245PUPH', NULL, 'Chest Freezer Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(186, 189, 7, 'TCF-305PUPH', NULL, 'Chest Freezer Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(187, 190, 7, 'TCF-380PUPH', NULL, 'Chest Freezer Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(188, 191, 7, 'TCF-450PUPH', NULL, 'Chest Freezer Non-Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(189, 192, 7, 'TUF-H260INV/D', NULL, 'Upright Freezer Inverter Defrost', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(190, 193, 7, 'TRF-INV660SBGB', NULL, '2 Door Side-by-Side No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(191, 194, 7, 'TRF-INV560CDGB', NULL, '4 Door Cross Door No Frost Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(192, 195, 7, 'TWT-60Z1', NULL, 'Twin Tub', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(193, 196, 7, 'TWT-80Z2', NULL, 'Twin Tub', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(194, 197, 7, 'TWT-90Z2', NULL, 'Twin Tub', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(195, 198, 7, 'TWT-100Z2', NULL, 'Twin Tub', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(196, 199, 7, 'TWT-130Z2', NULL, 'Twin Tub', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(197, 200, 7, 'TWA75-F107TLW', NULL, 'Top Load Fully Automatic', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(198, 201, 7, 'TWA85-F708TLW', NULL, 'Top Load Fully Automatic', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(199, 202, 7, 'TWA95-F709TLW', NULL, 'Top Load Fully Automatic', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(200, 203, 7, 'TWA90-P71', NULL, 'Top Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(201, 204, 7, 'TWA100-P71', NULL, 'Top Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(202, 205, 7, 'TWA110-P71', NULL, 'Top Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(203, 206, 7, 'TWA130-P70', NULL, 'Top Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(204, 207, 7, 'TWF60-P60', NULL, 'Front Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(205, 208, 7, 'TWF65-P60', NULL, 'Front Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(206, 209, 7, 'TWF75-P60', NULL, 'Front Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(207, 210, 7, 'TWF85-P60', NULL, 'Front Load Fully Automatic Inverter', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(208, 211, 7, 'TWF105-C20', NULL, 'Front Load Fully Automatic Inverter Commercial', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(209, 212, 7, 'TWD120-C682DG', NULL, 'Front Load Fully Automatic Inverter Commercial', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(210, 213, 7, 'TY-LWYR87W', NULL, 'Top Load', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04'),
(211, 214, 7, 'TY-LWYR110T', NULL, 'Bottom Load', 'White', 12, 1, 'year', 1, '2026-06-29 16:00:00', '2026-07-07 03:51:04');

-- --------------------------------------------------------

--
-- Table structure for table `archive_logs`
--

CREATE TABLE `archive_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `triggered_by` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'manual | scheduled | system',
  `triggered_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `run_type` varchar(20) NOT NULL DEFAULT 'auto_archive' COMMENT 'auto_archive | retention_cleanup | preview',
  `modules_processed` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON: {module: {archived: N, skipped: N, errors: N}}' CHECK (json_valid(`modules_processed`)),
  `total_archived` int(11) NOT NULL DEFAULT 0,
  `total_deleted` int(11) NOT NULL DEFAULT 0,
  `total_skipped` int(11) NOT NULL DEFAULT 0,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `duration_ms` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `archive_logs`
--

INSERT INTO `archive_logs` (`log_id`, `triggered_by`, `triggered_by_user_id`, `run_type`, `modules_processed`, `total_archived`, `total_deleted`, `total_skipped`, `is_dry_run`, `duration_ms`, `notes`, `created_at`, `updated_at`) VALUES
(6, 'scheduled', NULL, 'preview', '{\"purchase_orders\":{\"would_archive\":0,\"cutoff_date\":\"2026-05-13\",\"statuses\":[9,11]},\"purchase_requests\":{\"would_archive\":0,\"cutoff_date\":\"2026-04-28\",\"statuses\":[14,15,16]},\"transfers\":{\"would_archive\":0,\"cutoff_date\":\"2026-03-29\",\"statuses\":[18]},\"adjustments\":{\"would_archive\":0,\"cutoff_date\":\"2026-04-28\",\"statuses\":[20,29]},\"issuances\":{\"archived\":0,\"skipped\":0,\"reason\":\"auto_archive_disabled\"}}', 0, 0, 0, 1, 219, NULL, '2026-06-27 04:22:24', '2026-06-27 04:22:24'),
(7, 'scheduled', NULL, 'preview', '{\"purchase_orders\":{\"would_archive\":0,\"cutoff_date\":\"2026-05-13\",\"statuses\":[9,11]},\"purchase_requests\":{\"would_archive\":0,\"cutoff_date\":\"2026-04-28\",\"statuses\":[14,15,16]},\"transfers\":{\"would_archive\":0,\"cutoff_date\":\"2026-03-29\",\"statuses\":[18]},\"adjustments\":{\"would_archive\":0,\"cutoff_date\":\"2026-04-28\",\"statuses\":[20,29]},\"issuances\":{\"archived\":0,\"skipped\":0,\"reason\":\"auto_archive_disabled\"}}', 0, 0, 0, 1, 14, NULL, '2026-06-27 04:22:55', '2026-06-27 04:22:55');

-- --------------------------------------------------------

--
-- Table structure for table `audit_trail`
--

CREATE TABLE `audit_trail` (
  `audit_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `old_values` longtext DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_trail`
--

INSERT INTO `audit_trail` (`audit_id`, `user_id`, `action`, `table_name`, `record_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`, `updated_at`) VALUES
(2763, 2, 'logout', NULL, NULL, NULL, NULL, '10.218.135.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36 Edg/150.0.0.0', '2026-07-27 04:54:39', '2026-07-27 04:54:39');

-- --------------------------------------------------------

--
-- Table structure for table `barcode_scans`
--

CREATE TABLE `barcode_scans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `barcode` varchar(191) NOT NULL,
  `scan_mode` varchar(64) NOT NULL COMMENT 'receive-po | issue-out | transfer | adjustment',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `scanned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `brand_lookup`
--

CREATE TABLE `brand_lookup` (
  `brand_id` bigint(20) UNSIGNED NOT NULL,
  `brand_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `brand_lookup`
--

INSERT INTO `brand_lookup` (`brand_id`, `brand_name`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Airking', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(2, 'Samsung', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(3, 'LG', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(4, 'Panasonic', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(5, 'Sony', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(6, 'Whirlpool', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(7, 'Condura', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(8, 'Hanabishi', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(9, 'Imarflex', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(10, 'TCL', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(11, 'CHiQ', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL),
(12, 'Iffalcon', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL),
(13, 'Midea', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL),
(14, 'Haier', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL),
(15, 'Carrier', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL),
(16, 'Koppel', '2026-06-29 16:00:00', '2026-06-29 16:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `budget_allocations`
--

CREATE TABLE `budget_allocations` (
  `budget_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `period_label` varchar(100) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_budget` decimal(14,2) NOT NULL DEFAULT 0.00,
  `committed` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of approved PRs not yet completed',
  `spent` decimal(14,2) NOT NULL DEFAULT 0.00 COMMENT 'Sum of completed POs',
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_note` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_transactions`
--

CREATE TABLE `budget_transactions` (
  `txn_id` bigint(20) UNSIGNED NOT NULL,
  `budget_id` bigint(20) UNSIGNED NOT NULL,
  `txn_type` varchar(20) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `pr_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `performed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('laravel-cache-eeccb52dc3bffe8890fc8ea15e685be56ff3dedb', 'i:3;', 1784743416),
('laravel-cache-eeccb52dc3bffe8890fc8ea15e685be56ff3dedb:timer', 'i:1784743416;', 1784743416);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `category_lookup`
--

CREATE TABLE `category_lookup` (
  `category_id` bigint(20) UNSIGNED NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_type` varchar(50) NOT NULL COMMENT 'appliance | installation_material',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `category_lookup`
--

INSERT INTO `category_lookup` (`category_id`, `category_name`, `category_type`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Air Conditioning', 'appliance', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(2, 'Television', 'appliance', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(3, 'Refrigerator', 'appliance', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(4, 'Washing Machine', 'appliance', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(5, 'Small Appliances', 'appliance', '2026-04-22 21:50:17', '2026-04-22 21:50:17', '2026-07-07 17:33:06'),
(6, 'Installation Materials', 'installation_material', '2026-04-22 21:50:17', '2026-07-07 17:31:34', '2026-07-07 17:50:59'),
(7, 'Copper Tubing', 'installation_material', '2026-04-22 21:50:17', '2026-04-22 21:50:17', '2026-07-07 17:31:34'),
(8, 'Accessories', 'installation_material', '2026-04-22 21:50:17', '2026-04-22 21:50:17', '2026-07-07 17:31:34'),
(9, 'Water Dispenser', 'appliance', '2026-07-07 17:31:34', '2026-07-07 17:31:34', NULL),
(10, 'Copper Tube', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(11, 'PE Foam', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(12, 'Rubber Insulation', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(13, 'Brackets', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(14, 'Consumables', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(15, 'Electricals', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(16, 'Bolts, Nuts & Small Items', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(17, 'Refrigerants', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(18, 'PVC Fittings', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(19, 'Capacitors', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL),
(20, 'Copper Fittings', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', '2026-07-07 18:15:56'),
(21, 'Basic Tools', 'installation_material', '2026-07-07 17:50:59', '2026-07-07 17:50:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` bigint(20) UNSIGNED NOT NULL,
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
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `customer_code`, `customer_type`, `customer_name`, `contact_number`, `email`, `company_name`, `address`, `city`, `province`, `region`, `tin`, `notes`, `credit_limit`, `outstanding_balance`, `status_id`, `created_at`, `updated_at`, `deleted_at`) VALUES
(13, 'REG-1C7E86', 'registered', 'Abc', '0966372826', 'hajaj@gmail.com', 'Hjajja', NULL, 'Hsjshs', 'Hehehe', 'Hshsh', '9977', NULL, NULL, 0.00, 36, '2026-07-20 00:36:17', '2026-07-20 00:36:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `delivery_receipts`
--

CREATE TABLE `delivery_receipts` (
  `dr_id` bigint(20) UNSIGNED NOT NULL,
  `dr_number` varchar(50) NOT NULL,
  `sales_id` bigint(20) UNSIGNED DEFAULT NULL,
  `issuance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dr_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `issued_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `incident_reports`
--

CREATE TABLE `incident_reports` (
  `incident_id` bigint(20) UNSIGNED NOT NULL,
  `incident_number` varchar(40) NOT NULL,
  `source_type` varchar(20) NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expected_zone` varchar(100) DEFAULT NULL,
  `actual_zone` varchar(100) DEFAULT NULL,
  `reason` varchar(30) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `narrative` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `reported_by` bigint(20) UNSIGNED NOT NULL,
  `status_id` bigint(20) UNSIGNED NOT NULL,
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `adjustment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `correction_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resolution_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `installation_material_details`
--

CREATE TABLE `installation_material_details` (
  `consumable_detail_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL COMMENT 'One-to-one with products',
  `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `pieces_per_package` int(10) UNSIGNED DEFAULT NULL,
  `package_unit` varchar(50) DEFAULT NULL COMMENT 'e.g. Box, Drum, Carton',
  `is_serialized` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Always 0 — tracked by quantity only',
  `warranty_value` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `warranty_unit` varchar(10) NOT NULL DEFAULT 'month',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Consumable-only fields. Populated when products.product_type = consumable.';

--
-- Dumping data for table `installation_material_details`
--

INSERT INTO `installation_material_details` (`consumable_detail_id`, `product_id`, `unit_id`, `pieces_per_package`, `package_unit`, `is_serialized`, `warranty_value`, `warranty_unit`, `created_at`, `updated_at`) VALUES
(4, 215, 3, 10, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(5, 216, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(6, 217, 3, 8, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(7, 218, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(8, 219, 3, 6, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(9, 220, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(10, 221, 3, 5, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(11, 222, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(12, 223, 3, 4, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(13, 224, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(14, 225, 2, 150, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(15, 226, 2, 130, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(16, 227, 2, 100, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(17, 228, 2, 80, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(18, 229, 2, 70, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(19, 230, 2, 100, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(20, 231, 2, 90, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(21, 232, 2, 72, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(22, 233, 2, 63, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(23, 234, 2, 56, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(24, 235, 2, 48, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(25, 236, 2, 36, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(26, 237, 2, 30, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(27, 238, 2, 30, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(28, 239, 2, 15, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(29, 240, 2, 12, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(30, 241, 2, 12, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(31, 242, 2, 6, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(32, 243, 2, 50, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(33, 244, 13, 60, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(34, 245, 13, 90, 'Can', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(35, 246, 2, 52, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(36, 247, 2, 57, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(37, 248, 2, 40, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(38, 249, 2, 59, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(39, 250, 2, 20, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(40, 251, 2, 40, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(41, 252, 2, 25, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(42, 253, 2, 40, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(43, 254, 12, NULL, NULL, 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(44, 255, 2, 500, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(45, 256, 3, 75, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(46, 257, 4, 82, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(47, 258, 3, 75, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(48, 259, 4, 12, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(49, 260, 4, 150, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(50, 261, 4, 19, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(51, 262, 4, 150, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(52, 263, 4, 15, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(53, 264, 4, 150, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(54, 265, 4, 65, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(55, 266, 3, 100, 'Roll', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(56, 267, 4, 36, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(57, 268, 2, 400, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(58, 269, 2, 34, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(59, 270, 2, 85, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(60, 271, 2, 88, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(61, 272, 2, 20, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(62, 273, 2, 86, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(63, 274, 2, 65, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(64, 275, 2, 85, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(65, 276, 2, 10, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(66, 277, 2, 29, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(67, 278, 2, 386, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(68, 279, 2, 46, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(69, 280, 2, 46, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(70, 281, 2, 71, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(71, 282, 2, 37, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(72, 283, 2, 50, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(73, 284, 13, 12, 'Can', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(74, 285, 13, 35, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(75, 286, 13, 85, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(76, 287, 13, 82, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(77, 288, 2, 65, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(78, 289, 2, 42, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(79, 290, 2, 37, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(80, 291, 2, 22, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(81, 292, 2, 23, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(82, 293, 2, 35, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(83, 294, 2, 82, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(84, 295, 14, 28, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(85, 296, 6, 31, 'Box', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(86, 297, 2, 68, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(87, 298, 2, 18, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(88, 299, 2, 34, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(89, 300, 2, 79, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(90, 301, 2, 90, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(91, 302, 2, 33, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(92, 303, 2, 35, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(93, 304, 2, 45, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(94, 305, 2, 21, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(95, 306, 2, 82, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(96, 307, 2, 22, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(97, 308, 2, 88, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(98, 309, 2, 84, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(99, 310, 2, 15, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(100, 311, 2, 34, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(101, 312, 2, 55, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(102, 313, 2, 19, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(103, 314, 2, 76, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(104, 315, 2, 90, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(105, 316, 2, 45, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(106, 317, 2, 68, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(107, 318, 2, 70, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(108, 319, 2, 84, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(109, 320, 2, 39, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(110, 321, 2, 60, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(111, 322, 2, 44, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(112, 323, 2, 64, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(113, 324, 3, 90, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(114, 325, 4, 48, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(115, 326, 2, 68, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(116, 327, 2, 89, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(117, 328, 2, 62, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(118, 329, 2, 44, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(119, 330, 2, 62, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(120, 331, 2, 95, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(121, 332, 2, 53, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(122, 333, 2, 23, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(123, 334, 2, 64, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(124, 335, 2, 72, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(125, 336, 2, 90, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(126, 337, 2, 99, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(127, 338, 2, 12, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(128, 339, 2, 64, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(129, 340, 2, 82, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(130, 341, 2, 79, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18'),
(131, 342, 2, 64, 'Pack', 0, 1, 'year', '2026-06-29 16:00:00', '2026-07-07 17:22:18');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_on_hand` int(11) NOT NULL DEFAULT 0,
  `packages_on_hand` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `available_quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 0,
  `last_updated` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_batches`
--

CREATE TABLE `inventory_batches` (
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `quantity_remaining` int(11) NOT NULL DEFAULT 0,
  `received_at` timestamp NULL DEFAULT NULL,
  `zone_label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_batch_movements`
--

CREATE TABLE `inventory_batch_movements` (
  `batch_movement_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED NOT NULL,
  `movement_type` varchar(20) NOT NULL,
  `issuance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `from_location_id` bigint(20) UNSIGNED NOT NULL,
  `to_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `serial_number` varchar(255) DEFAULT NULL,
  `performed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_scan_events`
--

CREATE TABLE `inventory_scan_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `transaction_type` varchar(32) NOT NULL,
  `action` varchar(16) NOT NULL COMMENT 'accepted | rejected',
  `reason` varchar(500) DEFAULT NULL,
  `barcode` varchar(191) NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_delta` int(11) NOT NULL DEFAULT 0,
  `serial_number` varchar(191) DEFAULT NULL,
  `meta` longtext DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issuances`
--

CREATE TABLE `issuances` (
  `issuance_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dr_number` varchar(50) DEFAULT NULL,
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
  `issued_to_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `issued_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issuance_details`
--

CREATE TABLE `issuance_details` (
  `issuance_detail_id` bigint(20) UNSIGNED NOT NULL,
  `issuance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `issuance_set_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL COMMENT 'Appliances only — the scanned serial',
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `zone_label` varchar(100) DEFAULT NULL,
  `stock_before` int(10) UNSIGNED DEFAULT NULL,
  `quantity_deducted` int(10) UNSIGNED DEFAULT NULL,
  `stock_after` int(10) UNSIGNED DEFAULT NULL,
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
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issuance_sets`
--

CREATE TABLE `issuance_sets` (
  `issuance_set_id` bigint(20) UNSIGNED NOT NULL,
  `issuance_id` bigint(20) UNSIGNED NOT NULL,
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `set_quantity` int(11) NOT NULL DEFAULT 1,
  `set_name_snapshot` varchar(191) NOT NULL,
  `was_modified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_serial`
--

CREATE TABLE `item_serial` (
  `serial_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `scanned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_number` varchar(100) NOT NULL,
  `serial_type` varchar(50) DEFAULT NULL COMMENT 'received | issued | transferred',
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `parent_location_id` bigint(20) UNSIGNED DEFAULT NULL,
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
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`location_id`, `parent_location_id`, `location_code`, `location_name`, `location_type`, `address`, `city`, `province`, `region`, `contact_number`, `email`, `manager_name`, `capacity`, `opening_date`, `status_id`, `is_active`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, NULL, 'CDO105', 'CDO Warehouse A', 'warehouse', 'Zone 5, Bulua CDO Mis.Or', 'Cagayan de Oro', 'Misamis Oriental', 'Region X', '0934324564', 'cdoairking@gmail.com', 'Maam Abegail', 5000, '2026-04-24', 5, 1, '2026-04-24 03:18:22', '2026-04-24 03:18:36', NULL),
(2, NULL, 'CDO102', 'CDO Main Showroom', 'showroom', 'JR Borja', 'Cagayan de Oro', 'Misamis Oriental', 'Region X', '0934324564', 'cdoairking@gmail.com', 'Maam Abegail', 5000, '2026-04-24', NULL, 1, '2026-04-24 05:03:39', '2026-04-24 05:03:39', NULL),
(3, NULL, 'WH-CDO-B', 'CDO Warehouse B', 'warehouse', 'Zone 6, Bulua', 'Cagayan de Oro', 'Misamis Oriental', 'Region X', '092237544', NULL, NULL, NULL, '2026-07-11', NULL, 1, '2026-07-11 04:43:53', '2026-07-11 04:43:53', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `login_activities`
--

CREATE TABLE `login_activities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `username_attempt` varchar(100) DEFAULT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `failure_reason` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2026_00_01_create_roles_and_lookups', 1),
(2, '2026_00_02_create_locations_users_and_user_locations', 1),
(3, '2026_00_03_create_products_appliance_details_consumable_details', 1),
(4, '2026_00_04_create_budget_allocations', 1),
(5, '2026_00_05_create_purchase_requests_and_details', 1),
(6, '2026_00_06_create_purchase_orders_and_details', 1),
(7, '2026_00_07_create_receivings_and_returns', 1),
(8, '2026_00_08_create_inventory_and_transactions', 1),
(9, '2026_00_09_create_audit_and_security', 1),
(10, '2025_01_15_000000_update_locations_unified', 2),
(11, '2025_01_20_000000_add_approved_by_to_purchase_order_details', 2),
(12, '2026_04_27_000000_add_scan_fields_to_item_serial', 2),
(13, '2026_05_01_000000_create_user_alert_settings_table', 2),
(14, '2026_05_02_000000_add_scanned_barcode_to_receiving_details', 2),
(15, '2026_05_02_000001_add_po_receiving_statuses', 2),
(16, '2026_05_02_000003_add_packages_on_hand_to_inventory', 2),
(17, '2026_05_10_000001_create_system_settings_table', 3),
(18, '2026_05_10_090058_add_otp_fields_to_users_table', 3),
(19, '2026_05_10_100000_add_max_login_attempts_system_setting', 3),
(20, '2026_05_10_120000_create_password_reset_codes_table', 3),
(21, '2026_05_10_130000_add_code_plain_to_password_reset_codes_table', 3),
(22, '2026_05_10_140000_add_two_factor_auth_system_setting', 3),
(23, '2026_05_13_000000_extend_budget_period_label_column', 4),
(24, '2026_05_13_183230_create_scan_corrections_table', 5),
(25, '2026_05_13_183235_add_po_receiving_to_adjustments', 5),
(26, '2026_05_16_000000_add_preferred_supplier_to_purchase_requests', 6),
(27, '2026_05_19_205808_add_columns_to_issuance_details_table', 7),
(28, '2026_05_19_205808_add_columns_to_issuances_table', 7),
(29, '2026_05_19_205809_add_issuance_id_to_delivery_receipts', 7),
(30, '2026_05_19_205809_create_warranty_records_table', 7),
(31, '2026_05_20_000001_add_warranty_value_unit_columns', 8),
(32, '2026_05_20_000003_add_claim_fields_to_warranty_records', 9),
(33, '2026_05_21_090636_add_soft_delete_to_all_tables', 10),
(34, '2026_05_21_151716_create_archive_logs_table', 11),
(39, '2026_05_26_000001_setup_interbranch_stock_transfer', 13),
(40, '2026_06_07_000001_create_warranty_claims_tables', 14),
(41, '2026_06_07_000002_add_warranty_claim_statuses', 15),
(42, '2026_06_07_000001_guarantee_transfer_statuses', 16),
(43, '2026_06_07_000002_add_quantity_to_barcode_scans', 17),
(55, '2026_06_13_100001_create_permissions_table', 18),
(56, '2026_06_13_100002_create_role_permissions_table', 18),
(57, '2026_06_13_100003_create_user_permission_overrides_table', 18),
(58, '2026_06_13_100004_create_permission_audit_logs_table', 18),
(59, '2026_06_13_100005_create_nav_permission_bundles_table', 19),
(60, '2026_06_13_100006_create_nav_bundle_permissions_table', 19),
(61, '2026_06_25_000000_drop_unused_queue_tables', 20),
(62, '2026_06_28_000000_drop_user_alert_settings_table', 21),
(63, '2026_06_28_120000_add_avatar_and_address_to_users', 22),
(64, '2026_07_05_000000_add_products_created_at_index', 23),
(65, '2026_07_06_000001_extend_restock_recommendations', 24),
(66, '2026_07_06_000002_create_stock_parameter_suggestions_table', 25),
(67, '2026_06_25_100000_drop_subcategory_lookup', 26),
(68, '2026_06_25_110000_replace_appliance_model_id_with_model_code', 27),
(69, '2026_07_08_000000_rename_consumable_to_installation_material', 28),
(71, '2026_07_08_100000_create_budget_transactions_table', 29),
(72, '2026_07_09_000000_add_verification_to_budgets_and_pos', 30),
(73, '2026_07_10_000000_add_supplier_and_line_location_to_prs', 31),
(74, '2026_07_10_000001_create_supplier_product_table', 32),
(75, '2026_07_12_000001_add_location_id_to_purchase_order_details', 33),
(76, '2026_07_19_000001_restock_recommendations_per_warehouse_unique', 34),
(77, '2026_07_21_000001_create_inventory_batches_table', 35),
(78, '2026_07_21_000002_add_receiving_id_to_issuance_details_table', 36),
(80, '2026_07_21_000003_add_zone_label_to_inventory_batches_table', 37),
(81, '2026_07_22_000000_transfer_intent_and_nullable_source', 38),
(86, '2026_07_24_000000_drop_abandoned_product_set_schema', 41),
(88, '2026_07_24_000001_create_product_sets_tables', 42),
(89, '2026_07_24_000002_add_issuance_order_columns', 43),
(90, '2026_07_24_000003_create_issuance_sets_and_reservations', 44),
(91, '2026_07_24_000004_add_issuance_details_batch_foreign_key', 45),
(92, '2026_07_24_000005_create_incident_reports_table', 46),
(93, '2026_07_26_000001_add_issuance_cancelled_status', 47),
(94, '2026_07_27_000001_redesign_product_sets_multibrand', 48),
(95, '2026_07_27_000002_add_stock_snapshot_to_issuance_details', 49),
(96, '2026_07_27_000003_reconcile_inventory_batches', 50),
(97, '2026_07_27_000004_create_inventory_batch_movements_table', 51),
(98, '2026_07_27_000006_backfill_missing_issuance_warranties', 52);

-- --------------------------------------------------------

--
-- Table structure for table `nav_bundle_permissions`
--

CREATE TABLE `nav_bundle_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bundle_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `nav_bundle_permissions`
--

INSERT INTO `nav_bundle_permissions` (`id`, `bundle_id`, `permission_id`, `is_required`, `created_at`, `updated_at`) VALUES
(3, 3, 19, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(4, 4, 70, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(5, 5, 37, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(7, 7, 31, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(8, 8, 23, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(9, 9, 8, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(10, 10, 58, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(11, 11, 58, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(12, 12, 46, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(13, 13, 66, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(14, 14, 67, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(15, 15, 68, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(16, 16, 73, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(17, 17, 76, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(19, 19, 82, 1, '2026-06-13 21:54:32', '2026-06-13 21:54:32'),
(20, 20, 83, 1, '2026-06-13 21:54:32', '2026-06-13 21:54:32'),
(21, 21, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(22, 22, 96, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(23, 23, 91, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(24, 24, 92, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(25, 25, 93, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(26, 26, 87, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(28, 28, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(29, 29, 102, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(30, 30, 97, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(31, 31, 106, 1, '2026-07-22 07:38:10', '2026-07-22 07:38:10'),
(32, 32, 106, 1, '2026-07-26 00:19:20', '2026-07-26 00:19:20'),
(33, 33, 112, 1, '2026-07-26 00:19:20', '2026-07-26 00:19:20');

-- --------------------------------------------------------

--
-- Table structure for table `nav_permission_bundles`
--

CREATE TABLE `nav_permission_bundles` (
  `bundle_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `route_path` varchar(255) NOT NULL,
  `sidebar_section` varchar(255) NOT NULL,
  `sidebar_icon` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `nav_permission_bundles`
--

INSERT INTO `nav_permission_bundles` (`bundle_id`, `name`, `route_path`, `sidebar_section`, `sidebar_icon`, `description`, `sort_order`, `is_active`, `created_at`, `updated_at`) VALUES
(3, 'Location Management', '/admin/branches', 'ADMINISTRATION', 'location', NULL, 4, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(4, 'Archive Management', '/admin/archive', 'ADMINISTRATION', 'archive', NULL, 6, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(5, 'Purchase Orders', '/admin/draft-po-creator', 'PROCUREMENT & PLANNING', 'procurement', NULL, 2, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(7, 'Supplier Network', '/admin/suppliers', 'PROCUREMENT & PLANNING', 'supplier', NULL, 3, 1, '2026-06-13 08:27:07', '2026-07-19 02:24:39'),
(8, 'Item Master', '/admin/items', 'PROCUREMENT & PLANNING', 'items', NULL, 4, 1, '2026-06-13 08:27:07', '2026-07-19 02:24:39'),
(9, 'Categories and Brands', '/admin/categories', 'PROCUREMENT & PLANNING', 'category', NULL, 5, 1, '2026-06-13 08:27:07', '2026-07-26 00:19:20'),
(10, 'Transfer Requests', '/admin/transfer-requests', 'INVENTORY INTELLIGENCE', 'transfer', NULL, 1, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(11, 'Interbranch Transfers', '/admin/ist', 'INVENTORY INTELLIGENCE', 'transfer', NULL, 3, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(12, 'Transactions', '/admin/transactions', 'SALES & LOGISTICS', 'globaltransactions', NULL, 0, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(13, 'Scan Barcode', '/admin/inventory-operation', 'SALES & LOGISTICS', 'scan', NULL, 2, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(14, 'Compliance Review', '/admin/compliance', 'COMPLIANCE & SUPPORT', 'compliance', NULL, 0, 1, '2026-06-13 08:27:07', '2026-06-13 08:27:07'),
(15, 'Audit Trail', '/admin/audit', 'COMPLIANCE & SUPPORT', 'audit', NULL, 4, 1, '2026-06-13 08:27:07', '2026-07-13 12:10:38'),
(16, 'Purchase Requests', '/admin/purchase-requests', 'PROCUREMENT & PLANNING', 'approval', NULL, 1, 1, '2026-06-13 15:14:10', '2026-07-13 12:10:38'),
(17, 'Warranty Records', '/admin/warranties', 'COMPLIANCE & SUPPORT', 'warranty', NULL, 1, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(19, 'System Reports', '/admin/reports', 'COMPLIANCE & SUPPORT', 'reports', NULL, 2, 1, '2026-06-13 21:54:32', '2026-07-13 12:10:38'),
(20, 'Alerts & Notices', '/admin/alerts', 'COMPLIANCE & SUPPORT', 'notifications', NULL, 3, 1, '2026-06-13 21:54:32', '2026-07-13 12:10:38'),
(21, 'Dashboard', '/admin/dashboard', 'ADMINISTRATION', 'dashboard', NULL, 0, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(22, 'Analytics Dashboard', '/admin/analytics', 'ADMINISTRATION', 'analytics', NULL, 1, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(23, 'Role & Permissions', '/admin/roles', 'ADMINISTRATION', 'roles', NULL, 2, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(24, 'Permission Audit', '/admin/permission-audit', 'ADMINISTRATION', 'audit', NULL, 3, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(25, 'System Configuration', '/admin/config', 'ADMINISTRATION', 'config', NULL, 5, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(26, 'Budget Management', '/admin/budget-management', 'PROCUREMENT & PLANNING', 'budget', NULL, 0, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(28, 'Inventory Monitoring', '/admin/inventory', 'INVENTORY INTELLIGENCE', 'inventory', NULL, 0, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(29, 'Stock Transfers', '/admin/transfers', 'INVENTORY INTELLIGENCE', 'transfer', NULL, 2, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(30, 'Customer Registry', '/admin/customers', 'SALES & LOGISTICS', 'customers', NULL, 1, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(31, 'Product Sets / BOQ', '/admin/product-sets', 'PROCUREMENT & PLANNING', 'items', NULL, 5, 1, '2026-07-22 07:38:10', '2026-07-22 07:38:10'),
(32, 'Set Management', '/admin/sets', 'PROCUREMENT & PLANNING', 'sets', NULL, 6, 1, '2026-07-26 00:19:20', '2026-07-26 00:19:20'),
(33, 'Incident Reports', '/admin/incidents', 'INVENTORY INTELLIGENCE', 'incident', NULL, 4, 1, '2026-07-26 00:19:20', '2026-07-26 00:19:20');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_codes`
--

CREATE TABLE `password_reset_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `code_plain` varchar(40) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pending_products`
--

CREATE TABLE `pending_products` (
  `pending_product_id` bigint(20) UNSIGNED NOT NULL,
  `barcode` varchar(100) NOT NULL,
  `registration_kind` varchar(24) NOT NULL DEFAULT 'consumable',
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `brand_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supply_type` varchar(120) DEFAULT NULL,
  `packaging_unit` varchar(32) DEFAULT NULL,
  `quantity_per_package` decimal(12,2) DEFAULT NULL,
  `generated_name` varchar(255) NOT NULL,
  `appliance_snapshot` longtext DEFAULT NULL CHECK (json_valid(`appliance_snapshot`)),
  `opening_quantity` int(10) UNSIGNED DEFAULT NULL,
  `opening_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `final_product_name` varchar(255) DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `feature` varchar(255) NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`permission_id`, `feature`, `action`, `description`, `created_at`, `updated_at`) VALUES
(1, 'user_account_management', 'read', 'Read access for user account management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(2, 'user_account_management', 'create', 'Create access for user account management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(3, 'user_account_management', 'update', 'Update access for user account management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(4, 'user_account_management', 'archive', 'Archive access for user account management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(5, 'role_assignment', 'create', 'Create access for role assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(6, 'role_assignment', 'update', 'Update access for role assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(7, 'role_assignment', 'archive', 'Archive access for role assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(8, 'category_management', 'read', 'Read access for category management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(9, 'category_management', 'create', 'Create access for category management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(10, 'category_management', 'update', 'Update access for category management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(11, 'category_management', 'archive', 'Archive access for category management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(13, 'brand_management', 'create', 'Create access for brand management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(14, 'brand_management', 'update', 'Update access for brand management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(15, 'brand_management', 'archive', 'Archive access for brand management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(19, 'location_based_inventory', 'read', 'Read access for location based inventory', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(20, 'location_based_inventory', 'create', 'Create access for location based inventory', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(21, 'location_based_inventory', 'update', 'Update access for location based inventory', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(22, 'location_based_inventory', 'archive', 'Archive access for location based inventory', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(23, 'item_master_management', 'read', 'Read access for item master management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(24, 'item_master_management', 'create', 'Create access for item master management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(25, 'item_master_management', 'update', 'Update access for item master management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(26, 'item_master_management', 'archive', 'Archive access for item master management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(27, 'item_master_management', 'approve', 'Approve access for item master management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(28, 'barcode_assignment', 'create', 'Create access for barcode assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(29, 'barcode_assignment', 'update', 'Update access for barcode assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(30, 'barcode_assignment', 'archive', 'Archive access for barcode assignment', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(31, 'supplier_management', 'read', 'Read access for supplier management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(32, 'supplier_management', 'create', 'Create access for supplier management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(33, 'supplier_management', 'update', 'Update access for supplier management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(34, 'supplier_management', 'archive', 'Archive access for supplier management', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(35, 'item_supplier_mapping', 'create', 'Create access for item supplier mapping', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(36, 'item_supplier_mapping', 'update', 'Update access for item supplier mapping', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(37, 'purchase_order_creation', 'read', 'Read access for purchase order creation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(38, 'purchase_order_creation', 'create', 'Create access for purchase order creation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(39, 'purchase_order_creation', 'update', 'Update access for purchase order creation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(40, 'purchase_order_creation', 'archive', 'Archive access for purchase order creation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(41, 'purchase_request_approval', 'approve', 'Approve access for purchase request approval', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(42, 'delivery_receiving', 'read', 'Read access for delivery receiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(43, 'delivery_receiving', 'create', 'Create access for delivery receiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(44, 'delivery_receiving', 'update', 'Update access for delivery receiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(45, 'delivery_receiving', 'archive', 'Archive access for delivery receiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(46, 'sales_recording', 'read', 'Read access for pos sales recording', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(47, 'sales_recording', 'create', 'Create access for pos sales recording', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(48, 'sales_recording', 'update', 'Update access for pos sales recording', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(49, 'sales_recording', 'archive', 'Archive access for pos sales recording', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(50, 'delivery_receipt_generation', 'read', 'Read access for delivery receipt generation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(51, 'delivery_receipt_generation', 'create', 'Create access for delivery receipt generation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(52, 'delivery_receipt_generation', 'update', 'Update access for delivery receipt generation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(53, 'delivery_receipt_generation', 'archive', 'Archive access for delivery receipt generation', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(55, 'inventory_issuance', 'create', 'Create access for inventory issuance', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(56, 'inventory_issuance', 'update', 'Update access for inventory issuance', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(57, 'inventory_issuance', 'archive', 'Archive access for inventory issuance', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(58, 'inventory_transfers', 'read', 'Read access for inventory transfers', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(59, 'inventory_transfers', 'create', 'Create access for inventory transfers', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(60, 'inventory_transfers', 'update', 'Update access for inventory transfers', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(61, 'inventory_transfers', 'approve', 'Approve access for inventory transfers', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(62, 'inventory_adjustments', 'read', 'Read access for inventory adjustments', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(63, 'inventory_adjustments', 'create', 'Create access for inventory adjustments', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(64, 'inventory_adjustments', 'update', 'Update access for inventory adjustments', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(65, 'inventory_adjustments', 'archive', 'Archive access for inventory adjustments', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(66, 'barcode_inventory_processing', 'create', 'Create access for barcode inventory processing', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(67, 'exception_detection', 'read', 'Read access for exception detection', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(68, 'audit_trail_viewing', 'read', 'Read access for audit trail viewing', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(69, 'audit_trail_viewing', 'archive', 'Archive access for audit trail viewing', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(70, 'archive_viewing', 'read', 'Read access for archive viewing', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(71, 'data_archiving', 'update', 'Update access for data archiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(72, 'data_archiving', 'archive', 'Archive access for data archiving', '2026-06-13 07:37:59', '2026-06-13 07:37:59'),
(73, 'purchase_request_creation', 'read', 'Read access for purchase request creation', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(74, 'purchase_request_creation', 'create', 'Create access for purchase request creation', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(75, 'purchase_request_creation', 'archive', 'Archive access for purchase request creation', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(76, 'warranty_records', 'read', 'Read access for warranty records', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(77, 'warranty_records', 'update', 'Update access for warranty records', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(78, 'warranty_claims', 'read', 'Read access for warranty claims', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(79, 'warranty_claims', 'create', 'Create access for warranty claims', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(80, 'warranty_claims', 'update', 'Update access for warranty claims', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(81, 'warranty_claims', 'approve', 'Approve access for warranty claims', '2026-06-13 15:14:09', '2026-06-13 15:14:09'),
(82, 'report_viewing', 'read', 'Read access for report viewing', '2026-06-13 21:54:21', '2026-06-13 21:54:21'),
(83, 'notification_panel', 'read', 'Read access for notification panel', '2026-06-13 21:54:21', '2026-06-13 21:54:21'),
(84, 'po_recommendations', 'read', 'Read access for po recommendations', '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(85, 'po_recommendations', 'create', 'Create access for po recommendations', '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(86, 'po_recommendations', 'update', 'Update access for po recommendations', '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(87, 'budget_verification', 'read', 'Read access for budget verification', '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(88, 'budget_verification', 'verify', 'Verify access for budget verification', '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(89, 'purchase_order_verification', 'read', 'Read access for purchase order verification', '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(90, 'purchase_order_verification', 'verify', 'Verify access for purchase order verification', '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(91, 'role_assignment', 'read', 'Read access for role assignment', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(92, 'permission_audit', 'read', 'Read access for permission audit', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(93, 'system_configuration', 'read', 'Read access for system configuration', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(94, 'system_configuration', 'update', 'Update access for system configuration', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(95, 'dashboard_access', 'read', 'Read access for dashboard access', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(96, 'analytics_dashboard', 'read', 'Read access for analytics dashboard', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(97, 'customer_management', 'read', 'Read access for customer management', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(98, 'customer_management', 'create', 'Create access for customer management', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(99, 'customer_management', 'update', 'Update access for customer management', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(100, 'customer_management', 'archive', 'Archive access for customer management', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(101, 'inventory_monitoring', 'read', 'Read access for inventory monitoring', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(102, 'stock_transfers', 'read', 'Read access for stock transfers', '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(103, 'budget_verification', 'create', 'Create access for budget verification', '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(104, 'budget_verification', 'update', 'Update access for budget verification', '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(105, 'budget_verification', 'archive', 'Archive access for budget verification', '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(106, 'product_set_management', 'read', 'Read access for product set management', '2026-07-22 07:38:08', '2026-07-22 07:38:08'),
(107, 'product_set_management', 'create', 'Create access for product set management', '2026-07-22 07:38:08', '2026-07-22 07:38:08'),
(108, 'product_set_management', 'update', 'Update access for product set management', '2026-07-22 07:38:08', '2026-07-22 07:38:08'),
(109, 'product_set_management', 'archive', 'Archive access for product set management', '2026-07-22 07:38:08', '2026-07-22 07:38:08'),
(110, 'inventory_issuance', 'read', 'Read access for inventory issuance', '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(111, 'inventory_issuance', 'view_margin', 'View_margin access for inventory issuance', '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(112, 'incident_reports', 'read', 'Read access for incident reports', '2026-07-25 07:28:45', '2026-07-25 07:28:45'),
(113, 'incident_reports', 'create', 'Create access for incident reports', '2026-07-25 07:28:45', '2026-07-25 07:28:45'),
(114, 'incident_reports', 'resolve', 'Resolve access for incident reports', '2026-07-25 07:28:45', '2026-07-25 07:28:45');

-- --------------------------------------------------------

--
-- Table structure for table `permission_audit_logs`
--

CREATE TABLE `permission_audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `changed_by` bigint(20) UNSIGNED NOT NULL,
  `target_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `target_role_id` bigint(20) UNSIGNED DEFAULT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `old_value` tinyint(1) DEFAULT NULL,
  `new_value` tinyint(1) DEFAULT NULL,
  `change_type` varchar(255) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permission_audit_logs`
--

INSERT INTO `permission_audit_logs` (`id`, `changed_by`, `target_user_id`, `target_role_id`, `permission_id`, `old_value`, `new_value`, `change_type`, `reason`, `changed_at`, `created_at`, `updated_at`) VALUES
(1, 2, NULL, 2, 41, 0, 1, 'role_default', NULL, '2026-06-13 15:23:49', '2026-06-13 15:23:49', '2026-06-13 15:23:49'),
(2, 2, 4, NULL, 73, NULL, 1, 'user_override', 'okay lng', '2026-06-13 15:27:55', '2026-06-13 15:27:55', '2026-06-13 15:27:55'),
(3, 2, NULL, 3, 31, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(4, 2, NULL, 3, 32, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(5, 2, NULL, 3, 33, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(6, 2, NULL, 3, 35, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(7, 2, NULL, 3, 36, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(8, 2, NULL, 3, 37, 1, 0, 'role_default', NULL, '2026-06-13 15:38:11', '2026-06-13 15:38:11', '2026-06-13 15:38:11'),
(9, 2, 4, NULL, 75, NULL, 1, 'user_override', 'okay rana', '2026-06-13 15:47:21', '2026-06-13 15:47:21', '2026-06-13 15:47:21'),
(10, 2, 4, NULL, 73, 1, 1, 'user_override_clear', 'okay', '2026-06-13 15:49:56', '2026-06-13 15:49:56', '2026-06-13 15:49:56'),
(11, 2, 4, NULL, 75, 1, 1, 'user_override_clear', 'okay', '2026-06-13 15:49:56', '2026-06-13 15:49:56', '2026-06-13 15:49:56'),
(12, 2, 4, NULL, 73, NULL, 1, 'user_override', 'sadaasd', '2026-06-13 15:51:05', '2026-06-13 15:51:05', '2026-06-13 15:51:05'),
(13, 2, 4, NULL, 74, NULL, 1, 'user_override', 'sadaasd', '2026-06-13 15:51:05', '2026-06-13 15:51:05', '2026-06-13 15:51:05'),
(14, 2, NULL, 3, 73, 0, 1, 'role_default', NULL, '2026-06-13 15:51:58', '2026-06-13 15:51:58', '2026-06-13 15:51:58'),
(15, 2, NULL, 3, 74, 0, 1, 'role_default', NULL, '2026-06-13 15:51:58', '2026-06-13 15:51:58', '2026-06-13 15:51:58'),
(16, 2, NULL, 3, 1, 0, 1, 'role_default', NULL, '2026-06-13 15:54:44', '2026-06-13 15:54:44', '2026-06-13 15:54:44'),
(17, 2, NULL, 3, 2, 0, 1, 'role_default', NULL, '2026-06-13 15:54:44', '2026-06-13 15:54:44', '2026-06-13 15:54:44'),
(18, 2, NULL, 3, 3, 0, 1, 'role_default', NULL, '2026-06-13 15:54:44', '2026-06-13 15:54:44', '2026-06-13 15:54:44'),
(19, 2, NULL, 3, 73, 1, 0, 'role_default', NULL, '2026-06-13 15:54:44', '2026-06-13 15:54:44', '2026-06-13 15:54:44'),
(20, 2, NULL, 3, 74, 1, 0, 'role_default', NULL, '2026-06-13 15:54:44', '2026-06-13 15:54:44', '2026-06-13 15:54:44'),
(21, 2, 4, NULL, 73, 1, 0, 'user_override', 'asas', '2026-06-13 15:55:11', '2026-06-13 15:55:11', '2026-06-13 15:55:11'),
(22, 2, 4, NULL, 73, 0, 0, 'user_override_clear', 'assa', '2026-06-13 15:55:38', '2026-06-13 15:55:38', '2026-06-13 15:55:38'),
(23, 2, 4, NULL, 74, 1, 0, 'user_override', 'assa', '2026-06-13 15:55:38', '2026-06-13 15:55:38', '2026-06-13 15:55:38'),
(24, 2, 4, NULL, 74, 0, 0, 'user_override_clear', 'ax', '2026-06-13 15:55:56', '2026-06-13 15:55:56', '2026-06-13 15:55:56'),
(25, 2, 4, NULL, 73, NULL, 1, 'user_override', 'jhgjhg', '2026-06-13 16:03:03', '2026-06-13 16:03:03', '2026-06-13 16:03:03'),
(26, 2, 4, NULL, 74, NULL, 1, 'user_override', 'jhgjhg', '2026-06-13 16:03:03', '2026-06-13 16:03:03', '2026-06-13 16:03:03'),
(27, 2, NULL, 4, 1, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(28, 2, NULL, 4, 1, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(29, 2, NULL, 4, 2, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(30, 2, NULL, 4, 2, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(31, 2, NULL, 4, 3, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(32, 2, NULL, 4, 3, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(33, 2, NULL, 4, 4, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(34, 2, NULL, 4, 4, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(35, 2, NULL, 4, 5, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(36, 2, NULL, 4, 5, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(37, 2, NULL, 4, 6, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(38, 2, NULL, 4, 6, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(39, 2, NULL, 4, 7, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(40, 2, NULL, 4, 7, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(41, 2, NULL, 4, 8, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(42, 2, NULL, 4, 8, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(43, 2, NULL, 4, 9, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(44, 2, NULL, 4, 9, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(45, 2, NULL, 4, 10, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(46, 2, NULL, 4, 10, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(47, 2, NULL, 4, 11, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(48, 2, NULL, 4, 11, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(49, 2, NULL, 4, 12, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(50, 2, NULL, 4, 12, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(51, 2, NULL, 4, 13, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(52, 2, NULL, 4, 13, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(53, 2, NULL, 4, 14, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(54, 2, NULL, 4, 14, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(55, 2, NULL, 4, 15, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(56, 2, NULL, 4, 15, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(57, 2, NULL, 4, 16, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(58, 2, NULL, 4, 16, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(59, 2, NULL, 4, 17, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(60, 2, NULL, 4, 17, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(61, 2, NULL, 4, 18, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(62, 2, NULL, 4, 18, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(63, 2, NULL, 4, 19, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(64, 2, NULL, 4, 19, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(65, 2, NULL, 4, 20, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(66, 2, NULL, 4, 20, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(67, 2, NULL, 4, 21, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(68, 2, NULL, 4, 21, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(69, 2, NULL, 4, 22, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(70, 2, NULL, 4, 22, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(71, 2, NULL, 4, 23, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(72, 2, NULL, 4, 23, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(73, 2, NULL, 4, 24, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(74, 2, NULL, 4, 24, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(75, 2, NULL, 4, 25, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(76, 2, NULL, 4, 25, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(77, 2, NULL, 4, 26, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(78, 2, NULL, 4, 26, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(79, 2, NULL, 4, 27, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(80, 2, NULL, 4, 27, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(81, 2, NULL, 4, 28, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(82, 2, NULL, 4, 28, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(83, 2, NULL, 4, 29, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(84, 2, NULL, 4, 29, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(85, 2, NULL, 4, 30, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(86, 2, NULL, 4, 30, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(87, 2, NULL, 4, 31, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(88, 2, NULL, 4, 31, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(89, 2, NULL, 4, 32, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(90, 2, NULL, 4, 32, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(91, 2, NULL, 4, 33, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(92, 2, NULL, 4, 33, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(93, 2, NULL, 4, 34, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(94, 2, NULL, 4, 34, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(95, 2, NULL, 4, 35, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(96, 2, NULL, 4, 35, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(97, 2, NULL, 4, 36, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(98, 2, NULL, 4, 36, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(99, 2, NULL, 4, 37, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(100, 2, NULL, 4, 37, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(101, 2, NULL, 4, 38, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(102, 2, NULL, 4, 38, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(103, 2, NULL, 4, 39, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(104, 2, NULL, 4, 39, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(105, 2, NULL, 4, 40, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(106, 2, NULL, 4, 40, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(107, 2, NULL, 4, 41, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(108, 2, NULL, 4, 41, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(109, 2, NULL, 4, 42, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(110, 2, NULL, 4, 42, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(111, 2, NULL, 4, 43, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(112, 2, NULL, 4, 43, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(113, 2, NULL, 4, 44, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(114, 2, NULL, 4, 44, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(115, 2, NULL, 4, 45, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(116, 2, NULL, 4, 45, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(117, 2, NULL, 4, 46, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(118, 2, NULL, 4, 46, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(119, 2, NULL, 4, 47, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(120, 2, NULL, 4, 47, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(121, 2, NULL, 4, 48, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(122, 2, NULL, 4, 48, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(123, 2, NULL, 4, 49, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(124, 2, NULL, 4, 49, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(125, 2, NULL, 4, 50, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(126, 2, NULL, 4, 50, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(127, 2, NULL, 4, 51, 1, 0, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(128, 2, NULL, 4, 51, 0, 1, 'role_default', NULL, '2026-06-13 16:05:23', '2026-06-13 16:05:23', '2026-06-13 16:05:23'),
(129, 2, NULL, 4, 52, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(130, 2, NULL, 4, 52, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(131, 2, NULL, 4, 53, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(132, 2, NULL, 4, 53, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(133, 2, NULL, 4, 54, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(134, 2, NULL, 4, 54, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(135, 2, NULL, 4, 55, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(136, 2, NULL, 4, 55, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(137, 2, NULL, 4, 56, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(138, 2, NULL, 4, 56, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(139, 2, NULL, 4, 57, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(140, 2, NULL, 4, 57, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(141, 2, NULL, 4, 58, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(142, 2, NULL, 4, 58, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(143, 2, NULL, 4, 59, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(144, 2, NULL, 4, 59, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(145, 2, NULL, 4, 60, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(146, 2, NULL, 4, 60, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(147, 2, NULL, 4, 61, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(148, 2, NULL, 4, 61, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(149, 2, NULL, 4, 62, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(150, 2, NULL, 4, 62, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(151, 2, NULL, 4, 63, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(152, 2, NULL, 4, 63, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(153, 2, NULL, 4, 64, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(154, 2, NULL, 4, 64, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(155, 2, NULL, 4, 65, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(156, 2, NULL, 4, 65, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(157, 2, NULL, 4, 66, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(158, 2, NULL, 4, 66, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(159, 2, NULL, 4, 67, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(160, 2, NULL, 4, 67, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(161, 2, NULL, 4, 68, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(162, 2, NULL, 4, 68, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(163, 2, NULL, 4, 69, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(164, 2, NULL, 4, 69, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(165, 2, NULL, 4, 70, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(166, 2, NULL, 4, 70, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(167, 2, NULL, 4, 71, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(168, 2, NULL, 4, 71, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(169, 2, NULL, 4, 72, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(170, 2, NULL, 4, 72, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(171, 2, NULL, 4, 73, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(172, 2, NULL, 4, 73, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(173, 2, NULL, 4, 74, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(174, 2, NULL, 4, 74, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(175, 2, NULL, 4, 75, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(176, 2, NULL, 4, 75, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(177, 2, NULL, 4, 76, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(178, 2, NULL, 4, 76, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(179, 2, NULL, 4, 77, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(180, 2, NULL, 4, 77, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(181, 2, NULL, 4, 78, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(182, 2, NULL, 4, 78, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(183, 2, NULL, 4, 79, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(184, 2, NULL, 4, 79, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(185, 2, NULL, 4, 80, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(186, 2, NULL, 4, 80, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(187, 2, NULL, 4, 81, 1, 0, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(188, 2, NULL, 4, 81, 0, 1, 'role_default', NULL, '2026-06-13 16:05:24', '2026-06-13 16:05:24', '2026-06-13 16:05:24'),
(189, 2, 6, NULL, 1, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(190, 2, 6, NULL, 1, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(191, 2, 6, NULL, 2, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(192, 2, 6, NULL, 2, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(193, 2, 6, NULL, 3, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(194, 2, 6, NULL, 3, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(195, 2, 6, NULL, 4, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(196, 2, 6, NULL, 4, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(197, 2, 6, NULL, 5, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(198, 2, 6, NULL, 5, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(199, 2, 6, NULL, 6, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(200, 2, 6, NULL, 6, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(201, 2, 6, NULL, 7, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(202, 2, 6, NULL, 7, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(203, 2, 6, NULL, 8, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(204, 2, 6, NULL, 8, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(205, 2, 6, NULL, 9, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(206, 2, 6, NULL, 9, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(207, 2, 6, NULL, 10, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(208, 2, 6, NULL, 10, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(209, 2, 6, NULL, 11, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(210, 2, 6, NULL, 11, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(211, 2, 6, NULL, 12, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(212, 2, 6, NULL, 12, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(213, 2, 6, NULL, 13, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(214, 2, 6, NULL, 13, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(215, 2, 6, NULL, 14, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(216, 2, 6, NULL, 14, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(217, 2, 6, NULL, 15, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(218, 2, 6, NULL, 15, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(219, 2, 6, NULL, 16, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(220, 2, 6, NULL, 16, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(221, 2, 6, NULL, 17, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(222, 2, 6, NULL, 17, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(223, 2, 6, NULL, 18, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(224, 2, 6, NULL, 18, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(225, 2, 6, NULL, 19, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(226, 2, 6, NULL, 19, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(227, 2, 6, NULL, 20, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(228, 2, 6, NULL, 20, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(229, 2, 6, NULL, 21, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(230, 2, 6, NULL, 21, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(231, 2, 6, NULL, 22, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(232, 2, 6, NULL, 22, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(233, 2, 6, NULL, 23, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(234, 2, 6, NULL, 23, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(235, 2, 6, NULL, 24, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(236, 2, 6, NULL, 24, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(237, 2, 6, NULL, 25, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(238, 2, 6, NULL, 25, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(239, 2, 6, NULL, 26, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(240, 2, 6, NULL, 26, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(241, 2, 6, NULL, 27, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(242, 2, 6, NULL, 27, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(243, 2, 6, NULL, 28, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(244, 2, 6, NULL, 28, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(245, 2, 6, NULL, 29, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(246, 2, 6, NULL, 29, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(247, 2, 6, NULL, 30, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(248, 2, 6, NULL, 30, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(249, 2, 6, NULL, 31, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(250, 2, 6, NULL, 31, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(251, 2, 6, NULL, 32, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(252, 2, 6, NULL, 32, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(253, 2, 6, NULL, 33, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(254, 2, 6, NULL, 33, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(255, 2, 6, NULL, 34, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(256, 2, 6, NULL, 34, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(257, 2, 6, NULL, 35, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(258, 2, 6, NULL, 35, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(259, 2, 6, NULL, 36, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(260, 2, 6, NULL, 36, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(261, 2, 6, NULL, 37, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(262, 2, 6, NULL, 37, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(263, 2, 6, NULL, 38, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(264, 2, 6, NULL, 38, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(265, 2, 6, NULL, 39, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(266, 2, 6, NULL, 39, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(267, 2, 6, NULL, 40, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(268, 2, 6, NULL, 40, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(269, 2, 6, NULL, 41, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(270, 2, 6, NULL, 41, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(271, 2, 6, NULL, 42, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(272, 2, 6, NULL, 42, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(273, 2, 6, NULL, 43, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(274, 2, 6, NULL, 43, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(275, 2, 6, NULL, 44, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(276, 2, 6, NULL, 44, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(277, 2, 6, NULL, 45, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(278, 2, 6, NULL, 45, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(279, 2, 6, NULL, 46, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(280, 2, 6, NULL, 46, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(281, 2, 6, NULL, 47, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(282, 2, 6, NULL, 47, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(283, 2, 6, NULL, 48, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(284, 2, 6, NULL, 48, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(285, 2, 6, NULL, 49, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(286, 2, 6, NULL, 49, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(287, 2, 6, NULL, 50, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(288, 2, 6, NULL, 50, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(289, 2, 6, NULL, 51, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(290, 2, 6, NULL, 51, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(291, 2, 6, NULL, 52, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(292, 2, 6, NULL, 52, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(293, 2, 6, NULL, 53, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(294, 2, 6, NULL, 53, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(295, 2, 6, NULL, 54, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(296, 2, 6, NULL, 54, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(297, 2, 6, NULL, 55, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(298, 2, 6, NULL, 55, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(299, 2, 6, NULL, 56, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(300, 2, 6, NULL, 56, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(301, 2, 6, NULL, 57, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(302, 2, 6, NULL, 57, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(303, 2, 6, NULL, 58, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(304, 2, 6, NULL, 58, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(305, 2, 6, NULL, 59, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(306, 2, 6, NULL, 59, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(307, 2, 6, NULL, 60, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(308, 2, 6, NULL, 60, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(309, 2, 6, NULL, 61, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(310, 2, 6, NULL, 61, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(311, 2, 6, NULL, 62, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(312, 2, 6, NULL, 62, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(313, 2, 6, NULL, 63, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(314, 2, 6, NULL, 63, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(315, 2, 6, NULL, 64, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(316, 2, 6, NULL, 64, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(317, 2, 6, NULL, 65, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(318, 2, 6, NULL, 65, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(319, 2, 6, NULL, 66, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(320, 2, 6, NULL, 66, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(321, 2, 6, NULL, 67, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(322, 2, 6, NULL, 67, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(323, 2, 6, NULL, 68, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(324, 2, 6, NULL, 68, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(325, 2, 6, NULL, 69, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(326, 2, 6, NULL, 69, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(327, 2, 6, NULL, 70, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(328, 2, 6, NULL, 70, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(329, 2, 6, NULL, 71, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(330, 2, 6, NULL, 71, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(331, 2, 6, NULL, 72, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(332, 2, 6, NULL, 72, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(333, 2, 6, NULL, 73, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(334, 2, 6, NULL, 73, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(335, 2, 6, NULL, 74, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(336, 2, 6, NULL, 74, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(337, 2, 6, NULL, 75, NULL, 1, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(338, 2, 6, NULL, 75, 1, 1, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(339, 2, 6, NULL, 76, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(340, 2, 6, NULL, 76, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(341, 2, 6, NULL, 77, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(342, 2, 6, NULL, 77, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(343, 2, 6, NULL, 78, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(344, 2, 6, NULL, 78, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(345, 2, 6, NULL, 79, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(346, 2, 6, NULL, 79, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(347, 2, 6, NULL, 80, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(348, 2, 6, NULL, 80, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(349, 2, 6, NULL, 81, NULL, 0, 'user_override', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(350, 2, 6, NULL, 81, 0, 0, 'user_override_clear', 'restore', '2026-06-13 16:05:47', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(351, 2, 4, NULL, 73, 1, 0, 'user_override', 'cfg', '2026-06-13 16:32:59', '2026-06-13 16:32:59', '2026-06-13 16:32:59'),
(352, 2, 4, NULL, 73, 0, 1, 'user_override', 'asa', '2026-06-13 16:33:54', '2026-06-13 16:33:54', '2026-06-13 16:33:54'),
(353, 2, 3, NULL, 41, NULL, 1, 'user_override', 'tth', '2026-06-13 16:39:31', '2026-06-13 16:39:31', '2026-06-13 16:39:31'),
(354, 2, NULL, 3, 24, 0, 1, 'role_default', NULL, '2026-06-13 17:30:02', '2026-06-13 17:30:02', '2026-06-13 17:30:02'),
(355, 2, NULL, 3, 24, 1, 0, 'role_default', NULL, '2026-06-13 17:30:02', '2026-06-13 17:30:02', '2026-06-13 17:30:02'),
(356, 2, NULL, 3, 1, 0, 1, 'role_default', NULL, '2026-06-13 17:47:54', '2026-06-13 17:47:54', '2026-06-13 17:47:54'),
(357, 2, NULL, 3, 2, 0, 1, 'role_default', NULL, '2026-06-13 17:47:54', '2026-06-13 17:47:54', '2026-06-13 17:47:54'),
(358, 2, NULL, 3, 1, 1, 0, 'role_default', NULL, '2026-06-13 17:48:13', '2026-06-13 17:48:13', '2026-06-13 17:48:13'),
(359, 2, NULL, 3, 2, 1, 0, 'role_default', NULL, '2026-06-13 17:48:13', '2026-06-13 17:48:13', '2026-06-13 17:48:13'),
(360, 2, NULL, 3, 41, 0, 1, 'role_default', NULL, '2026-06-13 17:48:35', '2026-06-13 17:48:35', '2026-06-13 17:48:35'),
(361, 2, NULL, 3, 73, 0, 1, 'role_default', NULL, '2026-06-13 17:48:35', '2026-06-13 17:48:35', '2026-06-13 17:48:35'),
(362, 2, NULL, 3, 74, 0, 1, 'role_default', NULL, '2026-06-13 17:48:35', '2026-06-13 17:48:35', '2026-06-13 17:48:35'),
(363, 2, NULL, 3, 75, 0, 1, 'role_default', NULL, '2026-06-13 17:48:35', '2026-06-13 17:48:35', '2026-06-13 17:48:35'),
(364, 2, NULL, 3, 41, 1, 0, 'role_default', NULL, '2026-06-13 17:49:07', '2026-06-13 17:49:07', '2026-06-13 17:49:07'),
(365, 2, NULL, 3, 73, 1, 0, 'role_default', NULL, '2026-06-13 17:49:07', '2026-06-13 17:49:07', '2026-06-13 17:49:07'),
(366, 2, NULL, 3, 74, 1, 0, 'role_default', NULL, '2026-06-13 17:49:07', '2026-06-13 17:49:07', '2026-06-13 17:49:07'),
(367, 2, NULL, 3, 75, 1, 0, 'role_default', NULL, '2026-06-13 17:49:07', '2026-06-13 17:49:07', '2026-06-13 17:49:07'),
(368, 2, 4, NULL, 73, 1, 0, 'user_override', 'khgjh', '2026-06-13 17:49:49', '2026-06-13 17:49:49', '2026-06-13 17:49:49'),
(369, 2, 4, NULL, 38, NULL, 1, 'user_override', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:50:17', '2026-06-13 20:50:17'),
(370, 2, 4, NULL, 39, NULL, 1, 'user_override', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:50:17', '2026-06-13 20:50:17'),
(371, 2, 4, NULL, 40, NULL, 1, 'user_override', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:50:17', '2026-06-13 20:50:17'),
(372, 2, 4, NULL, 38, 1, 1, 'user_override_clear', 'khgj', '2026-06-13 20:51:46', '2026-06-13 20:51:46', '2026-06-13 20:51:46'),
(373, 2, 4, NULL, 39, 1, 1, 'user_override_clear', 'khgj', '2026-06-13 20:51:46', '2026-06-13 20:51:46', '2026-06-13 20:51:46'),
(374, 2, 4, NULL, 40, 1, 1, 'user_override_clear', 'khgj', '2026-06-13 20:51:46', '2026-06-13 20:51:46', '2026-06-13 20:51:46'),
(375, 2, 4, NULL, 37, NULL, 0, 'user_override', 'rtr', '2026-06-13 21:11:37', '2026-06-13 21:11:37', '2026-06-13 21:11:37'),
(376, 2, NULL, 4, 1, 0, 1, 'role_default', NULL, '2026-06-23 00:34:46', '2026-06-23 00:34:46', '2026-06-23 00:34:46'),
(377, 2, NULL, 4, 1, 1, 0, 'role_default', NULL, '2026-06-23 00:35:17', '2026-06-23 00:35:17', '2026-06-23 00:35:17'),
(378, 2, NULL, 5, 73, 0, 1, 'role_default', NULL, '2026-07-09 11:23:35', '2026-07-09 11:23:35', '2026-07-09 11:23:35'),
(379, 2, 7, NULL, 39, NULL, 1, 'user_override', 'Yes', '2026-07-12 09:40:46', '2026-07-12 09:40:46', '2026-07-12 09:40:46'),
(381, 2, 7, NULL, 39, 1, 1, 'user_override', 'yes', '2026-07-12 09:59:09', '2026-07-12 09:59:09', '2026-07-12 09:59:09'),
(382, 2, 7, NULL, 38, NULL, 1, 'user_override', 'ys', '2026-07-12 09:59:57', '2026-07-12 09:59:57', '2026-07-12 09:59:57'),
(383, 2, 7, NULL, 39, 1, 1, 'user_override', 'ys', '2026-07-12 09:59:57', '2026-07-12 09:59:57', '2026-07-12 09:59:57'),
(384, 2, 7, NULL, 38, 1, 1, 'user_override', 'yes', '2026-07-12 10:15:14', '2026-07-12 10:15:14', '2026-07-12 10:15:14'),
(385, 2, 7, NULL, 39, 1, 1, 'user_override', 'yes', '2026-07-12 10:15:14', '2026-07-12 10:15:14', '2026-07-12 10:15:14'),
(386, 2, 3, NULL, 41, 1, 0, 'user_override', 'Bawal lge', '2026-07-13 10:49:17', '2026-07-13 10:49:17', '2026-07-13 10:49:17'),
(387, 2, 4, NULL, 86, NULL, 0, 'user_override', 'bawal', '2026-07-13 11:28:53', '2026-07-13 11:28:53', '2026-07-13 11:28:53'),
(388, 2, 4, NULL, 84, NULL, 0, 'user_override', 'jn', '2026-07-13 11:29:25', '2026-07-13 11:29:25', '2026-07-13 11:29:25'),
(389, 2, NULL, 3, 84, 1, 0, 'role_default', NULL, '2026-07-13 12:23:43', '2026-07-13 12:23:43', '2026-07-13 12:23:43'),
(390, 2, NULL, 3, 31, 1, 0, 'role_default', NULL, '2026-07-13 12:24:43', '2026-07-13 12:24:43', '2026-07-13 12:24:43'),
(391, 2, NULL, 3, 32, 1, 0, 'role_default', NULL, '2026-07-13 12:24:43', '2026-07-13 12:24:43', '2026-07-13 12:24:43'),
(392, 2, NULL, 3, 33, 1, 0, 'role_default', NULL, '2026-07-13 12:24:43', '2026-07-13 12:24:43', '2026-07-13 12:24:43'),
(393, 2, NULL, 3, 96, 1, 0, 'role_default', NULL, '2026-07-13 12:26:09', '2026-07-13 12:26:09', '2026-07-13 12:26:09'),
(394, 2, NULL, 5, 87, 1, 0, 'role_default', NULL, '2026-07-13 12:33:54', '2026-07-13 12:33:54', '2026-07-13 12:33:54'),
(395, 2, NULL, 5, 87, 0, 1, 'role_default', NULL, '2026-07-13 12:34:08', '2026-07-13 12:34:08', '2026-07-13 12:34:08'),
(396, 2, 7, NULL, 103, NULL, 1, 'user_override', 'bawl', '2026-07-13 12:49:26', '2026-07-13 12:49:26', '2026-07-13 12:49:26'),
(397, 2, 7, NULL, 37, NULL, 0, 'user_override', 'jhvjh', '2026-07-13 12:50:37', '2026-07-13 12:50:37', '2026-07-13 12:50:37'),
(398, 2, 7, NULL, 38, 1, 1, 'user_override_clear', 'jhvjh', '2026-07-13 12:50:37', '2026-07-13 12:50:37', '2026-07-13 12:50:37'),
(399, 2, 7, NULL, 39, 1, 1, 'user_override_clear', 'jhvjh', '2026-07-13 12:50:37', '2026-07-13 12:50:37', '2026-07-13 12:50:37'),
(400, 2, 7, NULL, 37, 0, 0, 'user_override_clear', 'nbj', '2026-07-13 12:51:00', '2026-07-13 12:51:00', '2026-07-13 12:51:00'),
(401, 2, 3, NULL, 39, NULL, 0, 'user_override', 'mjbm', '2026-07-13 12:52:42', '2026-07-13 12:52:42', '2026-07-13 12:52:42');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(1, 'App\\Models\\User', 2, 'auth-token', '7e6c74c84e66ef72fcf56ba39827383e51453c19e1465bdca41cd40b4bbc3b7f', '[\"*\"]', '2026-05-09 20:01:50', NULL, '2026-05-09 14:13:10', '2026-05-09 20:01:50'),
(10, 'App\\Models\\User', 2, 'auth-token', 'f6fb7248d13bf65cb75d432c4feb17c142eddb0b2d1a0d97f0b28247ac5ce607', '[\"*\"]', '2026-05-10 08:04:00', NULL, '2026-05-10 08:03:20', '2026-05-10 08:04:00'),
(11, 'App\\Models\\User', 2, 'auth-token', '70178b205e92ae8e591ed7148703721830ef1c40d552e5567bc9777883209c83', '[\"*\"]', '2026-05-11 14:26:50', '2026-05-11 14:56:50', '2026-05-11 14:26:50', '2026-05-11 14:26:50'),
(12, 'App\\Models\\User', 2, 'auth-token', 'a818195d3c2c48b983b0ece9330effbff3d40456881925c6232acb6d2a69e39a', '[\"*\"]', '2026-05-11 14:55:33', '2026-05-11 15:25:33', '2026-05-11 14:27:32', '2026-05-11 14:55:33'),
(13, 'App\\Models\\User', 2, 'auth-token', '5b0a3d0d5a42310bd3a35ab010181426100b02ddb2dcd7047de6e85652c245ae', '[\"*\"]', '2026-05-12 10:24:10', '2026-05-12 10:54:10', '2026-05-12 10:24:10', '2026-05-12 10:24:10'),
(14, 'App\\Models\\User', 3, 'auth-token', '99f20661d2be31ab5397814fbcfb98c362f04bc90f82ac566a4fd46b7515eed4', '[\"*\"]', '2026-05-12 10:25:03', '2026-05-12 10:55:03', '2026-05-12 10:25:03', '2026-05-12 10:25:03'),
(15, 'App\\Models\\User', 2, 'auth-token', '3c9319e2950c988e2dd5612eb1697d4c704b6d38ba87601720a496c4051bb118', '[\"*\"]', '2026-05-12 10:25:50', '2026-05-12 10:55:51', '2026-05-12 10:25:51', '2026-05-12 10:25:51'),
(16, 'App\\Models\\User', 3, 'auth-token', '480604c7ae42a134eb0ffa7d351b4422123cdb28ea743c1a59f8607d010d5f31', '[\"*\"]', '2026-05-12 10:27:10', '2026-05-12 10:57:10', '2026-05-12 10:27:10', '2026-05-12 10:27:10'),
(17, 'App\\Models\\User', 2, 'auth-token', '17eba2ab8eda9c6ace3f40df5cd561ee5047409b140c36d1902ce9da28a1d819', '[\"*\"]', '2026-05-12 10:58:11', '2026-05-12 11:28:11', '2026-05-12 10:58:11', '2026-05-12 10:58:11'),
(18, 'App\\Models\\User', 3, 'auth-token', '2387d5bca022a3f07009bacd0b7d7a6e93a0556129b706ee37c1b35648687af6', '[\"*\"]', '2026-05-12 12:14:25', '2026-05-12 12:44:25', '2026-05-12 10:58:48', '2026-05-12 12:14:25'),
(19, 'App\\Models\\User', 2, 'auth-token', '9737be6537db1f67ae1248b001bf4f4130ad2651c458a74deebb1f79c84872f1', '[\"*\"]', '2026-05-12 11:30:31', '2026-05-12 12:00:31', '2026-05-12 11:30:31', '2026-05-12 11:30:31'),
(20, 'App\\Models\\User', 2, 'auth-token', 'dba4624dc71f3608c7e2ec2fc563184d837bbfa2de17df81afdfe9db62c68db4', '[\"*\"]', '2026-05-12 11:41:48', '2026-05-12 12:11:48', '2026-05-12 11:41:48', '2026-05-12 11:41:48'),
(21, 'App\\Models\\User', 2, 'auth-token', 'edf416eca4c190f4573b7a845e12662b46e1bfdbcc6971ca1460f4569feb4596', '[\"*\"]', '2026-05-12 12:40:55', '2026-05-12 13:10:55', '2026-05-12 11:41:52', '2026-05-12 12:40:55'),
(22, 'App\\Models\\User', 2, 'auth-token', 'ef30c0ba2b132e1dd51309c84f43951e6bd5973124fd6e0164a2cd42b2f57f79', '[\"*\"]', '2026-05-12 19:17:13', '2026-05-12 19:47:13', '2026-05-12 18:58:52', '2026-05-12 19:17:13'),
(23, 'App\\Models\\User', 3, 'auth-token', '53d20a792014a79f3bf47dcea85dec80ba70599fbef323ffc18f6493ce442ab8', '[\"*\"]', '2026-05-12 19:51:56', '2026-05-12 20:21:57', '2026-05-12 19:51:57', '2026-05-12 19:51:57'),
(24, 'App\\Models\\User', 2, 'auth-token', '61dee40a2e3724293bad6fc043206921a46bc82570136f33d82c7864c2664943', '[\"*\"]', '2026-05-12 21:34:17', '2026-05-12 22:04:17', '2026-05-12 21:13:10', '2026-05-12 21:34:17'),
(25, 'App\\Models\\User', 2, 'auth-token', 'c0adaaf123435fe9a31d891fc803217fa1db5c9427c26046304daa06e7346366', '[\"*\"]', '2026-05-13 00:04:58', '2026-05-13 00:34:58', '2026-05-12 22:39:33', '2026-05-13 00:04:58'),
(26, 'App\\Models\\User', 2, 'auth-token', '5c52a8e7b1eaceb0c1e9e4c147df1da437b02cfb2325a03356806e25a711114e', '[\"*\"]', '2026-05-13 00:41:44', '2026-05-13 01:11:44', '2026-05-13 00:41:18', '2026-05-13 00:41:44'),
(27, 'App\\Models\\User', 2, 'auth-token', '7ed69843246a02038d38a0f3d90613e642a0240a6817ef2a896952a06ad2a82a', '[\"*\"]', '2026-05-13 09:34:16', '2026-05-13 10:04:16', '2026-05-13 08:57:57', '2026-05-13 09:34:16'),
(28, 'App\\Models\\User', 2, 'auth-token', 'a2472c72e5fb7729ba3a0cc425dc4c588cec37e5ab9d32be6d49a4da94252bf8', '[\"*\"]', '2026-05-13 12:53:17', '2026-05-13 13:23:17', '2026-05-13 11:38:09', '2026-05-13 12:53:17'),
(29, 'App\\Models\\User', 2, 'auth-token', '76473e17d66a6d81aea519133c3a420e162796eebf85979b1c370e77d0798884', '[\"*\"]', '2026-05-14 13:25:28', '2026-05-14 13:55:28', '2026-05-14 09:25:57', '2026-05-14 13:25:28'),
(30, 'App\\Models\\User', 2, 'auth-token', 'd174975fbff0092b5c4447cb6952823746bbc3eb25ad555ed9cdc45298f55369', '[\"*\"]', '2026-05-14 12:48:15', '2026-05-14 13:18:15', '2026-05-14 11:46:19', '2026-05-14 12:48:15'),
(31, 'App\\Models\\User', 2, 'auth-token', '7ba26cc3d16051eb282003631adc8e8d53558be7e96b3fbea4595024a5e9239e', '[\"*\"]', '2026-05-15 15:25:14', '2026-05-15 15:55:14', '2026-05-15 12:18:44', '2026-05-15 15:25:14'),
(32, 'App\\Models\\User', 2, 'auth-token', 'd6f1e947e6d5891c12008b86fc568bf66ca8e099d1860c51fd362c78979e8ea9', '[\"*\"]', '2026-05-15 15:40:36', '2026-05-15 16:10:36', '2026-05-15 15:27:23', '2026-05-15 15:40:36'),
(33, 'App\\Models\\User', 2, 'auth-token', 'd40e5c0af762f8dd47f025f13f99889ff84833522a735a1384b4ec331e5779c5', '[\"*\"]', '2026-05-16 08:41:08', '2026-05-16 09:11:08', '2026-05-16 07:55:59', '2026-05-16 08:41:08'),
(34, 'App\\Models\\User', 2, 'auth-token', '472d2b6311aceef82b5627ed7969cdf91e7245e1ba464f51f833a078dbedc458', '[\"*\"]', '2026-05-16 12:01:37', '2026-05-16 12:31:37', '2026-05-16 09:50:24', '2026-05-16 12:01:37'),
(35, 'App\\Models\\User', 2, 'auth-token', '2717e4356c3201c364937557e3eadc8ae9f1453c0613de8aa9211dbeec651a18', '[\"*\"]', '2026-05-16 12:58:04', '2026-05-16 13:28:04', '2026-05-16 12:51:52', '2026-05-16 12:58:04'),
(36, 'App\\Models\\User', 2, 'auth-token', 'be496607fe2d137089ba4cdd99a9409e82ba19f065eda25ecbd7600287f898ad', '[\"*\"]', '2026-05-17 16:17:16', '2026-05-17 16:47:16', '2026-05-17 12:32:14', '2026-05-17 16:17:16'),
(37, 'App\\Models\\User', 2, 'auth-token', 'e19aec0a80cff487d3c60911b24ee1fed304a2aee5885964a498630df1b90485', '[\"*\"]', '2026-05-18 11:16:13', '2026-05-18 11:46:13', '2026-05-18 11:16:13', '2026-05-18 11:16:13'),
(38, 'App\\Models\\User', 2, 'auth-token', '1fe1ed149a3d11c62dac1220fac3612697001e52399597ea2bf2a0383ce1c5c3', '[\"*\"]', '2026-05-18 11:50:03', '2026-05-18 12:20:03', '2026-05-18 11:50:03', '2026-05-18 11:50:03'),
(39, 'App\\Models\\User', 2, 'auth-token', 'd6e190f79cc19a1f98830fbae0f36da62648958f44ef0a7693cd7e6caed33168', '[\"*\"]', '2026-05-18 13:23:40', '2026-05-18 13:53:40', '2026-05-18 11:50:07', '2026-05-18 13:23:40'),
(40, 'App\\Models\\User', 2, 'auth-token', '98413e347b714692cdce7ae42b9c3b94be658f47f990da013bd623f1ebdcf51d', '[\"*\"]', '2026-05-19 12:10:54', '2026-05-19 12:40:54', '2026-05-19 11:40:55', '2026-05-19 12:10:54'),
(41, 'App\\Models\\User', 2, 'auth-token', '67ecfee3681fe703284de2367fff1230b00ea6d1177f93c292473957503a10d0', '[\"*\"]', '2026-05-19 23:03:28', '2026-05-19 23:33:28', '2026-05-19 22:27:59', '2026-05-19 23:03:28'),
(42, 'App\\Models\\User', 2, 'auth-token', '866eee3acdce872aed4bbc05d471df060f6e857fb276490af3b0e4baf0fd08b1', '[\"*\"]', '2026-05-20 02:55:20', '2026-05-20 03:25:20', '2026-05-20 00:48:50', '2026-05-20 02:55:20'),
(43, 'App\\Models\\User', 2, 'auth-token', 'b9d6010068386f019729e22dd5c1690890b5c9f4a11ac0a484de9339669aff58', '[\"*\"]', '2026-05-20 04:36:19', '2026-05-20 05:06:19', '2026-05-20 04:14:37', '2026-05-20 04:36:19'),
(46, 'App\\Models\\User', 2, 'phase4-test', 'cd4f3fa49d970d1ee6c3e6815b6f42c1d8f69562bb577ce1cac6d54989c91900', '[\"*\"]', '2026-05-20 12:22:03', '2026-05-20 12:52:04', '2026-05-20 12:16:00', '2026-05-20 12:22:04'),
(47, 'App\\Models\\User', 2, 'auth-token', '4cb78b6504c0ee8698fa97e23b857f741b3d627f8a55d44e8a4faf4c5551ded2', '[\"*\"]', '2026-05-20 12:50:52', '2026-05-20 13:20:52', '2026-05-20 12:23:46', '2026-05-20 12:50:52'),
(48, 'App\\Models\\User', 2, 'auth-token', 'b9e0dceb87a4cce2261105a03233ce0eef5fc633371b1bb3a7fa23559c627630', '[\"*\"]', '2026-05-20 14:24:51', '2026-05-20 14:54:51', '2026-05-20 13:48:34', '2026-05-20 14:24:51'),
(49, 'App\\Models\\User', 2, 'auth-token', 'd7403837339a11571ae5b171ed51da0a7c5e2ea3159e8a65faee29b461c612b7', '[\"*\"]', '2026-05-20 16:57:34', '2026-05-20 17:27:34', '2026-05-20 14:40:54', '2026-05-20 16:57:34'),
(50, 'App\\Models\\User', 2, 'auth-token', '02b432107eac98d76447713e14c8de9b1bebeea105d5d3a3b47db1d0deba0b3c', '[\"*\"]', '2026-05-21 00:12:12', '2026-05-21 00:42:12', '2026-05-20 23:23:51', '2026-05-21 00:12:12'),
(51, 'App\\Models\\User', 2, 'auth-token', 'c006d680cefafc3db2a7df80d9b74d3fb235abeb2d3bb94959e9cc2a787ecfd4', '[\"*\"]', '2026-05-21 04:05:26', '2026-05-21 04:35:26', '2026-05-21 04:00:17', '2026-05-21 04:05:26'),
(52, 'App\\Models\\User', 2, 'auth-token', 'f46ef606b47bf4f96731671f0079ad73985721e54f404a75ef148ecb7c849d2b', '[\"*\"]', '2026-05-21 05:17:16', '2026-05-21 05:47:16', '2026-05-21 04:41:23', '2026-05-21 05:17:16'),
(53, 'App\\Models\\User', 2, 'auth-token', '879d49e94746441eb613153f55599cadc5ca2dcccb99d88d1d15a87bedfb2255', '[\"*\"]', '2026-05-21 08:31:59', '2026-05-21 09:01:59', '2026-05-21 08:31:59', '2026-05-21 08:31:59'),
(54, 'App\\Models\\User', 9, 'auth-token', '82a2db5ca227bf41395a1bd2015178c251ccd866108acd0baf7295c56e9c7fd4', '[\"*\"]', '2026-05-21 08:40:46', '2026-05-21 09:10:46', '2026-05-21 08:32:23', '2026-05-21 08:40:46'),
(55, 'App\\Models\\User', 2, 'auth-token', 'f71b516520094e3d787519f4b8533731ec6e7911d8f84d37bc7b76426443e138', '[\"*\"]', '2026-05-21 12:05:59', '2026-05-21 12:35:59', '2026-05-21 12:05:59', '2026-05-21 12:05:59'),
(56, 'App\\Models\\User', 9, 'auth-token', 'f2008dad84d52adedc2faf617236678d40fef009d5827b041ab819fc81c10d51', '[\"*\"]', '2026-05-21 12:42:24', '2026-05-21 13:12:24', '2026-05-21 12:42:24', '2026-05-21 12:42:24'),
(57, 'App\\Models\\User', 9, 'auth-token', '1bcb36a4f9681806530a937d0d7964c5522e963fc5d356ee61cfe3f116415ad2', '[\"*\"]', '2026-05-21 12:55:50', '2026-05-21 13:25:50', '2026-05-21 12:43:46', '2026-05-21 12:55:50'),
(58, 'App\\Models\\User', 3, 'auth-token', 'cafcc2a0d5a11019e5bb0d1492c04729d78288342917ef4579ed9ebb144335fb', '[\"*\"]', '2026-05-21 13:02:49', '2026-05-21 13:32:49', '2026-05-21 12:44:19', '2026-05-21 13:02:49'),
(59, 'App\\Models\\User', 9, 'auth-token', 'fbfe21381caa0472e6aed5fe65205d2500906644161c8a190f4888ad9d3bf463', '[\"*\"]', '2026-05-21 23:42:40', '2026-05-22 00:12:40', '2026-05-21 23:42:40', '2026-05-21 23:42:40'),
(60, 'App\\Models\\User', 3, 'auth-token', 'c2c4788a0c10f5ac549e834d1a735521fd7e53db7fb5fe036eec64fc03f82384', '[\"*\"]', '2026-05-21 23:44:02', '2026-05-22 00:14:02', '2026-05-21 23:44:02', '2026-05-21 23:44:02'),
(61, 'App\\Models\\User', 6, 'auth-token', 'ef2f572d4219ecb4f072f9f09570357b4801220daa74540fe1b725a981f8408f', '[\"*\"]', '2026-05-21 23:49:00', '2026-05-22 00:19:05', '2026-05-21 23:49:05', '2026-05-21 23:49:05'),
(62, 'App\\Models\\User', 3, 'auth-token', 'e46bf67917b2689736f0d87812e1644048492a5a4b3f3af8b3c2a3e6bc766c12', '[\"*\"]', '2026-05-21 23:59:10', '2026-05-22 00:29:10', '2026-05-21 23:59:10', '2026-05-21 23:59:10'),
(63, 'App\\Models\\User', 3, 'auth-token', 'ac64d13309e2e79e727059e33cbc5052022e576c59f9f9afbedb144a692870e2', '[\"*\"]', '2026-05-21 23:59:56', '2026-05-22 00:29:56', '2026-05-21 23:59:56', '2026-05-21 23:59:56'),
(64, 'App\\Models\\User', 6, 'auth-token', '2b6aeec45920f681c24bcd8e35bd6234f1d2deeb4d63e9ca221ad811debe6b6b', '[\"*\"]', '2026-05-22 00:02:23', '2026-05-22 00:32:23', '2026-05-22 00:02:23', '2026-05-22 00:02:23'),
(65, 'App\\Models\\User', 2, 'auth-token', '664590b6c31fb95369cd688057b69551ac7e487c7d9cc26481ce4ee4a456cf38', '[\"*\"]', '2026-05-22 02:37:57', '2026-05-22 03:07:57', '2026-05-22 00:02:41', '2026-05-22 02:37:57'),
(66, 'App\\Models\\User', 3, 'auth-token', 'eb2f481121f32db38a1bdf72d84125a33d43e26419b277ff67f72479b91f1b56', '[\"*\"]', '2026-05-22 00:03:55', '2026-05-22 00:33:56', '2026-05-22 00:03:54', '2026-05-22 00:03:56'),
(67, 'App\\Models\\User', 6, 'auth-token', '8a0680d639f68b9b95dc1816cf46587334efa16655f3f71efe6e1cb6e549efe2', '[\"*\"]', '2026-05-22 02:03:45', '2026-05-22 02:33:45', '2026-05-22 02:03:45', '2026-05-22 02:03:45'),
(68, 'App\\Models\\User', 3, 'auth-token', '128ccfd1ccf00d19205eab99a831644e35c8c40522bd9e9124209109eef38776', '[\"*\"]', '2026-05-22 01:05:21', '2026-05-22 01:35:21', '2026-05-22 01:05:21', '2026-05-22 01:05:21'),
(69, 'App\\Models\\User', 6, 'auth-token', '9b47e3b6496d1383d32b39efa4e205ca1ec77594bcd4f2690477a8341b34971d', '[\"*\"]', '2026-05-22 02:37:51', '2026-05-22 03:07:51', '2026-05-22 02:16:04', '2026-05-22 02:37:51'),
(70, 'App\\Models\\User', 2, 'auth-token', '0332d968781a72816f0a6a41da7defcc93c22bcf5f0d98f8fe55f0f880a87f2a', '[\"*\"]', '2026-05-22 06:56:28', '2026-05-22 07:26:28', '2026-05-22 05:46:53', '2026-05-22 06:56:28'),
(71, 'App\\Models\\User', 2, 'auth-token', 'c569d467864b5cd764d8cacb2e3fb28c071b9b60099372bd083349286855ba08', '[\"*\"]', '2026-05-22 08:39:07', '2026-05-22 09:09:07', '2026-05-22 08:32:57', '2026-05-22 08:39:07'),
(72, 'App\\Models\\User', 2, 'auth-token', 'dde876714323f543bdd61d16f4731060ba24ce25a4a166ff2b5ee8573b280e9a', '[\"*\"]', '2026-05-22 12:37:14', '2026-05-22 13:07:14', '2026-05-22 12:37:14', '2026-05-22 12:37:14'),
(73, 'App\\Models\\User', 2, 'auth-token', '91c474b20190108bca1492dabcebffe1875d270ee6bf5ee9e8af4e7b2cd33cbb', '[\"*\"]', '2026-05-22 13:18:04', '2026-05-22 13:48:04', '2026-05-22 13:18:04', '2026-05-22 13:18:04'),
(74, 'App\\Models\\User', 2, 'auth-token', '00f1c87f28b2cd7e1b184cb4f4cc8a46df024e873a9599364fe98a629f7f9b15', '[\"*\"]', '2026-05-22 14:28:43', '2026-05-22 14:58:43', '2026-05-22 14:28:43', '2026-05-22 14:28:43'),
(75, 'App\\Models\\User', 2, 'auth-token', 'a9e9041eb67f5b946b16fb6e9fc880b0194020c21a75722ed1b0c9b663af51fe', '[\"*\"]', '2026-05-25 12:17:44', '2026-05-25 12:47:44', '2026-05-25 12:02:00', '2026-05-25 12:17:44'),
(76, 'App\\Models\\User', 2, 'auth-token', 'c156769dc35fcb93c471f385261ee9e977c897cdcf3e60af5708e31633344569', '[\"*\"]', '2026-05-25 13:56:37', '2026-05-25 14:26:37', '2026-05-25 13:56:37', '2026-05-25 13:56:37'),
(77, 'App\\Models\\User', 2, 'auth-token', 'cd432b79a68e66af589f56bf0658b4e0ea38e1157d3e6dec0b16f48ac11d3cad', '[\"*\"]', '2026-05-25 13:57:29', '2026-05-25 14:27:29', '2026-05-25 13:57:29', '2026-05-25 13:57:29'),
(78, 'App\\Models\\User', 3, 'auth-token', 'd181ea578a7332047dd135e5a255316fc8212f12c0ef85efb37c4e3f294296e2', '[\"*\"]', '2026-05-25 13:58:12', '2026-05-25 14:28:12', '2026-05-25 13:58:12', '2026-05-25 13:58:12'),
(79, 'App\\Models\\User', 6, 'auth-token', '7227a85f384d0c6066b6ef74e7ff74bec3848c2f423e732db246133517080a59', '[\"*\"]', '2026-05-25 14:02:00', '2026-05-25 14:32:00', '2026-05-25 14:02:00', '2026-05-25 14:02:00'),
(80, 'App\\Models\\User', 2, 'auth-token', '9516311b07fa88e368c2f5c142e3747e63a23b49ab7c8a273ca261862b412578', '[\"*\"]', '2026-05-25 14:49:53', '2026-05-25 15:19:53', '2026-05-25 14:02:30', '2026-05-25 14:49:53'),
(81, 'App\\Models\\User', 2, 'auth-token', '235525ab81fde59397cb2755d7efeae6d4dfed9c041752e9cf38683ba31767b2', '[\"*\"]', '2026-05-25 15:52:04', '2026-05-25 16:22:04', '2026-05-25 15:52:04', '2026-05-25 15:52:04'),
(82, 'App\\Models\\User', 6, 'auth-token', '66c06dbe8ee0cde48348feb902779a73d3957b34126fb62791a161813b10ddf3', '[\"*\"]', '2026-05-25 15:59:08', '2026-05-25 16:29:08', '2026-05-25 15:52:17', '2026-05-25 15:59:08'),
(83, 'App\\Models\\User', 2, 'auth-token', 'd1ef95d6e50581a44695dbc34c1b963476617ffd3dd3179e774c16d7b1e7a495', '[\"*\"]', '2026-06-01 11:15:09', '2026-06-01 11:45:09', '2026-06-01 11:05:41', '2026-06-01 11:15:09'),
(84, 'App\\Models\\User', 2, 'auth-token', '32c406ddae90bbdd1354d7991ea927ed04fe50f19246bbb1528b0325c0b8281c', '[\"*\"]', '2026-06-01 12:07:30', '2026-06-01 12:37:30', '2026-06-01 12:02:28', '2026-06-01 12:07:30'),
(85, 'App\\Models\\User', 2, 'auth-token', 'f4cb83c34e284eaac1118133b14b0cf4dbf810b376620134bbc3764098a460a3', '[\"*\"]', '2026-06-01 13:55:03', '2026-06-01 14:25:03', '2026-06-01 13:55:03', '2026-06-01 13:55:03'),
(86, 'App\\Models\\User', 2, 'auth-token', '17e51d9027d2e5ed2cec059bbfbdf7cfcd1bd308e307b44e8f7ae5b778e6f105', '[\"*\"]', '2026-06-01 14:39:34', '2026-06-01 15:09:34', '2026-06-01 13:55:23', '2026-06-01 14:39:34'),
(87, 'App\\Models\\User', 2, 'auth-token', '68905199c13563ff4eedd12a58f83d30c4d76ae4a11195acd29e655085dad562', '[\"*\"]', '2026-06-01 15:38:08', '2026-06-01 16:08:08', '2026-06-01 15:38:08', '2026-06-01 15:38:08'),
(88, 'App\\Models\\User', 3, 'auth-token', '20dfe98f8bc88ff52d4683b8b653a0ed9d6d2897a49c9f343ac60b52b8bd9ea1', '[\"*\"]', '2026-06-01 15:45:56', '2026-06-01 16:15:56', '2026-06-01 15:45:56', '2026-06-01 15:45:56'),
(89, 'App\\Models\\User', 2, 'auth-token', '0304fe2f31b6b8e9b0872b3e63efc4dfad83460cbbafee9220e92525b464ae67', '[\"*\"]', '2026-06-01 15:56:35', '2026-06-01 16:26:35', '2026-06-01 15:46:16', '2026-06-01 15:56:35'),
(90, 'App\\Models\\User', 2, 'auth-token', 'a66a27cf01e97fa789c91283a88b26b5a33322756c895d7dd89d31719b1f0ab9', '[\"*\"]', '2026-06-01 18:24:50', '2026-06-01 18:54:50', '2026-06-01 17:09:00', '2026-06-01 18:24:50'),
(91, 'App\\Models\\User', 2, 'auth-token', '903054764b2113c034f884d67a25dc896fa9821914481ba7b609ff611762c859', '[\"*\"]', '2026-06-02 15:02:07', '2026-06-02 15:32:07', '2026-06-02 14:29:09', '2026-06-02 15:02:07'),
(92, 'App\\Models\\User', 2, 'auth-token', 'd4e92b4d03a2ec5298f2ae2a57a0fd80ad7e844ab12c4c4101e75ee5467b4b98', '[\"*\"]', '2026-06-02 16:21:29', '2026-06-02 16:51:29', '2026-06-02 16:21:29', '2026-06-02 16:21:29'),
(93, 'App\\Models\\User', 3, 'auth-token', '74f517f19f7b9322b6998ef86b3b82a750d36aacd613884d7e729def6fecc17e', '[\"*\"]', '2026-06-02 16:22:06', '2026-06-02 16:52:06', '2026-06-02 16:22:06', '2026-06-02 16:22:06'),
(94, 'App\\Models\\User', 2, 'auth-token', 'df46e34873427f63b62cb69f9c3de0e0bdc6661b357eef617eeca7224e4b0c54', '[\"*\"]', '2026-06-02 20:06:53', '2026-06-02 20:36:53', '2026-06-02 20:06:53', '2026-06-02 20:06:53'),
(95, 'App\\Models\\User', 2, 'auth-token', 'fbbc3cabb83fafdcf0aab7430d99a04093ea5a1b0fe54ee9fc536e76d6105e6d', '[\"*\"]', '2026-06-02 20:53:31', '2026-06-02 21:23:31', '2026-06-02 20:06:59', '2026-06-02 20:53:31'),
(96, 'App\\Models\\User', 2, 'auth-token', '42d105828e783f326b2d3c4e9fae13bd5ecbc33d8ceee0e2672c7081cb88b923', '[\"*\"]', '2026-06-05 12:51:23', '2026-06-05 13:21:23', '2026-06-05 12:51:23', '2026-06-05 12:51:23'),
(97, 'App\\Models\\User', 2, 'auth-token', '63f6ff74107a5c469cf8e91f715d9d7ada41ee46cb912f94fdbb6879bde067aa', '[\"*\"]', '2026-06-05 12:58:54', '2026-06-05 13:28:54', '2026-06-05 12:51:28', '2026-06-05 12:58:54'),
(98, 'App\\Models\\User', 2, 'auth-token', 'b61756300a8b6bea43855771f4f6009b126b9b84f4c8c053c34e3809742c728b', '[\"*\"]', '2026-06-05 14:22:54', '2026-06-05 14:52:54', '2026-06-05 13:13:24', '2026-06-05 14:22:54'),
(99, 'App\\Models\\User', 2, 'auth-token', '5509729433005325ff75a755ed9f3a1c45100ba3c30ef7df6c015984446383cf', '[\"*\"]', '2026-06-05 15:03:10', '2026-06-05 15:33:11', '2026-06-05 15:03:11', '2026-06-05 15:03:11'),
(100, 'App\\Models\\User', 2, 'auth-token', 'd16d822a924a9c6bc2e5e84d21671840acb85b280d749f300363e4ff820c598c', '[\"*\"]', '2026-06-05 15:03:47', '2026-06-05 15:33:47', '2026-06-05 15:03:16', '2026-06-05 15:03:47'),
(101, 'App\\Models\\User', 2, 'auth-token', '481cf9c6a58f3deb1f85bea827451742980ad0fb219e284638a57ce96b5553fb', '[\"*\"]', '2026-06-05 15:26:09', '2026-06-05 15:56:09', '2026-06-05 15:17:01', '2026-06-05 15:26:09'),
(102, 'App\\Models\\User', 2, 'auth-token', '777c8658239125eb0d6cbf5520fec7fc1b0ebce8e08877046fe9f9dc84095956', '[\"*\"]', '2026-06-06 14:02:04', '2026-06-06 14:32:04', '2026-06-06 13:36:33', '2026-06-06 14:02:04'),
(103, 'App\\Models\\User', 2, 'auth-token', '39aeb477324d22d2ff98ac7cbbe0255e7f2169a8feb1e5b9a944bf48d8c2b115', '[\"*\"]', '2026-06-06 14:39:11', '2026-06-06 15:09:11', '2026-06-06 14:17:38', '2026-06-06 14:39:11'),
(104, 'App\\Models\\User', 2, 'auth-token', '10528ae78f57ad3e57940c145262e1339b0fbaab677a317f71d513865ae41fb3', '[\"*\"]', '2026-06-06 18:06:55', '2026-06-06 18:36:55', '2026-06-06 17:58:44', '2026-06-06 18:06:55'),
(105, 'App\\Models\\User', 2, 'auth-token', '1da845ed9e42315c235e9bb987e4be1f3be4569f9e2db2b21c78b1a1264b4ef7', '[\"*\"]', '2026-06-06 19:13:44', '2026-06-06 19:43:44', '2026-06-06 19:13:44', '2026-06-06 19:13:44'),
(106, 'App\\Models\\User', 2, 'auth-token', 'ade40b1ea9191ce3e12ab0560f3b423e151d6c3568d2fc6583f78eca4f80bd95', '[\"*\"]', '2026-06-06 20:41:40', '2026-06-06 21:11:40', '2026-06-06 20:41:40', '2026-06-06 20:41:40'),
(107, 'App\\Models\\User', 2, 'auth-token', '1cdffc6fd36efeefeb52d381d5057875f3d3f22d0754b11dc72ecb88c49328ff', '[\"*\"]', '2026-06-06 21:35:40', '2026-06-06 22:05:40', '2026-06-06 20:41:45', '2026-06-06 21:35:40'),
(108, 'App\\Models\\User', 2, 'auth-token', 'ebfd261855c49264a57fb7fb3ca45f5cfd861d4f6591e0e5ac1a3168dae90793', '[\"*\"]', '2026-06-06 23:11:27', '2026-06-06 23:41:27', '2026-06-06 23:11:27', '2026-06-06 23:11:27'),
(109, 'App\\Models\\User', 2, 'auth-token', '722c0ca187da744dfa46dbf5e9cda3b9255c822ed812bf285e53859db68f1e39', '[\"*\"]', '2026-06-07 07:27:51', '2026-06-07 07:57:51', '2026-06-07 06:32:09', '2026-06-07 07:27:51'),
(110, 'App\\Models\\User', 2, 'auth-token', '1fe59176757c770d0dcc5e55f382ad37ffa7045c9b923f7b014cc5502d9b8374', '[\"*\"]', '2026-06-09 02:17:14', '2026-06-09 02:47:14', '2026-06-09 01:54:42', '2026-06-09 02:17:14'),
(111, 'App\\Models\\User', 2, 'auth-token', '03248257d802c75dfa5af28dbb7757e2e9810d4de879c6d58bd6829b9d212b24', '[\"*\"]', '2026-06-09 06:57:27', '2026-06-09 07:27:27', '2026-06-09 05:51:06', '2026-06-09 06:57:27'),
(112, 'App\\Models\\User', 2, 'auth-token', '110ec6a260af1c6fe13c7ad52a952d58df048d6da309bc2a47b84fdb637c6d25', '[\"*\"]', '2026-06-09 07:03:30', '2026-06-09 07:33:30', '2026-06-09 07:03:30', '2026-06-09 07:03:30'),
(113, 'App\\Models\\User', 3, 'auth-token', '911a7e0da36e34c12bae3f0d7858a413ec65c329064339fa48ef34f2dd1fb6ca', '[\"*\"]', '2026-06-09 07:02:49', '2026-06-09 07:32:49', '2026-06-09 07:02:49', '2026-06-09 07:02:49'),
(114, 'App\\Models\\User', 2, 'auth-token', 'd5332d7599bfc050ca119197c60a1ba24bc237669865f71c0843a6b62d83b6e7', '[\"*\"]', '2026-06-09 08:12:18', '2026-06-09 08:42:18', '2026-06-09 08:12:18', '2026-06-09 08:12:18'),
(115, 'App\\Models\\User', 3, 'auth-token', '87794d37bf28c3cf3413504ff7411bdc16465a209d2e59030b127649fb5d8d14', '[\"*\"]', '2026-06-09 08:57:45', '2026-06-09 09:27:45', '2026-06-09 08:57:45', '2026-06-09 08:57:45'),
(116, 'App\\Models\\User', 2, 'auth-token', '377eeabff1a59b172fcba3a59f166a1798c9c360eccdbcb657ca3b8a65aef1ca', '[\"*\"]', '2026-06-09 08:46:03', '2026-06-09 09:16:03', '2026-06-09 08:22:44', '2026-06-09 08:46:03'),
(117, 'App\\Models\\User', 2, 'auth-token', '121f4d640cffc2bb9a79a49876df4607fbb6471bb62b7cc3f058df3811ade32b', '[\"*\"]', '2026-06-09 10:29:30', '2026-06-09 10:59:30', '2026-06-09 09:49:33', '2026-06-09 10:29:30'),
(118, 'App\\Models\\User', 2, 'auth-token', '36342ff3fbabd8e53cf3fe1fefcba6f30a10ff873d2eff0db8084d8610330c4c', '[\"*\"]', '2026-06-09 14:46:53', '2026-06-09 15:16:53', '2026-06-09 14:46:53', '2026-06-09 14:46:53'),
(119, 'App\\Models\\User', 2, 'auth-token', '59c63931989a5ad034128848d47a5f66143a35a746241c2c54f354f7a5f57fbe', '[\"*\"]', '2026-06-09 14:53:04', '2026-06-09 15:23:04', '2026-06-09 14:46:59', '2026-06-09 14:53:04'),
(120, 'App\\Models\\User', 2, 'auth-token', '2a4f47f0813e619041ed80b766fa46257c4f23a56df3b72021013d84c741a47b', '[\"*\"]', '2026-06-09 17:16:08', '2026-06-09 17:46:08', '2026-06-09 16:18:55', '2026-06-09 17:16:08'),
(121, 'App\\Models\\User', 6, 'auth-token', '9d55eaa6ceae8b57eb0f56104167dcb66813d6f3139aa92192e563137f6d2613', '[\"*\"]', '2026-06-09 17:22:26', '2026-06-09 17:52:26', '2026-06-09 17:22:26', '2026-06-09 17:22:26'),
(122, 'App\\Models\\User', 3, 'auth-token', '422ac83b57f849cda8db1e4314a9002eccf0c335cb9bc5a532028dbe01574fd5', '[\"*\"]', '2026-06-09 17:23:19', '2026-06-09 17:53:19', '2026-06-09 17:23:19', '2026-06-09 17:23:19'),
(123, 'App\\Models\\User', 2, 'auth-token', 'a03a0b1cfe8b80fb13d9aa60a398b7845bff03c8f9d1a920e7f6c29242f21e75', '[\"*\"]', '2026-06-09 20:57:12', '2026-06-09 21:27:12', '2026-06-09 19:20:02', '2026-06-09 20:57:12'),
(124, 'App\\Models\\User', 2, 'pdf-test', 'b01226fb8ea1710c65d8d8ea49a70598be8c37627ce07c705e1eeed4433f38f0', '[\"*\"]', '2026-06-09 20:03:47', NULL, '2026-06-09 20:02:59', '2026-06-09 20:03:47'),
(125, 'App\\Models\\User', 3, 'auth-token', 'a7ca59e7ce7409d35df2481ec054c84480e01dfe22ac2526264b8951bb536fc3', '[\"*\"]', '2026-06-09 21:19:38', '2026-06-09 21:49:38', '2026-06-09 21:19:38', '2026-06-09 21:19:38'),
(126, 'App\\Models\\User', 2, 'auth-token', 'f868755997cefbb7c065251e2baa5cdbeef54dc142bbccc9c6141ad4b60c6ad2', '[\"*\"]', '2026-06-10 00:04:54', '2026-06-10 00:34:54', '2026-06-09 23:55:50', '2026-06-10 00:04:54'),
(127, 'App\\Models\\User', 6, 'auth-token', 'af62c0a8f219bfe1bd37e289a0f485fab76f2576f640dc68264a10ffcecf9202', '[\"*\"]', '2026-06-10 02:03:21', '2026-06-10 02:33:21', '2026-06-10 02:03:19', '2026-06-10 02:03:21'),
(128, 'App\\Models\\User', 6, 'auth-token', 'f8c154afa9d6132f13ce5abf47d477102349902d722e8ea4db74f53ece97bc52', '[\"*\"]', '2026-06-10 02:04:22', '2026-06-10 02:34:22', '2026-06-10 02:04:22', '2026-06-10 02:04:22'),
(129, 'App\\Models\\User', 6, 'auth-token', '5644c26c9520704e86699e5aa3b8b3e86ca72db82275f51e75cd9970fb59fe90', '[\"*\"]', '2026-06-10 02:06:08', '2026-06-10 02:36:08', '2026-06-10 02:06:08', '2026-06-10 02:06:08'),
(130, 'App\\Models\\User', 7, 'auth-token', 'b60492a282a099e083057aef78d55cf93c963ff71dcb87c6a263915d0555abb5', '[\"*\"]', '2026-06-10 02:10:18', '2026-06-10 02:40:18', '2026-06-10 02:06:34', '2026-06-10 02:10:18'),
(131, 'App\\Models\\User', 2, 'auth-token', '4c4a370b21b4cb0545a6d62e96a09666adad3adcf991f8a7a6845d16e884a96d', '[\"*\"]', '2026-06-10 13:36:43', '2026-06-10 14:06:43', '2026-06-10 13:36:43', '2026-06-10 13:36:43'),
(132, 'App\\Models\\User', 2, 'auth-token', '31bf88bc7d0140435ebb1852bb413aa1760d43f82a5187e2ea8f2ab0f16b07fe', '[\"*\"]', '2026-06-10 20:22:26', '2026-06-10 20:52:26', '2026-06-10 20:22:26', '2026-06-10 20:22:26'),
(133, 'App\\Models\\User', 2, 'auth-token', 'adf97ff3b3840e226727e6c1dbad6a4cb71b6d151a1af2d6b35b6ee549d9ab30', '[\"*\"]', '2026-06-10 21:24:48', '2026-06-10 21:54:48', '2026-06-10 20:34:18', '2026-06-10 21:24:48'),
(134, 'App\\Models\\User', 6, 'auth-token', '0aa0b7fec6fab1468fdd5352c6e12cce32ff56fda6f678a49cbf2112f384bf33', '[\"*\"]', '2026-06-10 21:06:22', '2026-06-10 21:36:22', '2026-06-10 21:01:41', '2026-06-10 21:06:22'),
(135, 'App\\Models\\User', 6, 'auth-token', '4d13eb7cba2535aa01361e42219ccae53c42b8d54184abac479d8c8b3eee004d', '[\"*\"]', '2026-06-10 21:07:25', '2026-06-10 21:37:25', '2026-06-10 21:07:25', '2026-06-10 21:07:25'),
(136, 'App\\Models\\User', 3, 'auth-token', '78804adaa154e8360955491225f251f0d3f23fde4ecc6fe295e8d78ff3692709', '[\"*\"]', '2026-06-10 21:24:15', '2026-06-10 21:54:15', '2026-06-10 21:07:36', '2026-06-10 21:24:15'),
(137, 'App\\Models\\User', 2, 'auth-token', '709216a66936052a30acf4a5ef0504fe62dc8caf2ec7e531c3b6e49c8f4979e2', '[\"*\"]', '2026-06-11 01:43:36', '2026-06-11 02:13:36', '2026-06-11 01:43:36', '2026-06-11 01:43:36'),
(138, 'App\\Models\\User', 3, 'auth-token', '94aaa093538773716026ef91dbff0fc85e6f9ea29b2edf89188e7603263f2ac0', '[\"*\"]', '2026-06-11 00:49:29', '2026-06-11 01:19:29', '2026-06-11 00:49:23', '2026-06-11 00:49:29'),
(139, 'App\\Models\\User', 3, 'auth-token', 'cc1eaa02600e9a6523b51eb2954a32aa6912a0963447b8a14e07f847e6006b81', '[\"*\"]', '2026-06-11 01:43:02', '2026-06-11 02:13:02', '2026-06-11 01:43:02', '2026-06-11 01:43:02'),
(140, 'App\\Models\\User', 3, 'auth-token', 'd6cd97166435a8e60d2e63f1fe764d96fc056ce627c9cd3af147556f6de0fb92', '[\"*\"]', '2026-06-11 02:12:41', '2026-06-11 02:42:41', '2026-06-11 02:12:40', '2026-06-11 02:12:41'),
(141, 'App\\Models\\User', 2, 'auth-token', '19de380518ebfbb27c26dd712a2ae153a1f3b240afa264d4b8b456048cbb40dd', '[\"*\"]', '2026-06-11 02:18:26', '2026-06-11 02:48:26', '2026-06-11 01:44:05', '2026-06-11 02:18:26'),
(142, 'App\\Models\\User', 3, 'auth-token', '25bc9b1748ae2b28bb12533df7178080cbf625bd6f59b2d59bc1e8ab6040b707', '[\"*\"]', '2026-06-11 02:42:56', '2026-06-11 03:12:57', '2026-06-11 02:42:57', '2026-06-11 02:42:57'),
(143, 'App\\Models\\User', 2, 'auth-token', '3fb1ffc46d326ba9e2d05cc38599dddf24e0d9196a315989929c9e982a77d1ed', '[\"*\"]', '2026-06-11 06:44:54', '2026-06-11 07:14:54', '2026-06-11 05:49:33', '2026-06-11 06:44:54'),
(144, 'App\\Models\\User', 6, 'auth-token', '2329b70e462db2417c9c1f272c206438781800ba7aecc57173c8e239ea9bc7f1', '[\"*\"]', '2026-06-11 06:08:30', '2026-06-11 06:38:30', '2026-06-11 06:08:30', '2026-06-11 06:08:30'),
(145, 'App\\Models\\User', 3, 'auth-token', 'eb30a83d9b1a401222c5e0216e604720dc356d1b07f3d02a14974c626003a0b0', '[\"*\"]', '2026-06-11 06:44:13', '2026-06-11 07:14:13', '2026-06-11 06:44:13', '2026-06-11 06:44:13'),
(147, 'App\\Models\\User', 2, 'auth-token', 'def3ad79b1c13d1acefa67aaf5f1f4f492c9e911407bb37b99010eab48c33766', '[\"*\"]', '2026-06-11 14:30:03', '2026-06-11 15:00:03', '2026-06-11 14:21:01', '2026-06-11 14:30:03'),
(148, 'App\\Models\\User', 2, 'auth-token', '40fb6537fd12b55f629c7be7c8ad2b99ab7148ea3cba1a1dc56e10535ef2c195', '[\"*\"]', '2026-06-12 22:18:14', '2026-06-12 22:48:14', '2026-06-12 22:12:41', '2026-06-12 22:18:14'),
(159, 'App\\Models\\User', 2, 'auth-token', 'f8acaa04a07b8654015ec6a6ef99dd1a6a9c8efc8262d6b27747e96993c0f798', '[\"*\"]', '2026-06-12 23:36:57', '2026-06-13 00:06:57', '2026-06-12 23:00:05', '2026-06-12 23:36:57'),
(160, 'App\\Models\\User', 4, 'auth-token', 'd99c86e66bd87f7f83f0637e7bc0244f55ae5154a4a22e7834f3bd102527ca86', '[\"*\"]', '2026-06-12 23:07:37', '2026-06-12 23:37:37', '2026-06-12 23:07:33', '2026-06-12 23:07:37'),
(161, 'App\\Models\\User', 3, 'auth-token', '25be7221d02186790b339ae01599a408810d7dac9bc03d4762bd7b2f59936641', '[\"*\"]', '2026-06-12 23:36:09', '2026-06-13 00:06:09', '2026-06-12 23:08:05', '2026-06-12 23:36:09'),
(162, 'App\\Models\\User', 2, 'auth-token', '1e80d5e56b706306d9c91339f294de087703ac120ebe6e52228633c1d3aa60b9', '[\"*\"]', '2026-06-12 23:23:33', '2026-06-12 23:53:33', '2026-06-12 23:23:26', '2026-06-12 23:23:33'),
(163, 'App\\Models\\User', 2, 'auth-token', '783388ede07cb92da677800ddd7146183e5da76ec45bd137e10329c4740aa17e', '[\"*\"]', '2026-06-12 23:26:41', '2026-06-12 23:56:41', '2026-06-12 23:26:33', '2026-06-12 23:26:41'),
(164, 'App\\Models\\User', 2, 'auth-token', '7d1dbf0c7b7adfbb6ef85d4ea23378c0f5842b4b6cb71a6ade5e8b82d85ad946', '[\"*\"]', '2026-06-12 23:35:02', '2026-06-13 00:05:02', '2026-06-12 23:34:57', '2026-06-12 23:35:02'),
(165, 'App\\Models\\User', 2, 'auth-token', '48653759ef1f61c8482ae0617d436a30283c10353e0f8dfb308787799eeaa2a8', '[\"*\"]', '2026-06-12 23:35:35', '2026-06-13 00:05:35', '2026-06-12 23:35:33', '2026-06-12 23:35:35'),
(166, 'App\\Models\\User', 2, 'auth-token', 'a97ed608481e24e3b975612e17d70b2107d5c4a4d1fb35c193903c3e9a2bf7ba', '[\"*\"]', '2026-06-13 00:10:36', '2026-06-13 00:40:36', '2026-06-12 23:38:50', '2026-06-13 00:10:36'),
(167, 'App\\Models\\User', 4, 'auth-token', '0b97b255289ff1029126831aaef76a298e8b7ae60c602adef40963d6fe16f006', '[\"*\"]', '2026-06-12 23:41:22', '2026-06-13 00:11:22', '2026-06-12 23:41:22', '2026-06-12 23:41:22'),
(168, 'App\\Models\\User', 4, 'auth-token', 'd7826f5abd650201d3f47190fd5433344ed5065b43feae292b12252db7297af0', '[\"*\"]', '2026-06-13 00:07:36', '2026-06-13 00:37:36', '2026-06-12 23:41:27', '2026-06-13 00:07:36'),
(170, 'App\\Models\\User', 4, 'auth-token', '9ff9a733ea0ab05a0230d79edd5881939bf50f5aa1931262ac8074b7ff26734b', '[\"*\"]', '2026-06-13 00:33:01', '2026-06-13 01:03:01', '2026-06-13 00:10:57', '2026-06-13 00:33:01'),
(171, 'App\\Models\\User', 2, 'auth-token', 'f328d141a2213eaf0d2bfefb0f943adb9eaade906d4b316fe17a6c32afd86f42', '[\"*\"]', '2026-06-13 00:30:46', '2026-06-13 01:00:46', '2026-06-13 00:11:18', '2026-06-13 00:30:46'),
(172, 'App\\Models\\User', 3, 'auth-token', '1abb2fcb1ee714e43139a6fbbe03ec232345884c5f19ef970039649c48e01159', '[\"*\"]', '2026-06-13 00:33:14', '2026-06-13 01:03:14', '2026-06-13 00:14:37', '2026-06-13 00:33:14'),
(173, 'App\\Models\\User', 6, 'auth-token', 'caf741b86ce2a826cd2fb83ccd5846ffb4dacb5b81fa7e304413e39c486617b2', '[\"*\"]', '2026-06-13 00:28:08', '2026-06-13 00:58:08', '2026-06-13 00:28:08', '2026-06-13 00:28:08'),
(175, 'App\\Models\\User', 2, 'auth-token', 'e81ba5452fcb7f2e7654bcc6de55e451feb9731b3942ae3c334892ab958575d7', '[\"*\"]', '2026-06-13 02:54:24', '2026-06-13 03:24:24', '2026-06-13 02:39:44', '2026-06-13 02:54:24'),
(176, 'App\\Models\\User', 2, 'auth-token', 'ba38925d02d8854d02a687a0bc8c5a8534ae775bd6ff3e5a18717af54bf6594f', '[\"*\"]', '2026-06-13 04:07:21', '2026-06-13 04:37:21', '2026-06-13 04:07:21', '2026-06-13 04:07:21'),
(177, 'App\\Models\\User', 2, 'auth-token', '6904e263148f852410a8a968a9304753bc98b7cf31f58aab67d1f189757dd322', '[\"*\"]', '2026-06-13 14:43:25', '2026-06-13 15:13:25', '2026-06-13 14:43:25', '2026-06-13 14:43:25'),
(178, 'App\\Models\\User', 4, 'auth-token', '670be73b4ba53475c6a964d7bcfccfc8af7beaf99a3b31637c77fd021b93d998', '[\"*\"]', '2026-06-13 15:48:33', '2026-06-13 16:18:32', '2026-06-13 15:48:32', '2026-06-13 15:48:33'),
(179, 'App\\Models\\User', 2, 'auth-token', '5c35011f24f769ce8ffbaac0f87edf057ff329275d5cf7503752bb66725c4d38', '[\"*\"]', '2026-06-13 17:23:47', '2026-06-13 17:53:47', '2026-06-13 17:23:47', '2026-06-13 17:23:47'),
(180, 'App\\Models\\User', 3, 'auth-token', '0cc4893ab00ed00d3a5130a044ba13c1c621347dc3e63f385c20349f318c31a1', '[\"*\"]', '2026-06-13 15:54:01', '2026-06-13 16:24:01', '2026-06-13 15:54:01', '2026-06-13 15:54:01'),
(181, 'App\\Models\\User', 4, 'auth-token', 'cf5e10b21b53f9dbeaa97c9a60a254978738d61091b5e220a264461e4308ced6', '[\"*\"]', '2026-06-13 16:31:44', '2026-06-13 17:01:44', '2026-06-13 16:31:44', '2026-06-13 16:31:44'),
(182, 'App\\Models\\User', 4, 'auth-token', '0763ca558c3b4161d62374e659472b59663862bc37aae3ed2fc04f0a093a4eeb', '[\"*\"]', '2026-06-13 17:15:20', '2026-06-13 17:45:20', '2026-06-13 17:15:20', '2026-06-13 17:15:20'),
(183, 'App\\Models\\User', 3, 'auth-token', '6552bcadd2acb60c26f3e409386c88a08bd7b68d69b393bfd13ec505acb2e375', '[\"*\"]', '2026-06-13 17:18:15', '2026-06-13 17:48:15', '2026-06-13 17:18:15', '2026-06-13 17:18:15'),
(186, 'App\\Models\\User', 2, 'auth-token', 'ccf287999dc4c3ad5851ccce0e1d9b2f52c6ccfd25caf43024a28908e0037f2a', '[\"*\"]', '2026-06-13 17:52:17', '2026-06-13 18:22:17', '2026-06-13 17:37:16', '2026-06-13 17:52:17'),
(187, 'App\\Models\\User', 3, 'auth-token', 'bdce27e59b1a7fbd2e967662943ad97967d13b3b72772e5eced40327d262837d', '[\"*\"]', '2026-06-13 17:57:46', '2026-06-13 18:27:46', '2026-06-13 17:38:32', '2026-06-13 17:57:46'),
(188, 'App\\Models\\User', 4, 'auth-token', '63ffd942832b08dcafaef3c8361c4695b14f4e115436784f4dd142569945fd86', '[\"*\"]', '2026-06-13 17:56:53', '2026-06-13 18:26:53', '2026-06-13 17:47:09', '2026-06-13 17:56:53'),
(189, 'App\\Models\\User', 2, 'auth-token', 'c812ad457e0d1923c4d4cb1c4f841c965fcf5a5dc856ae78ec06bd05f89b4bf5', '[\"*\"]', '2026-06-13 21:23:06', '2026-06-13 21:53:06', '2026-06-13 20:47:46', '2026-06-13 21:23:06'),
(190, 'App\\Models\\User', 4, 'auth-token', '30d611b2cc8d7711bc8138cd5da714d14acc6e7b0d16a7e4f616ede4c3792ae1', '[\"*\"]', '2026-06-13 21:49:16', '2026-06-13 22:19:16', '2026-06-13 21:49:16', '2026-06-13 21:49:16'),
(191, 'App\\Models\\User', 3, 'auth-token', '7241f130087560d4c16ecf675daea2fc4713977761306bf0faaaa1ecbe5135ce', '[\"*\"]', '2026-06-13 21:49:29', '2026-06-13 22:19:29', '2026-06-13 21:49:29', '2026-06-13 21:49:29'),
(192, 'App\\Models\\User', 2, 'auth-token', 'a3cb14234707ea5cebf0b4b2bc24323a218dc2c46d181cf6456708294f3e9fbd', '[\"*\"]', '2026-06-14 01:23:46', '2026-06-14 01:53:46', '2026-06-14 01:23:46', '2026-06-14 01:23:46'),
(193, 'App\\Models\\User', 2, 'auth-token', '250b30fa1231091511a8723c8722008e2601fabb4ccd450fac7b8564178c9886', '[\"*\"]', '2026-06-14 02:22:44', '2026-06-14 02:52:44', '2026-06-14 01:23:50', '2026-06-14 02:22:44'),
(194, 'App\\Models\\User', 4, 'auth-token', '5adef502a6597365102138271a4d8eac54dedb1f4f932472dba2aa1e25610632', '[\"*\"]', '2026-06-14 02:24:40', '2026-06-14 02:54:40', '2026-06-14 02:02:12', '2026-06-14 02:24:40'),
(195, 'App\\Models\\User', 2, 'auth-token', 'cf86936e47d8c3c19bae791a91f931347a34d271f7353d791d0259fb7f835cf2', '[\"*\"]', '2026-06-14 07:17:41', '2026-06-14 07:47:41', '2026-06-14 07:11:40', '2026-06-14 07:17:41'),
(196, 'App\\Models\\User', 2, 'auth-token', 'a04fdcecc2f0c1d5a97ba576cca339a1c4df1e259002f846970a059c18b59ac5', '[\"*\"]', '2026-06-15 17:16:54', '2026-06-15 17:46:54', '2026-06-15 17:16:49', '2026-06-15 17:16:54'),
(197, 'App\\Models\\User', 2, 'auth-token', 'd50ac4145e9943f7268076ef0d7c13c11eefd2c5a76b04e2be32e9e7c9c41f09', '[\"*\"]', '2026-06-21 21:20:38', '2026-06-21 21:50:38', '2026-06-21 21:07:12', '2026-06-21 21:20:38'),
(198, 'App\\Models\\User', 2, 'auth-token', 'de2215b125a8acc2a4bae861778293df170cd1a763798eb06c088380f7b47052', '[\"*\"]', '2026-06-21 23:31:07', '2026-06-22 00:01:07', '2026-06-21 23:31:07', '2026-06-21 23:31:07'),
(199, 'App\\Models\\User', 4, 'auth-token', 'af41f3b5e27721ea38f3623d57c92ccdf7e97882b7eca74ce28d19ff81b43039', '[\"*\"]', '2026-06-21 22:56:52', '2026-06-21 23:26:52', '2026-06-21 22:56:52', '2026-06-21 22:56:52'),
(200, 'App\\Models\\User', 4, 'auth-token', '53d4f75b47e644c14c24ebd29a7b5f5af0e5af0e383eea7ce4b7201564bc32d0', '[\"*\"]', '2026-06-21 23:38:38', '2026-06-22 00:08:38', '2026-06-21 23:38:38', '2026-06-21 23:38:38'),
(201, 'App\\Models\\User', 2, 'auth-token', 'cf1ec27ba1c6f182e33cc1139791d305c4198490354ff459fa1361c36ced288b', '[\"*\"]', '2026-06-22 00:05:05', '2026-06-22 00:35:05', '2026-06-21 23:33:01', '2026-06-22 00:05:05'),
(202, 'App\\Models\\User', 4, 'auth-token', '0c9976b5c9e31c0d03ed81dc12ec79ccbe7668d12ce9a6688609c25de313eb4d', '[\"*\"]', '2026-06-22 00:09:11', '2026-06-22 00:39:11', '2026-06-22 00:09:11', '2026-06-22 00:09:11'),
(203, 'App\\Models\\User', 4, 'auth-token', '5f895170ee26220dbb0714077cd872c390d22e620c79a763d0675257abe1475f', '[\"*\"]', '2026-06-22 01:35:01', '2026-06-22 02:05:01', '2026-06-22 01:10:00', '2026-06-22 01:35:01'),
(204, 'App\\Models\\User', 3, 'auth-token', 'ab336289c9c2d556268ac32cfa2910e71640f988a6965b3381b87a8fc1570787', '[\"*\"]', '2026-06-22 01:35:08', '2026-06-22 02:05:08', '2026-06-22 01:10:27', '2026-06-22 01:35:08'),
(205, 'App\\Models\\User', 2, 'auth-token', 'a6103df0019a2d70a9af6a65a1d0a82e68623a7909d1e782b21aac5f3852a483', '[\"*\"]', '2026-06-22 23:53:13', '2026-06-23 00:23:13', '2026-06-22 23:51:33', '2026-06-22 23:53:13'),
(206, 'App\\Models\\User', 2, 'auth-token', 'b3b883e375414fdef5f4ef928fe875a0d513e35d703b2fdbfe55a2973898f453', '[\"*\"]', '2026-06-23 00:44:00', '2026-06-23 01:14:00', '2026-06-23 00:04:23', '2026-06-23 00:44:00'),
(208, 'App\\Models\\User', 4, 'auth-token', '559678376d235cdec237929186ddf9919c0b017e12876aa2a78af83e34943b15', '[\"*\"]', '2026-06-23 02:41:49', '2026-06-23 03:11:49', '2026-06-23 02:41:49', '2026-06-23 02:41:49'),
(209, 'App\\Models\\User', 3, 'auth-token', '5d5a2cc9b3b8320885cc763eb594e835115e1d2106eca2e64a0ad134f447003c', '[\"*\"]', '2026-06-23 00:57:54', '2026-06-23 01:27:54', '2026-06-23 00:57:54', '2026-06-23 00:57:54'),
(210, 'App\\Models\\User', 7, 'auth-token', 'e5964b5959efbe44759d9f60c70b779413d15003b63f91eb8ebd65eb413c9d44', '[\"*\"]', '2026-06-23 01:03:50', '2026-06-23 01:33:50', '2026-06-23 01:03:50', '2026-06-23 01:03:50'),
(211, 'App\\Models\\User', 2, 'auth-token', '36d41add8981cfbb9efefdcaba952f23710da7a76c348e54a530c654dc1aca88', '[\"*\"]', '2026-06-23 02:52:52', '2026-06-23 03:22:52', '2026-06-23 02:21:17', '2026-06-23 02:52:52'),
(212, 'App\\Models\\User', 3, 'auth-token', '3e4429ac3538733fa1ec59da727d56bd0cb415769041e80f6575f831e5a659e6', '[\"*\"]', '2026-06-23 03:11:04', '2026-06-23 03:41:04', '2026-06-23 03:11:04', '2026-06-23 03:11:04'),
(213, 'App\\Models\\User', 2, 'auth-token', '883d696ba464f82d6959761afda86828f03ddd20cc2d623e263beeaec3b5294a', '[\"*\"]', '2026-06-23 08:07:24', '2026-06-23 08:37:24', '2026-06-23 07:58:10', '2026-06-23 08:07:24'),
(214, 'App\\Models\\User', 6, 'auth-token', '088a55c6642dcd89f3523c267c368c5bdb3e50387d426fc1b3a6395cf618dc3f', '[\"*\"]', '2026-06-23 08:51:20', '2026-06-23 09:21:20', '2026-06-23 08:51:20', '2026-06-23 08:51:20'),
(216, 'App\\Models\\User', 2, 'auth-token', 'f040680435d4c504d513f6be0fc5dd33499eaf8ef2d1efc9e0cc5b3516014dd8', '[\"*\"]', '2026-06-23 09:04:03', '2026-06-23 09:34:03', '2026-06-23 08:38:07', '2026-06-23 09:04:03'),
(218, 'App\\Models\\User', 3, 'auth-token', '7fa826dbf2fd39e0ae55ed11a98d774e7bb4d072e58f5bbcd4c97c78f41dc8b1', '[\"*\"]', '2026-06-23 09:02:35', '2026-06-23 09:32:35', '2026-06-23 08:52:51', '2026-06-23 09:02:35'),
(219, 'App\\Models\\User', 3, 'auth-token', '784f385397b18f0cfc6660e430fae18f6ad4f67eb6359f971668942e1daf77fe', '[\"*\"]', '2026-06-23 10:07:17', '2026-06-23 10:37:17', '2026-06-23 09:44:09', '2026-06-23 10:07:17'),
(220, 'App\\Models\\User', 2, 'auth-token', '45bd916e6695ff0d721771a631356987a8c619f4af5723fd4dab83fe8562ef58', '[\"*\"]', '2026-06-23 09:54:45', '2026-06-23 10:24:45', '2026-06-23 09:44:26', '2026-06-23 09:54:45'),
(221, 'App\\Models\\User', 2, 'auth-token', '800974709bb6976d3e409fec3dff0f19cd03ef73c6a7abc237f479ff7d35b6d4', '[\"*\"]', '2026-06-23 10:03:36', '2026-06-23 10:33:36', '2026-06-23 09:54:59', '2026-06-23 10:03:36'),
(222, 'App\\Models\\User', 2, 'auth-token', 'b6b569edbdb311776ef3bc7fcf6990af8f200ad2572034142ccb6156edd1886b', '[\"*\"]', '2026-06-23 10:10:59', '2026-06-23 10:40:59', '2026-06-23 10:03:40', '2026-06-23 10:10:59'),
(223, 'App\\Models\\User', 2, 'auth-token', '93ba15f5ba05691dc8f00a8bd7b0b294fb348da8fca38dffe3849a73032eb4aa', '[\"*\"]', '2026-06-23 11:41:40', '2026-06-23 12:11:40', '2026-06-23 10:51:00', '2026-06-23 11:41:40'),
(224, 'App\\Models\\User', 3, 'auth-token', '72c995f9bd319c6f7eeb8bc25dc4f7ae4b3f63f6fee59ede5c3ca316e8d5e26a', '[\"*\"]', '2026-06-23 11:36:30', '2026-06-23 12:06:30', '2026-06-23 11:36:30', '2026-06-23 11:36:30'),
(225, 'App\\Models\\User', 3, 'auth-token', '3680cd2b7fbace6f952711ff88ec73ded49406c5cd6abc4bc4aedf4ff55b99f5', '[\"*\"]', '2026-06-23 11:22:42', '2026-06-23 11:52:42', '2026-06-23 11:22:42', '2026-06-23 11:22:42'),
(226, 'App\\Models\\User', 6, 'auth-token', '02344663c68a6d08ff94644d1d3af5b99e4e5b274ff17a02aa8cfa717c754e39', '[\"*\"]', '2026-06-23 11:41:40', '2026-06-23 12:11:40', '2026-06-23 11:36:37', '2026-06-23 11:41:40'),
(227, 'App\\Models\\User', 2, 'auth-token', 'cfb9fe43df0a67ffac00846fdc52a1fb858e8311fd1fc36bb90024351095e501', '[\"*\"]', '2026-06-24 07:09:24', '2026-06-24 07:39:24', '2026-06-24 06:47:42', '2026-06-24 07:09:24'),
(228, 'App\\Models\\User', 2, 'auth-token', 'bee04b57d7c4b7656349ee132fcc5a60ad4e3a8cf8bf3e708cd44539a960e072', '[\"*\"]', '2026-06-24 07:57:46', '2026-06-24 08:27:46', '2026-06-24 07:42:59', '2026-06-24 07:57:46'),
(229, 'App\\Models\\User', 2, 'auth-token', '86beb0bc1ba7fbeb2ce7cab0ad0c3cb3c4f46b2771296b24a0e77b6b0c64459f', '[\"*\"]', '2026-06-24 09:10:24', '2026-06-24 09:40:24', '2026-06-24 07:57:49', '2026-06-24 09:10:24'),
(230, 'App\\Models\\User', 6, 'auth-token', '4f70840cb6e8cee4301ae01441399706d383ae9d33de9dbdaf19ad74f5f917e1', '[\"*\"]', '2026-06-24 08:03:49', '2026-06-24 08:33:49', '2026-06-24 08:03:29', '2026-06-24 08:03:49'),
(231, 'App\\Models\\User', 6, 'auth-token', 'df9a44adc9f70a81a3249362cbc45d280fb409da59d6f7fdc3af982c48e212d7', '[\"*\"]', '2026-06-24 08:11:02', '2026-06-24 08:41:02', '2026-06-24 08:03:44', '2026-06-24 08:11:02'),
(232, 'App\\Models\\User', 4, 'auth-token', '4299fc9c40044db29166db3e8af3ac16de25064c4bb1892b631a76264cfb83bf', '[\"*\"]', '2026-06-24 08:43:15', '2026-06-24 09:13:15', '2026-06-24 08:43:15', '2026-06-24 08:43:15'),
(233, 'App\\Models\\User', 2, 'auth-token', '0d57a1c1e41a0d50ecaf1aedfbf9d6d492df0e25c181233cfb5c62e4e7925df8', '[\"*\"]', '2026-06-26 06:01:38', '2026-06-26 06:31:38', '2026-06-26 05:26:47', '2026-06-26 06:01:38'),
(234, 'App\\Models\\User', 2, 'auth-token', 'b795cab70dbd64be9d6f212e42f8f3220f7089cfb35b275a43dfcddc9a513201', '[\"*\"]', '2026-06-26 07:14:59', '2026-06-26 07:44:59', '2026-06-26 07:14:59', '2026-06-26 07:14:59'),
(235, 'App\\Models\\User', 2, 'auth-token', '3a80d0ca9c44d393b7d95fe24141cea827b4ab3c28081a64872d553b2bbbe32a', '[\"*\"]', '2026-06-26 10:06:28', '2026-06-26 10:36:28', '2026-06-26 09:39:43', '2026-06-26 10:06:28'),
(236, 'App\\Models\\User', 4, 'auth-token', 'fa3cf491591ebe25fcdcc8661809aefdab023eab77206718562b9269099831fe', '[\"*\"]', '2026-06-26 10:35:03', '2026-06-26 11:05:03', '2026-06-26 09:46:08', '2026-06-26 10:35:03'),
(237, 'App\\Models\\User', 3, 'auth-token', 'e6fab4556a5f3265e34e27412e8a3996d949128aa30d365434e87e535f151136', '[\"*\"]', '2026-06-26 10:35:02', '2026-06-26 11:05:02', '2026-06-26 09:46:56', '2026-06-26 10:35:02'),
(238, 'App\\Models\\User', 2, 'auth-token', '4539bb9c8b0faedd25a70a111899e13afa1c46b6381d4e8fe00c72e1d51e927c', '[\"*\"]', '2026-06-26 10:35:22', '2026-06-26 11:05:22', '2026-06-26 10:26:26', '2026-06-26 10:35:22'),
(239, 'App\\Models\\User', 2, 'auth-token', 'b82aec5d97ca2e0e68c2f3e396e56642d82be49ad2576c734870dd8854e102bc', '[\"*\"]', '2026-06-26 21:44:54', '2026-06-26 22:14:54', '2026-06-26 21:23:16', '2026-06-26 21:44:54'),
(240, 'App\\Models\\User', 2, 'auth-token', 'de262f9d289e0415d220f00049a97206f2bbceeebc252c17dfaed068fa945dd3', '[\"*\"]', '2026-06-26 22:30:21', '2026-06-26 23:00:21', '2026-06-26 22:30:21', '2026-06-26 22:30:21'),
(241, 'App\\Models\\User', 2, 'auth-token', '01ee933060ad238e8f6f0c885b1a60fffe645e47b7450a0b75a73655c2176c04', '[\"*\"]', '2026-06-27 02:17:50', '2026-06-27 02:47:50', '2026-06-27 02:17:50', '2026-06-27 02:17:50'),
(242, 'App\\Models\\User', 2, 'auth-token', '35e691a78df60d6a5444131997f186c8fc165e7a633edbda18558cf756a59510', '[\"*\"]', '2026-06-27 04:17:20', '2026-06-27 04:47:20', '2026-06-27 04:17:20', '2026-06-27 04:17:20'),
(243, 'App\\Models\\User', 2, 'auth-token', '8ed3bd8e267cc1aa935556b977ca0353dbc4e300fbb5eafc5e3c58e44fdd2c31', '[\"*\"]', '2026-06-27 04:32:56', '2026-06-27 05:02:56', '2026-06-27 04:17:48', '2026-06-27 04:32:56'),
(244, 'App\\Models\\User', 2, 'auth-token', '01e1b41d4d58c2646295b782866789f9905e2f41fc27002e5752d7ca86f983f6', '[\"*\"]', '2026-06-27 05:13:26', '2026-06-27 05:43:26', '2026-06-27 05:11:09', '2026-06-27 05:13:26'),
(245, 'App\\Models\\User', 2, 'auth-token', '19608c3c4dc903902b9ade4f4d28774d3c8b965775822392565a369474f45af6', '[\"*\"]', '2026-06-27 06:13:09', '2026-06-27 06:43:08', '2026-06-27 05:57:09', '2026-06-27 06:13:09'),
(246, 'App\\Models\\User', 2, 'auth-token', 'a6695fd0fd3a835cfdad72a315b6527db94eb72552b2fa304e653da3c6469a43', '[\"*\"]', '2026-06-27 09:18:25', '2026-06-27 09:48:25', '2026-06-27 08:46:37', '2026-06-27 09:18:25'),
(247, 'App\\Models\\User', 2, 'auth-token', '531ff13a5d1cdce04cde2c222540e7729641389525efc5fd7d80e56ed2f990b3', '[\"*\"]', '2026-06-27 21:11:03', '2026-06-27 21:41:03', '2026-06-27 21:06:08', '2026-06-27 21:11:03'),
(248, 'App\\Models\\User', 2, 'auth-token', '42024f9882e6e541f6b750234830973cdf49e6d30671fee078ae1869467bda89', '[\"*\"]', '2026-06-27 22:25:36', '2026-06-27 22:55:36', '2026-06-27 21:15:14', '2026-06-27 22:25:36'),
(249, 'App\\Models\\User', 2, 'auth-token', '02f1b268b09cba6a83cae93f200b6120be5038834b1de29ee047938bfc1b3dcb', '[\"*\"]', '2026-06-28 02:12:59', '2026-06-28 02:42:59', '2026-06-28 02:12:59', '2026-06-28 02:12:59'),
(250, 'App\\Models\\User', 3, 'auth-token', '9c943be83451152e496e9b69a4cb4182ee21d34d7e962934fadbb509dd3367cd', '[\"*\"]', '2026-06-28 01:36:00', '2026-06-28 02:06:00', '2026-06-28 01:32:43', '2026-06-28 01:36:00'),
(251, 'App\\Models\\User', 3, 'auth-token', '923d95acd22e7c25d930c35b1898c760cce4ec7876ad2dc133b2337c2af7a8dd', '[\"*\"]', '2026-06-28 02:00:44', '2026-06-28 02:30:44', '2026-06-28 01:37:06', '2026-06-28 02:00:44'),
(252, 'App\\Models\\User', 2, 'auth-token', '0c15c62628b967afdb9bc675a6a2b1d3d1b6847e969cc2f553435e482df61e4b', '[\"*\"]', '2026-06-28 07:17:26', '2026-06-28 07:47:26', '2026-06-28 07:17:26', '2026-06-28 07:17:26'),
(253, 'App\\Models\\User', 3, 'auth-token', '7cd563f604bb83ccff5737143352b41cd4f81dfc4f0cabc840931d75cc0c0aaa', '[\"*\"]', '2026-06-28 07:23:17', '2026-06-28 07:53:17', '2026-06-28 07:23:17', '2026-06-28 07:23:17'),
(254, 'App\\Models\\User', 2, 'auth-token', '9b59229005eda78339d4b96349860446ca184b2dfc2a84caad654934408502fe', '[\"*\"]', '2026-06-28 11:23:19', '2026-06-28 11:53:19', '2026-06-28 11:23:19', '2026-06-28 11:23:19'),
(256, 'App\\Models\\User', 2, 'auth-token', 'ba69fa705b37645d490c0722b0911fa9ad0041aab4002094b0d5836fc49193a1', '[\"*\"]', '2026-06-28 12:46:17', '2026-06-28 13:16:18', '2026-06-28 12:26:38', '2026-06-28 12:46:18'),
(257, 'App\\Models\\User', 2, 'auth-token', '32bd265a39df67f220b495c025251f42adfa1424c017943aa887de8a228bc64a', '[\"*\"]', '2026-06-28 21:29:22', '2026-06-28 21:59:22', '2026-06-28 20:00:03', '2026-06-28 21:29:22'),
(258, 'App\\Models\\User', 3, 'auth-token', '90e257c22a7fc06c59145b5dd61a1d2fe06b021a3a66d1152d31c4af98494264', '[\"*\"]', '2026-06-28 21:21:09', '2026-06-28 21:51:09', '2026-06-28 21:21:09', '2026-06-28 21:21:09'),
(259, 'App\\Models\\User', 2, 'auth-token', '4c1130f4556a3bae8cd048158ac7038262ac2f8b278132a72597d55ff55755f3', '[\"*\"]', '2026-06-28 22:07:36', '2026-06-28 22:37:36', '2026-06-28 22:02:18', '2026-06-28 22:07:36'),
(260, 'App\\Models\\User', 3, 'auth-token', '07b8ebcbf8b16261d320d4d130b4220bbe197042bda0ebffef4636f383711fe2', '[\"*\"]', '2026-06-28 22:07:03', '2026-06-28 22:37:03', '2026-06-28 22:02:42', '2026-06-28 22:07:03'),
(261, 'App\\Models\\User', 2, 'auth-token', '62f48129e75744a8e87c988ffe18a2e631c158bed1b831a1465616ec480cc45e', '[\"*\"]', '2026-06-29 01:05:15', '2026-06-29 01:35:15', '2026-06-29 01:05:15', '2026-06-29 01:05:15'),
(262, 'App\\Models\\User', 2, 'auth-token', 'c281cab77b642f6c355a3aa5461f528850070b3be8c7f8880a336c3301cc14f4', '[\"*\"]', '2026-06-29 02:38:23', '2026-06-29 03:08:23', '2026-06-29 01:15:48', '2026-06-29 02:38:23');
INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(263, 'App\\Models\\User', 2, 'auth-token', 'bccb42c6a6172b7ee69c66cb95c06759d228b94d26b3fe911e3b3ccb219d1512', '[\"*\"]', '2026-06-29 05:31:18', '2026-06-29 06:01:18', '2026-06-29 03:45:21', '2026-06-29 05:31:18'),
(264, 'App\\Models\\User', 2, 'auth-token', '72116bd3f3e1c60797ae3a1dc6f50407616b0053df165464840115e9e90a5ca9', '[\"*\"]', '2026-06-29 09:28:53', '2026-06-29 09:58:53', '2026-06-29 08:44:49', '2026-06-29 09:28:53'),
(265, 'App\\Models\\User', 3, 'auth-token', 'e98a32fdfe4d88271470a5411db10dff082f78f0f5243cee5680db6b2cc60d2e', '[\"*\"]', '2026-06-29 10:08:45', '2026-06-29 10:38:44', '2026-06-29 10:08:43', '2026-06-29 10:08:45'),
(266, 'App\\Models\\User', 4, 'auth-token', 'a36de66fbe1da4a9853cd6740ed2f7d2cd44539afc0d93c497fbb2dfb783cd22', '[\"*\"]', '2026-06-29 10:08:45', '2026-06-29 10:38:40', '2026-06-29 10:08:40', '2026-06-29 10:08:45'),
(267, 'App\\Models\\User', 2, 'auth-token', 'b8ddc958d9f41623d16d75c43c4550b41153f64694de85e1ab8d7f2efa7b753c', '[\"*\"]', '2026-06-29 12:51:33', '2026-06-29 13:21:33', '2026-06-29 10:14:28', '2026-06-29 12:51:33'),
(268, 'App\\Models\\User', 2, 'auth-token', 'e1f6580113c080e1b25f322747ecef56bbe04ef1a83ef9d968f0f5e1e8981ece', '[\"*\"]', '2026-06-29 13:08:20', '2026-06-29 13:38:20', '2026-06-29 13:05:09', '2026-06-29 13:08:20'),
(269, 'App\\Models\\User', 2, 'auth-token', '93a2876e4c5d52e00044f232905a71c95ae15a397396ce8cfc649bdb369e77e5', '[\"*\"]', '2026-06-30 05:24:03', '2026-06-30 05:54:03', '2026-06-30 05:24:03', '2026-06-30 05:24:03'),
(270, 'App\\Models\\User', 2, 'auth-token', '174eb18f0e6a4c880940286882c0371eeb4015a9fcd14505d9871e3b496142dc', '[\"*\"]', '2026-06-30 06:36:41', '2026-06-30 07:06:41', '2026-06-30 06:32:57', '2026-06-30 06:36:41'),
(271, 'App\\Models\\User', 2, 'auth-token', 'c1b3510bde001bb0c1aae5f673ea6b9b9f29e8d7f92000a7ca8e03e4b3d99ac1', '[\"*\"]', '2026-06-30 07:30:23', '2026-06-30 08:00:23', '2026-06-30 07:11:40', '2026-06-30 07:30:23'),
(272, 'App\\Models\\User', 2, 'auth-token', '9943858124b6807745bdb24bfb172c52aaa0d75186737e591b821eb2a7e0d046', '[\"*\"]', '2026-07-05 04:19:06', '2026-07-05 04:49:06', '2026-07-05 04:09:58', '2026-07-05 04:19:06'),
(273, 'App\\Models\\User', 2, 'perftest', '214912e2f720e254f7b5672bc0ba30c9aab932126e573636ddb53345a8de3b8d', '[\"*\"]', '2026-07-05 04:40:19', '2026-07-05 05:10:20', '2026-07-05 04:40:19', '2026-07-05 04:40:20'),
(274, 'App\\Models\\User', 2, 'auth-token', 'a632a8b0c35d929fbd4270f8ae760a95508512228822707539e1e84a9b415129', '[\"*\"]', '2026-07-05 05:14:42', '2026-07-05 05:44:42', '2026-07-05 04:52:05', '2026-07-05 05:14:42'),
(275, 'App\\Models\\User', 2, 't', 'a6ae5372391e466904e3838bf63f06176c99c8218518bd54f78ac9358a1c2828', '[\"*\"]', '2026-07-05 05:31:58', '2026-07-05 06:01:58', '2026-07-05 05:31:58', '2026-07-05 05:31:58'),
(276, 'App\\Models\\User', 2, 'auth-token', '7eab02808c43a07c286a8a07ff5debaf97962ec3b887860cf4b6bee3ece7e440', '[\"*\"]', '2026-07-05 06:05:09', '2026-07-05 06:35:09', '2026-07-05 06:01:02', '2026-07-05 06:05:09'),
(277, 'App\\Models\\User', 2, 't', '900e81bff2095dbfdee5b3b10dbe30864c44cc246981aa09f57efc106419e542', '[\"*\"]', NULL, NULL, '2026-07-05 06:22:16', '2026-07-05 06:22:16'),
(278, 'App\\Models\\User', 2, 't', '28e31f80b6981812ad04462d5f38118de50ec928cea5251d5bcb3a5d81e0ad8d', '[\"*\"]', '2026-07-05 06:24:39', '2026-07-05 06:54:39', '2026-07-05 06:24:39', '2026-07-05 06:24:39'),
(279, 'App\\Models\\User', 2, 'auth-token', 'b0facd3809a2fd6b49ab00cf89ce91bb6f65b7476e82c1fe51ab8b327e85515a', '[\"*\"]', '2026-07-05 08:10:04', '2026-07-05 08:36:05', '2026-07-05 07:56:52', '2026-07-05 08:10:04'),
(280, 'App\\Models\\User', 2, 't', '238c29ce569e05ba91f4c555abb74cd42bbea29f8042f0784afb9a5b00227045', '[\"*\"]', '2026-07-05 07:58:22', '2026-07-05 08:28:22', '2026-07-05 07:58:22', '2026-07-05 07:58:22'),
(281, 'App\\Models\\User', 2, 'auth-token', '142533e82d55afe1d60b885f826605db23051a35d05be900d19befde8ffe1153', '[\"*\"]', '2026-07-05 08:10:27', '2026-07-05 08:40:27', '2026-07-05 08:06:43', '2026-07-05 08:10:27'),
(282, 'App\\Models\\User', 2, 'auth-token', '5559595eeb32d94fe8f131588c652ac1e00f4ae6293f69a575ed85f84bbc66b3', '[\"*\"]', '2026-07-06 23:01:29', '2026-07-06 23:31:29', '2026-07-06 21:36:10', '2026-07-06 23:01:29'),
(283, 'App\\Models\\User', 2, 'auth-token', '62030828c4f3bb672621d5957db471388cdfc8f6bf293719cf50c19afe41dc3e', '[\"*\"]', '2026-07-07 03:41:52', '2026-07-07 04:11:52', '2026-07-07 03:33:37', '2026-07-07 03:41:52'),
(284, 'App\\Models\\User', 2, 'auth-token', '3f01a31271ae7f992d4914b28071da16fd28fd853d3bf946bc14115211edc8b2', '[\"*\"]', '2026-07-07 10:08:45', '2026-07-07 10:38:45', '2026-07-07 09:34:57', '2026-07-07 10:08:45'),
(285, 'App\\Models\\User', 2, 'auth-token', '426fd7c2cb0f62918cb9dfe02114c0fe6c9c27f6e955ff7f5d8e09ec256632ae', '[\"*\"]', '2026-07-08 02:15:12', '2026-07-08 02:45:12', '2026-07-08 02:11:52', '2026-07-08 02:15:12'),
(286, 'App\\Models\\User', 2, 'auth-token', '76d10dbcce413b140d85f6969fe093126586abc135f601ac33a6ef5232075d89', '[\"*\"]', '2026-07-08 18:25:03', '2026-07-08 18:55:03', '2026-07-08 18:03:37', '2026-07-08 18:25:03'),
(287, 'App\\Models\\User', 3, 'auth-token', '9539d1df10dd663c2bdaa036934327fbe87be0fbdc572aa91c1d70753ab9290c', '[\"*\"]', '2026-07-08 18:54:50', '2026-07-08 19:24:50', '2026-07-08 18:54:50', '2026-07-08 18:54:50'),
(288, 'App\\Models\\User', 3, 'auth-token', '26fa0b45a3eff6db86289da8fff22af99e5adcf9900b8f9596ef0c121b676732', '[\"*\"]', '2026-07-08 19:14:03', '2026-07-08 19:44:03', '2026-07-08 19:06:53', '2026-07-08 19:14:03'),
(289, 'App\\Models\\User', 2, 'auth-token', 'b03694752472ed8096f5130cbed2219624edc4af4fe79b40326868407392cba3', '[\"*\"]', '2026-07-08 19:13:31', '2026-07-08 19:43:31', '2026-07-08 19:07:29', '2026-07-08 19:13:31'),
(290, 'App\\Models\\User', 2, 'auth-token', '000483771ae1afd22d0c55f7cbecad5791b7125f49a6103cdac22fdd012eeae4', '[\"*\"]', '2026-07-08 22:41:43', '2026-07-08 23:11:43', '2026-07-08 22:04:01', '2026-07-08 22:41:43'),
(291, 'App\\Models\\User', 2, 'auth-token', 'c3f94aedb7547b7e45ff1e3168f750e433830bc3dd14e0583df3236f481cc0b0', '[\"*\"]', '2026-07-08 23:09:25', '2026-07-08 23:39:25', '2026-07-08 23:01:44', '2026-07-08 23:09:25'),
(292, 'App\\Models\\User', 3, 'auth-token', '6e9be1bbf10d7a6c886e4952e9ae4f44665c52cc159e28e1cbe0e77aa91f2cf6', '[\"*\"]', '2026-07-08 23:09:15', '2026-07-08 23:39:15', '2026-07-08 23:04:31', '2026-07-08 23:09:15'),
(293, 'App\\Models\\User', 2, 'auth-token', '8590bb2d30de1fb175c86d4cd7bec16f12ed6a493edbaa27ab7995ae56d35215', '[\"*\"]', '2026-07-09 03:31:47', '2026-07-09 04:01:47', '2026-07-09 03:15:45', '2026-07-09 03:31:47'),
(294, 'App\\Models\\User', 2, 'auth-token', 'f190425b532ae64fb42a144f921de0973c7d851eadb0d16f7d33d957a984c50a', '[\"*\"]', '2026-07-09 12:08:38', '2026-07-09 12:38:38', '2026-07-09 10:14:04', '2026-07-09 12:08:38'),
(295, 'App\\Models\\User', 7, 'auth-token', '88a60f0d64db8a5139c71f335a8a0184d8a7e547bf3809a9d7f8209a31e1a77e', '[\"*\"]', '2026-07-09 10:19:31', '2026-07-09 10:49:31', '2026-07-09 10:17:12', '2026-07-09 10:19:31'),
(296, 'App\\Models\\User', 7, 'auth-token', '0189b21a055cb42c15440439e2e4b6209a221bf817b5ad7e3a7d5bbf73fa23ae', '[\"*\"]', '2026-07-09 12:09:46', '2026-07-09 12:39:46', '2026-07-09 10:19:47', '2026-07-09 12:09:46'),
(297, 'App\\Models\\User', 3, 'auth-token', 'd2fa75da3257bb11f84de4ad45991391620b43c57b40477a93a356f3e9f9cb59', '[\"*\"]', '2026-07-09 11:47:50', '2026-07-09 12:17:51', '2026-07-09 11:21:52', '2026-07-09 11:47:51'),
(298, 'App\\Models\\User', 2, 'auth-token', '4928c9ca0665942f7839b9c8771cc7b112221dd12964941f2b9c48f1198436a1', '[\"*\"]', '2026-07-09 23:01:53', '2026-07-09 23:31:53', '2026-07-09 23:01:53', '2026-07-09 23:01:53'),
(299, 'App\\Models\\User', 2, 'auth-token', '704702e6f3b4cb182f666d3f541a18da3542cadb67cbb03441da424a340ab696', '[\"*\"]', '2026-07-11 03:12:58', '2026-07-11 03:42:58', '2026-07-11 03:08:18', '2026-07-11 03:12:58'),
(300, 'App\\Models\\User', 2, 'auth-token', '4f6dfd1013df6b2ba12d266e939bcf9a147392303a2b99ea592d7e1d5f78005f', '[\"*\"]', '2026-07-11 05:14:06', '2026-07-11 05:44:06', '2026-07-11 05:14:06', '2026-07-11 05:14:06'),
(301, 'App\\Models\\User', 2, 'auth-token', '3637770de20803ed7a8e42d8921be9b564a08339544aaeb3db9bebdae5aba866', '[\"*\"]', '2026-07-11 06:03:31', '2026-07-11 06:33:31', '2026-07-11 05:18:27', '2026-07-11 06:03:31'),
(302, 'App\\Models\\User', 2, 'auth-token', '1a25901dcf164c2e7490f090838f8076d6fc4577f62479c4445fe4550871b751', '[\"*\"]', '2026-07-11 06:41:01', '2026-07-11 07:11:01', '2026-07-11 06:28:17', '2026-07-11 06:41:01'),
(303, 'App\\Models\\User', 7, 'auth-token', '0063df4e6d16ab29f3d7bf13ea67d48a92b859fa3086ab93dbc8da23f6b272e6', '[\"*\"]', '2026-07-11 06:41:31', '2026-07-11 07:11:32', '2026-07-11 06:33:17', '2026-07-11 06:41:32'),
(304, 'App\\Models\\User', 2, 'auth-token', '3d1de24dfc7811655b8293b09a8db761f93b17868b34756975e0752f953158b5', '[\"*\"]', '2026-07-12 05:23:00', '2026-07-12 05:53:00', '2026-07-12 03:33:23', '2026-07-12 05:23:00'),
(305, 'App\\Models\\User', 2, 'auth-token', 'b8e396f7c884bdd49519bf193944ff4a3be1d0ab71ce2cbea667dd1e2e53db90', '[\"*\"]', '2026-07-12 08:36:27', '2026-07-12 09:06:26', '2026-07-12 08:25:23', '2026-07-12 08:36:27'),
(306, 'App\\Models\\User', 2, 'auth-token', '13238a835f913a420fd5256b491a973e71c120499477b6c509e5187d93e8298a', '[\"*\"]', '2026-07-12 10:29:05', '2026-07-12 10:59:05', '2026-07-12 09:28:35', '2026-07-12 10:29:05'),
(307, 'App\\Models\\User', 7, 'auth-token', '928c4b3489c4f503b1edca91ea8955dfed8201f7689b35563bdceae432a5cc5b', '[\"*\"]', '2026-07-12 10:28:52', '2026-07-12 10:58:52', '2026-07-12 09:38:55', '2026-07-12 10:28:52'),
(310, 'App\\Models\\User', 2, 'auth-token', '1ae02d2acd278d5d6b12c7d23046f4db53470098124e1c591afabc8f6cedf5d4', '[\"*\"]', '2026-07-12 17:35:53', '2026-07-12 18:05:53', '2026-07-12 16:37:41', '2026-07-12 17:35:53'),
(311, 'App\\Models\\User', 7, 'auth-token', '52a79f157ed31d0928e1c562757bc89fed65b0dc435746f160a2581d082fef26', '[\"*\"]', '2026-07-12 16:43:54', '2026-07-12 17:13:54', '2026-07-12 16:43:54', '2026-07-12 16:43:54'),
(312, 'App\\Models\\User', 7, 'auth-token', 'a70b691c4574a317160783e598f685fd16371435c9566c0c2b20256fa776a5aa', '[\"*\"]', '2026-07-12 16:44:05', '2026-07-12 17:14:05', '2026-07-12 16:44:05', '2026-07-12 16:44:05'),
(313, 'App\\Models\\User', 3, 'auth-token', 'a1a7434d96e8b370f43446fef40814b4f0f91ba1961e0de69b8453ff6a1215aa', '[\"*\"]', '2026-07-12 17:23:33', '2026-07-12 17:53:33', '2026-07-12 17:23:33', '2026-07-12 17:23:33'),
(315, 'App\\Models\\User', 6, 'auth-token', '73a6a5367bdcfd8df156c63d1593e0dd4b5ea543f91290fed8ef9b447de007ba', '[\"*\"]', '2026-07-12 17:25:35', '2026-07-12 17:55:35', '2026-07-12 17:25:35', '2026-07-12 17:25:35'),
(316, 'App\\Models\\User', 2, 'auth-token', 'fc44a84ecb2faf3d71cb09d2c1e9efb59fce4c6601795c69879363431caa2cd8', '[\"*\"]', '2026-07-12 18:59:51', '2026-07-12 19:29:50', '2026-07-12 18:30:51', '2026-07-12 18:59:51'),
(317, 'App\\Models\\User', 3, 'auth-token', '6baf283accb99638c21008e49b0c6034765341c1f857cdf0ee7ad0e273b24da5', '[\"*\"]', '2026-07-12 18:59:50', '2026-07-12 19:29:50', '2026-07-12 18:34:59', '2026-07-12 18:59:50'),
(318, 'App\\Models\\User', 4, 'auth-token', '5e107adf51d9e573a82f9587a5362401bb1a2565fed3f5999642c28e70ad59fb', '[\"*\"]', '2026-07-13 11:22:24', '2026-07-13 11:52:24', '2026-07-13 11:22:24', '2026-07-13 11:22:24'),
(319, 'App\\Models\\User', 3, 'auth-token', '13c6692bd66001afd5f5de344d5fdc004de9a5b6d43f6374053a86638597622d', '[\"*\"]', '2026-07-13 11:19:42', '2026-07-13 11:49:42', '2026-07-13 11:19:42', '2026-07-13 11:19:42'),
(321, 'App\\Models\\User', 6, 'auth-token', '439d06ec99ced9cf247c8f23a436bb3a46ad50905302375a6861e745026d1e46', '[\"*\"]', '2026-07-13 11:19:26', '2026-07-13 11:49:26', '2026-07-13 11:19:26', '2026-07-13 11:19:26'),
(322, 'App\\Models\\User', 2, 'auth-token', 'f4a535f8560e68f4269bdfdb52d9ab9e12b9616a18ef501a2fd8a50b6dc5740a', '[\"*\"]', '2026-07-13 12:15:13', '2026-07-13 12:45:13', '2026-07-13 12:15:13', '2026-07-13 12:15:13'),
(323, 'App\\Models\\User', 3, 'auth-token', 'cd6d1752ef9f60f8cbacfc44dba23c24798ce5d441ea28bc111f79d42136ef19', '[\"*\"]', '2026-07-13 11:54:43', '2026-07-13 12:24:43', '2026-07-13 11:26:17', '2026-07-13 11:54:43'),
(324, 'App\\Models\\User', 6, 'auth-token', 'b2b5cbeae411c566d0cde50cfd5e804c904ec8b79cbf4a4e695e4378ef516980', '[\"*\"]', '2026-07-13 11:50:58', '2026-07-13 12:20:58', '2026-07-13 11:26:40', '2026-07-13 11:50:58'),
(325, 'App\\Models\\User', 4, 'auth-token', '03ace1a699f89f26d3129d4e43917eeb446def35e84e6cce58d3024d698942af', '[\"*\"]', '2026-07-13 12:21:10', '2026-07-13 12:51:10', '2026-07-13 12:21:10', '2026-07-13 12:21:10'),
(326, 'App\\Models\\User', 2, 'auth-token', '36502f5fe6de4ee2ef29e2fe28abc909e09fc38dbf64bfc5017d785a7bd3d019', '[\"*\"]', '2026-07-13 13:21:43', '2026-07-13 13:51:43', '2026-07-13 12:15:18', '2026-07-13 13:21:43'),
(327, 'App\\Models\\User', 4, 'auth-token', '4511f188d95002deb69253e2e665f2b5303b873c1804f702df24da576aed97a0', '[\"*\"]', '2026-07-13 13:23:43', '2026-07-13 13:53:43', '2026-07-13 13:23:43', '2026-07-13 13:23:43'),
(328, 'App\\Models\\User', 6, 'auth-token', '05fd6e25e693c51334578ba0b23cb3a787e234e3a59ad1ae924305a942b1613f', '[\"*\"]', '2026-07-13 12:55:21', '2026-07-13 13:25:21', '2026-07-13 12:55:21', '2026-07-13 12:55:21'),
(329, 'App\\Models\\User', 3, 'auth-token', '5c532b98e953e17a8456825c8ece4045710f5d71419497cb339b86aa87f269e3', '[\"*\"]', '2026-07-13 13:23:33', '2026-07-13 13:53:33', '2026-07-13 13:23:33', '2026-07-13 13:23:33'),
(330, 'App\\Models\\User', 7, 'auth-token', '5c8119978ab1ead68c8a29bbe5ce616b488e5414a3f53c07e9370a15eeae78bb', '[\"*\"]', '2026-07-13 13:21:52', '2026-07-13 13:51:52', '2026-07-13 13:21:52', '2026-07-13 13:21:52'),
(332, 'App\\Models\\User', 2, 'auth-token', '4286e625d1dc7bc4b35765f6e83df0f63f719a2ff5fbc105e18ad88ae2cd1105', '[\"*\"]', '2026-07-13 16:35:12', '2026-07-13 17:05:12', '2026-07-13 16:32:29', '2026-07-13 16:35:12'),
(333, 'App\\Models\\User', 6, 'auth-token', '6488386c74633fcc9f47c15f7cf19e550e1dbf0511f79155a7da23788936b499', '[\"*\"]', '2026-07-13 16:36:40', '2026-07-13 17:06:40', '2026-07-13 16:34:03', '2026-07-13 16:36:40'),
(334, 'App\\Models\\User', 2, 'auth-token', 'b85b6f594226a89a53e8e8a2bbcfb9c534e664d11479db42d95c054abb8ff3a0', '[\"*\"]', '2026-07-13 21:16:16', '2026-07-13 21:46:16', '2026-07-13 21:02:17', '2026-07-13 21:16:16'),
(336, 'App\\Models\\User', 2, 'auth-token', 'e85b9790b36b46bca3bd0ed65e04f6bac279f3356aee5376f25065cc72358cfe', '[\"*\"]', '2026-07-15 19:55:04', '2026-07-15 20:25:04', '2026-07-15 19:55:04', '2026-07-15 19:55:04'),
(337, 'App\\Models\\User', 7, 'auth-token', '0f3be52f1d140c611ad88163db6dbbaeb24001f22f99b002fe558ea2d2873082', '[\"*\"]', '2026-07-15 19:48:12', '2026-07-15 20:18:12', '2026-07-15 19:48:12', '2026-07-15 19:48:12'),
(338, 'App\\Models\\User', 2, 'auth-token', '1593bf7e5f32d486a259e7e3ed1b326d5d261def390b0054c8d3a26098047872', '[\"*\"]', '2026-07-15 20:33:00', '2026-07-15 21:03:00', '2026-07-15 20:31:56', '2026-07-15 20:33:00'),
(339, 'App\\Models\\User', 2, 'auth-token', '43b8e746c05934091d43dc1b46c77e6d0dbd3e4cba3ee0e3ad94fdbd425ee46e', '[\"*\"]', '2026-07-15 21:14:16', '2026-07-15 21:44:16', '2026-07-15 20:44:41', '2026-07-15 21:14:16'),
(340, 'App\\Models\\User', 7, 'auth-token', 'af9be0521e1c62924d34d3b6274a3f276450dba007cb0273d4af06b687ed4392', '[\"*\"]', '2026-07-15 22:17:38', '2026-07-15 22:47:38', '2026-07-15 22:17:38', '2026-07-15 22:17:38'),
(341, 'App\\Models\\User', 2, 'auth-token', 'dbc35eaa25d690be33e2f7b5610772ea38f0c553d7a9f88e74268d7ea9f89d00', '[\"*\"]', '2026-07-15 21:43:43', '2026-07-15 22:13:43', '2026-07-15 21:43:43', '2026-07-15 21:43:43'),
(342, 'App\\Models\\User', 2, 'auth-token', '31c3e1e61314d42c48899edf4ddb30a7f6e313e29c99c403dfff532f7e18f47e', '[\"*\"]', '2026-07-15 21:47:38', '2026-07-15 22:17:38', '2026-07-15 21:43:56', '2026-07-15 21:47:38'),
(343, 'App\\Models\\User', 2, 'auth-token', '32bb64153a9719cbbdd4294a8df2e5dff74e28dfa775450a4db26c8f151e4288', '[\"*\"]', '2026-07-15 23:10:58', '2026-07-15 23:40:58', '2026-07-15 21:45:53', '2026-07-15 23:10:58'),
(344, 'App\\Models\\User', 2, 'auth-token', 'b85486368e2bceefb37c1fda8056354d5033949fa468e0f1e8ee2f05e2606420', '[\"*\"]', '2026-07-15 23:08:27', '2026-07-15 23:38:27', '2026-07-15 23:08:27', '2026-07-15 23:08:27'),
(345, 'App\\Models\\User', 7, 'auth-token', 'c4b2d8ddb60ae264d8f2b0941a76b6543d72efc137297cab8ba132ece3c1f0f1', '[\"*\"]', '2026-07-15 23:10:42', '2026-07-15 23:40:42', '2026-07-15 22:50:54', '2026-07-15 23:10:42'),
(346, 'App\\Models\\User', 6, 'auth-token', 'e66af73adae94f19ae5e8ed2dfc12b4149965c62dc0491df4a4714096ce8188c', '[\"*\"]', '2026-07-15 23:11:20', '2026-07-15 23:40:23', '2026-07-15 23:09:04', '2026-07-15 23:11:20'),
(347, 'App\\Models\\User', 2, 'auth-token', 'a631b26993e61a1f0f70f298bb6def198b4df3236313094cd4ce29dc85de8245', '[\"*\"]', '2026-07-19 02:38:43', '2026-07-19 03:08:43', '2026-07-19 02:36:20', '2026-07-19 02:38:43'),
(348, 'App\\Models\\User', 2, 'auth-token', 'f37fc460f33ade1bca2bca6e8f6ac799689b009771e6ff61d1f32769a76517f0', '[\"*\"]', '2026-07-19 06:49:30', '2026-07-19 07:19:30', '2026-07-19 06:49:29', '2026-07-19 06:49:30'),
(349, 'App\\Models\\User', 2, 'auth-token', '863b0a5c8180657335acc6acc2d91794af9959e5775de64f513c2ae28a749ea1', '[\"*\"]', '2026-07-19 08:34:08', '2026-07-19 09:04:09', '2026-07-19 06:49:34', '2026-07-19 08:34:09'),
(351, 'App\\Models\\User', 2, 'dbg', 'b36a2c7659df84389cefed1aaddec655f7354f4a7762ae592e8dc42230eb8249', '[\"*\"]', NULL, NULL, '2026-07-19 10:43:19', '2026-07-19 10:43:19'),
(352, 'App\\Models\\User', 2, 'auth-token', 'f729c0f4c6e93ce860d23840dc5ea0dd833a6e518ff6745e64622a7e12369e81', '[\"*\"]', '2026-07-19 12:21:54', '2026-07-19 12:51:54', '2026-07-19 10:49:06', '2026-07-19 12:21:54'),
(353, 'App\\Models\\User', 7, 'auth-token', 'b1fa2825a6cad4cb96f08192ba05641f23e55c473c66a0d16eb7ee19a5648bb6', '[\"*\"]', '2026-07-19 12:21:14', '2026-07-19 12:51:14', '2026-07-19 12:03:37', '2026-07-19 12:21:14'),
(354, 'App\\Models\\User', 2, 'auth-token', 'cfb6ac1c3b8dafef1e8500edb219f3db1e930658780a406a3adfc9e4d950d41d', '[\"*\"]', '2026-07-19 14:41:07', '2026-07-19 15:11:07', '2026-07-19 14:21:13', '2026-07-19 14:41:07'),
(355, 'App\\Models\\User', 2, 'auth-token', '619ad3880e3639b37a126bf9ffde68110351c16ac3342a591829830b6ad7621c', '[\"*\"]', '2026-07-19 15:31:59', '2026-07-19 16:01:59', '2026-07-19 14:56:32', '2026-07-19 15:31:59'),
(357, 'App\\Models\\User', 7, 'auth-token', 'f9fd79a9978768724973058a4298057cb240cf7f832d964bede5794e141396a4', '[\"*\"]', '2026-07-19 16:33:29', '2026-07-19 17:03:29', '2026-07-19 16:33:29', '2026-07-19 16:33:29'),
(358, 'App\\Models\\User', 2, 'auth-token', '979da8202419a760cb8185c179f736153a1bafa07d9264c7263f1c9dd92e4f7d', '[\"*\"]', '2026-07-19 17:50:07', '2026-07-19 18:20:07', '2026-07-19 17:45:59', '2026-07-19 17:50:07'),
(359, 'App\\Models\\User', 2, 'auth-token', '93dc552b16682b0c2a9bae0c3b9ad0a330f03ea3d36a02003441b467d763b21a', '[\"*\"]', '2026-07-19 20:07:35', '2026-07-19 20:37:35', '2026-07-19 18:35:37', '2026-07-19 20:07:35'),
(361, 'App\\Models\\User', 2, 'auth-token', '26424e24be081c8bafbe186410b1bb552b593a14cb2266c491d3bb1dbfd7e5e8', '[\"*\"]', '2026-07-19 20:16:09', '2026-07-19 20:46:09', '2026-07-19 20:12:08', '2026-07-19 20:16:09'),
(362, 'App\\Models\\User', 2, 'auth-token', 'dd47e5951beabfcdcc3ef773548ba4c7105d3acf2609bf355f18b6069f6372ba', '[\"*\"]', '2026-07-20 00:00:34', '2026-07-20 00:30:34', '2026-07-19 23:46:44', '2026-07-20 00:00:34'),
(364, 'App\\Models\\User', 2, 'auth-token', 'a42855997cc3c708e565dbb8418b5cced30c539b7780281dfde09c46be2987bf', '[\"*\"]', '2026-07-20 01:00:53', '2026-07-20 01:30:54', '2026-07-20 00:04:07', '2026-07-20 01:00:54'),
(365, 'App\\Models\\User', 6, 'auth-token', '45c1eecd356dd9acfa0ef9735c6581f3ebf94e0b8cd989a20fa7de0bfc43baa7', '[\"*\"]', '2026-07-20 00:48:27', '2026-07-20 01:18:27', '2026-07-20 00:04:54', '2026-07-20 00:48:27'),
(366, 'App\\Models\\User', 2, 'auth-token', 'f340382332361e834f7ebeae0e3decbbfaa9fd4d63d24a9bcf0a38dce10d750a', '[\"*\"]', '2026-07-20 07:55:07', '2026-07-20 08:25:07', '2026-07-20 07:52:06', '2026-07-20 07:55:07'),
(367, 'App\\Models\\User', 2, 'auth-token', '276c8ba02b41eea498df61abd2a52ef105e7d8aef2a503513d61fa6d27747ac7', '[\"*\"]', '2026-07-20 10:57:05', '2026-07-20 11:27:05', '2026-07-20 10:21:29', '2026-07-20 10:57:05'),
(368, 'App\\Models\\User', 2, 'auth-token', '829123d5db03147c484c1274fd290ae8e8fbb5ea6fd2a7773781a6c3141b5b04', '[\"*\"]', '2026-07-20 19:26:46', '2026-07-20 19:27:34', '2026-07-20 18:42:08', '2026-07-20 19:26:46'),
(370, 'App\\Models\\User', 7, 'auth-token', 'cc353ec5d571556f0b5980dc94dcf3d459a7b16727d49d07c3b69d94b5bb80a1', '[\"*\"]', '2026-07-20 19:44:47', '2026-07-20 20:14:47', '2026-07-20 18:53:10', '2026-07-20 19:44:47'),
(371, 'App\\Models\\User', 2, 'auth-token', 'fcdffa316626c511d20d6218f541b79eb087f8e756306755f43f3c087004ca5b', '[\"*\"]', '2026-07-20 19:26:54', '2026-07-20 19:56:54', '2026-07-20 18:57:40', '2026-07-20 19:26:54'),
(372, 'App\\Models\\User', 2, 'auth-token', '1c34bfc3667adac07fa0da92158fe1449a0bf07264eae4992d6abdd4506b29a7', '[\"*\"]', '2026-07-20 21:21:46', '2026-07-20 21:51:46', '2026-07-20 19:26:58', '2026-07-20 21:21:46'),
(373, 'App\\Models\\User', 6, 'auth-token', '17a994928ca2c69282653791dbea903225e330f0ad5937f917721a1a0134874b', '[\"*\"]', '2026-07-20 21:10:49', '2026-07-20 21:40:49', '2026-07-20 21:10:49', '2026-07-20 21:10:49'),
(374, 'App\\Models\\User', 2, 'auth-token', '958dfa2ea4ec084a973173a2e4783baadfd08d9f852bb635f558308c71f43cc1', '[\"*\"]', '2026-07-20 23:38:33', '2026-07-21 00:08:33', '2026-07-20 23:38:33', '2026-07-20 23:38:33'),
(375, 'App\\Models\\User', 2, 'auth-token', '624597706be75c58d91badddc372fe346d491b35671d15fb21ca3b5bcceb84d3', '[\"*\"]', '2026-07-21 01:45:23', '2026-07-21 02:15:23', '2026-07-20 23:38:37', '2026-07-21 01:45:23'),
(376, 'App\\Models\\User', 2, 'auth-token', '207159f82b74572da520d78d0e59f66c88510306e0a824d7e0ae4af0e3640f3a', '[\"*\"]', '2026-07-21 05:59:53', '2026-07-21 06:29:53', '2026-07-21 03:41:23', '2026-07-21 05:59:53'),
(378, 'App\\Models\\User', 7, 'auth-token', '2bd38d4dc5927b4fce6b93d7748272d3f788ffc004db6e1866ff060b49910e27', '[\"*\"]', '2026-07-21 06:04:23', '2026-07-21 06:34:23', '2026-07-21 06:04:23', '2026-07-21 06:04:23'),
(379, 'App\\Models\\User', 2, 'auth-token', 'dd705ea441f08161074e4f766974db60fd4c146f5b21dc0785aa7c1a111c0ec9', '[\"*\"]', '2026-07-21 06:58:04', '2026-07-21 07:28:04', '2026-07-21 06:54:50', '2026-07-21 06:58:04'),
(381, 'App\\Models\\User', 2, 'auth-token', 'a324bace56ac04ab4403deaf6249f05aa5dbc0790377edc89f00e4e330ec65cf', '[\"*\"]', '2026-07-21 09:21:46', '2026-07-21 09:51:46', '2026-07-21 09:03:48', '2026-07-21 09:21:46'),
(382, 'App\\Models\\User', 6, 'auth-token', 'c6da6cc94836fa57efbd732bdf3c4eca5b10b5b67b7bfbf320b1c8d5eaf4f040', '[\"*\"]', '2026-07-21 09:37:39', '2026-07-21 10:07:39', '2026-07-21 09:37:39', '2026-07-21 09:37:39'),
(383, 'App\\Models\\User', 2, 'auth-token', '9a65968a12a12e171fe2acac2ff31da410deb0dfdecbb68fcd405a4f0cd58b81', '[\"*\"]', '2026-07-21 21:39:48', '2026-07-21 22:09:47', '2026-07-21 19:49:13', '2026-07-21 21:39:48'),
(385, 'App\\Models\\User', 3, 'auth-token', '5fa102643786da3e04864072df995ca20dcb03d23a2cee36a735e1633d1f86fd', '[\"*\"]', '2026-07-21 21:16:28', '2026-07-21 21:46:28', '2026-07-21 21:16:28', '2026-07-21 21:16:28'),
(389, 'App\\Models\\User', 10, 'diag', 'e4baf81037f5aa8b1abb8b14307cf082fbbe3be6ad75db9f3fa91b693d73d2b7', '[\"*\"]', NULL, NULL, '2026-07-21 21:27:25', '2026-07-21 21:27:25'),
(390, 'App\\Models\\User', 6, 'auth-token', '828cb089278dbe6f0e80825849eba6391e6ddba62b553dc5fe5a7997468388be', '[\"*\"]', '2026-07-21 21:43:21', '2026-07-21 22:13:21', '2026-07-21 21:43:21', '2026-07-21 21:43:21'),
(391, 'App\\Models\\User', 2, 'auth-token', '35a57d884780d80277acda8a8ce9842dd59b9969de4fd4c6dac57a4839330215', '[\"*\"]', '2026-07-21 22:37:02', '2026-07-21 23:07:02', '2026-07-21 21:40:41', '2026-07-21 22:37:02'),
(392, 'App\\Models\\User', 6, 'auth-token', '6d0bbce7dadaa8b5ceee67e46bb9ac08d73cf7148ed8ab8e3f368a9262b72f12', '[\"*\"]', '2026-07-21 21:59:59', '2026-07-21 22:29:59', '2026-07-21 21:59:59', '2026-07-21 21:59:59'),
(393, 'App\\Models\\User', 10, 'auth-token', 'ffce9c6bdd199e606ec65e0ad5955cca790be4e491afb4e1c3545122baeca7c7', '[\"*\"]', '2026-07-21 22:08:34', '2026-07-21 22:38:34', '2026-07-21 22:08:34', '2026-07-21 22:08:34'),
(394, 'App\\Models\\User', 6, 'auth-token', '84e34acbf82ee36bf70d0f15f2798fefb82a9ffc1568922a06491893837bd054', '[\"*\"]', '2026-07-21 22:49:55', '2026-07-21 23:19:55', '2026-07-21 22:49:55', '2026-07-21 22:49:55'),
(395, 'App\\Models\\User', 2, 'auth-token', '83a638fb0f2327c21cb7a73fc13c6efe597b12c2bcd3006d2593c2451fbbb571', '[\"*\"]', '2026-07-21 23:22:04', '2026-07-21 23:52:04', '2026-07-21 23:17:56', '2026-07-21 23:22:04'),
(396, 'App\\Models\\User', 2, 'auth-token', '98530822f44a0bddb1aa4fea7875bfedc58a1a47913e2cbd622c416e6a9eed50', '[\"*\"]', '2026-07-22 01:53:29', '2026-07-22 02:23:29', '2026-07-22 00:08:29', '2026-07-22 01:53:29'),
(397, 'App\\Models\\User', 6, 'auth-token', '6cbbcc73a2491ef28df2dc52df89f2ac2e027e58facec4a84ff82a13a453ab48', '[\"*\"]', '2026-07-22 00:17:48', '2026-07-22 00:47:48', '2026-07-22 00:17:48', '2026-07-22 00:17:48'),
(398, 'App\\Models\\User', 6, 'auth-token', '11de02c74f329e93e4062879f2b7c1433bc63bcfae475cc8281851c24c01ec1f', '[\"*\"]', '2026-07-22 02:02:28', '2026-07-22 02:32:28', '2026-07-22 00:17:38', '2026-07-22 02:02:28'),
(399, 'App\\Models\\User', 10, 'auth-token', 'daeb61c34a5ccd72e730bdfd71e0eb51720dea6777eabc90f88e2b7108da6a42', '[\"*\"]', '2026-07-22 01:51:46', '2026-07-22 02:21:46', '2026-07-22 00:18:06', '2026-07-22 01:51:46'),
(400, 'App\\Models\\User', 2, 'auth-token', 'a2c0f66e38cd6999061e15073ac0b778dca7c4a754671c4db8dc29c2957a2c47', '[\"*\"]', '2026-07-22 05:34:44', '2026-07-22 06:04:44', '2026-07-22 05:09:25', '2026-07-22 05:34:44'),
(401, 'App\\Models\\User', 2, 'auth-token', '94a6b6e84efe74aed78eb3c348dd7b1afd6b7a1844d6baa6de144b541f573bd2', '[\"*\"]', '2026-07-22 09:08:29', '2026-07-22 09:38:29', '2026-07-22 07:03:17', '2026-07-22 09:08:29'),
(402, 'App\\Models\\User', 2, 'auth-token', '61547a497dc927cc089a85991ae57c0d4a0e4248a1083821dc147285bc84ef3a', '[\"*\"]', '2026-07-22 10:09:11', '2026-07-22 10:39:11', '2026-07-22 10:02:55', '2026-07-22 10:09:11'),
(403, 'App\\Models\\User', 2, 'auth-token', 'a65f188c18d79859585b12da89ae261e145601e90b956f8063fc0ddccbf16185', '[\"*\"]', '2026-07-24 02:49:01', '2026-07-24 03:19:01', '2026-07-24 02:41:44', '2026-07-24 02:49:01'),
(404, 'App\\Models\\User', 2, 'auth-token', '520ff1aedc2562284ae96e3308a3e1af961ded697dbf1dfe99921673fbe93bf3', '[\"*\"]', '2026-07-24 04:48:33', '2026-07-24 05:18:33', '2026-07-24 04:14:15', '2026-07-24 04:48:33'),
(405, 'App\\Models\\User', 2, 'auth-token', '7e49f7dcca3eb25f78a8a6bef2cc99e4fea29b3787c3018e920f93de77f525d7', '[\"*\"]', '2026-07-24 19:02:51', '2026-07-24 19:32:51', '2026-07-24 18:56:49', '2026-07-24 19:02:51'),
(406, 'App\\Models\\User', 2, 'auth-token', '00b10fb0e0490d747f00d15a5157f52688f6f6de9ba3f94be7547292924d55af', '[\"*\"]', '2026-07-25 19:24:47', '2026-07-25 19:54:47', '2026-07-25 19:13:17', '2026-07-25 19:24:47'),
(407, 'App\\Models\\User', 2, 'auth-token', '14d990173ca639fb61e53c19917429b4c40a247323b15bfb533fa538fef14e14', '[\"*\"]', '2026-07-26 01:22:00', '2026-07-26 01:52:00', '2026-07-26 00:49:56', '2026-07-26 01:22:00'),
(408, 'App\\Models\\User', 2, 'auth-token', 'bda27c40ac6c6899b3c5318e44e972a65650207df36ee9a4b8adb8fa1e69a7dc', '[\"*\"]', '2026-07-26 07:05:52', '2026-07-26 07:35:52', '2026-07-26 06:46:26', '2026-07-26 07:05:52'),
(409, 'App\\Models\\User', 2, 'auth-token', '8cdafbe3f6063cd21aab0ca1a9d9e073d832ed7117e105a53ba26e48c32cfed5', '[\"*\"]', '2026-07-26 09:50:51', '2026-07-26 10:20:51', '2026-07-26 08:01:07', '2026-07-26 09:50:51'),
(410, 'App\\Models\\User', 2, 'auth-token', '06dac53b59bc7220be0312a746698a99703d2194f3d7b5e3768bb7c7ead15472', '[\"*\"]', '2026-07-26 10:46:40', '2026-07-26 11:16:40', '2026-07-26 10:24:11', '2026-07-26 10:46:40'),
(411, 'App\\Models\\User', 2, 'auth-token', '743a5a14197e7f2bd607425502d28dc00565bb5e2d42876948094db374e1d18f', '[\"*\"]', '2026-07-26 12:10:52', '2026-07-26 12:40:52', '2026-07-26 12:10:52', '2026-07-26 12:10:52'),
(412, 'App\\Models\\User', 2, 'auth-token', '3c0351fa7d4325cf5fb1da931f1a9f9b464edf6e0f0861e139678f1b33eaa46c', '[\"*\"]', '2026-07-26 12:33:41', '2026-07-26 13:03:41', '2026-07-26 12:33:41', '2026-07-26 12:33:41'),
(413, 'App\\Models\\User', 2, 'auth-token', 'a4802c9c7191782d6da6a496c0f73de094d8b02ce2b5848c108211faff2a6918', '[\"*\"]', '2026-07-26 13:08:00', '2026-07-26 13:38:00', '2026-07-26 12:52:50', '2026-07-26 13:08:00'),
(414, 'App\\Models\\User', 2, 'auth-token', '378a85012cbd83c3dd5f6a99474f2f080b1e1f354b879494ed744d0c665f4acf', '[\"*\"]', '2026-07-27 00:00:50', '2026-07-27 00:30:50', '2026-07-26 23:47:21', '2026-07-27 00:00:50'),
(415, 'App\\Models\\User', 2, 'auth-token', 'cd4e5db4ce0a914a9ee6bfec73ecf66b6a7f7f20034125b2ab470536a37f7862', '[\"*\"]', '2026-07-27 04:53:36', '2026-07-27 05:23:36', '2026-07-27 04:53:36', '2026-07-27 04:53:36'),
(416, 'App\\Models\\User', 2, 'auth-token', '6e3b4c42077dcd1297c2c709fa5dc16f1919a0ad6d5700d38ac15bb71ec2650a', '[\"*\"]', '2026-07-27 04:54:39', '2026-07-27 05:24:39', '2026-07-27 04:54:39', '2026-07-27 04:54:39');

-- --------------------------------------------------------

--
-- Table structure for table `pick_reservations`
--

CREATE TABLE `pick_reservations` (
  `reservation_id` bigint(20) UNSIGNED NOT NULL,
  `source_type` varchar(20) NOT NULL,
  `source_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `batch_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `released_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_type` enum('appliance','installation_material') NOT NULL DEFAULT 'appliance',
  `barcode` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `recommended_stocks` int(11) NOT NULL DEFAULT 0,
  `safety_stock` int(10) UNSIGNED DEFAULT NULL,
  `max_stock_level` int(10) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Shared product fields. Type-specific details in appliance_details or consumable_details.';

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `category_id`, `product_code`, `product_name`, `product_type`, `barcode`, `description`, `unit_price`, `cost_price`, `recommended_stocks`, `safety_stock`, `max_stock_level`, `status_id`, `created_at`, `updated_at`, `deleted_at`) VALUES
(7, 1, 'CSD-10DA', 'CHiQ Morandi Inverter 1HP Aircon', 'appliance', '4800000000071', 'CHiQ Morandi Inverter 1HP split-type air conditioner. 3-year warranty.', 28520.13, 20612.35, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(8, 1, 'CSD-15DA', 'CHiQ Morandi Inverter 1.5HP Aircon', 'appliance', '4800000000088', 'CHiQ Morandi Inverter 1.5HP split-type air conditioner. 3-year warranty.', 39554.05, 30474.02, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(9, 1, 'CSD-20DA', 'CHiQ Morandi Inverter 2HP Aircon', 'appliance', '4800000000095', 'CHiQ Morandi Inverter 2HP split-type air conditioner. 3-year warranty.', 53516.32, 39302.92, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(10, 1, 'CSD-25DA', 'CHiQ Morandi Inverter 2.5HP Aircon', 'appliance', '4800000000101', 'CHiQ Morandi Inverter 2.5HP split-type air conditioner. 3-year warranty.', 59478.58, 41940.73, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(11, 1, 'CSD-10DC', 'CHiQ EVA Inverter 1HP Aircon', 'appliance', '4800000000118', 'CHiQ EVA Inverter 1HP split-type air conditioner. 3-year warranty.', 27681.81, 19503.22, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(12, 1, 'CSD-15DC', 'CHiQ EVA Inverter 1.5HP Aircon', 'appliance', '4800000000125', 'CHiQ EVA Inverter 1.5HP split-type air conditioner. 3-year warranty.', 37225.71, 28622.48, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(13, 1, 'TAC-06CWM/F', 'TCL Window Non-Inverter 0.6HP Aircon', 'appliance', '4800000000132', 'TCL Window Non-Inverter 0.6HP window-type air conditioner. 3-year warranty.', 17713.15, 13379.10, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(14, 1, 'TAC-07CWI/UB', 'TCL Window Standard Inverter 0.8HP Aircon', 'appliance', '4800000000149', 'TCL Window Standard Inverter 0.8HP window-type air conditioner. 3-year warranty.', 19867.76, 13653.92, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(15, 1, 'TAC-09CWI/UB', 'TCL Window Standard Inverter 1HP Aircon', 'appliance', '4800000000156', 'TCL Window Standard Inverter 1HP window-type air conditioner. 3-year warranty.', 26787.38, 20045.80, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(16, 1, 'TAC-12CWI/UB', 'TCL Window Standard Inverter 1.5HP Aircon', 'appliance', '4800000000163', 'TCL Window Standard Inverter 1.5HP window-type air conditioner. 3-year warranty.', 37199.26, 26521.21, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(17, 1, 'TAC-09CWI/UJE', 'TCL Window Smart Inverter (WiFi) 1HP Aircon', 'appliance', '4800000000170', 'TCL Window Smart Inverter (WiFi) 1HP window-type air conditioner. 3-year warranty.', 29057.06, 19803.55, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(18, 1, 'TAC-12CWI/UJE', 'TCL Window Smart Inverter (WiFi) 1.5HP Aircon', 'appliance', '4800000000187', 'TCL Window Smart Inverter (WiFi) 1.5HP window-type air conditioner. 3-year warranty.', 38805.30, 28107.07, 12, 4, 30, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(19, 1, 'TAC-18CWI/UJE', 'TCL Window Smart Inverter (WiFi) 2HP Aircon', 'appliance', '4800000000194', 'TCL Window Smart Inverter (WiFi) 2HP window-type air conditioner. 3-year warranty.', 53432.70, 39323.26, 12, 4, 30, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(20, 1, 'TAC-24CWI/UJE', 'TCL Window Smart Inverter (WiFi) 2.5HP Aircon', 'appliance', '4800000000200', 'TCL Window Smart Inverter (WiFi) 2.5HP window-type air conditioner. 3-year warranty.', 64599.54, 44009.73, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(21, 1, 'TAC-07CWI/UB2', 'TCL Window Standard Inverter 0.8HP Aircon', 'appliance', '4800000000217', 'TCL Window Standard Inverter 0.8HP window-type air conditioner. 3-year warranty.', 20067.20, 14036.20, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(22, 1, 'TAC-09CWI/UB2', 'TCL Window Standard Inverter 1HP Aircon', 'appliance', '4800000000224', 'TCL Window Standard Inverter 1HP window-type air conditioner. 3-year warranty.', 26815.60, 20423.83, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(23, 1, 'TAC-12CWI/UB2', 'TCL Window Standard Inverter 1.5HP Aircon', 'appliance', '4800000000231', 'TCL Window Standard Inverter 1.5HP window-type air conditioner. 3-year warranty.', 38549.48, 29976.46, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(24, 1, 'KV09WR-ARF31A', 'TCL Window Standard Inverter (New Model) 1HP Aircon', 'appliance', '4800000000248', 'TCL Window Standard Inverter (New Model) 1HP window-type air conditioner. 3-year warranty.', 28063.17, 21348.50, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(25, 1, 'KV12WR-ARF31A', 'TCL Window Standard Inverter (New Model) 1.5HP Aircon', 'appliance', '4800000000255', 'TCL Window Standard Inverter (New Model) 1.5HP window-type air conditioner. 3-year warranty.', 37139.98, 28694.35, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(26, 1, 'TAC-09CWI/UJE2', 'TCL Window Smart Inverter (WiFi) 1HP Aircon', 'appliance', '4800000000262', 'TCL Window Smart Inverter (WiFi) 1HP window-type air conditioner. 3-year warranty.', 29381.97, 22627.06, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(27, 1, 'TAC-12CWI/UJE2', 'TCL Window Smart Inverter (WiFi) 1.5HP Aircon', 'appliance', '4800000000279', 'TCL Window Smart Inverter (WiFi) 1.5HP window-type air conditioner. 3-year warranty.', 38006.84, 29550.70, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(28, 1, 'TAC-18CWI/UJE2', 'TCL Window Smart Inverter (WiFi) 2HP Aircon', 'appliance', '4800000000286', 'TCL Window Smart Inverter (WiFi) 2HP window-type air conditioner. 3-year warranty.', 53070.16, 40215.51, 14, 5, 35, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(29, 1, 'TAC-24CWI/UJE2', 'TCL Window Smart Inverter (WiFi) 2.5HP Aircon', 'appliance', '4800000000293', 'TCL Window Smart Inverter (WiFi) 2.5HP window-type air conditioner. 3-year warranty.', 63235.78, 46586.43, 14, 5, 35, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(30, 1, 'TAC-09CSA/MEI', 'TCL T-Pro IoT Inverter (Wi-Fi) 1HP Aircon', 'appliance', '4800000000309', 'TCL T-Pro IoT Inverter (Wi-Fi) 1HP split-type air conditioner. 3-year warranty.', 28781.20, 19680.30, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(31, 1, 'TAC-12CSA/MEI', 'TCL T-Pro IoT Inverter (Wi-Fi) 1.5HP Aircon', 'appliance', '4800000000316', 'TCL T-Pro IoT Inverter (Wi-Fi) 1.5HP split-type air conditioner. 3-year warranty.', 38968.09, 27139.72, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(32, 1, 'TAC-18CSA/MEI', 'TCL T-Pro IoT Inverter (Wi-Fi) 2HP Aircon', 'appliance', '4800000000323', 'TCL T-Pro IoT Inverter (Wi-Fi) 2HP split-type air conditioner. 3-year warranty.', 52913.54, 40239.69, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(33, 1, 'TAC-24CSA/MEI', 'TCL T-Pro IoT Inverter (Wi-Fi) 2.5HP Aircon', 'appliance', '4800000000330', 'TCL T-Pro IoT Inverter (Wi-Fi) 2.5HP split-type air conditioner. 3-year warranty.', 62444.91, 45762.75, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(34, 1, 'TAC-10CSD/KEI-S', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 1HP Aircon', 'appliance', '4800000000347', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 1HP split-type air conditioner. 3-year warranty.', 27413.68, 19022.08, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(35, 1, 'TAC-13CSD/KEI-S', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 1.5HP Aircon', 'appliance', '4800000000354', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 1.5HP split-type air conditioner. 3-year warranty.', 38810.31, 29486.52, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(36, 1, 'TAC-19CSD/KEI-S', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 2HP Aircon', 'appliance', '4800000000361', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 2HP split-type air conditioner. 3-year warranty.', 54806.75, 38341.71, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(37, 1, 'TAC-25CSD/KEI-S', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 2.5HP Aircon', 'appliance', '4800000000378', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 2.5HP split-type air conditioner. 3-year warranty.', 60056.67, 43415.57, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(38, 1, 'TAC-30CSD/KEI-S', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 3HP Aircon', 'appliance', '4800000000385', 'TCL CoolPro EcoIn (2025 B2B Exclusive) 3HP split-type air conditioner. 3-year warranty.', 70593.60, 54254.01, 17, 6, 43, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(39, 1, 'TAC-09CSA/BEI', 'TCL Cool Pro Obsidian+ (Wi-Fi) 1HP Aircon', 'appliance', '4800000000392', 'TCL Cool Pro Obsidian+ (Wi-Fi) 1HP split-type air conditioner. 3-year warranty.', 27816.54, 19334.44, 17, 6, 43, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(40, 1, 'TAC-12CSA/BEI', 'TCL Cool Pro Obsidian+ (Wi-Fi) 1.5HP Aircon', 'appliance', '4800000000408', 'TCL Cool Pro Obsidian+ (Wi-Fi) 1.5HP split-type air conditioner. 3-year warranty.', 36860.00, 26670.79, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(41, 1, 'TAC-18CSA/BEI', 'TCL Cool Pro Obsidian+ (Wi-Fi) 2HP Aircon', 'appliance', '4800000000415', 'TCL Cool Pro Obsidian+ (Wi-Fi) 2HP split-type air conditioner. 3-year warranty.', 54577.12, 41181.71, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(42, 1, 'TAC-24CSA/BEI', 'TCL Cool Pro Obsidian+ (Wi-Fi) 2.5HP Aircon', 'appliance', '4800000000422', 'TCL Cool Pro Obsidian+ (Wi-Fi) 2.5HP split-type air conditioner. 3-year warranty.', 63857.77, 49766.91, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(43, 1, 'TAC-10CSD/WEI', 'TCL VoxIN (Voice Control + Wi-Fi) 1HP Aircon', 'appliance', '4800000000439', 'TCL VoxIN (Voice Control + Wi-Fi) 1HP split-type air conditioner. 3-year warranty.', 27913.65, 19140.67, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(44, 1, 'TAC-13CSD/WEI', 'TCL VoxIN (Voice Control + Wi-Fi) 1.5HP Aircon', 'appliance', '4800000000446', 'TCL VoxIN (Voice Control + Wi-Fi) 1.5HP split-type air conditioner. 3-year warranty.', 36310.06, 27927.88, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(45, 1, 'TAC-19CSD/WEI', 'TCL VoxIN (Voice Control + Wi-Fi) 2HP Aircon', 'appliance', '4800000000453', 'TCL VoxIN (Voice Control + Wi-Fi) 2HP split-type air conditioner. 3-year warranty.', 53043.33, 38016.15, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(46, 1, 'TAC-25CSD/WEI', 'TCL VoxIN (Voice Control + Wi-Fi) 2.5HP Aircon', 'appliance', '4800000000460', 'TCL VoxIN (Voice Control + Wi-Fi) 2.5HP split-type air conditioner. 3-year warranty.', 63639.03, 43707.29, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(47, 1, '28TCFS/JI', 'TCL Floor Standing Inverter 3HP Aircon', 'appliance', '4800000000477', 'TCL Floor Standing Inverter 3HP split-type air conditioner. 3-year warranty.', 73463.70, 53643.93, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(48, 1, '36TCFS/JI', 'TCL Floor Standing Inverter 4HP Aircon', 'appliance', '4800000000484', 'TCL Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 89392.34, 66243.30, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(49, 1, '48TCFS/JI', 'TCL Floor Standing Inverter 6HP Aircon', 'appliance', '4800000000491', 'TCL Floor Standing Inverter 6HP split-type air conditioner. 3-year warranty.', 141671.38, 97420.32, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(50, 1, '28TCFS/JI2', 'TCL Floor Standing Inverter 3HP Aircon', 'appliance', '4800000000507', 'TCL Floor Standing Inverter 3HP split-type air conditioner. 3-year warranty.', 75445.50, 56325.35, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(51, 1, '60TCFS/JI2', 'TCL Floor Standing Inverter 7HP Aircon', 'appliance', '4800000000514', 'TCL Floor Standing Inverter 7HP split-type air conditioner. 3-year warranty.', 164420.60, 126097.45, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(52, 1, '24TCHC/MEI2', 'TCL Ceiling Cassette Inverter 2.5HP Aircon', 'appliance', '4800000000521', 'TCL Ceiling Cassette Inverter 2.5HP split-type air conditioner. 3-year warranty.', 64917.97, 49375.31, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(53, 1, '36TCHC/MEI2', 'TCL Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000000538', 'TCL Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 98078.00, 68104.38, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(54, 1, '42TCHC/MEI2', 'TCL Ceiling Cassette Inverter 5HP Aircon', 'appliance', '4800000000545', 'TCL Ceiling Cassette Inverter 5HP split-type air conditioner. 3-year warranty.', 113740.32, 86177.63, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(55, 1, '60TCHC/MEI2', 'TCL Ceiling Cassette Inverter 6HP Aircon', 'appliance', '4800000000552', 'TCL Ceiling Cassette Inverter 6HP split-type air conditioner. 3-year warranty.', 144128.26, 98817.22, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(56, 1, '36TCHC/CHI2', 'TCL Ceiling Mounted Inverter 4HP Aircon', 'appliance', '4800000000569', 'TCL Ceiling Mounted Inverter 4HP split-type air conditioner. 3-year warranty.', 90613.28, 66470.28, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(57, 1, '42TCHC/CHI2', 'TCL Ceiling Mounted Inverter 5HP Aircon', 'appliance', '4800000000576', 'TCL Ceiling Mounted Inverter 5HP split-type air conditioner. 3-year warranty.', 123026.88, 85559.04, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(58, 1, '60TCHC/CHI2', 'TCL Ceiling Mounted Inverter 6HP Aircon', 'appliance', '4800000000583', 'TCL Ceiling Mounted Inverter 6HP split-type air conditioner. 3-year warranty.', 147578.68, 112696.98, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(59, 1, 'TAC-08CSA-KEI', 'TCL Titan Gold 0.8HP Aircon', 'appliance', '4800000000590', 'TCL Titan Gold 0.8HP split-type air conditioner. 3-year warranty.', 19044.80, 13275.18, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(60, 1, 'TAC-09CSA-KEI', 'TCL Titan Gold 1HP Aircon', 'appliance', '4800000000606', 'TCL Titan Gold 1HP split-type air conditioner. 3-year warranty.', 29358.11, 20432.07, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(61, 1, 'TAC-12CSA-KEI', 'TCL Titan Gold 1.5HP Aircon', 'appliance', '4800000000613', 'TCL Titan Gold 1.5HP split-type air conditioner. 3-year warranty.', 36622.88, 26899.14, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(62, 1, 'TAC-18CSA-KEI', 'TCL Titan Gold 2HP Aircon', 'appliance', '4800000000620', 'TCL Titan Gold 2HP split-type air conditioner. 3-year warranty.', 52124.18, 37841.63, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(63, 1, 'TAC-22CSA-KEI', 'TCL Titan Gold 2.5HP Aircon', 'appliance', '4800000000637', 'TCL Titan Gold 2.5HP split-type air conditioner. 3-year warranty.', 58375.98, 40275.92, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(64, 1, 'IFAC-09CSD/XAF1', 'Iffalcon 1HP Inverter Aircon', 'appliance', '4800000000644', 'Iffalcon Inverter 1HP split-type air conditioner. 3-year warranty.', 28069.89, 20267.02, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(65, 1, 'IFAC-12CSD/XAF1', 'Iffalcon 1.5HP Inverter Aircon', 'appliance', '4800000000651', 'Iffalcon Inverter 1.5HP split-type air conditioner. 3-year warranty.', 39166.45, 27422.39, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(66, 1, 'IFAC-18CSD/XAF1', 'Iffalcon 2HP Inverter Aircon', 'appliance', '4800000000668', 'Iffalcon Inverter 2HP split-type air conditioner. 3-year warranty.', 50300.85, 34253.37, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(67, 1, 'IFAC-24CSD/XAF1', 'Iffalcon 2.5HP Inverter Aircon', 'appliance', '4800000000675', 'Iffalcon Inverter 2.5HP split-type air conditioner. 3-year warranty.', 63275.96, 43171.92, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(68, 1, 'FP-51ARA006HMNV-N5', 'Midea Window Non-Inverter 0.6HP Aircon', 'appliance', '4800000000682', 'Midea Window Non-Inverter 0.6HP window-type air conditioner. 3-year warranty.', 17681.83, 12991.37, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(69, 1, 'FP-38AFS036KENV-J5', 'Midea Floor Standing Non-Inverter 4HP Aircon', 'appliance', '4800000000699', 'Midea Floor Standing Non-Inverter 4HP split-type air conditioner. 3-year warranty.', 98576.18, 76798.73, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(70, 1, 'MWWA-08CRFN8', 'Midea Window Standard Inverter 0.8HP Aircon', 'appliance', '4800000000705', 'Midea Window Standard Inverter 0.8HP window-type air conditioner. 3-year warranty.', 19602.80, 13350.29, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(71, 1, 'MWWA-09CRFN8', 'Midea Window Standard Inverter 1HP Aircon', 'appliance', '4800000000712', 'Midea Window Standard Inverter 1HP window-type air conditioner. 3-year warranty.', 29340.98, 21063.60, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(72, 1, 'MWWA-12CRFN8', 'Midea Window Standard Inverter 1.5HP Aircon', 'appliance', '4800000000729', 'Midea Window Standard Inverter 1.5HP window-type air conditioner. 3-year warranty.', 38935.71, 28228.00, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(73, 1, 'FP-51ARA020HEIV-N4', 'Midea Window Smart Inverter (WiFi) 2HP Aircon', 'appliance', '4800000000736', 'Midea Window Smart Inverter (WiFi) 2HP window-type air conditioner. 3-year warranty.', 50937.95, 35904.12, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(74, 1, 'FP-51ARA025HEIV-N4', 'Midea Window Smart Inverter (WiFi) 2.5HP Aircon', 'appliance', '4800000000743', 'Midea Window Smart Inverter (WiFi) 2.5HP window-type air conditioner. 3-year warranty.', 65597.24, 50041.51, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(75, 1, 'FP-53AST010KEIV-F4', 'Midea Celest Inverter 1HP Aircon', 'appliance', '4800000000750', 'Midea Celest Inverter 1HP split-type air conditioner. 3-year warranty.', 26611.98, 18814.40, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(76, 1, 'FP-53AST010KEIV-F4-2.5HP', 'Midea Celest Inverter 2.5HP Aircon', 'appliance', '4800000000767', 'Midea Celest Inverter 2.5HP split-type air conditioner. 3-year warranty.', 58736.82, 43214.44, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(77, 1, 'MSCE-10CRFN8', 'Midea Celest Pro Full DC Inverter 1HP Aircon', 'appliance', '4800000000774', 'Midea Celest Pro Full DC Inverter 1HP split-type air conditioner. 3-year warranty.', 27107.58, 19534.81, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(78, 1, 'MSCE-13CRFN8', 'Midea Celest Pro Full DC Inverter 1.5HP Aircon', 'appliance', '4800000000781', 'Midea Celest Pro Full DC Inverter 1.5HP split-type air conditioner. 3-year warranty.', 37151.84, 28849.52, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(79, 1, 'MSCE-19CRFN8', 'Midea Celest Pro Full DC Inverter 2HP Aircon', 'appliance', '4800000000798', 'Midea Celest Pro Full DC Inverter 2HP split-type air conditioner. 3-year warranty.', 54537.18, 40148.09, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(80, 1, 'MSCE-22CRFN8', 'Midea Celest Pro Full DC Inverter 2.5HP Aircon', 'appliance', '4800000000804', 'Midea Celest Pro Full DC Inverter 2.5HP split-type air conditioner. 3-year warranty.', 63101.12, 47664.69, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(81, 1, 'MSCE-25CRFN8', 'Midea Celest Pro Full DC Inverter 3HP Aircon', 'appliance', '4800000000811', 'Midea Celest Pro Full DC Inverter 3HP split-type air conditioner. 3-year warranty.', 78346.20, 54206.17, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(82, 1, 'MFSI/O-36CDN8-MD3', 'Midea Floor Standing Inverter 4HP Aircon', 'appliance', '4800000000828', 'Midea Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 98927.30, 72440.50, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(83, 1, 'MFSI/O-52CDN8-MD1', 'Midea Floor Standing Inverter 6HP Aircon', 'appliance', '4800000000835', 'Midea Floor Standing Inverter 6HP split-type air conditioner. 3-year warranty.', 136360.90, 99334.82, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(84, 1, 'MFSI/O-52CDN8-DD1', 'Midea Floor Standing Inverter (3P 220V) 6HP Aircon', 'appliance', '4800000000842', 'Midea Floor Standing Inverter (3P 220V) 6HP split-type air conditioner. 3-year warranty.', 152297.56, 109055.71, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(85, 1, 'MCCI/O-36CDN8-MD3', 'Midea Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000000859', 'Midea Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 89632.88, 61296.34, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(86, 1, 'MCCI/O-52CDN8-MD1', 'Midea Ceiling Cassette Inverter 6HP Aircon', 'appliance', '4800000000866', 'Midea Ceiling Cassette Inverter 6HP split-type air conditioner. 3-year warranty.', 143578.42, 103646.39, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(87, 1, 'MUCI/O-36CDN8-MD3', 'Midea Ceiling Mounted Inverter 4HP Aircon', 'appliance', '4800000000873', 'Midea Ceiling Mounted Inverter 4HP split-type air conditioner. 3-year warranty.', 94545.14, 70637.51, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(88, 1, 'MCCI/O-52CDN8-MD1-6HP', 'Midea Ceiling Mounted Inverter 6HP Aircon', 'appliance', '4800000000880', 'Midea Ceiling Mounted Inverter 6HP split-type air conditioner. 3-year warranty.', 145941.34, 109695.35, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(89, 1, 'FP-42AFC036KENV-N6', 'Midea Ceiling Mounted Non-Inverter 4HP Aircon', 'appliance', '4800000000897', 'Midea Ceiling Mounted Non-Inverter 4HP split-type air conditioner. 3-year warranty.', 97830.62, 68803.30, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(90, 1, 'HSU-09CSV32', 'Haier Clean Cool Pro Inverter 1HP Aircon', 'appliance', '4800000000903', 'Haier Clean Cool Pro Inverter 1HP split-type air conditioner. 3-year warranty.', 26939.25, 18785.55, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(91, 1, 'HSU-13CSV32', 'Haier Clean Cool Pro Inverter 1.5HP Aircon', 'appliance', '4800000000910', 'Haier Clean Cool Pro Inverter 1.5HP split-type air conditioner. 3-year warranty.', 36207.46, 27969.54, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(92, 1, 'HSU-18CSV32', 'Haier Clean Cool Pro Inverter 2HP Aircon', 'appliance', '4800000000927', 'Haier Clean Cool Pro Inverter 2HP split-type air conditioner. 3-year warranty.', 49727.39, 38682.94, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(93, 1, 'HSU-25CSV32', 'Haier Clean Cool Pro Inverter 2.5HP Aircon', 'appliance', '4800000000934', 'Haier Clean Cool Pro Inverter 2.5HP split-type air conditioner. 3-year warranty.', 64636.74, 48531.20, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(94, 1, 'HSU-30PSV32', 'Haier Clean Cool Pro Inverter 3HP Aircon', 'appliance', '4800000000941', 'Haier Clean Cool Pro Inverter 3HP split-type air conditioner. 3-year warranty.', 76239.30, 58773.64, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(95, 1, 'AP105S2SK1SA', 'Haier Floor Standing Inverter 4HP Aircon', 'appliance', '4800000000958', 'Haier Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 91442.06, 70628.02, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(96, 1, 'AP60S4SE1ET', 'Haier Floor Standing Inverter 6HP Aircon', 'appliance', '4800000000965', 'Haier Floor Standing Inverter 6HP split-type air conditioner. 3-year warranty.', 145132.24, 98747.98, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(97, 1, 'ABH105H1ERG', 'Haier Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000000972', 'Haier Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 98996.84, 76961.13, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(98, 1, 'ABH140K1ERG', 'Haier Ceiling Cassette Inverter 6HP Aircon', 'appliance', '4800000000989', 'Haier Ceiling Cassette Inverter 6HP split-type air conditioner. 3-year warranty.', 153376.36, 117363.59, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(99, 1, 'AC105S2SH1FA', 'Haier Ceiling Mounted Inverter 4HP Aircon', 'appliance', '4800000000996', 'Haier Ceiling Mounted Inverter 4HP split-type air conditioner. 3-year warranty.', 97681.28, 68358.34, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(100, 1, 'AC140S2SK1FA', 'Haier Ceiling Mounted Inverter 6HP Aircon', 'appliance', '4800000001009', 'Haier Ceiling Mounted Inverter 6HP split-type air conditioner. 3-year warranty.', 146221.48, 101071.21, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(101, 1, 'AR09TYHYEWKNTC', 'Samsung Standard Inverter (Digital S-Inverter) 1HP Aircon', 'appliance', '4800000001016', 'Samsung Standard Inverter (Digital S-Inverter) 1HP split-type air conditioner. 3-year warranty.', 26989.31, 19688.97, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(102, 1, 'AR12TYHYEWKNTC', 'Samsung Standard Inverter (Digital S-Inverter) 1.5HP Aircon', 'appliance', '4800000001023', 'Samsung Standard Inverter (Digital S-Inverter) 1.5HP split-type air conditioner. 3-year warranty.', 38358.87, 29800.62, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(103, 1, 'AR18TYHYEWKNTC', 'Samsung Standard Inverter (Digital S-Inverter) 2HP Aircon', 'appliance', '4800000001030', 'Samsung Standard Inverter (Digital S-Inverter) 2HP split-type air conditioner. 3-year warranty.', 48913.07, 34871.11, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(104, 1, 'AR24TYHYEWKNTC', 'Samsung Standard Inverter (Digital S-Inverter) 2.5HP Aircon', 'appliance', '4800000001047', 'Samsung Standard Inverter (Digital S-Inverter) 2.5HP split-type air conditioner. 3-year warranty.', 61098.27, 46661.97, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(105, 1, 'AR10AYKAGWKXTC', 'Samsung WindFree ActivClean 1HP Aircon', 'appliance', '4800000001054', 'Samsung WindFree ActivClean 1HP split-type air conditioner. 3-year warranty.', 26756.13, 20459.07, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(106, 1, 'AR10AYKAGWKXTC-1.5HP', 'Samsung WindFree ActivClean 1.5HP Aircon', 'appliance', '4800000001061', 'Samsung WindFree ActivClean 1.5HP split-type air conditioner. 3-year warranty.', 36180.10, 26147.72, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(107, 1, 'AR10AYKAGWKXTC-2HP', 'Samsung WindFree ActivClean 2HP Aircon', 'appliance', '4800000001078', 'Samsung WindFree ActivClean 2HP split-type air conditioner. 3-year warranty.', 52912.91, 38051.79, 13, 5, 33, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(108, 1, 'AR10AYKAGWKXTC-2.5HP', 'Samsung WindFree ActivClean 2.5HP Aircon', 'appliance', '4800000001085', 'Samsung WindFree ActivClean 2.5HP split-type air conditioner. 3-year warranty.', 61627.26, 44686.54, 14, 5, 35, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(109, 1, 'AC036BNPDKC/TC', 'Samsung Floor Standing Inverter 4HP Aircon', 'appliance', '4800000001092', 'Samsung Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 99109.70, 75365.00, 14, 5, 35, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(110, 1, 'AC048BNPDKC/TC', 'Samsung Floor Standing Inverter 6HP Aircon', 'appliance', '4800000001108', 'Samsung Floor Standing Inverter 6HP split-type air conditioner. 3-year warranty.', 145913.50, 112677.32, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(111, 1, 'AC100TN4DKC/TC', 'Samsung Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000001115', 'Samsung Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 89622.62, 63584.56, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(112, 1, 'AC140TN4DKC/TC', 'Samsung Ceiling Cassette Inverter 5HP Aircon', 'appliance', '4800000001122', 'Samsung Ceiling Cassette Inverter 5HP split-type air conditioner. 3-year warranty.', 124216.32, 86907.95, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(113, 1, 'AC100TNCDKC/TC', 'Samsung Ceiling Mounted Inverter 4HP Aircon', 'appliance', '4800000001139', 'Samsung Ceiling Mounted Inverter 4HP split-type air conditioner. 3-year warranty.', 91641.56, 63833.85, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(114, 1, 'AC140TNCDKC/TC', 'Samsung Ceiling Mounted Inverter 5HP Aircon', 'appliance', '4800000001146', 'Samsung Ceiling Mounted Inverter 5HP split-type air conditioner. 3-year warranty.', 123235.68, 84983.32, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(115, 1, 'AC160TNCDKC/TC', 'Samsung Ceiling Mounted Inverter 6HP Aircon', 'appliance', '4800000001153', 'Samsung Ceiling Mounted Inverter 6HP split-type air conditioner. 3-year warranty.', 144820.78, 104533.09, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(116, 1, 'FP-53CAC009308', 'Carrier Nexus Inverter 1HP Aircon', 'appliance', '4800000001160', 'Carrier Nexus Inverter 1HP split-type air conditioner. 3-year warranty.', 26530.34, 18315.22, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(117, 1, 'FP-53CAC012308', 'Carrier Nexus Inverter 1.5HP Aircon', 'appliance', '4800000001177', 'Carrier Nexus Inverter 1.5HP split-type air conditioner. 3-year warranty.', 39191.07, 30568.25, 18, 6, 45, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(118, 1, 'FP-53CAC018308', 'Carrier Nexus Inverter 2HP Aircon', 'appliance', '4800000001184', 'Carrier Nexus Inverter 2HP split-type air conditioner. 3-year warranty.', 52174.72, 36653.26, 17, 6, 43, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(119, 1, 'FP-53CAC024308', 'Carrier Nexus Inverter 2.5HP Aircon', 'appliance', '4800000001191', 'Carrier Nexus Inverter 2.5HP split-type air conditioner. 3-year warranty.', 65159.02, 46735.96, 17, 6, 43, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(120, 1, '38CL V036308', 'Carrier Slim Floor Standing Inverter 4HP Aircon', 'appliance', '4800000001207', 'Carrier Slim Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 100426.40, 70004.23, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(121, 1, 'DFE-ASD 04.0HP', 'Carrier Floor Standing Inverter 4HP Aircon', 'appliance', '4800000001214', 'Carrier Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 93784.76, 64492.03, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(122, 1, '42DFECAR04HEGS2GN1', 'Carrier Floor Standing Inverter 4HP Aircon', 'appliance', '4800000001221', 'Carrier Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 97632.26, 70685.76, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(123, 1, 'DFE-ASD 06.0HP', 'Carrier Floor Standing Inverter (1-Phase 220V) 6HP Aircon', 'appliance', '4800000001238', 'Carrier Floor Standing Inverter (1-Phase 220V) 6HP split-type air conditioner. 3-year warranty.', 137752.90, 99078.77, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(124, 1, 'FP-53CFE-ASC', 'Carrier Floor Standing Non-Inverter 4HP Aircon', 'appliance', '4800000001245', 'Carrier Floor Standing Non-Inverter 4HP split-type air conditioner. 3-year warranty.', 99179.24, 75516.07, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(125, 1, 'FP-53CFE600', 'Carrier Floor Standing Non-Inverter 6HP Aircon', 'appliance', '4800000001252', 'Carrier Floor Standing Non-Inverter 6HP split-type air conditioner. 3-year warranty.', 142209.04, 101673.78, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(126, 1, '53CAV036308-2', 'Carrier Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000001269', 'Carrier Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 93120.14, 65740.96, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(127, 1, '53CAV055308-2', 'Carrier Ceiling Cassette Inverter 5HP Aircon', 'appliance', '4800000001276', 'Carrier Ceiling Cassette Inverter 5HP split-type air conditioner. 3-year warranty.', 123457.44, 84527.61, 8, 3, 20, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(128, 1, '53CUV036308-2', 'Carrier Ceiling Mounted Inverter 4HP Aircon', 'appliance', '4800000001283', 'Carrier Ceiling Mounted Inverter 4HP split-type air conditioner. 3-year warranty.', 93902.18, 69658.52, 7, 2, 18, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(129, 1, '53CUV055308-2', 'Carrier Ceiling Mounted Inverter 5HP Aircon', 'appliance', '4800000001290', 'Carrier Ceiling Mounted Inverter 5HP split-type air conditioner. 3-year warranty.', 116179.68, 83318.26, 7, 2, 18, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(130, 1, '42DFECAR06HEGS2GN', 'Carrier Chilled Water Aircon', 'appliance', '4800000001306', 'Carrier Chilled Water split-type air conditioner. 3-year warranty.', 30677.88, 22264.47, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(131, 1, 'KV09WM-ARF31D', 'Koppel R32 Super Inverter - Lunaire 1HP Aircon', 'appliance', '4800000001313', 'Koppel R32 Super Inverter - Lunaire 1HP split-type air conditioner. 3-year warranty.', 29592.98, 21594.00, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(132, 1, 'KV12WM-ARF31D', 'Koppel R32 Super Inverter - Lunaire 1.5HP Aircon', 'appliance', '4800000001320', 'Koppel R32 Super Inverter - Lunaire 1.5HP split-type air conditioner. 3-year warranty.', 35908.78, 26527.97, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(133, 1, 'KV18WM-ARF31D', 'Koppel R32 Super Inverter - Lunaire 2HP Aircon', 'appliance', '4800000001337', 'Koppel R32 Super Inverter - Lunaire 2HP split-type air conditioner. 3-year warranty.', 49648.77, 36232.18, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(134, 1, 'KV24WM-ARF31D', 'Koppel R32 Super Inverter - Lunaire 2.5HP Aircon', 'appliance', '4800000001344', 'Koppel R32 Super Inverter - Lunaire 2.5HP split-type air conditioner. 3-year warranty.', 62345.96, 45213.91, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(135, 1, 'KV27WM-ARF31D', 'Koppel R32 Super Inverter - Lunaire 3HP Aircon', 'appliance', '4800000001351', 'Koppel R32 Super Inverter - Lunaire 3HP split-type air conditioner. 3-year warranty.', 73112.70, 51257.85, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(136, 1, 'KV36FM-ARF21D', 'Koppel Floor Inverter 4HP Aircon', 'appliance', '4800000001368', 'Koppel Floor Inverter 4HP split-type air conditioner. 3-year warranty.', 100668.08, 76090.97, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(137, 1, 'KV60FM-ARF21D', 'Koppel Floor Inverter 6HP Aircon', 'appliance', '4800000001375', 'Koppel Floor Inverter 6HP split-type air conditioner. 3-year warranty.', 145588.12, 107628.93, 11, 4, 28, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(138, 1, 'KV36CC-ARF21D', 'Koppel Ceiling Cassette Inverter 4HP Aircon', 'appliance', '4800000001382', 'Koppel Ceiling Cassette Inverter 4HP split-type air conditioner. 3-year warranty.', 92717.72, 69058.01, 12, 4, 30, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(139, 1, 'KV60CC-ARF21D', 'Koppel Ceiling Cassette Inverter 6HP Aircon', 'appliance', '4800000001399', 'Koppel Ceiling Cassette Inverter 6HP split-type air conditioner. 3-year warranty.', 151264.00, 111728.13, 12, 4, 30, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(140, 1, 'HS09IBA', 'LG Dual Inverter 1HP Aircon', 'appliance', '4800000001405', 'LG Dual Inverter 1HP split-type air conditioner. 3-year warranty.', 28027.55, 20650.98, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(141, 1, 'HS12IBA', 'LG Dual Inverter 1.5HP Aircon', 'appliance', '4800000001412', 'LG Dual Inverter 1.5HP split-type air conditioner. 3-year warranty.', 37480.16, 25529.99, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(142, 1, 'HS18IBA', 'LG Dual Inverter 2HP Aircon', 'appliance', '4800000001429', 'LG Dual Inverter 2HP split-type air conditioner. 3-year warranty.', 53816.46, 38615.46, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(143, 1, 'HS24IBA', 'LG Dual Inverter 2.5HP Aircon', 'appliance', '4800000001436', 'LG Dual Inverter 2.5HP split-type air conditioner. 3-year warranty.', 64810.09, 44547.22, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(144, 1, 'ZPNQ36GT3E0', 'LG Floor Standing Inverter 4HP Aircon', 'appliance', '4800000001443', 'LG Floor Standing Inverter 4HP split-type air conditioner. 3-year warranty.', 90266.72, 68291.29, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(145, 1, 'ZPNQ60GT3A0', 'LG Floor Standing Inverter 6HP Aircon', 'appliance', '4800000001450', 'LG Floor Standing Inverter 6HP split-type air conditioner. 3-year warranty.', 150689.80, 106908.39, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(146, 1, 'ZTNQ30GNLE0/ENWHASA', 'LG Ceiling Cassette Inverter 3HP Aircon', 'appliance', '4800000001467', 'LG Ceiling Cassette Inverter 3HP split-type air conditioner. 3-year warranty.', 71260.50, 50632.01, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 17:22:18', NULL),
(147, 2, 'S45H', 'TCL Soundbar S45H', 'appliance', '4800000001474', 'TCL Soundbar television. 12-month warranty.', 5087.12, 3493.07, 19, 7, 48, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(148, 2, 'S55H', 'TCL Soundbar S55H', 'appliance', '4800000001481', 'TCL Soundbar television. 12-month warranty.', 5591.66, 3828.95, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(149, 2, 'Q75H', 'TCL Soundbar Q75H', 'appliance', '4800000001498', 'TCL Soundbar television. 12-month warranty.', 8362.83, 6450.17, 20, 7, 50, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(150, 2, 'Q85H', 'TCL Soundbar Q85H', 'appliance', '4800000001504', 'TCL Soundbar television. 12-month warranty.', 7335.08, 5417.76, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(151, 2, '32D3400', 'TCL Digital TV 32D3400', 'appliance', '4800000001511', 'TCL Digital TV television. 12-month warranty.', 15587.34, 11133.10, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(152, 2, '32S5401AF', 'TCL Digital TV 32S5401AF', 'appliance', '4800000001528', 'TCL Digital TV television. 12-month warranty.', 15678.96, 10784.30, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(153, 2, '32S5402', 'TCL Android Smart TV 32S5402', 'appliance', '4800000001535', 'TCL Android Smart TV television. 12-month warranty.', 15089.64, 10660.98, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(154, 2, '32S5K', 'TCL 2K Google TV 32S5K', 'appliance', '4800000001542', 'TCL 2K Google TV television. 12-month warranty.', 15223.74, 11068.72, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(155, 2, '40S5400A', 'TCL Android Smart TV 40S5400A', 'appliance', '4800000001559', 'TCL Android Smart TV television. 12-month warranty.', 15504.90, 11655.65, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(156, 2, '40S5K', 'TCL 2K Google TV 40S5K', 'appliance', '4800000001566', 'TCL 2K Google TV television. 12-month warranty.', 14782.20, 11079.55, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(157, 2, '43S5400A', 'TCL Android Smart TV 43S5400A', 'appliance', '4800000001573', 'TCL Android Smart TV television. 12-month warranty.', 15171.36, 10847.67, 16, 6, 40, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(158, 2, '43S5K', 'TCL 2K Google TV 43S5K', 'appliance', '4800000001580', 'TCL 2K Google TV television. 12-month warranty.', 14496.72, 10550.13, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(159, 2, '43V6B', 'TCL 4K Google TV 43V6B', 'appliance', '4800000001597', 'TCL 4K Google TV television. 12-month warranty.', 14670.60, 10121.10, 15, 5, 38, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(160, 2, '50V6B', 'TCL 4K Google TV 50V6B', 'appliance', '4800000001603', 'TCL 4K Google TV television. 12-month warranty.', 15018.00, 11448.52, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(161, 2, '55P635', 'TCL 4K Google TV 55P635', 'appliance', '4800000001610', 'TCL 4K Google TV television. 12-month warranty.', 14316.36, 10703.63, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(162, 2, '65V6B', 'TCL 4K Google TV 65V6B', 'appliance', '4800000001627', 'TCL 4K Google TV television. 12-month warranty.', 15574.74, 11951.90, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(163, 2, '43P7K', 'TCL QLED Google TV 43P7K', 'appliance', '4800000001634', 'TCL QLED Google TV television. 12-month warranty.', 15001.26, 10921.82, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(164, 2, '50P7K', 'TCL QLED Google TV 50P7K', 'appliance', '4800000001641', 'TCL QLED Google TV television. 12-month warranty.', 14720.28, 10011.85, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(165, 2, '55P7K', 'TCL QLED Google TV 55P7K', 'appliance', '4800000001658', 'TCL QLED Google TV television. 12-month warranty.', 15619.20, 10660.26, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(166, 2, '65P7K', 'TCL QLED Google TV 65P7K', 'appliance', '4800000001665', 'TCL QLED Google TV television. 12-month warranty.', 14767.26, 10377.99, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(167, 2, '75P7K', 'TCL QLED Google TV 75P7K', 'appliance', '4800000001672', 'TCL QLED Google TV television. 12-month warranty.', 15777.06, 11059.09, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(168, 2, '85P8K', 'TCL QLED Google TV 144Hz 85P8K', 'appliance', '4800000001689', 'TCL QLED Google TV 144Hz television. 12-month warranty.', 15196.02, 11508.40, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(169, 2, '98P8K', 'TCL QLED Google TV 144Hz 98P8K', 'appliance', '4800000001696', 'TCL QLED Google TV 144Hz television. 12-month warranty.', 14680.14, 9991.89, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(170, 3, 'TR-59PHB', 'Condura 1 Door Direct Cool Non-Inverter TR-59PHB', 'appliance', '4800000001702', 'Condura 1 Door Direct Cool Non-Inverter refrigerator. 12-month warranty.', 15239.04, 11615.20, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(171, 3, 'TR-99PHB', 'Condura Single Door Non-Inverter TR-99PHB', 'appliance', '4800000001719', 'Condura Single Door Non-Inverter refrigerator. 12-month warranty.', 44108.65, 34091.13, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(172, 3, 'TR-190INV/DB', 'Condura Single Door Inverter TR-190INV/DB', 'appliance', '4800000001726', 'Condura Single Door Inverter refrigerator. 12-month warranty.', 32847.69, 25323.93, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(173, 3, 'TRF-118PH', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-118PH', 'appliance', '4800000001733', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 21167.71, 14712.83, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(174, 3, 'TRF-207PH', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-207PH', 'appliance', '4800000001740', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 34120.22, 23543.63, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(175, 3, 'TRF-230PH', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-230PH', 'appliance', '4800000001757', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 44419.26, 31917.46, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(176, 3, 'TRF-230INV/D', 'Condura 2 Door Top Mount No Frost Inverter TRF-230INV/D', 'appliance', '4800000001764', 'Condura 2 Door Top Mount No Frost Inverter refrigerator. 12-month warranty.', 20239.75, 14498.34, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(177, 3, 'TRF-H199INV/N', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-H199INV/N', 'appliance', '4800000001771', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 14998.47, 11680.21, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(178, 3, 'TRF-H220INV/N', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-H220INV/N', 'appliance', '4800000001788', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 29890.30, 21043.07, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(179, 3, 'TRF-H260INV/N', 'Condura 2 Door Top Mount Defrost Non-Inverter TRF-H260INV/N', 'appliance', '4800000001795', 'Condura 2 Door Top Mount Defrost Non-Inverter refrigerator. 12-month warranty.', 31442.87, 22177.29, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(180, 3, 'TRF-H360INV/N', 'Condura 2 Door Top Mount No Frost Inverter TRF-H360INV/N', 'appliance', '4800000001801', 'Condura 2 Door Top Mount No Frost Inverter refrigerator. 12-month warranty.', 25169.21, 17128.40, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(181, 3, 'TRF-INV430CDSS', 'Condura 4 Door Cross Door No Frost Inverter TRF-INV430CDSS', 'appliance', '4800000001818', 'Condura 4 Door Cross Door No Frost Inverter refrigerator. 12-month warranty.', 34674.50, 25093.24, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(182, 3, 'TRF-INV436BMSB', 'Condura 4 Door Cross Door No Frost Inverter TRF-INV436BMSB', 'appliance', '4800000001825', 'Condura 4 Door Cross Door No Frost Inverter refrigerator. 12-month warranty.', 21158.61, 14678.57, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(183, 3, 'TRF-505PH', 'Condura 2 Door Side-by-Side No Frost Inverter TRF-505PH', 'appliance', '4800000001832', 'Condura 2 Door Side-by-Side No Frost Inverter refrigerator. 12-month warranty.', 41503.51, 30046.47, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(184, 3, 'TRF-199 INV/S', 'Condura Freezer TRF-199 INV/S', 'appliance', '4800000001849', 'Condura Freezer refrigerator. 12-month warranty.', 29548.09, 20883.11, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(185, 3, 'TRF-210 INV/N', 'Condura Freezer TRF-210 INV/N', 'appliance', '4800000001856', 'Condura Freezer refrigerator. 12-month warranty.', 15793.45, 10884.53, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(186, 3, 'TCF-230 INV/D', 'Condura Inverter TCF-230 INV/D', 'appliance', '4800000001863', 'Condura Inverter refrigerator. 12-month warranty.', 32047.58, 23635.73, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(187, 3, 'TCF-185PUPH', 'Condura Chest Freezer Non-Inverter TCF-185PUPH', 'appliance', '4800000001870', 'Condura Chest Freezer Non-Inverter refrigerator. 12-month warranty.', 28899.11, 21023.81, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(188, 3, 'TCF-245PUPH', 'Condura Chest Freezer Non-Inverter TCF-245PUPH', 'appliance', '4800000001887', 'Condura Chest Freezer Non-Inverter refrigerator. 12-month warranty.', 24016.39, 17562.71, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(189, 3, 'TCF-305PUPH', 'Condura Chest Freezer Non-Inverter TCF-305PUPH', 'appliance', '4800000001894', 'Condura Chest Freezer Non-Inverter refrigerator. 12-month warranty.', 27572.74, 21038.28, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(190, 3, 'TCF-380PUPH', 'Condura Chest Freezer Non-Inverter TCF-380PUPH', 'appliance', '4800000001900', 'Condura Chest Freezer Non-Inverter refrigerator. 12-month warranty.', 41645.51, 29705.33, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(191, 3, 'TCF-450PUPH', 'Condura Chest Freezer Non-Inverter TCF-450PUPH', 'appliance', '4800000001917', 'Condura Chest Freezer Non-Inverter refrigerator. 12-month warranty.', 46262.84, 31638.23, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(192, 3, 'TUF-H260INV/D', 'Condura Upright Freezer Inverter Defrost TUF-H260INV/D', 'appliance', '4800000001924', 'Condura Upright Freezer Inverter Defrost refrigerator. 12-month warranty.', 43545.82, 32242.20, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(193, 3, 'TRF-INV660SBGB', 'Condura 2 Door Side-by-Side No Frost Inverter TRF-INV660SBGB', 'appliance', '4800000001931', 'Condura 2 Door Side-by-Side No Frost Inverter refrigerator. 12-month warranty.', 41347.14, 29590.91, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(194, 3, 'TRF-INV560CDGB', 'Condura 4 Door Cross Door No Frost Inverter TRF-INV560CDGB', 'appliance', '4800000001948', 'Condura 4 Door Cross Door No Frost Inverter refrigerator. 12-month warranty.', 31300.10, 22107.57, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(195, 4, 'TWT-60Z1', 'Condura Twin Tub TWT-60Z1', 'appliance', '4800000001955', 'Condura Twin Tub washing machine. 12-month warranty.', 18801.56, 14322.65, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(196, 4, 'TWT-80Z2', 'Condura Twin Tub TWT-80Z2', 'appliance', '4800000001962', 'Condura Twin Tub washing machine. 12-month warranty.', 12984.04, 9751.01, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL);
INSERT INTO `products` (`product_id`, `category_id`, `product_code`, `product_name`, `product_type`, `barcode`, `description`, `unit_price`, `cost_price`, `recommended_stocks`, `safety_stock`, `max_stock_level`, `status_id`, `created_at`, `updated_at`, `deleted_at`) VALUES
(197, 4, 'TWT-90Z2', 'Condura Twin Tub TWT-90Z2', 'appliance', '4800000001979', 'Condura Twin Tub washing machine. 12-month warranty.', 16506.71, 12076.47, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(198, 4, 'TWT-100Z2', 'Condura Twin Tub TWT-100Z2', 'appliance', '4800000001986', 'Condura Twin Tub washing machine. 12-month warranty.', 25371.46, 18371.98, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(199, 4, 'TWT-130Z2', 'Condura Twin Tub TWT-130Z2', 'appliance', '4800000001993', 'Condura Twin Tub washing machine. 12-month warranty.', 31544.81, 21473.50, 6, 2, 15, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(200, 4, 'TWA75-F107TLW', 'Condura Top Load Fully Automatic TWA75-F107TLW', 'appliance', '4800000002006', 'Condura Top Load Fully Automatic washing machine. 12-month warranty.', 36656.49, 26545.53, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(201, 4, 'TWA85-F708TLW', 'Condura Top Load Fully Automatic TWA85-F708TLW', 'appliance', '4800000002013', 'Condura Top Load Fully Automatic washing machine. 12-month warranty.', 23637.52, 17734.76, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(202, 4, 'TWA95-F709TLW', 'Condura Top Load Fully Automatic TWA95-F709TLW', 'appliance', '4800000002020', 'Condura Top Load Fully Automatic washing machine. 12-month warranty.', 18526.06, 13728.92, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(203, 4, 'TWA90-P71', 'Condura Top Load Fully Automatic Inverter TWA90-P71', 'appliance', '4800000002037', 'Condura Top Load Fully Automatic Inverter washing machine. 12-month warranty.', 29057.37, 20116.71, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(204, 4, 'TWA100-P71', 'Condura Top Load Fully Automatic Inverter TWA100-P71', 'appliance', '4800000002044', 'Condura Top Load Fully Automatic Inverter washing machine. 12-month warranty.', 33550.55, 24507.67, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(205, 4, 'TWA110-P71', 'Condura Top Load Fully Automatic Inverter TWA110-P71', 'appliance', '4800000002051', 'Condura Top Load Fully Automatic Inverter washing machine. 12-month warranty.', 27765.26, 19920.46, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(206, 4, 'TWA130-P70', 'Condura Top Load Fully Automatic Inverter TWA130-P70', 'appliance', '4800000002068', 'Condura Top Load Fully Automatic Inverter washing machine. 12-month warranty.', 17314.58, 11867.41, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(207, 4, 'TWF60-P60', 'Condura Front Load Fully Automatic Inverter TWF60-P60', 'appliance', '4800000002075', 'Condura Front Load Fully Automatic Inverter washing machine. 12-month warranty.', 31947.40, 24824.73, 10, 4, 25, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(208, 4, 'TWF65-P60', 'Condura Front Load Fully Automatic Inverter TWF65-P60', 'appliance', '4800000002082', 'Condura Front Load Fully Automatic Inverter washing machine. 12-month warranty.', 19440.53, 15057.08, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(209, 4, 'TWF75-P60', 'Condura Front Load Fully Automatic Inverter TWF75-P60', 'appliance', '4800000002099', 'Condura Front Load Fully Automatic Inverter washing machine. 12-month warranty.', 24436.21, 18863.04, 9, 3, 23, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(210, 4, 'TWF85-P60', 'Condura Front Load Fully Automatic Inverter TWF85-P60', 'appliance', '4800000002105', 'Condura Front Load Fully Automatic Inverter washing machine. 12-month warranty.', 23792.41, 17051.78, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(211, 4, 'TWF105-C20', 'Condura Front Load Fully Automatic Inverter Commercial TWF105-C20', 'appliance', '4800000002112', 'Condura Front Load Fully Automatic Inverter Commercial washing machine. 12-month warranty.', 24838.08, 18094.04, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(212, 4, 'TWD120-C682DG', 'Condura Front Load Fully Automatic Inverter Commercial TWD120-C682DG', 'appliance', '4800000002129', 'Condura Front Load Fully Automatic Inverter Commercial washing machine. 12-month warranty.', 31229.95, 22474.95, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 03:51:04', NULL),
(213, 9, 'TY-LWYR87W', 'Condura Water Dispenser Top Load TY-LWYR87W', 'appliance', '4800000002136', 'Condura Top Load home appliance. 12-month warranty.', 1537.17, 1135.95, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:31:34', NULL),
(214, 9, 'TY-LWYR110T', 'Condura Water Dispenser Bottom Load TY-LWYR110T', 'appliance', '4800000002143', 'Condura Bottom Load home appliance. 12-month warranty.', 1886.89, 1387.90, 5, 2, 13, 3, '2026-06-29 16:00:00', '2026-07-07 17:31:34', NULL),
(215, 10, 'PROD-215', 'Copper Tube 1/4 CT (Roll)', 'installation_material', '4800000002150', 'Copper Tube 1/4 CT (Roll).', 1893.15, 1443.15, 134, 47, 335, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(216, 10, 'PROD-216', 'Copper Tube 1/4 CT (Per Foot)', 'installation_material', '4800000002167', 'Copper Tube 1/4 CT (Per Foot).', 79.97, 62.10, 170, 60, 425, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(217, 10, 'PROD-217', 'Copper Tube 3/8 CT (Roll)', 'installation_material', '4800000002174', 'Copper Tube 3/8 CT (Roll).', 1220.68, 842.72, 32, 11, 80, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(218, 10, 'PROD-218', 'Copper Tube 3/8 CT (Per Foot)', 'installation_material', '4800000002181', 'Copper Tube 3/8 CT (Per Foot).', 69.71, 50.11, 111, 39, 278, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(219, 10, 'PROD-219', 'Copper Tube 1/2 CT (Roll)', 'installation_material', '4800000002198', 'Copper Tube 1/2 CT (Roll).', 1464.96, 1101.84, 165, 58, 413, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(220, 10, 'PROD-220', 'Copper Tube 1/2 CT (Per Foot)', 'installation_material', '4800000002204', 'Copper Tube 1/2 CT (Per Foot).', 114.52, 80.98, 146, 51, 365, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(221, 10, 'PROD-221', 'Copper Tube 5/8 CT (Roll)', 'installation_material', '4800000002211', 'Copper Tube 5/8 CT (Roll).', 1773.30, 1215.44, 105, 37, 263, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(222, 10, 'PROD-222', 'Copper Tube 5/8 CT (Per Foot)', 'installation_material', '4800000002228', 'Copper Tube 5/8 CT (Per Foot).', 78.31, 54.33, 67, 23, 168, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(223, 10, 'PROD-223', 'Copper Tube 3/4 CT (Roll)', 'installation_material', '4800000002235', 'Copper Tube 3/4 CT (Roll).', 2052.00, 1575.07, 176, 62, 440, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(224, 10, 'PROD-224', 'Copper Tube 3/4 CT (Per Foot)', 'installation_material', '4800000002242', 'Copper Tube 3/4 CT (Per Foot).', 81.40, 57.64, 184, 64, 460, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(225, 11, 'PROD-225', 'PE Foam 1/4\"', 'installation_material', '4800000002259', 'PE Foam 1/4\".', 151.15, 107.37, 79, 28, 198, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(226, 11, 'PROD-226', 'PE Foam 3/8\"', 'installation_material', '4800000002266', 'PE Foam 3/8\".', 307.19, 235.25, 120, 42, 300, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(227, 11, 'PROD-227', 'PE Foam 1/2\"', 'installation_material', '4800000002273', 'PE Foam 1/2\".', 406.96, 311.63, 141, 49, 353, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(228, 11, 'PROD-228', 'PE Foam 5/8\"', 'installation_material', '4800000002280', 'PE Foam 5/8\".', 219.53, 154.38, 59, 21, 148, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(229, 11, 'PROD-229', 'PE Foam 3/4\"', 'installation_material', '4800000002297', 'PE Foam 3/4\".', 311.59, 234.07, 124, 43, 310, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(230, 12, 'PROD-230', 'Rubber Insulation 1/4\" x 1/2', 'installation_material', '4800000002303', 'Rubber Insulation 1/4\" x 1/2.', 378.33, 268.21, 192, 67, 480, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(231, 12, 'PROD-231', 'Rubber Insulation 3/8\" x 1/2', 'installation_material', '4800000002310', 'Rubber Insulation 3/8\" x 1/2.', 382.65, 284.91, 75, 26, 188, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(232, 12, 'PROD-232', 'Rubber Insulation 1/2\" x 1/2', 'installation_material', '4800000002327', 'Rubber Insulation 1/2\" x 1/2.', 331.83, 244.67, 67, 23, 168, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(233, 12, 'PROD-233', 'Rubber Insulation 5/8\" x 1/2', 'installation_material', '4800000002334', 'Rubber Insulation 5/8\" x 1/2.', 263.76, 192.66, 166, 58, 415, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(234, 12, 'PROD-234', 'Rubber Insulation 3/4\" x 1/2', 'installation_material', '4800000002341', 'Rubber Insulation 3/4\" x 1/2.', 294.59, 211.62, 158, 55, 395, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(235, 12, 'PROD-235', 'Rubber Insulation 1/4\" x 3/4', 'installation_material', '4800000002358', 'Rubber Insulation 1/4\" x 3/4.', 334.29, 228.38, 63, 22, 158, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(236, 12, 'PROD-236', 'Rubber Insulation 3/8\" x 3/4', 'installation_material', '4800000002365', 'Rubber Insulation 3/8\" x 3/4.', 362.62, 260.28, 84, 29, 210, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(237, 12, 'PROD-237', 'Rubber Insulation 1/2\" x 3/4', 'installation_material', '4800000002372', 'Rubber Insulation 1/2\" x 3/4.', 362.37, 268.98, 181, 63, 453, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(238, 12, 'PROD-238', 'Rubber Insulation 5/8\" x 3/4', 'installation_material', '4800000002389', 'Rubber Insulation 5/8\" x 3/4.', 305.44, 233.65, 163, 57, 408, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(239, 13, 'PROD-239', 'Bracket Iron Painted (1HP-1.5HP)', 'installation_material', '4800000002396', 'Bracket Iron Painted (1HP-1.5HP).', 96.44, 69.80, 87, 30, 218, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(240, 13, 'PROD-240', 'Bracket Iron Painted (2HP-3HP)', 'installation_material', '4800000002402', 'Bracket Iron Painted (2HP-3HP).', 116.80, 81.86, 76, 27, 190, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(241, 13, 'PROD-241', 'Bracket White Powder (1HP-1.5HP)', 'installation_material', '4800000002419', 'Bracket White Powder (1HP-1.5HP).', 116.58, 84.13, 71, 25, 178, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(242, 13, 'PROD-242', 'Bracket White Powder (2HP-3HP)', 'installation_material', '4800000002426', 'Bracket White Powder (2HP-3HP).', 91.31, 63.53, 37, 13, 93, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(243, 14, 'PROD-243', 'PVC/Polyethylene Tape', 'installation_material', '4800000002433', 'PVC/Polyethylene Tape.', 137.73, 98.12, 108, 38, 270, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(244, 14, 'PROD-244', 'Spray Paint', 'installation_material', '4800000002440', 'Spray Paint.', 137.87, 107.37, 56, 20, 140, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(245, 14, 'PROD-245', 'Solvent', 'installation_material', '4800000002457', 'Solvent.', 618.63, 442.05, 45, 16, 113, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(246, 14, 'PROD-246', 'Elastoseal', 'installation_material', '4800000002464', 'Elastoseal.', 618.81, 440.49, 60, 21, 150, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(247, 14, 'PROD-247', 'Welding Rod', 'installation_material', '4800000002471', 'Welding Rod.', 459.23, 332.01, 117, 41, 293, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(248, 14, 'PROD-248', 'Welding Electrodes', 'installation_material', '4800000002488', 'Welding Electrodes.', 334.59, 234.69, 137, 48, 343, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(249, 14, 'PROD-249', 'Electrical Clip', 'installation_material', '4800000002495', 'Electrical Clip.', 556.51, 415.61, 164, 57, 410, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(250, 14, 'PROD-250', 'Cutting Disc', 'installation_material', '4800000002501', 'Cutting Disc.', 121.02, 92.52, 157, 55, 393, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(251, 14, 'PROD-251', 'Angle Bar (1/2 length)', 'installation_material', '4800000002518', 'Angle Bar (1/2 length).', 308.99, 211.06, 181, 63, 453, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(252, 14, 'PROD-252', 'Silver Rod', 'installation_material', '4800000002525', 'Silver Rod.', 298.72, 227.97, 171, 60, 428, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(253, 14, 'PROD-253', 'Insulation Tape (Prime) (Box)', 'installation_material', '4800000002532', 'Insulation Tape (Prime) (Box).', 293.85, 210.28, 127, 44, 318, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(254, 14, 'PROD-254', 'Insulation Tape (Prime) (Per Foot)', 'installation_material', '4800000002549', 'Insulation Tape (Prime) (Per Foot).', 356.32, 260.09, 123, 43, 308, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(255, 15, 'PROD-255', 'Electrical Tape 8 mtrs', 'installation_material', '4800000002556', 'Electrical Tape 8 mtrs.', 448.45, 318.10, 56, 20, 140, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(256, 15, 'PROD-256', 'Royal Cord #16/4 (Roll)', 'installation_material', '4800000002563', 'Royal Cord #16/4 (Roll).', 280.14, 214.77, 144, 50, 360, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(257, 15, 'PROD-257', 'Royal Cord #16/4 (Per Meter)', 'installation_material', '4800000002570', 'Royal Cord #16/4 (Per Meter).', 698.51, 530.52, 172, 60, 430, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(258, 15, 'PROD-258', 'Royal Cord #14/4 (Roll)', 'installation_material', '4800000002587', 'Royal Cord #14/4 (Roll).', 235.05, 162.04, 159, 56, 398, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(259, 15, 'PROD-259', 'Royal Cord #14/4 (Per Meter)', 'installation_material', '4800000002594', 'Royal Cord #14/4 (Per Meter).', 341.15, 249.11, 111, 39, 278, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(260, 15, 'PROD-260', 'THHN Wire #14 (Box)', 'installation_material', '4800000002600', 'THHN Wire #14 (Box).', 417.63, 284.97, 168, 59, 420, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(261, 15, 'PROD-261', 'THHN Wire #14 (Per Meter)', 'installation_material', '4800000002617', 'THHN Wire #14 (Per Meter).', 81.51, 62.49, 33, 12, 83, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(262, 15, 'PROD-262', 'THHN Wire #12 (Box)', 'installation_material', '4800000002624', 'THHN Wire #12 (Box).', 52.13, 39.83, 174, 61, 435, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(263, 15, 'PROD-263', 'THHN Wire #12 (Per Meter)', 'installation_material', '4800000002631', 'THHN Wire #12 (Per Meter).', 454.27, 350.50, 185, 65, 463, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(264, 15, 'PROD-264', 'THHN Wire #10 (Box)', 'installation_material', '4800000002648', 'THHN Wire #10 (Box).', 722.78, 514.83, 176, 62, 440, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(265, 15, 'PROD-265', 'THHN Wire #10 (Per Meter)', 'installation_material', '4800000002655', 'THHN Wire #10 (Per Meter).', 305.87, 234.44, 54, 19, 135, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(266, 15, 'PROD-266', 'Mouldflex 1/2 (Roll)', 'installation_material', '4800000002662', 'Mouldflex 1/2 (Roll).', 731.47, 559.59, 65, 23, 163, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(267, 15, 'PROD-267', 'Mouldflex 1/2 (Per Meter)', 'installation_material', '4800000002679', 'Mouldflex 1/2 (Per Meter).', 446.83, 332.74, 81, 28, 203, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(268, 16, 'PROD-268', 'Expansion Bolt 3/8', 'installation_material', '4800000002686', 'Expansion Bolt 3/8.', 318.82, 220.80, 198, 69, 495, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(269, 16, 'PROD-269', 'Conduit Clamp 1.5\' (nipis)', 'installation_material', '4800000002693', 'Conduit Clamp 1.5\' (nipis).', 402.29, 278.27, 161, 56, 403, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(270, 16, 'PROD-270', 'Conduit Clamp 2\' (nipis)', 'installation_material', '4800000002709', 'Conduit Clamp 2\' (nipis).', 233.56, 164.65, 151, 53, 378, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(271, 16, 'PROD-271', 'Conduit Clamp 1.5\' (baga)', 'installation_material', '4800000002716', 'Conduit Clamp 1.5\' (baga).', 125.55, 97.80, 200, 70, 500, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(272, 16, 'PROD-272', 'Conduit Clamp 2\' (baga)', 'installation_material', '4800000002723', 'Conduit Clamp 2\' (baga).', 239.50, 180.84, 77, 27, 193, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(273, 16, 'PROD-273', 'Bolt 1/4 x 1.5 Ord.', 'installation_material', '4800000002730', 'Bolt 1/4 x 1.5 Ord..', 721.17, 555.25, 159, 56, 398, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(274, 16, 'PROD-274', 'Nut 1/4 x 1.5', 'installation_material', '4800000002747', 'Nut 1/4 x 1.5.', 148.37, 113.82, 112, 39, 280, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(275, 16, 'PROD-275', 'Washer', 'installation_material', '4800000002754', 'Washer.', 276.39, 200.23, 90, 32, 225, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(276, 16, 'PROD-276', 'Tox #6', 'installation_material', '4800000002761', 'Tox #6.', 476.32, 327.87, 120, 42, 300, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(277, 16, 'PROD-277', 'Metal Screw 8 x 1', 'installation_material', '4800000002778', 'Metal Screw 8 x 1.', 191.80, 132.09, 123, 43, 308, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(278, 16, 'PROD-278', 'Text Screw (1 1/2\")', 'installation_material', '4800000002785', 'Text Screw (1 1/2\").', 386.36, 278.13, 184, 64, 460, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(279, 16, 'PROD-279', 'Text Screw #3', 'installation_material', '4800000002792', 'Text Screw #3.', 465.47, 337.93, 95, 33, 238, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(280, 16, 'PROD-280', 'Text Wood Screw (1.5\")', 'installation_material', '4800000002808', 'Text Wood Screw (1.5\").', 344.00, 256.18, 92, 32, 230, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(281, 16, 'PROD-281', 'Text Wood Screw (2.0\")', 'installation_material', '4800000002815', 'Text Wood Screw (2.0\").', 762.74, 579.95, 178, 62, 445, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(282, 16, 'PROD-282', 'Text Wood Screw (2 1/2\")', 'installation_material', '4800000002822', 'Text Wood Screw (2 1/2\").', 657.54, 508.44, 148, 52, 370, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(283, 16, 'PROD-283', 'Access Valve', 'installation_material', '4800000002839', 'Access Valve.', 432.18, 322.54, 152, 53, 380, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(284, 17, 'PROD-284', 'MAPP Gas', 'installation_material', '4800000002846', 'MAPP Gas.', 705.42, 481.15, 200, 70, 500, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(285, 17, 'PROD-285', 'R32 Refrigerant', 'installation_material', '4800000002853', 'R32 Refrigerant.', 105.43, 78.95, 184, 64, 460, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(286, 17, 'PROD-286', 'R-410A Refrigerant', 'installation_material', '4800000002860', 'R-410A Refrigerant.', 51.22, 34.92, 46, 16, 115, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(287, 17, 'PROD-287', 'R-134A Refrigerant', 'installation_material', '4800000002877', 'R-134A Refrigerant.', 473.50, 335.30, 127, 44, 318, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(288, 18, 'PROD-288', 'PVC Clamp 3/4\"', 'installation_material', '4800000002884', 'PVC Clamp 3/4\".', 623.26, 479.53, 148, 52, 370, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(289, 18, 'PROD-289', 'PVC Elbow 3/4\"', 'installation_material', '4800000002891', 'PVC Elbow 3/4\".', 628.72, 446.03, 151, 53, 378, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(290, 18, 'PROD-290', 'PVC Tee 3/4\"', 'installation_material', '4800000002907', 'PVC Tee 3/4\".', 537.88, 366.20, 160, 56, 400, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(291, 18, 'PROD-291', 'PVC Clamp 1/2\"', 'installation_material', '4800000002914', 'PVC Clamp 1/2\".', 687.53, 480.11, 39, 14, 98, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(292, 18, 'PROD-292', 'PVC Elbow 1/2\"', 'installation_material', '4800000002921', 'PVC Elbow 1/2\".', 574.71, 441.15, 129, 45, 323, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(293, 18, 'PROD-293', 'PVC Pipe 1/2\"', 'installation_material', '4800000002938', 'PVC Pipe 1/2\".', 120.14, 81.77, 82, 29, 205, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(294, 18, 'PROD-294', 'PVC Pipe 3/4\"', 'installation_material', '4800000002945', 'PVC Pipe 3/4\".', 637.50, 452.09, 50, 18, 125, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(295, 18, 'PROD-295', 'PVC Elbow Big', 'installation_material', '4800000002952', 'PVC Elbow Big.', 284.91, 204.55, 85, 30, 213, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(296, 18, 'PROD-296', 'PVC Cladding', 'installation_material', '4800000002969', 'PVC Cladding.', 63.50, 48.71, 179, 63, 448, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(297, 18, 'PROD-297', 'PVC Tee 1/2', 'installation_material', '4800000002976', 'PVC Tee 1/2.', 756.10, 584.87, 125, 44, 313, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(298, 19, 'PROD-298', 'Capacitor 15+ 3UF', 'installation_material', '4800000002983', 'Capacitor 15+ 3UF.', 816.44, 580.64, 89, 31, 223, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(299, 19, 'PROD-299', 'Capacitor 17+ 2UF', 'installation_material', '4800000002990', 'Capacitor 17+ 2UF.', 733.27, 511.13, 166, 58, 415, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(300, 19, 'PROD-300', 'Capacitor 20+ 1.5UF', 'installation_material', '4800000003003', 'Capacitor 20+ 1.5UF.', 548.41, 423.89, 92, 32, 230, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(301, 19, 'PROD-301', 'Capacitor 20+ 4UF', 'installation_material', '4800000003010', 'Capacitor 20+ 4UF.', 776.31, 601.38, 97, 34, 243, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(302, 19, 'PROD-302', 'Capacitor 25UF', 'installation_material', '4800000003027', 'Capacitor 25UF.', 751.45, 526.05, 86, 30, 215, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(303, 19, 'PROD-303', 'Capacitor 30UF', 'installation_material', '4800000003034', 'Capacitor 30UF.', 751.60, 538.39, 186, 65, 465, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(304, 19, 'PROD-304', 'Capacitor 30+ 3UF', 'installation_material', '4800000003041', 'Capacitor 30+ 3UF.', 123.27, 90.58, 49, 17, 122, 3, '2026-06-29 16:00:00', '2026-07-19 06:13:09', NULL),
(305, 19, 'PROD-305', 'Capacitor 30+ 5UF', 'installation_material', '4800000003058', 'Capacitor 30+ 5UF.', 327.98, 243.53, 173, 61, 433, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(306, 19, 'PROD-306', 'Capacitor 40UF', 'installation_material', '4800000003065', 'Capacitor 40UF.', 110.99, 81.60, 170, 60, 425, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(307, 19, 'PROD-307', 'Capacitor 60+ 5UF', 'installation_material', '4800000003072', 'Capacitor 60+ 5UF.', 789.52, 596.30, 177, 62, 443, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(308, 19, 'PROD-308', 'Capacitor 60UF', 'installation_material', '4800000003089', 'Capacitor 60UF.', 249.20, 185.13, 78, 27, 195, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(309, 19, 'PROD-309', 'Capacitor 45UF', 'installation_material', '4800000003096', 'Capacitor 45UF.', 716.61, 493.08, 51, 18, 128, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(310, 19, 'PROD-310', 'Capacitor 4UF', 'installation_material', '4800000003102', 'Capacitor 4UF.', 517.84, 380.61, 93, 33, 233, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(311, 19, 'PROD-311', 'Capacitor 5UF', 'installation_material', '4800000003119', 'Capacitor 5UF.', 528.81, 360.51, 69, 24, 173, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(312, 19, 'PROD-312', 'Capacitor 10UF', 'installation_material', '4800000003126', 'Capacitor 10UF.', 595.26, 412.87, 39, 14, 98, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(313, 15, 'PROD-313', 'Circuit Breaker 20A Bolt On 2P', 'installation_material', '4800000003133', 'Circuit Breaker 20A Bolt On 2P.', 639.40, 471.45, 83, 29, 208, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(314, 15, 'PROD-314', 'Circuit Breaker 30A Bolt On 2P', 'installation_material', '4800000003140', 'Circuit Breaker 30A Bolt On 2P.', 737.51, 562.73, 171, 60, 428, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(315, 15, 'PROD-315', 'Circuit Breaker 40A Bolt On 2P', 'installation_material', '4800000003157', 'Circuit Breaker 40A Bolt On 2P.', 445.60, 339.26, 131, 46, 328, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(316, 15, 'PROD-316', 'Circuit Breaker 20A Plug In 2P', 'installation_material', '4800000003164', 'Circuit Breaker 20A Plug In 2P.', 437.73, 304.20, 159, 56, 398, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(317, 15, 'PROD-317', 'Circuit Breaker 30A Plug In 2P', 'installation_material', '4800000003171', 'Circuit Breaker 30A Plug In 2P.', 383.35, 263.76, 195, 68, 488, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(318, 15, 'PROD-318', 'Circuit Breaker 40A Plug In 2P', 'installation_material', '4800000003188', 'Circuit Breaker 40A Plug In 2P.', 310.38, 217.04, 130, 46, 325, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(319, 15, 'PROD-319', 'Safety Breaker w/ Housing Indoor 20A', 'installation_material', '4800000003195', 'Safety Breaker w/ Housing Indoor 20A.', 368.54, 269.49, 178, 62, 445, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(320, 15, 'PROD-320', 'Safety Breaker w/ Housing Indoor 30A', 'installation_material', '4800000003201', 'Safety Breaker w/ Housing Indoor 30A.', 532.59, 386.99, 90, 32, 225, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(321, 15, 'PROD-321', 'Breaker Enclosure Nema Bolt On', 'installation_material', '4800000003218', 'Breaker Enclosure Nema Bolt On.', 380.41, 268.61, 118, 41, 295, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(322, 15, 'PROD-322', 'Breaker Enclosure Nema Plug In', 'installation_material', '4800000003225', 'Breaker Enclosure Nema Plug In.', 478.69, 327.95, 71, 25, 178, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(323, 15, 'PROD-323', 'Nema Type 3R', 'installation_material', '4800000003232', 'Nema Type 3R.', 298.17, 210.65, 124, 43, 310, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(324, 15, 'PROD-324', 'Drain Hose (Roll)', 'installation_material', '4800000003249', 'Drain Hose (Roll).', 773.45, 527.18, 79, 28, 198, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(325, 15, 'PROD-325', 'Drain Hose (Per Meter)', 'installation_material', '4800000003256', 'Drain Hose (Per Meter).', 112.25, 81.04, 30, 11, 75, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(326, 15, 'PROD-326', 'Copper Elbow 1/4', 'installation_material', '4800000003263', 'Copper Elbow 1/4.', 346.03, 262.19, 63, 22, 158, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(327, 15, 'PROD-327', 'Copper Elbow 1/2', 'installation_material', '4800000003270', 'Copper Elbow 1/2.', 532.24, 404.75, 47, 16, 118, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(328, 15, 'PROD-328', 'Copper Elbow 3/4', 'installation_material', '4800000003287', 'Copper Elbow 3/4.', 649.31, 444.82, 111, 39, 278, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(329, 15, 'PROD-329', 'Copper Elbow 3/8', 'installation_material', '4800000003294', 'Copper Elbow 3/8.', 282.27, 197.09, 41, 14, 103, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(330, 15, 'PROD-330', 'Copper Elbow 5/8', 'installation_material', '4800000003300', 'Copper Elbow 5/8.', 552.99, 409.40, 148, 52, 370, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(331, 15, 'PROD-331', 'Copper Coupling 1/4', 'installation_material', '4800000003317', 'Copper Coupling 1/4.', 751.89, 547.67, 163, 57, 408, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(332, 15, 'PROD-332', 'Copper Coupling 1/2', 'installation_material', '4800000003324', 'Copper Coupling 1/2.', 479.03, 332.99, 150, 53, 375, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(333, 15, 'PROD-333', 'Copper Coupling 3/4', 'installation_material', '4800000003331', 'Copper Coupling 3/4.', 469.26, 322.38, 132, 46, 330, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(334, 15, 'PROD-334', 'Copper Coupling 3/8', 'installation_material', '4800000003348', 'Copper Coupling 3/8.', 259.40, 194.33, 177, 62, 443, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(335, 15, 'PROD-335', 'Copper Coupling 5/8', 'installation_material', '4800000003355', 'Copper Coupling 5/8.', 612.60, 435.69, 199, 70, 498, 3, '2026-06-29 16:00:00', '2026-07-07 18:15:56', NULL),
(336, 21, 'PROD-336', 'Tube Cutter (Aruki)', 'installation_material', '4800000003362', 'Tube Cutter (Aruki).', 742.38, 535.69, 39, 14, 98, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(337, 21, 'PROD-337', 'Hand Torch Mapgas', 'installation_material', '4800000003379', 'Hand Torch Mapgas.', 447.83, 317.04, 36, 13, 90, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(338, 21, 'PROD-338', 'Tube Bender 1/4', 'installation_material', '4800000003386', 'Tube Bender 1/4.', 125.07, 89.61, 150, 53, 375, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(339, 21, 'PROD-339', 'Tube Bender 3/8', 'installation_material', '4800000003393', 'Tube Bender 3/8.', 550.42, 385.00, 132, 46, 330, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(340, 21, 'PROD-340', 'Tube Bender 1/2', 'installation_material', '4800000003409', 'Tube Bender 1/2.', 476.79, 347.77, 130, 46, 325, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(341, 21, 'PROD-341', 'Tube Bender 5/8', 'installation_material', '4800000003416', 'Tube Bender 5/8.', 547.33, 426.09, 66, 23, 165, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL),
(342, 21, 'PROD-342', 'Tube Bender 3/4', 'installation_material', '4800000003423', 'Tube Bender 3/4.', 571.98, 445.36, 90, 32, 225, 3, '2026-06-29 16:00:00', '2026-07-07 17:50:59', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `product_sets`
--

CREATE TABLE `product_sets` (
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `capacity_rating` varchar(60) DEFAULT NULL,
  `variant` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_set_brands`
--

CREATE TABLE `product_set_brands` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `brand_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `product_set_lines`
--

CREATE TABLE `product_set_lines` (
  `set_line_id` bigint(20) UNSIGNED NOT NULL,
  `set_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `spec_note` varchar(191) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `profit_loss`
--

CREATE TABLE `profit_loss` (
  `profit_loss_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `incident_date` date DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity_lost` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_loss_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` bigint(20) UNSIGNED NOT NULL,
  `pr_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Source Purchase Request (if converted from PR)',
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_number` varchar(50) NOT NULL,
  `order_date` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `payment_terms` varchar(50) DEFAULT 'Net 30',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `references_note` text DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Inventory Analyst',
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Admin',
  `verified_by` bigint(20) UNSIGNED DEFAULT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_at` timestamp NULL DEFAULT NULL,
  `verification_note` varchar(255) DEFAULT NULL,
  `ship_to_name` varchar(255) DEFAULT NULL COMMENT 'Custom delivery name override for export document',
  `ship_to_address` text DEFAULT NULL COMMENT 'Custom delivery address override for export document',
  `approval_notes` text DEFAULT NULL,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Warehouse Personnel',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_details`
--

CREATE TABLE `purchase_order_details` (
  `po_detail_id` bigint(20) UNSIGNED NOT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_ordered` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_requests`
--

CREATE TABLE `purchase_requests` (
  `pr_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `budget_id` bigint(20) UNSIGNED DEFAULT NULL,
  `pr_number` varchar(50) NOT NULL,
  `request_date` date DEFAULT NULL,
  `needed_by_date` date DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `preferred_supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `estimated_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT '13=Pending 14=Approved 15=Rejected 16=Partially Approved',
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Inventory Analyst',
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Admin',
  `approval_date` date DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `converted_to_po_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Filled once PR is converted to a PO',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_request_details`
--

CREATE TABLE `purchase_request_details` (
  `pr_detail_id` bigint(20) UNSIGNED NOT NULL,
  `pr_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `estimated_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
  `purchase_return_id` bigint(20) UNSIGNED NOT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `return_number` varchar(50) NOT NULL,
  `return_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_details`
--

CREATE TABLE `purchase_return_details` (
  `pr_return_detail_id` bigint(20) UNSIGNED NOT NULL,
  `purchase_return_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_returned` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `condition` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receivings`
--

CREATE TABLE `receivings` (
  `receiving_id` bigint(20) UNSIGNED NOT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receiving_number` varchar(50) NOT NULL,
  `receiving_date` date DEFAULT NULL,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `total_quantity_received` int(11) NOT NULL DEFAULT 0,
  `total_quantity_damaged` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receiving_details`
--

CREATE TABLE `receiving_details` (
  `receiving_detail_id` bigint(20) UNSIGNED NOT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_amount` int(11) NOT NULL DEFAULT 0,
  `prod_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `condition` varchar(100) DEFAULT NULL,
  `scanned_barcode` varchar(191) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restock_recommendations`
--

CREATE TABLE `restock_recommendations` (
  `recommendation_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
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
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`role_id`, `role_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'admin', 'System Administrator — full access', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(2, 'inventory_analyst', 'Inventory Analyst — creates PRs and POs', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(3, 'branch_manager', 'Branch / Location Manager', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(4, 'warehouse_personnel', 'Warehouse Personnel — scan and move stock', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(5, 'auditor', 'Auditor — read-only audit access', '2026-04-22 21:50:17', '2026-04-22 21:50:17');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `is_granted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `is_granted`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2026-06-13 07:38:07', '2026-06-13 07:38:07'),
(2, 5, 1, 0, '2026-06-13 07:38:07', '2026-06-29 01:48:10'),
(3, 3, 1, 0, '2026-06-13 07:38:07', '2026-06-13 17:48:13'),
(4, 2, 1, 0, '2026-06-13 07:38:07', '2026-06-13 07:38:07'),
(5, 4, 1, 0, '2026-06-13 07:38:07', '2026-06-23 00:35:17'),
(6, 1, 2, 1, '2026-06-13 07:38:07', '2026-06-13 07:38:07'),
(7, 5, 2, 0, '2026-06-13 07:38:07', '2026-06-13 07:38:07'),
(8, 3, 2, 0, '2026-06-13 07:38:07', '2026-06-13 17:48:13'),
(9, 2, 2, 0, '2026-06-13 07:38:07', '2026-06-13 07:38:07'),
(10, 4, 2, 0, '2026-06-13 07:38:07', '2026-06-13 16:05:23'),
(11, 1, 3, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(12, 5, 3, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(13, 3, 3, 0, '2026-06-13 07:38:08', '2026-06-13 16:13:48'),
(14, 2, 3, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(15, 4, 3, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(16, 1, 4, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(17, 5, 4, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(18, 3, 4, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(19, 2, 4, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(20, 4, 4, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(21, 1, 5, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(22, 5, 5, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(23, 3, 5, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(24, 2, 5, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(25, 4, 5, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(26, 1, 6, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(27, 5, 6, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(28, 3, 6, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(29, 2, 6, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(30, 4, 6, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(31, 1, 7, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(32, 5, 7, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(33, 3, 7, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(34, 2, 7, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(35, 4, 7, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(36, 1, 8, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(37, 5, 8, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(38, 3, 8, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(39, 2, 8, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(40, 4, 8, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(41, 1, 9, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(42, 5, 9, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(43, 3, 9, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(44, 2, 9, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(45, 4, 9, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(46, 1, 10, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(47, 5, 10, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(48, 3, 10, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(49, 2, 10, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(50, 4, 10, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(51, 1, 11, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(52, 5, 11, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(53, 3, 11, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(54, 2, 11, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(55, 4, 11, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(61, 1, 13, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(62, 5, 13, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(63, 3, 13, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(64, 2, 13, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(65, 4, 13, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(66, 1, 14, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(67, 5, 14, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(68, 3, 14, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(69, 2, 14, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(70, 4, 14, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(71, 1, 15, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(72, 5, 15, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(73, 3, 15, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(74, 2, 15, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(75, 4, 15, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(91, 1, 19, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(92, 5, 19, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(93, 3, 19, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(94, 2, 19, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(95, 4, 19, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(96, 1, 20, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(97, 5, 20, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(98, 3, 20, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(99, 2, 20, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(100, 4, 20, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(101, 1, 21, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(102, 5, 21, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(103, 3, 21, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(104, 2, 21, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(105, 4, 21, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(106, 1, 22, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(107, 5, 22, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(108, 3, 22, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(109, 2, 22, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(110, 4, 22, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(111, 1, 23, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(112, 5, 23, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(113, 3, 23, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(114, 2, 23, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(115, 4, 23, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(116, 1, 24, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(117, 5, 24, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(118, 3, 24, 0, '2026-06-13 07:38:08', '2026-06-13 17:30:02'),
(119, 2, 24, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(120, 4, 24, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(121, 1, 25, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(122, 5, 25, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(123, 3, 25, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(124, 2, 25, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(125, 4, 25, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(126, 1, 26, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(127, 5, 26, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(128, 3, 26, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(129, 2, 26, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(130, 4, 26, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(131, 1, 27, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(132, 5, 27, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(133, 3, 27, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(134, 2, 27, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(135, 4, 27, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(136, 1, 28, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(137, 5, 28, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(138, 3, 28, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(139, 2, 28, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(140, 4, 28, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(141, 1, 29, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(142, 5, 29, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(143, 3, 29, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(144, 2, 29, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(145, 4, 29, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(146, 1, 30, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(147, 5, 30, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(148, 3, 30, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(149, 2, 30, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(150, 4, 30, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(151, 1, 31, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(152, 5, 31, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(153, 3, 31, 1, '2026-06-13 07:38:08', '2026-07-22 07:38:09'),
(154, 2, 31, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(155, 4, 31, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(156, 1, 32, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(157, 5, 32, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(158, 3, 32, 1, '2026-06-13 07:38:08', '2026-07-22 07:38:09'),
(159, 2, 32, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(160, 4, 32, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(161, 1, 33, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(162, 5, 33, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(163, 3, 33, 1, '2026-06-13 07:38:08', '2026-07-22 07:38:09'),
(164, 2, 33, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(165, 4, 33, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(166, 1, 34, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(167, 5, 34, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(168, 3, 34, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(169, 2, 34, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(170, 4, 34, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(171, 1, 35, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(172, 5, 35, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(173, 3, 35, 1, '2026-06-13 07:38:08', '2026-06-13 16:13:48'),
(174, 2, 35, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(175, 4, 35, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(176, 1, 36, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(177, 5, 36, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(178, 3, 36, 1, '2026-06-13 07:38:08', '2026-06-13 16:13:48'),
(179, 2, 36, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(180, 4, 36, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(181, 1, 37, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(182, 5, 37, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(183, 3, 37, 1, '2026-06-13 07:38:08', '2026-06-13 16:13:48'),
(184, 2, 37, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(185, 4, 37, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(186, 1, 38, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(187, 5, 38, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(188, 3, 38, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(189, 2, 38, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(190, 4, 38, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(191, 1, 39, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(192, 5, 39, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(193, 3, 39, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(194, 2, 39, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(195, 4, 39, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(196, 1, 40, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(197, 5, 40, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(198, 3, 40, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(199, 2, 40, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(200, 4, 40, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(201, 1, 41, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(202, 5, 41, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(203, 3, 41, 0, '2026-06-13 07:38:08', '2026-06-13 17:49:07'),
(204, 2, 41, 0, '2026-06-13 07:38:08', '2026-06-13 16:13:48'),
(205, 4, 41, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(206, 1, 42, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(207, 5, 42, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(208, 3, 42, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(209, 2, 42, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(210, 4, 42, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(211, 1, 43, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(212, 5, 43, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(213, 3, 43, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(214, 2, 43, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(215, 4, 43, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(216, 1, 44, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(217, 5, 44, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(218, 3, 44, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(219, 2, 44, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(220, 4, 44, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(221, 1, 45, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(222, 5, 45, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(223, 3, 45, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(224, 2, 45, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(225, 4, 45, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(226, 1, 46, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(227, 5, 46, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(228, 3, 46, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(229, 2, 46, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(230, 4, 46, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(231, 1, 47, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(232, 5, 47, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(233, 3, 47, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(234, 2, 47, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(235, 4, 47, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(236, 1, 48, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(237, 5, 48, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(238, 3, 48, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(239, 2, 48, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(240, 4, 48, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(241, 1, 49, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(242, 5, 49, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(243, 3, 49, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(244, 2, 49, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(245, 4, 49, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(246, 1, 50, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(247, 5, 50, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(248, 3, 50, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(249, 2, 50, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(250, 4, 50, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(251, 1, 51, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(252, 5, 51, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(253, 3, 51, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(254, 2, 51, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(255, 4, 51, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:23'),
(256, 1, 52, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(257, 5, 52, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(258, 3, 52, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(259, 2, 52, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(260, 4, 52, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(261, 1, 53, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(262, 5, 53, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(263, 3, 53, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(264, 2, 53, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(265, 4, 53, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(271, 1, 55, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(272, 5, 55, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(273, 3, 55, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(274, 2, 55, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(275, 4, 55, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(276, 1, 56, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(277, 5, 56, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(278, 3, 56, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(279, 2, 56, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(280, 4, 56, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(281, 1, 57, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(282, 5, 57, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(283, 3, 57, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(284, 2, 57, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(285, 4, 57, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(286, 1, 58, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(287, 5, 58, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(288, 3, 58, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(289, 2, 58, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(290, 4, 58, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(291, 1, 59, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(292, 5, 59, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(293, 3, 59, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(294, 2, 59, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(295, 4, 59, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(296, 1, 60, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(297, 5, 60, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(298, 3, 60, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(299, 2, 60, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(300, 4, 60, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(301, 1, 61, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(302, 5, 61, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(303, 3, 61, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(304, 2, 61, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(305, 4, 61, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(306, 1, 62, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(307, 5, 62, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(308, 3, 62, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(309, 2, 62, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(310, 4, 62, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(311, 1, 63, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(312, 5, 63, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(313, 3, 63, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(314, 2, 63, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(315, 4, 63, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(316, 1, 64, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(317, 5, 64, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(318, 3, 64, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(319, 2, 64, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(320, 4, 64, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(321, 1, 65, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(322, 5, 65, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(323, 3, 65, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(324, 2, 65, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(325, 4, 65, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(326, 1, 66, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(327, 5, 66, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(328, 3, 66, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(329, 2, 66, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(330, 4, 66, 1, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(331, 1, 67, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(332, 5, 67, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(333, 3, 67, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(334, 2, 67, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(335, 4, 67, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(336, 1, 68, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(337, 5, 68, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(338, 3, 68, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(339, 2, 68, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(340, 4, 68, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(341, 1, 69, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(342, 5, 69, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(343, 3, 69, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(344, 2, 69, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(345, 4, 69, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(346, 1, 70, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(347, 5, 70, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(348, 3, 70, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(349, 2, 70, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(350, 4, 70, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(351, 1, 71, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(352, 5, 71, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(353, 3, 71, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(354, 2, 71, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(355, 4, 71, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(356, 1, 72, 1, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(357, 5, 72, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(358, 3, 72, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(359, 2, 72, 0, '2026-06-13 07:38:08', '2026-06-13 07:38:08'),
(360, 4, 72, 0, '2026-06-13 07:38:08', '2026-06-13 16:05:24'),
(361, 1, 73, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(362, 5, 73, 0, '2026-06-13 15:14:10', '2026-07-22 07:38:09'),
(363, 3, 73, 0, '2026-06-13 15:14:10', '2026-06-13 17:49:07'),
(364, 2, 73, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(365, 4, 73, 0, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(366, 1, 74, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(367, 5, 74, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(368, 3, 74, 0, '2026-06-13 15:14:10', '2026-06-13 17:49:07'),
(369, 2, 74, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(370, 4, 74, 0, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(371, 1, 75, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(372, 5, 75, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(373, 3, 75, 0, '2026-06-13 15:14:10', '2026-06-13 17:49:07'),
(374, 2, 75, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(375, 4, 75, 0, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(376, 1, 76, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(377, 5, 76, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(378, 3, 76, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(379, 2, 76, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(380, 4, 76, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(381, 1, 77, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(382, 5, 77, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(383, 3, 77, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(384, 2, 77, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(385, 4, 77, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(386, 1, 78, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(387, 5, 78, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(388, 3, 78, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(389, 2, 78, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(390, 4, 78, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(391, 1, 79, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(392, 5, 79, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(393, 3, 79, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(394, 2, 79, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(395, 4, 79, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(396, 1, 80, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(397, 5, 80, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(398, 3, 80, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(399, 2, 80, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(400, 4, 80, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(401, 1, 81, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(402, 5, 81, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(403, 3, 81, 1, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(404, 2, 81, 0, '2026-06-13 15:14:10', '2026-06-13 15:14:10'),
(405, 4, 81, 1, '2026-06-13 15:14:10', '2026-06-13 16:05:24'),
(406, 1, 82, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(407, 5, 82, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(408, 3, 82, 0, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(409, 2, 82, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(410, 4, 82, 0, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(411, 1, 83, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(412, 5, 83, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(413, 3, 83, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(414, 2, 83, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(415, 4, 83, 1, '2026-06-13 21:54:24', '2026-06-13 21:54:24'),
(416, 1, 84, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(417, 5, 84, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(418, 3, 84, 1, '2026-07-05 17:12:26', '2026-07-22 07:38:09'),
(419, 2, 84, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(420, 4, 84, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(421, 1, 85, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(422, 5, 85, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(423, 3, 85, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(424, 2, 85, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(425, 4, 85, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(426, 1, 86, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(427, 5, 86, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(428, 3, 86, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(429, 2, 86, 1, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(430, 4, 86, 0, '2026-07-05 17:12:26', '2026-07-05 17:12:26'),
(431, 1, 87, 1, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(432, 5, 87, 1, '2026-07-09 09:45:46', '2026-07-13 12:34:08'),
(433, 3, 87, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(434, 2, 87, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(435, 4, 87, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(436, 1, 88, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(437, 5, 88, 1, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(438, 3, 88, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(439, 2, 88, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(440, 4, 88, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(441, 1, 89, 1, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(442, 5, 89, 1, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(443, 3, 89, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(444, 2, 89, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(445, 4, 89, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(446, 1, 90, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(447, 5, 90, 1, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(448, 3, 90, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(449, 2, 90, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(450, 4, 90, 0, '2026-07-09 09:45:46', '2026-07-09 09:45:46'),
(451, 1, 91, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(452, 5, 91, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(453, 3, 91, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(454, 2, 91, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(455, 4, 91, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(456, 1, 92, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(457, 5, 92, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(458, 3, 92, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(459, 2, 92, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(460, 4, 92, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(461, 1, 93, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(462, 5, 93, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(463, 3, 93, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(464, 2, 93, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(465, 4, 93, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(466, 1, 94, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(467, 5, 94, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(468, 3, 94, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(469, 2, 94, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(470, 4, 94, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(471, 1, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(472, 5, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(473, 3, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(474, 2, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(475, 4, 95, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(476, 1, 96, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(477, 5, 96, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(478, 3, 96, 1, '2026-07-13 12:10:38', '2026-07-22 07:38:09'),
(479, 2, 96, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(480, 4, 96, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(481, 1, 97, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(482, 5, 97, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(483, 3, 97, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(484, 2, 97, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(485, 4, 97, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(486, 1, 98, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(487, 5, 98, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(488, 3, 98, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(489, 2, 98, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(490, 4, 98, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(491, 1, 99, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(492, 5, 99, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(493, 3, 99, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(494, 2, 99, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(495, 4, 99, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(496, 1, 100, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(497, 5, 100, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(498, 3, 100, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(499, 2, 100, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(500, 4, 100, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(501, 1, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(502, 5, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(503, 3, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(504, 2, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(505, 4, 101, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(506, 1, 102, 1, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(507, 5, 102, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(508, 3, 102, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(509, 2, 102, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(510, 4, 102, 0, '2026-07-13 12:10:38', '2026-07-13 12:10:38'),
(511, 1, 103, 1, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(512, 5, 103, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(513, 3, 103, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(514, 2, 103, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(515, 4, 103, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(516, 1, 104, 1, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(517, 5, 104, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(518, 3, 104, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(519, 2, 104, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(520, 4, 104, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(521, 1, 105, 1, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(522, 5, 105, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(523, 3, 105, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(524, 2, 105, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(525, 4, 105, 0, '2026-07-13 12:42:56', '2026-07-13 12:42:56'),
(526, 1, 106, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(527, 5, 106, 1, '2026-07-22 07:38:09', '2026-07-24 08:20:12'),
(528, 3, 106, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(529, 2, 106, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(530, 4, 106, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(531, 1, 107, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(532, 5, 107, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(533, 3, 107, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(534, 2, 107, 0, '2026-07-22 07:38:09', '2026-07-24 08:20:12'),
(535, 4, 107, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(536, 1, 108, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(537, 5, 108, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(538, 3, 108, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(539, 2, 108, 0, '2026-07-22 07:38:09', '2026-07-24 08:20:12'),
(540, 4, 108, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(541, 1, 109, 1, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(542, 5, 109, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(543, 3, 109, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(544, 2, 109, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(545, 4, 109, 0, '2026-07-22 07:38:09', '2026-07-22 07:38:09'),
(546, 1, 110, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(547, 5, 110, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(548, 3, 110, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(549, 2, 110, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(550, 4, 110, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(551, 1, 111, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(552, 5, 111, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(553, 3, 111, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(554, 2, 111, 1, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(555, 4, 111, 0, '2026-07-24 19:10:23', '2026-07-24 19:10:23'),
(556, 1, 112, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(557, 5, 112, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(558, 3, 112, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(559, 2, 112, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(560, 4, 112, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(561, 1, 113, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(562, 5, 113, 0, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(563, 3, 113, 0, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(564, 2, 113, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(565, 4, 113, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(566, 1, 114, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(567, 5, 114, 0, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(568, 3, 114, 0, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(569, 2, 114, 1, '2026-07-25 07:28:46', '2026-07-25 07:28:46'),
(570, 4, 114, 0, '2026-07-25 07:28:46', '2026-07-25 07:28:46');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sales_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `order_date` date DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sold_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_details`
--

CREATE TABLE `sales_details` (
  `sales_detail_id` bigint(20) UNSIGNED NOT NULL,
  `sales_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scan_corrections`
--

CREATE TABLE `scan_corrections` (
  `correction_id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receiving_id` bigint(20) UNSIGNED DEFAULT NULL,
  `original_barcode` varchar(100) DEFAULT NULL COMMENT 'The barcode that was scanned incorrectly',
  `original_product_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Product the barcode was originally matched to',
  `corrected_barcode` varchar(100) DEFAULT NULL COMMENT 'The correct barcode (if barcode changed)',
  `corrected_product_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Product the barcode should have been matched to',
  `correction_type` varchar(50) DEFAULT NULL,
  `quantity_before` int(11) NOT NULL DEFAULT 0,
  `quantity_after` int(11) NOT NULL DEFAULT 0,
  `serial_number_before` varchar(100) DEFAULT NULL,
  `serial_number_after` varchar(100) DEFAULT NULL,
  `receiving_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reason` text DEFAULT NULL COMMENT 'Why this correction was made',
  `corrected_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `status_lookup`
--

CREATE TABLE `status_lookup` (
  `status_id` bigint(20) UNSIGNED NOT NULL,
  `status_name` varchar(50) NOT NULL,
  `status_category` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `status_lookup`
--

INSERT INTO `status_lookup` (`status_id`, `status_name`, `status_category`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Active', 'user', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(2, 'Inactive', 'user', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(3, 'Active', 'product', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(4, 'Inactive', 'product', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(5, 'Active', 'location', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(6, 'Inactive', 'location', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(7, 'Pending', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(8, 'Approved', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(9, 'Rejected', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(10, 'Authorized', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(11, 'Completed', 'purchase_order', 1, '2026-05-01 18:51:20', '2026-05-01 18:51:20'),
(12, 'Partially Received', 'purchase_order', 1, '2026-05-01 18:51:20', '2026-05-01 18:51:20'),
(13, 'Pending', 'purchase_request', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(14, 'Approved', 'purchase_request', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(15, 'Rejected', 'purchase_request', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(16, 'Partially Approved', 'purchase_request', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(17, 'In Transit', 'transfer', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(18, 'Received', 'transfer', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(19, 'Pending', 'adjustment', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(20, 'Approved', 'adjustment', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(21, 'Paid', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(22, 'Unpaid', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(23, 'Partial', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(24, 'Active', 'supplier', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(25, 'Inactive', 'supplier', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(26, 'In Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(27, 'Low Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(28, 'Out of Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(29, 'Rejected', 'adjustment', 1, '2026-05-13 18:38:03', '2026-05-13 18:38:03'),
(30, 'Pending', 'issuance', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(31, 'Completed', 'issuance', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(32, 'Voided', 'issuance', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(33, 'Active', 'warranty', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(34, 'Expired', 'warranty', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(35, 'Claimed', 'warranty', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(36, 'Active', 'customer', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(37, 'Inactive', 'customer', 1, '2026-05-19 12:54:39', '2026-05-19 12:54:39'),
(38, 'Pending', 'transfer', 1, '2026-05-25 12:14:26', '2026-05-25 12:14:26'),
(39, 'Approved', 'transfer', 1, '2026-05-25 12:14:26', '2026-05-25 12:14:26'),
(40, 'Rejected', 'transfer', 1, '2026-05-25 12:14:26', '2026-05-25 12:14:26'),
(41, 'Partially Received', 'transfer', 1, '2026-05-25 12:14:26', '2026-05-25 12:14:26'),
(43, 'Pending', 'warranty_claim', 1, '2026-06-06 17:39:56', '2026-06-06 17:39:56'),
(44, 'Completed', 'warranty_claim', 1, '2026-06-06 17:39:56', '2026-06-06 17:39:56'),
(45, 'Draft', 'issuance', 1, '2026-07-24 09:38:14', '2026-07-24 09:38:14'),
(46, 'Open', 'incident', 1, '2026-07-25 01:37:20', '2026-07-25 01:37:20'),
(47, 'Resolved', 'incident', 1, '2026-07-25 01:37:20', '2026-07-25 01:37:20'),
(48, 'Dismissed', 'incident', 1, '2026-07-25 01:37:20', '2026-07-25 01:37:20'),
(49, 'Cancelled', 'issuance', 1, '2026-07-26 01:46:45', '2026-07-26 01:46:45');

-- --------------------------------------------------------

--
-- Table structure for table `stock_parameter_suggestions`
--

CREATE TABLE `stock_parameter_suggestions` (
  `suggestion_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `current_recommended` int(11) NOT NULL DEFAULT 0,
  `suggested_recommended` int(11) NOT NULL DEFAULT 0,
  `current_safety` int(11) DEFAULT NULL,
  `suggested_safety` int(11) DEFAULT NULL,
  `current_max` int(11) DEFAULT NULL,
  `suggested_max` int(11) DEFAULT NULL,
  `reason_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reason_codes`)),
  `summary` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `origin` varchar(20) NOT NULL DEFAULT 'Local',
  `region` varchar(100) DEFAULT NULL,
  `tin` varchar(50) DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `contact_number`, `email`, `address`, `origin`, `region`, `tin`, `status_id`, `notes`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Supplier China', 'Xijin Ping', '093343566453', 'supplier@gmail.com', 'China', 'Local', NULL, '2222', 24, 'Supplier', '2026-04-25 12:47:51', '2026-04-25 12:47:51', NULL),
(2, 'ABC', 'ABC', '09654554345', 'abc@gmail.com', 'South Korea', 'Local', NULL, 'Net30', 36, NULL, '2026-07-12 03:59:35', '2026-07-12 03:59:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `supplier_product`
--

CREATE TABLE `supplier_product` (
  `supplier_prod_id` bigint(20) UNSIGNED NOT NULL,
  `supplier_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `product_price` decimal(10,2) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'PHP',
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `key`, `value`, `created_at`, `updated_at`) VALUES
(1, 'session_timeout_minutes', '30', '2026-05-11 10:24:02', '2026-05-11 10:24:02'),
(2, 'max_login_attempts', '5', '2026-05-11 10:24:02', '2026-05-11 10:24:02'),
(3, 'two_factor_auth_enabled', '0', '2026-05-11 10:24:03', '2026-05-11 13:40:28'),
(4, 'company_name', 'Airking Air Conditioning', '2026-05-17 20:00:16', '2026-05-17 20:00:16'),
(5, 'company_address', 'CDO Branch Office, Cogon Cagayan de Oro City, Mis.Or', '2026-05-17 20:00:16', '2026-07-12 16:58:51'),
(6, 'company_email', 'cdoairking@gmail.com', '2026-05-17 20:00:16', '2026-05-17 20:00:16'),
(7, 'company_phone', '0934324564', '2026-05-17 20:00:16', '2026-05-17 20:00:16'),
(8, 'archive_config', '{\"purchase_orders\":{\"auto_archive_enabled\":true,\"archive_after_days\":45,\"archivable_statuses\":[9,11],\"retention_days\":365,\"require_reason\":false,\"pinnable\":true},\"purchase_requests\":{\"auto_archive_enabled\":true,\"archive_after_days\":60,\"archivable_statuses\":[14,15,16],\"retention_days\":365,\"require_reason\":false,\"pinnable\":true},\"transfers\":{\"auto_archive_enabled\":true,\"archive_after_days\":90,\"archivable_statuses\":[18],\"retention_days\":365,\"require_reason\":false,\"pinnable\":false},\"adjustments\":{\"auto_archive_enabled\":true,\"archive_after_days\":60,\"archivable_statuses\":[20,29],\"retention_days\":365,\"require_reason\":false,\"pinnable\":false},\"issuances\":{\"auto_archive_enabled\":false,\"archive_after_days\":180,\"archivable_statuses\":[31,32],\"retention_days\":730,\"require_reason\":false,\"pinnable\":false},\"products\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"customers\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"suppliers\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"users\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true},\"locations\":{\"auto_archive_enabled\":false,\"archive_after_days\":null,\"archivable_statuses\":[],\"retention_days\":null,\"require_reason\":true,\"pinnable\":true}}', '2026-05-21 15:18:54', '2026-05-21 07:49:49'),
(9, 'archive_notifications', '{\"notify_on_auto_archive\": true, \"notify_on_retention_run\": true, \"notify_admins_only\": true}', '2026-05-21 15:18:54', '2026-05-21 15:18:54'),
(10, 'archive_schedule', '{\"auto_archive_enabled\":true,\"auto_archive_time\":\"04:00\",\"auto_archive_frequency\":\"daily\",\"retention_enabled\":true,\"retention_frequency\":\"weekly\",\"retention_time\":\"03:00\"}', '2026-05-21 15:18:54', '2026-05-21 07:52:59'),
(11, 'archive_last_run', '{\"ran_at\":\"2026-06-27T12:22:55+00:00\",\"total_archived\":0,\"is_dry_run\":true,\"log_id\":7}', '2026-05-21 07:28:30', '2026-06-27 04:22:55'),
(12, 'account_self_service_policy', '{\"avatar\":true,\"email\":true,\"phone\":true,\"address\":true}', '2026-06-28 22:02:32', '2026-06-29 02:12:21');

-- --------------------------------------------------------

--
-- Table structure for table `transaction_type_lookup`
--

CREATE TABLE `transaction_type_lookup` (
  `transaction_type_id` bigint(20) UNSIGNED NOT NULL,
  `transaction_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transaction_type_lookup`
--

INSERT INTO `transaction_type_lookup` (`transaction_type_id`, `transaction_name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Stock In', 'Receiving inventory from supplier', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(2, 'Stock Out', 'Issuing inventory for operations', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(3, 'Transfer', 'Moving stock between locations', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(4, 'Adjustment', 'Manual inventory count correction', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(5, 'Return to Vendor', 'Returning goods to supplier', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(6, 'Sales', 'Customer purchase transaction', '2026-04-22 21:50:17', '2026-04-22 21:50:17');

-- --------------------------------------------------------

--
-- Table structure for table `transfers`
--

CREATE TABLE `transfers` (
  `transfer_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_request_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = this is a request. Non-null = this is the IST linked to the request.',
  `from_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `intent` varchar(16) NOT NULL DEFAULT 'demand',
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date DEFAULT NULL,
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `prepared_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `total_quantity_transferred` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_quantity_received` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_details`
--

CREATE TABLE `transfer_details` (
  `transfer_detail_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_requested` int(11) NOT NULL DEFAULT 0,
  `quantity_transferred` int(11) NOT NULL DEFAULT 0,
  `quantity_scanned` int(11) NOT NULL DEFAULT 0,
  `quantity_received` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_tracking`
--

CREATE TABLE `transfer_tracking` (
  `tracking_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status_note` varchar(255) DEFAULT NULL,
  `recorded_at` datetime DEFAULT NULL,
  `recorded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `unit_lookup`
--

CREATE TABLE `unit_lookup` (
  `unit_id` bigint(20) UNSIGNED NOT NULL,
  `unit_name` varchar(50) NOT NULL,
  `unit_abbreviation` varchar(10) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `unit_lookup`
--

INSERT INTO `unit_lookup` (`unit_id`, `unit_name`, `unit_abbreviation`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Unit', 'pc', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(2, 'Piece', 'pc', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(3, 'Roll', 'roll', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(4, 'Meter', 'm', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(5, 'Set', 'set', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(6, 'Box', 'box', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(7, 'Kilogram', 'kg', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(8, 'Liter', 'L', '2026-04-26 09:31:36', '2026-04-26 09:31:36', NULL),
(9, 'Quarter Roll', '1/4', '2026-04-26 09:31:36', '2026-04-26 09:31:36', NULL),
(10, '3/8 Roll', '3/8', '2026-04-26 09:31:36', '2026-04-26 09:31:36', NULL),
(11, 'Half Roll', '1/2', '2026-04-26 09:31:36', '2026-04-26 09:31:36', NULL),
(12, 'Foot', 'ft', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(13, 'Can', 'can', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(14, 'Inch', 'in', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(15, 'Pack', 'pack', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(16, 'Drum', 'drum', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(17, 'Carton', 'ctn', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL),
(18, 'Bag', 'bag', '2026-05-22 13:29:31', '2026-05-22 13:29:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Primary assigned location',
  `status_id` bigint(20) UNSIGNED NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `otp_code` varchar(255) DEFAULT NULL,
  `otp_expires_at` timestamp NULL DEFAULT NULL,
  `alerts_last_seen_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `username`, `email`, `phone`, `avatar_path`, `address`, `role_id`, `location_id`, `status_id`, `email_verified_at`, `password_hash`, `remember_token`, `created_at`, `updated_at`, `otp_code`, `otp_expires_at`, `alerts_last_seen_at`, `deleted_at`) VALUES
(2, 'Marichu', 'Contado', 'Admin Marichu', 'adminchu@airking.com', '09123456789', NULL, NULL, 1, NULL, 1, NULL, '$2y$12$UDKW5rtglrWqFOvppxL0P.muDw7vE3W4Npi3M.76So2BlcVaOOi6m', NULL, '2026-04-22 15:17:34', '2026-06-28 10:30:47', '523089', '2026-05-11 13:24:24', '2026-06-23 08:51:39', NULL),
(3, 'Inventory', 'Analyst', 'Inventory Analyst', 'IA@airking.com', '0975543453466', NULL, NULL, 2, 1, 1, NULL, '$2y$12$rEMek7CtkHO3FBWoC8MtI.C0Lo0YduK2A4MJ1A04VWbuW1uFbLoAa', NULL, '2026-04-24 04:11:24', '2026-05-11 12:27:35', '270775', '2026-05-11 12:37:35', NULL, NULL),
(4, 'Branch', 'Manager', 'Branch Manager', 'branch@airking.com', '093343566454', NULL, NULL, 3, 1, 1, NULL, '$2y$12$9Zy7PC.bLFu2uKlGEdO4XeT51GlepW9TtZ84eZk41NdwMInEeQ07i', NULL, '2026-04-24 04:43:44', '2026-06-13 17:44:21', NULL, NULL, NULL, NULL),
(5, 'Admin', 'Ibong', 'Admin Ibong', 'adminibong@gmail.com', '09752267355', NULL, NULL, 1, 2, 1, NULL, '$2y$12$tlHaN./20TxxEl0zykV1o.sn2vadx12vJQEw2w78N2g9h0mNLITgu', NULL, '2026-04-24 05:02:02', '2026-05-21 04:03:05', NULL, NULL, NULL, '2026-05-21 04:03:05'),
(6, 'Warehouse A', 'Personnel', 'WarehouseMan', 'Awarehouse@airking.com', '0912345654', NULL, NULL, 4, 1, 1, NULL, '$2y$12$InbdYmphQnA/7cNrEAhOfOQmvkap6rtTxl4xpAmvvNk4.RfD5Gg6q', NULL, '2026-04-26 13:20:13', '2026-07-19 14:57:28', NULL, NULL, NULL, NULL),
(7, 'Auditor', 'Yan', 'auditor_yan', 'auditor@airking.com', '092345463423', NULL, NULL, 5, 2, 1, NULL, '$2y$12$bpRoo39Tui.8208x5exnGObnTaUb22WvGkadcZEVbTiriSO/9LO6e', NULL, '2026-04-26 13:21:50', '2026-04-26 13:21:50', NULL, NULL, NULL, NULL),
(8, 'Mari', 'Con', 'maricontado', 'contadomarichu@gmail.com', '09664392599', NULL, NULL, 1, 2, 1, NULL, '$2y$12$QzG/4gdhzdQCvF6/meIRa.HBbBGb.T7x06Jx6gQehaGT9AUU0AcsW', NULL, '2026-05-11 13:48:36', '2026-05-11 13:48:36', NULL, NULL, NULL, NULL),
(9, 'Admin', 'Chai', 'AdminChai', 'adminchai@airking.com', '095545366', NULL, NULL, 1, 2, 1, NULL, '$2y$12$NF5Ib8BDWdoY9n1vt5J.hO3xiblJV80Vawl2wGONQ080z3KQ.UBq6', NULL, '2026-05-21 08:31:43', '2026-05-21 08:31:43', NULL, NULL, NULL, NULL),
(10, 'Warehouse B', 'Personnel', 'abcWarehouse', 'Bwarehouse@airking.com', '09324564322', NULL, NULL, 4, 3, 1, NULL, '$2y$12$E5uIf1KnEEfUydtW2StawewfVhiCfPIc7UISECMGFnZ.QZS3HY5cy', NULL, '2026-06-13 00:27:56', '2026-07-21 21:56:41', NULL, NULL, NULL, NULL),
(11, 'T', 'U', 'u_6a5f949d9ec44', '6a5f949d9ec5f@example.test', NULL, NULL, NULL, 3, NULL, 1, NULL, '$2y$12$odPHJ6yds6CTmNaHjVCNZeX3DFqje7o51azMkg3ykOTFXiai4sMCK', NULL, '2026-07-21 07:47:41', '2026-07-21 07:47:41', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_locations`
--

CREATE TABLE `user_locations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_permission_overrides`
--

CREATE TABLE `user_permission_overrides` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `is_granted` tinyint(1) NOT NULL,
  `granted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `granted_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_permission_overrides`
--

INSERT INTO `user_permission_overrides` (`id`, `user_id`, `permission_id`, `is_granted`, `granted_by`, `granted_at`, `revoked_at`, `reason`, `created_at`, `updated_at`) VALUES
(1, 4, 73, 1, 2, '2026-06-13 15:27:55', '2026-06-13 15:49:56', 'okay lng', '2026-06-13 15:27:55', '2026-06-13 15:49:56'),
(2, 4, 75, 1, 2, '2026-06-13 15:47:21', '2026-06-13 15:49:56', 'okay rana', '2026-06-13 15:47:21', '2026-06-13 15:49:56'),
(3, 4, 73, 1, 2, '2026-06-13 15:51:05', '2026-06-13 15:55:11', 'sadaasd', '2026-06-13 15:51:05', '2026-06-13 15:55:11'),
(4, 4, 74, 1, 2, '2026-06-13 15:51:05', '2026-06-13 15:55:38', 'sadaasd', '2026-06-13 15:51:05', '2026-06-13 15:55:38'),
(5, 4, 73, 0, 2, '2026-06-13 15:55:11', '2026-06-13 15:55:38', 'asas', '2026-06-13 15:55:11', '2026-06-13 15:55:38'),
(6, 4, 74, 0, 2, '2026-06-13 15:55:38', '2026-06-13 15:55:56', 'assa', '2026-06-13 15:55:38', '2026-06-13 15:55:56'),
(7, 4, 73, 1, 2, '2026-06-13 16:03:03', '2026-06-13 16:32:59', 'jhgjhg', '2026-06-13 16:03:03', '2026-06-13 16:32:59'),
(8, 4, 74, 1, 2, '2026-06-13 16:03:03', NULL, 'jhgjhg', '2026-06-13 16:03:03', '2026-06-13 16:03:03'),
(9, 6, 1, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(10, 6, 2, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(11, 6, 3, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(12, 6, 4, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(13, 6, 5, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(14, 6, 6, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(15, 6, 7, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(16, 6, 8, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(17, 6, 9, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(18, 6, 10, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(19, 6, 11, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(21, 6, 13, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(22, 6, 14, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(23, 6, 15, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(27, 6, 19, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(28, 6, 20, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(29, 6, 21, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(30, 6, 22, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(31, 6, 23, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(32, 6, 24, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(33, 6, 25, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(34, 6, 26, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(35, 6, 27, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(36, 6, 28, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(37, 6, 29, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(38, 6, 30, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(39, 6, 31, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(40, 6, 32, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(41, 6, 33, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(42, 6, 34, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(43, 6, 35, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(44, 6, 36, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(45, 6, 37, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(46, 6, 38, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(47, 6, 39, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(48, 6, 40, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(49, 6, 41, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(50, 6, 42, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(51, 6, 43, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(52, 6, 44, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(53, 6, 45, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(54, 6, 46, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(55, 6, 47, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(56, 6, 48, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(57, 6, 49, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(58, 6, 50, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(59, 6, 51, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(60, 6, 52, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(61, 6, 53, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(63, 6, 55, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(64, 6, 56, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(65, 6, 57, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(66, 6, 58, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(67, 6, 59, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(68, 6, 60, 0, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(69, 6, 61, 1, 2, '2026-06-13 16:05:46', '2026-06-13 16:05:46', 'test', '2026-06-13 16:05:46', '2026-06-13 16:05:46'),
(70, 6, 62, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(71, 6, 63, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(72, 6, 64, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(73, 6, 65, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(74, 6, 66, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(75, 6, 67, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(76, 6, 68, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(77, 6, 69, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(78, 6, 70, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(79, 6, 71, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(80, 6, 72, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(81, 6, 73, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(82, 6, 74, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(83, 6, 75, 1, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(84, 6, 76, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(85, 6, 77, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(86, 6, 78, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(87, 6, 79, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(88, 6, 80, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(89, 6, 81, 0, 2, '2026-06-13 16:05:47', '2026-06-13 16:05:47', 'test', '2026-06-13 16:05:47', '2026-06-13 16:05:47'),
(90, 4, 73, 0, 2, '2026-06-13 16:32:59', '2026-06-13 16:33:54', 'cfg', '2026-06-13 16:32:59', '2026-06-13 16:33:54'),
(91, 4, 73, 1, 2, '2026-06-13 16:33:54', '2026-06-13 17:49:49', 'asa', '2026-06-13 16:33:54', '2026-06-13 17:49:49'),
(92, 3, 41, 1, 2, '2026-06-13 16:39:31', '2026-07-13 10:49:17', 'tth', '2026-06-13 16:39:31', '2026-07-13 10:49:17'),
(93, 4, 73, 0, 2, '2026-06-13 17:49:49', NULL, 'khgjh', '2026-06-13 17:49:49', '2026-06-13 17:49:49'),
(94, 4, 38, 1, 2, '2026-06-13 20:50:17', '2026-06-13 20:51:45', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:51:45'),
(95, 4, 39, 1, 2, '2026-06-13 20:50:17', '2026-06-13 20:51:46', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:51:46'),
(96, 4, 40, 1, 2, '2026-06-13 20:50:17', '2026-06-13 20:51:46', 'rfrf', '2026-06-13 20:50:17', '2026-06-13 20:51:46'),
(97, 4, 37, 0, 2, '2026-06-13 21:11:37', NULL, 'rtr', '2026-06-13 21:11:37', '2026-06-13 21:11:37'),
(98, 7, 39, 1, 2, '2026-07-12 09:40:46', '2026-07-12 09:59:09', 'Yes', '2026-07-12 09:40:46', '2026-07-12 09:59:09'),
(100, 7, 39, 1, 2, '2026-07-12 09:59:09', '2026-07-12 09:59:57', 'yes', '2026-07-12 09:59:09', '2026-07-12 09:59:57'),
(101, 7, 38, 1, 2, '2026-07-12 09:59:57', '2026-07-12 10:15:14', 'ys', '2026-07-12 09:59:57', '2026-07-12 10:15:14'),
(102, 7, 39, 1, 2, '2026-07-12 09:59:57', '2026-07-12 10:15:14', 'ys', '2026-07-12 09:59:57', '2026-07-12 10:15:14'),
(103, 7, 38, 1, 2, '2026-07-12 10:15:14', '2026-07-13 12:50:37', 'yes', '2026-07-12 10:15:14', '2026-07-13 12:50:37'),
(104, 7, 39, 1, 2, '2026-07-12 10:15:14', '2026-07-13 12:50:37', 'yes', '2026-07-12 10:15:14', '2026-07-13 12:50:37'),
(105, 3, 41, 0, 2, '2026-07-13 10:49:17', NULL, 'Bawal lge', '2026-07-13 10:49:17', '2026-07-13 10:49:17'),
(106, 4, 86, 0, 2, '2026-07-13 11:28:53', NULL, 'bawal', '2026-07-13 11:28:53', '2026-07-13 11:28:53'),
(107, 4, 84, 0, 2, '2026-07-13 11:29:25', NULL, 'jn', '2026-07-13 11:29:25', '2026-07-13 11:29:25'),
(108, 7, 103, 1, 2, '2026-07-13 12:49:26', NULL, 'bawl', '2026-07-13 12:49:26', '2026-07-13 12:49:26'),
(109, 7, 37, 0, 2, '2026-07-13 12:50:37', '2026-07-13 12:51:00', 'jhvjh', '2026-07-13 12:50:37', '2026-07-13 12:51:00'),
(110, 3, 39, 0, 2, '2026-07-13 12:52:42', NULL, 'mjbm', '2026-07-13 12:52:42', '2026-07-13 12:52:42');

-- --------------------------------------------------------

--
-- Table structure for table `warranty_claims`
--

CREATE TABLE `warranty_claims` (
  `claim_id` bigint(20) UNSIGNED NOT NULL,
  `claim_number` varchar(40) NOT NULL,
  `warranty_id` bigint(20) UNSIGNED NOT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `claim_type` enum('repair','replacement') NOT NULL,
  `notes` text DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `parts_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warranty_claim_details`
--

CREATE TABLE `warranty_claim_details` (
  `claim_detail_id` bigint(20) UNSIGNED NOT NULL,
  `claim_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `quantity_issued` int(11) NOT NULL DEFAULT 1,
  `quantity_in_pieces` int(11) NOT NULL DEFAULT 1,
  `quantity_unit` varchar(50) DEFAULT NULL,
  `unit_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `warranty_records`
--

CREATE TABLE `warranty_records` (
  `warranty_id` bigint(20) UNSIGNED NOT NULL,
  `issuance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `issuance_detail_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `customer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `warranty_start_date` date DEFAULT NULL,
  `warranty_end_date` date DEFAULT NULL,
  `warranty_period_months` int(11) NOT NULL DEFAULT 0,
  `warranty_duration_label` varchar(30) DEFAULT NULL COMMENT 'e.g. 2 Years, 6 Months, No Warranty',
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `claim_date` date DEFAULT NULL,
  `claimed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `claim_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `adjustments`
--
ALTER TABLE `adjustments`
  ADD PRIMARY KEY (`adjustment_id`),
  ADD UNIQUE KEY `adjustments_adjustment_number_unique` (`adjustment_number`),
  ADD KEY `adjustments_location_id_foreign` (`location_id`),
  ADD KEY `adjustments_created_by_foreign` (`created_by`),
  ADD KEY `adjustments_adjusted_by_foreign` (`adjusted_by`),
  ADD KEY `adjustments_approved_by_foreign` (`approved_by`),
  ADD KEY `adjustments_status_id_foreign` (`status_id`),
  ADD KEY `adjustments_po_id_foreign` (`po_id`),
  ADD KEY `adjustments_receiving_id_foreign` (`receiving_id`);

--
-- Indexes for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD PRIMARY KEY (`adjustment_detail_id`),
  ADD KEY `adjustment_details_adjustment_id_foreign` (`adjustment_id`),
  ADD KEY `adjustment_details_product_id_foreign` (`product_id`);

--
-- Indexes for table `appliance_details`
--
ALTER TABLE `appliance_details`
  ADD PRIMARY KEY (`appliance_detail_id`),
  ADD UNIQUE KEY `appliance_details_product_id_unique` (`product_id`) COMMENT 'Enforces one-to-one with products',
  ADD KEY `appliance_details_brand_id_foreign` (`brand_id`);

--
-- Indexes for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `archive_logs_triggered_by_user_id_foreign` (`triggered_by_user_id`);

--
-- Indexes for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `audit_trail_user_id_index` (`user_id`),
  ADD KEY `audit_trail_table_name_index` (`table_name`),
  ADD KEY `audit_trail_created_at_index` (`created_at`);

--
-- Indexes for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `barcode_scans_user_id_scanned_at_index` (`user_id`,`scanned_at`),
  ADD KEY `barcode_scans_product_id_foreign` (`product_id`),
  ADD KEY `fk_barcode_scans_transfer` (`transfer_id`);

--
-- Indexes for table `brand_lookup`
--
ALTER TABLE `brand_lookup`
  ADD PRIMARY KEY (`brand_id`),
  ADD UNIQUE KEY `brand_lookup_brand_name_unique` (`brand_name`);

--
-- Indexes for table `budget_allocations`
--
ALTER TABLE `budget_allocations`
  ADD PRIMARY KEY (`budget_id`),
  ADD UNIQUE KEY `budget_location_category_period_unique` (`location_id`,`category_id`,`period_start`),
  ADD KEY `budget_allocations_category_id_foreign` (`category_id`),
  ADD KEY `budget_allocations_created_by_foreign` (`created_by`),
  ADD KEY `budget_allocations_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `budget_transactions`
--
ALTER TABLE `budget_transactions`
  ADD PRIMARY KEY (`txn_id`),
  ADD KEY `budget_transactions_po_id_foreign` (`po_id`),
  ADD KEY `budget_transactions_performed_by_foreign` (`performed_by`),
  ADD KEY `budget_transactions_budget_id_txn_type_index` (`budget_id`,`txn_type`),
  ADD KEY `budget_transactions_pr_id_txn_type_index` (`pr_id`,`txn_type`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `category_lookup`
--
ALTER TABLE `category_lookup`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_lookup_category_name_unique` (`category_name`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD KEY `customers_status_id_foreign` (`status_id`);

--
-- Indexes for table `delivery_receipts`
--
ALTER TABLE `delivery_receipts`
  ADD PRIMARY KEY (`dr_id`),
  ADD UNIQUE KEY `delivery_receipts_dr_number_unique` (`dr_number`),
  ADD KEY `delivery_receipts_sales_id_foreign` (`sales_id`),
  ADD KEY `delivery_receipts_issued_by_foreign` (`issued_by`),
  ADD KEY `delivery_receipts_status_id_foreign` (`status_id`),
  ADD KEY `delivery_receipts_issuance_id_foreign` (`issuance_id`),
  ADD KEY `delivery_receipts_customer_id_foreign` (`customer_id`);

--
-- Indexes for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD PRIMARY KEY (`incident_id`),
  ADD UNIQUE KEY `incident_reports_incident_number_unique` (`incident_number`),
  ADD KEY `incident_source_idx` (`source_type`,`source_id`),
  ADD KEY `incident_loc_status_idx` (`location_id`,`status_id`),
  ADD KEY `incident_batch_status_idx` (`batch_id`,`status_id`),
  ADD KEY `incident_reports_reported_by_index` (`reported_by`),
  ADD KEY `incident_reports_product_id_foreign` (`product_id`),
  ADD KEY `incident_reports_serial_id_foreign` (`serial_id`),
  ADD KEY `incident_reports_status_id_foreign` (`status_id`),
  ADD KEY `incident_reports_reviewed_by_foreign` (`reviewed_by`),
  ADD KEY `incident_reports_adjustment_id_foreign` (`adjustment_id`),
  ADD KEY `incident_reports_correction_id_foreign` (`correction_id`);

--
-- Indexes for table `installation_material_details`
--
ALTER TABLE `installation_material_details`
  ADD PRIMARY KEY (`consumable_detail_id`),
  ADD UNIQUE KEY `consumable_details_product_id_unique` (`product_id`) COMMENT 'Enforces one-to-one with products',
  ADD KEY `consumable_details_unit_id_foreign` (`unit_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD UNIQUE KEY `inventory_location_product_unique` (`location_id`,`product_id`),
  ADD KEY `inventory_product_id_foreign` (`product_id`),
  ADD KEY `inventory_status_id_foreign` (`status_id`);

--
-- Indexes for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `inventory_batches_fifo_idx` (`product_id`,`location_id`,`received_at`),
  ADD KEY `inventory_batches_location_id_foreign` (`location_id`),
  ADD KEY `inventory_batches_receiving_id_foreign` (`receiving_id`);

--
-- Indexes for table `inventory_batch_movements`
--
ALTER TABLE `inventory_batch_movements`
  ADD PRIMARY KEY (`batch_movement_id`),
  ADD KEY `inventory_batch_movements_batch_id_moved_at_index` (`batch_id`,`moved_at`),
  ADD KEY `inventory_batch_movements_transfer_id_product_id_index` (`transfer_id`,`product_id`),
  ADD KEY `inventory_batch_movements_issuance_id_product_id_index` (`issuance_id`,`product_id`);

--
-- Indexes for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ise_product_id_foreign` (`product_id`),
  ADD KEY `ise_location_id_foreign` (`location_id`),
  ADD KEY `ise_to_location_id_foreign` (`to_location_id`),
  ADD KEY `ise_user_id_created_at_index` (`user_id`,`created_at`),
  ADD KEY `ise_transaction_type_created_at` (`transaction_type`,`created_at`),
  ADD KEY `ise_po_id_location_product_idx` (`po_id`,`location_id`,`product_id`);

--
-- Indexes for table `issuances`
--
ALTER TABLE `issuances`
  ADD PRIMARY KEY (`issuance_id`),
  ADD UNIQUE KEY `issuances_dr_number_unique` (`dr_number`),
  ADD KEY `issuances_location_id_foreign` (`location_id`),
  ADD KEY `issuances_issued_to_user_id_foreign` (`issued_to_user_id`),
  ADD KEY `issuances_issued_by_foreign` (`issued_by`),
  ADD KEY `issuances_approved_by_foreign` (`approved_by`),
  ADD KEY `issuances_status_id_foreign` (`status_id`),
  ADD KEY `issuances_customer_id_foreign` (`customer_id`);

--
-- Indexes for table `issuance_details`
--
ALTER TABLE `issuance_details`
  ADD PRIMARY KEY (`issuance_detail_id`),
  ADD KEY `issuance_details_issuance_id_foreign` (`issuance_id`),
  ADD KEY `issuance_details_product_id_foreign` (`product_id`),
  ADD KEY `issuance_details_receiving_id_foreign` (`receiving_id`),
  ADD KEY `issuance_details_issuance_set_id_index` (`issuance_set_id`),
  ADD KEY `issuance_details_batch_id_index` (`batch_id`);

--
-- Indexes for table `issuance_sets`
--
ALTER TABLE `issuance_sets`
  ADD PRIMARY KEY (`issuance_set_id`),
  ADD KEY `issuance_sets_issuance_id_index` (`issuance_id`),
  ADD KEY `issuance_sets_set_id_index` (`set_id`);

--
-- Indexes for table `item_serial`
--
ALTER TABLE `item_serial`
  ADD PRIMARY KEY (`serial_id`),
  ADD UNIQUE KEY `item_serial_serial_number_unique` (`serial_number`),
  ADD KEY `item_serial_product_id_foreign` (`product_id`),
  ADD KEY `item_serial_location_id_foreign` (`location_id`),
  ADD KEY `item_serial_status_id_foreign` (`status_id`),
  ADD KEY `item_serial_receiving_id_foreign` (`receiving_id`),
  ADD KEY `item_serial_scanned_by_foreign` (`scanned_by`),
  ADD KEY `fk_item_serial_transfer` (`transfer_id`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`location_id`),
  ADD UNIQUE KEY `locations_location_code_unique` (`location_code`),
  ADD KEY `locations_parent_location_id_foreign` (`parent_location_id`),
  ADD KEY `locations_status_id_foreign` (`status_id`),
  ADD KEY `locations_type_idx` (`location_type`);

--
-- Indexes for table `login_activities`
--
ALTER TABLE `login_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `login_activities_user_id_index` (`user_id`),
  ADD KEY `login_activities_username_attempt_index` (`username_attempt`),
  ADD KEY `login_activities_ip_address_index` (`ip_address`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `nav_bundle_permissions`
--
ALTER TABLE `nav_bundle_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nav_bundle_permissions_bundle_id_permission_id_unique` (`bundle_id`,`permission_id`),
  ADD KEY `nav_bundle_permissions_permission_id_foreign` (`permission_id`);

--
-- Indexes for table `nav_permission_bundles`
--
ALTER TABLE `nav_permission_bundles`
  ADD PRIMARY KEY (`bundle_id`),
  ADD UNIQUE KEY `nav_permission_bundles_route_path_unique` (`route_path`);

--
-- Indexes for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `password_reset_codes_user_id_used_at_expires_at_index` (`user_id`,`used_at`,`expires_at`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `pending_products`
--
ALTER TABLE `pending_products`
  ADD PRIMARY KEY (`pending_product_id`),
  ADD UNIQUE KEY `pending_products_barcode_unique` (`barcode`),
  ADD KEY `pending_products_status_created_at_index` (`status`,`created_at`),
  ADD KEY `pending_products_category_id_foreign` (`category_id`),
  ADD KEY `pending_products_brand_id_foreign` (`brand_id`),
  ADD KEY `pending_products_created_by_foreign` (`created_by`),
  ADD KEY `pending_products_approved_by_foreign` (`approved_by`),
  ADD KEY `pending_products_product_id_foreign` (`product_id`),
  ADD KEY `pending_products_opening_location_id_foreign` (`opening_location_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `permissions_feature_action_unique` (`feature`,`action`);

--
-- Indexes for table `permission_audit_logs`
--
ALTER TABLE `permission_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `permission_audit_logs_changed_at_index` (`changed_at`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `pick_reservations`
--
ALTER TABLE `pick_reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `pick_res_source_idx` (`source_type`,`source_id`),
  ADD KEY `pick_res_avail_idx` (`product_id`,`location_id`,`released_at`),
  ADD KEY `pick_reservations_location_id_foreign` (`location_id`),
  ADD KEY `pick_reservations_batch_id_foreign` (`batch_id`),
  ADD KEY `pick_reservations_serial_id_foreign` (`serial_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `products_product_code_unique` (`product_code`),
  ADD KEY `products_product_type_idx` (`product_type`),
  ADD KEY `products_category_id_foreign` (`category_id`),
  ADD KEY `products_status_id_foreign` (`status_id`),
  ADD KEY `products_created_at_product_id_idx` (`created_at`,`product_id`);

--
-- Indexes for table `product_sets`
--
ALTER TABLE `product_sets`
  ADD PRIMARY KEY (`set_id`),
  ADD KEY `product_sets_is_active_index` (`is_active`),
  ADD KEY `product_sets_created_by_foreign` (`created_by`),
  ADD KEY `product_sets_updated_by_foreign` (`updated_by`);

--
-- Indexes for table `product_set_brands`
--
ALTER TABLE `product_set_brands`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_set_brands_set_id_brand_id_unique` (`set_id`,`brand_id`),
  ADD KEY `product_set_brands_brand_id_foreign` (`brand_id`);

--
-- Indexes for table `product_set_lines`
--
ALTER TABLE `product_set_lines`
  ADD PRIMARY KEY (`set_line_id`),
  ADD KEY `product_set_lines_set_id_index` (`set_id`),
  ADD KEY `product_set_lines_product_id_index` (`product_id`);

--
-- Indexes for table `profit_loss`
--
ALTER TABLE `profit_loss`
  ADD PRIMARY KEY (`profit_loss_id`),
  ADD KEY `profit_loss_product_id_foreign` (`product_id`),
  ADD KEY `profit_loss_recorded_by_foreign` (`recorded_by`),
  ADD KEY `profit_loss_approved_by_foreign` (`approved_by`),
  ADD KEY `profit_loss_status_id_foreign` (`status_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `purchase_orders_po_number_unique` (`po_number`),
  ADD KEY `purchase_orders_pr_id_foreign` (`pr_id`),
  ADD KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  ADD KEY `purchase_orders_location_id_foreign` (`location_id`),
  ADD KEY `purchase_orders_created_by_foreign` (`created_by`),
  ADD KEY `purchase_orders_approved_by_foreign` (`approved_by`),
  ADD KEY `purchase_orders_received_by_foreign` (`received_by`),
  ADD KEY `purchase_orders_status_id_foreign` (`status_id`),
  ADD KEY `purchase_orders_verified_by_foreign` (`verified_by`);

--
-- Indexes for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  ADD PRIMARY KEY (`po_detail_id`),
  ADD KEY `purchase_order_details_po_id_foreign` (`po_id`),
  ADD KEY `purchase_order_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_order_details_location_id_foreign` (`location_id`);

--
-- Indexes for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD PRIMARY KEY (`pr_id`),
  ADD UNIQUE KEY `purchase_requests_pr_number_unique` (`pr_number`),
  ADD KEY `purchase_requests_location_id_foreign` (`location_id`),
  ADD KEY `purchase_requests_budget_id_foreign` (`budget_id`),
  ADD KEY `purchase_requests_requested_by_foreign` (`requested_by`),
  ADD KEY `purchase_requests_approved_by_foreign` (`approved_by`),
  ADD KEY `purchase_requests_status_id_foreign` (`status_id`),
  ADD KEY `purchase_requests_preferred_supplier_id_foreign` (`preferred_supplier_id`),
  ADD KEY `purchase_requests_supplier_id_foreign` (`supplier_id`);

--
-- Indexes for table `purchase_request_details`
--
ALTER TABLE `purchase_request_details`
  ADD PRIMARY KEY (`pr_detail_id`),
  ADD KEY `pr_details_pr_id_foreign` (`pr_id`),
  ADD KEY `pr_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_request_details_location_id_foreign` (`location_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`purchase_return_id`),
  ADD UNIQUE KEY `purchase_returns_return_number_unique` (`return_number`),
  ADD KEY `purchase_returns_po_id_foreign` (`po_id`),
  ADD KEY `purchase_returns_supplier_id_foreign` (`supplier_id`),
  ADD KEY `purchase_returns_receiving_id_foreign` (`receiving_id`),
  ADD KEY `purchase_returns_status_id_foreign` (`status_id`),
  ADD KEY `purchase_returns_requested_by_foreign` (`requested_by`),
  ADD KEY `purchase_returns_approved_by_foreign` (`approved_by`);

--
-- Indexes for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
  ADD PRIMARY KEY (`pr_return_detail_id`),
  ADD KEY `purchase_return_details_purchase_return_id_foreign` (`purchase_return_id`),
  ADD KEY `purchase_return_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_return_details_serial_id_foreign` (`serial_id`);

--
-- Indexes for table `receivings`
--
ALTER TABLE `receivings`
  ADD PRIMARY KEY (`receiving_id`),
  ADD UNIQUE KEY `receivings_receiving_number_unique` (`receiving_number`),
  ADD KEY `receivings_po_id_foreign` (`po_id`),
  ADD KEY `receivings_supplier_id_foreign` (`supplier_id`),
  ADD KEY `receivings_location_id_foreign` (`location_id`),
  ADD KEY `receivings_received_by_foreign` (`received_by`);

--
-- Indexes for table `receiving_details`
--
ALTER TABLE `receiving_details`
  ADD PRIMARY KEY (`receiving_detail_id`),
  ADD KEY `receiving_details_receiving_id_foreign` (`receiving_id`),
  ADD KEY `receiving_details_po_detail_id_foreign` (`po_detail_id`),
  ADD KEY `receiving_details_product_id_foreign` (`product_id`),
  ADD KEY `rd_scanned_barcode_idx` (`scanned_barcode`);

--
-- Indexes for table `restock_recommendations`
--
ALTER TABLE `restock_recommendations`
  ADD PRIMARY KEY (`recommendation_id`),
  ADD UNIQUE KEY `restock_reco_product_location_unique` (`product_id`,`location_id`),
  ADD KEY `restock_recommendations_product_id_foreign` (`product_id`),
  ADD KEY `restock_recommendations_location_id_foreign` (`location_id`),
  ADD KEY `restock_recommendations_status_id_foreign` (`status_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`role_id`),
  ADD UNIQUE KEY `roles_role_name_unique` (`role_name`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_permissions_role_id_permission_id_unique` (`role_id`,`permission_id`),
  ADD KEY `role_permissions_permission_id_foreign` (`permission_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sales_id`),
  ADD UNIQUE KEY `sales_invoice_number_unique` (`invoice_number`),
  ADD KEY `sales_customer_id_foreign` (`customer_id`),
  ADD KEY `sales_location_id_foreign` (`location_id`),
  ADD KEY `sales_payment_status_id_foreign` (`payment_status_id`),
  ADD KEY `sales_sold_by_foreign` (`sold_by`);

--
-- Indexes for table `sales_details`
--
ALTER TABLE `sales_details`
  ADD PRIMARY KEY (`sales_detail_id`),
  ADD KEY `sales_details_sales_id_foreign` (`sales_id`),
  ADD KEY `sales_details_product_id_foreign` (`product_id`);

--
-- Indexes for table `scan_corrections`
--
ALTER TABLE `scan_corrections`
  ADD PRIMARY KEY (`correction_id`),
  ADD KEY `scan_corrections_adjustment_id_foreign` (`adjustment_id`),
  ADD KEY `scan_corrections_po_id_foreign` (`po_id`),
  ADD KEY `scan_corrections_receiving_id_foreign` (`receiving_id`),
  ADD KEY `scan_corrections_original_product_id_foreign` (`original_product_id`),
  ADD KEY `scan_corrections_corrected_product_id_foreign` (`corrected_product_id`),
  ADD KEY `scan_corrections_receiving_detail_id_foreign` (`receiving_detail_id`),
  ADD KEY `scan_corrections_serial_id_foreign` (`serial_id`),
  ADD KEY `scan_corrections_corrected_by_foreign` (`corrected_by`),
  ADD KEY `scan_corrections_approved_by_foreign` (`approved_by`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `status_lookup`
--
ALTER TABLE `status_lookup`
  ADD PRIMARY KEY (`status_id`),
  ADD UNIQUE KEY `status_lookup_name_category_unique` (`status_name`,`status_category`);

--
-- Indexes for table `stock_parameter_suggestions`
--
ALTER TABLE `stock_parameter_suggestions`
  ADD PRIMARY KEY (`suggestion_id`),
  ADD KEY `stock_parameter_suggestions_reviewed_by_foreign` (`reviewed_by`),
  ADD KEY `stock_parameter_suggestions_product_id_status_index` (`product_id`,`status`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD KEY `suppliers_status_id_foreign` (`status_id`);

--
-- Indexes for table `supplier_product`
--
ALTER TABLE `supplier_product`
  ADD PRIMARY KEY (`supplier_prod_id`),
  ADD UNIQUE KEY `supplier_product_sup_prod_unique` (`supplier_id`,`product_id`),
  ADD KEY `supplier_product_product_id_foreign` (`product_id`),
  ADD KEY `supplier_product_status_id_foreign` (`status_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `system_settings_key_unique` (`key`);

--
-- Indexes for table `transaction_type_lookup`
--
ALTER TABLE `transaction_type_lookup`
  ADD PRIMARY KEY (`transaction_type_id`),
  ADD UNIQUE KEY `transaction_type_lookup_name_unique` (`transaction_name`);

--
-- Indexes for table `transfers`
--
ALTER TABLE `transfers`
  ADD PRIMARY KEY (`transfer_id`),
  ADD UNIQUE KEY `transfers_transfer_number_unique` (`transfer_number`),
  ADD KEY `transfers_from_location_id_foreign` (`from_location_id`),
  ADD KEY `transfers_to_location_id_foreign` (`to_location_id`),
  ADD KEY `transfers_requested_by_foreign` (`requested_by`),
  ADD KEY `transfers_approved_by_foreign` (`approved_by`),
  ADD KEY `transfers_received_by_foreign` (`received_by`),
  ADD KEY `transfers_status_id_foreign` (`status_id`),
  ADD KEY `transfers_transfer_request_id_foreign` (`transfer_request_id`);

--
-- Indexes for table `transfer_details`
--
ALTER TABLE `transfer_details`
  ADD PRIMARY KEY (`transfer_detail_id`),
  ADD KEY `transfer_details_transfer_id_foreign` (`transfer_id`),
  ADD KEY `transfer_details_product_id_foreign` (`product_id`);

--
-- Indexes for table `transfer_tracking`
--
ALTER TABLE `transfer_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `transfer_tracking_transfer_id_foreign` (`transfer_id`),
  ADD KEY `transfer_tracking_location_id_foreign` (`location_id`),
  ADD KEY `transfer_tracking_recorded_by_foreign` (`recorded_by`);

--
-- Indexes for table `unit_lookup`
--
ALTER TABLE `unit_lookup`
  ADD PRIMARY KEY (`unit_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD KEY `users_role_id_foreign` (`role_id`),
  ADD KEY `users_status_id_foreign` (`status_id`),
  ADD KEY `users_location_id_foreign` (`location_id`);

--
-- Indexes for table `user_locations`
--
ALTER TABLE `user_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_locations_unique` (`user_id`,`location_id`),
  ADD KEY `user_locations_location_id_foreign` (`location_id`);

--
-- Indexes for table `user_permission_overrides`
--
ALTER TABLE `user_permission_overrides`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_permission_overrides_user_id_permission_id_index` (`user_id`,`permission_id`),
  ADD KEY `user_permission_overrides_permission_id_foreign` (`permission_id`);

--
-- Indexes for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  ADD PRIMARY KEY (`claim_id`),
  ADD UNIQUE KEY `warranty_claims_claim_number_unique` (`claim_number`),
  ADD KEY `warranty_claims_warranty_id_foreign` (`warranty_id`),
  ADD KEY `warranty_claims_customer_id_foreign` (`customer_id`),
  ADD KEY `warranty_claims_product_id_foreign` (`product_id`),
  ADD KEY `warranty_claims_location_id_foreign` (`location_id`),
  ADD KEY `warranty_claims_status_id_foreign` (`status_id`),
  ADD KEY `warranty_claims_created_by_foreign` (`created_by`),
  ADD KEY `warranty_claims_serial_number_index` (`serial_number`);

--
-- Indexes for table `warranty_claim_details`
--
ALTER TABLE `warranty_claim_details`
  ADD PRIMARY KEY (`claim_detail_id`),
  ADD KEY `warranty_claim_details_claim_id_foreign` (`claim_id`),
  ADD KEY `warranty_claim_details_product_id_foreign` (`product_id`);

--
-- Indexes for table `warranty_records`
--
ALTER TABLE `warranty_records`
  ADD PRIMARY KEY (`warranty_id`),
  ADD KEY `warranty_records_issuance_id_foreign` (`issuance_id`),
  ADD KEY `warranty_records_issuance_detail_id_foreign` (`issuance_detail_id`),
  ADD KEY `warranty_records_product_id_foreign` (`product_id`),
  ADD KEY `warranty_records_customer_id_foreign` (`customer_id`),
  ADD KEY `warranty_records_status_id_foreign` (`status_id`),
  ADD KEY `warranty_records_claimed_by_foreign` (`claimed_by`),
  ADD KEY `warranty_records_serial_number_index` (`serial_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adjustments`
--
ALTER TABLE `adjustments`
  MODIFY `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  MODIFY `adjustment_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `appliance_details`
--
ALTER TABLE `appliance_details`
  MODIFY `appliance_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=212;

--
-- AUTO_INCREMENT for table `archive_logs`
--
ALTER TABLE `archive_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2764;

--
-- AUTO_INCREMENT for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `brand_lookup`
--
ALTER TABLE `brand_lookup`
  MODIFY `brand_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `budget_allocations`
--
ALTER TABLE `budget_allocations`
  MODIFY `budget_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `budget_transactions`
--
ALTER TABLE `budget_transactions`
  MODIFY `txn_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=52;

--
-- AUTO_INCREMENT for table `category_lookup`
--
ALTER TABLE `category_lookup`
  MODIFY `category_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `delivery_receipts`
--
ALTER TABLE `delivery_receipts`
  MODIFY `dr_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `incident_reports`
--
ALTER TABLE `incident_reports`
  MODIFY `incident_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `installation_material_details`
--
ALTER TABLE `installation_material_details`
  MODIFY `consumable_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=133;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  MODIFY `batch_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `inventory_batch_movements`
--
ALTER TABLE `inventory_batch_movements`
  MODIFY `batch_movement_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `issuances`
--
ALTER TABLE `issuances`
  MODIFY `issuance_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=152;

--
-- AUTO_INCREMENT for table `issuance_details`
--
ALTER TABLE `issuance_details`
  MODIFY `issuance_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=116;

--
-- AUTO_INCREMENT for table `issuance_sets`
--
ALTER TABLE `issuance_sets`
  MODIFY `issuance_set_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `item_serial`
--
ALTER TABLE `item_serial`
  MODIFY `serial_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `location_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `login_activities`
--
ALTER TABLE `login_activities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=448;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `nav_bundle_permissions`
--
ALTER TABLE `nav_bundle_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `nav_permission_bundles`
--
ALTER TABLE `nav_permission_bundles`
  MODIFY `bundle_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_products`
--
ALTER TABLE `pending_products`
  MODIFY `pending_product_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `permission_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `permission_audit_logs`
--
ALTER TABLE `permission_audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=402;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=417;

--
-- AUTO_INCREMENT for table `pick_reservations`
--
ALTER TABLE `pick_reservations`
  MODIFY `reservation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=346;

--
-- AUTO_INCREMENT for table `product_sets`
--
ALTER TABLE `product_sets`
  MODIFY `set_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product_set_brands`
--
ALTER TABLE `product_set_brands`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `product_set_lines`
--
ALTER TABLE `product_set_lines`
  MODIFY `set_line_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `profit_loss`
--
ALTER TABLE `profit_loss`
  MODIFY `profit_loss_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  MODIFY `po_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  MODIFY `pr_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=99;

--
-- AUTO_INCREMENT for table `purchase_request_details`
--
ALTER TABLE `purchase_request_details`
  MODIFY `pr_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=137;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `purchase_return_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
  MODIFY `pr_return_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receivings`
--
ALTER TABLE `receivings`
  MODIFY `receiving_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `receiving_details`
--
ALTER TABLE `receiving_details`
  MODIFY `receiving_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `restock_recommendations`
--
ALTER TABLE `restock_recommendations`
  MODIFY `recommendation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1717;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=571;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sales_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_details`
--
ALTER TABLE `sales_details`
  MODIFY `sales_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scan_corrections`
--
ALTER TABLE `scan_corrections`
  MODIFY `correction_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `status_lookup`
--
ALTER TABLE `status_lookup`
  MODIFY `status_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `stock_parameter_suggestions`
--
ALTER TABLE `stock_parameter_suggestions`
  MODIFY `suggestion_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1352;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `supplier_product`
--
ALTER TABLE `supplier_product`
  MODIFY `supplier_prod_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `transaction_type_lookup`
--
ALTER TABLE `transaction_type_lookup`
  MODIFY `transaction_type_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `transfer_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `transfer_details`
--
ALTER TABLE `transfer_details`
  MODIFY `transfer_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `transfer_tracking`
--
ALTER TABLE `transfer_tracking`
  MODIFY `tracking_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

--
-- AUTO_INCREMENT for table `unit_lookup`
--
ALTER TABLE `unit_lookup`
  MODIFY `unit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `user_locations`
--
ALTER TABLE `user_locations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_permission_overrides`
--
ALTER TABLE `user_permission_overrides`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=111;

--
-- AUTO_INCREMENT for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  MODIFY `claim_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `warranty_claim_details`
--
ALTER TABLE `warranty_claim_details`
  MODIFY `claim_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `warranty_records`
--
ALTER TABLE `warranty_records`
  MODIFY `warranty_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `adjustments`
--
ALTER TABLE `adjustments`
  ADD CONSTRAINT `adjustments_adjusted_by_foreign` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `adjustments_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD CONSTRAINT `adjustment_details_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `adjustment_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

--
-- Constraints for table `appliance_details`
--
ALTER TABLE `appliance_details`
  ADD CONSTRAINT `appliance_details_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `appliance_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `archive_logs`
--
ALTER TABLE `archive_logs`
  ADD CONSTRAINT `archive_logs_triggered_by_user_id_foreign` FOREIGN KEY (`triggered_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `audit_trail_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  ADD CONSTRAINT `barcode_scans_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `barcode_scans_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_barcode_scans_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL;

--
-- Constraints for table `budget_allocations`
--
ALTER TABLE `budget_allocations`
  ADD CONSTRAINT `budget_allocations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `budget_allocations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `budget_allocations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `budget_allocations_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `budget_transactions`
--
ALTER TABLE `budget_transactions`
  ADD CONSTRAINT `budget_transactions_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budget_allocations` (`budget_id`),
  ADD CONSTRAINT `budget_transactions_performed_by_foreign` FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `budget_transactions_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `budget_transactions_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE SET NULL;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `delivery_receipts`
--
ALTER TABLE `delivery_receipts`
  ADD CONSTRAINT `delivery_receipts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_receipts_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `delivery_receipts_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_receipts_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_receipts_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `incident_reports`
--
ALTER TABLE `incident_reports`
  ADD CONSTRAINT `incident_reports_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `incident_reports_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `incident_reports_correction_id_foreign` FOREIGN KEY (`correction_id`) REFERENCES `scan_corrections` (`correction_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `incident_reports_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `incident_reports_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `incident_reports_reported_by_foreign` FOREIGN KEY (`reported_by`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `incident_reports_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `incident_reports_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `incident_reports_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`);

--
-- Constraints for table `installation_material_details`
--
ALTER TABLE `installation_material_details`
  ADD CONSTRAINT `consumable_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `consumable_details_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `unit_lookup` (`unit_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_batches`
--
ALTER TABLE `inventory_batches`
  ADD CONSTRAINT `inventory_batches_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_batches_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_batches_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_batch_movements`
--
ALTER TABLE `inventory_batch_movements`
  ADD CONSTRAINT `inventory_batch_movements_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE CASCADE;

--
-- Constraints for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  ADD CONSTRAINT `ise_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ise_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ise_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ise_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `ise_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `issuances`
--
ALTER TABLE `issuances`
  ADD CONSTRAINT `issuances_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_issued_to_user_id_foreign` FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `issuance_details`
--
ALTER TABLE `issuance_details`
  ADD CONSTRAINT `issuance_details_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuance_details_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issuance_details_issuance_set_id_foreign` FOREIGN KEY (`issuance_set_id`) REFERENCES `issuance_sets` (`issuance_set_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issuance_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuance_details_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL;

--
-- Constraints for table `issuance_sets`
--
ALTER TABLE `issuance_sets`
  ADD CONSTRAINT `issuance_sets_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issuance_sets_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`);

--
-- Constraints for table `item_serial`
--
ALTER TABLE `item_serial`
  ADD CONSTRAINT `fk_item_serial_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `item_serial_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `item_serial_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `item_serial_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `item_serial_scanned_by_foreign` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `item_serial_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `locations`
--
ALTER TABLE `locations`
  ADD CONSTRAINT `locations_parent_location_id_foreign` FOREIGN KEY (`parent_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `locations_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `login_activities`
--
ALTER TABLE `login_activities`
  ADD CONSTRAINT `login_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `nav_bundle_permissions`
--
ALTER TABLE `nav_bundle_permissions`
  ADD CONSTRAINT `nav_bundle_permissions_bundle_id_foreign` FOREIGN KEY (`bundle_id`) REFERENCES `nav_permission_bundles` (`bundle_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `nav_bundle_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  ADD CONSTRAINT `password_reset_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `pending_products`
--
ALTER TABLE `pending_products`
  ADD CONSTRAINT `pending_products_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_products_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_products_opening_location_id_foreign` FOREIGN KEY (`opening_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pending_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

--
-- Constraints for table `pick_reservations`
--
ALTER TABLE `pick_reservations`
  ADD CONSTRAINT `pick_reservations_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `inventory_batches` (`batch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `pick_reservations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `pick_reservations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `pick_reservations_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `product_sets`
--
ALTER TABLE `product_sets`
  ADD CONSTRAINT `product_sets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_sets_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `product_set_brands`
--
ALTER TABLE `product_set_brands`
  ADD CONSTRAINT `product_set_brands_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_set_brands_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`) ON DELETE CASCADE;

--
-- Constraints for table `product_set_lines`
--
ALTER TABLE `product_set_lines`
  ADD CONSTRAINT `product_set_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `product_set_lines_set_id_foreign` FOREIGN KEY (`set_id`) REFERENCES `product_sets` (`set_id`) ON DELETE CASCADE;

--
-- Constraints for table `profit_loss`
--
ALTER TABLE `profit_loss`
  ADD CONSTRAINT `profit_loss_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `profit_loss_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `profit_loss_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `profit_loss_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  ADD CONSTRAINT `purchase_order_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_order_details_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_requests`
--
ALTER TABLE `purchase_requests`
  ADD CONSTRAINT `purchase_requests_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_budget_id_foreign` FOREIGN KEY (`budget_id`) REFERENCES `budget_allocations` (`budget_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_preferred_supplier_id_foreign` FOREIGN KEY (`preferred_supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_requests_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_request_details`
--
ALTER TABLE `purchase_request_details`
  ADD CONSTRAINT `pr_details_pr_id_foreign` FOREIGN KEY (`pr_id`) REFERENCES `purchase_requests` (`pr_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pr_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_request_details_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `purchase_returns_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
  ADD CONSTRAINT `purchase_return_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_return_details_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`purchase_return_id`) ON DELETE CASCADE;

--
-- Constraints for table `receivings`
--
ALTER TABLE `receivings`
  ADD CONSTRAINT `receivings_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `receivings_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `receivings_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `receivings_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL;

--
-- Constraints for table `receiving_details`
--
ALTER TABLE `receiving_details`
  ADD CONSTRAINT `receiving_details_po_detail_id_foreign` FOREIGN KEY (`po_detail_id`) REFERENCES `purchase_order_details` (`po_detail_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `receiving_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `receiving_details_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE CASCADE;

--
-- Constraints for table `restock_recommendations`
--
ALTER TABLE `restock_recommendations`
  ADD CONSTRAINT `restock_recommendations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `restock_recommendations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `restock_recommendations_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_payment_status_id_foreign` FOREIGN KEY (`payment_status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_sold_by_foreign` FOREIGN KEY (`sold_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `sales_details`
--
ALTER TABLE `sales_details`
  ADD CONSTRAINT `sales_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `sales_details_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE CASCADE;

--
-- Constraints for table `scan_corrections`
--
ALTER TABLE `scan_corrections`
  ADD CONSTRAINT `scan_corrections_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `scan_corrections_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_corrected_by_foreign` FOREIGN KEY (`corrected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_corrected_product_id_foreign` FOREIGN KEY (`corrected_product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_original_product_id_foreign` FOREIGN KEY (`original_product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_receiving_detail_id_foreign` FOREIGN KEY (`receiving_detail_id`) REFERENCES `receiving_details` (`receiving_detail_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_receiving_id_foreign` FOREIGN KEY (`receiving_id`) REFERENCES `receivings` (`receiving_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `scan_corrections_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL;

--
-- Constraints for table `stock_parameter_suggestions`
--
ALTER TABLE `stock_parameter_suggestions`
  ADD CONSTRAINT `stock_parameter_suggestions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stock_parameter_suggestions_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD CONSTRAINT `suppliers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `supplier_product`
--
ALTER TABLE `supplier_product`
  ADD CONSTRAINT `supplier_product_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supplier_product_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `supplier_product_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE;

--
-- Constraints for table `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_transfer_request_id_foreign` FOREIGN KEY (`transfer_request_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE SET NULL;

--
-- Constraints for table `transfer_details`
--
ALTER TABLE `transfer_details`
  ADD CONSTRAINT `transfer_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfer_details_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE;

--
-- Constraints for table `transfer_tracking`
--
ALTER TABLE `transfer_tracking`
  ADD CONSTRAINT `transfer_tracking_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfer_tracking_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfer_tracking_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `users_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`);

--
-- Constraints for table `user_locations`
--
ALTER TABLE `user_locations`
  ADD CONSTRAINT `user_locations_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_locations_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permission_overrides`
--
ALTER TABLE `user_permission_overrides`
  ADD CONSTRAINT `user_permission_overrides_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_permission_overrides_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  ADD CONSTRAINT `warranty_claims_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_claims_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_claims_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_claims_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_claims_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_claims_warranty_id_foreign` FOREIGN KEY (`warranty_id`) REFERENCES `warranty_records` (`warranty_id`) ON DELETE CASCADE;

--
-- Constraints for table `warranty_claim_details`
--
ALTER TABLE `warranty_claim_details`
  ADD CONSTRAINT `warranty_claim_details_claim_id_foreign` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`claim_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warranty_claim_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `warranty_records`
--
ALTER TABLE `warranty_records`
  ADD CONSTRAINT `warranty_records_claimed_by_foreign` FOREIGN KEY (`claimed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_records_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_records_issuance_detail_id_foreign` FOREIGN KEY (`issuance_detail_id`) REFERENCES `issuance_details` (`issuance_detail_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_records_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warranty_records_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `warranty_records_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
