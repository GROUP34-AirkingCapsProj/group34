-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 10, 2026 at 01:44 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `airking_cleans`
--

-- --------------------------------------------------------

--
-- Table structure for table `adjustments`
--

CREATE TABLE `adjustments` (
  `adjustment_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `adjusted_by` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'FK — was varchar before, now fixed',
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `adjustment_number` varchar(50) NOT NULL,
  `adjustment_date` date DEFAULT NULL,
  `adjustment_type` varchar(50) DEFAULT NULL,
  `total_variance_positive` int(11) NOT NULL DEFAULT 0,
  `total_variance_negative` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
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
-- Table structure for table `audit_trail`
--

CREATE TABLE `audit_trail` (
  `audit_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL COMMENT 'login | logout | create | update | delete | password_reset',
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
(474, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 16:47:31', '2026-05-09 16:47:31'),
(475, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:18:51', '2026-05-09 17:18:51'),
(476, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:34:11', '2026-05-09 17:34:11'),
(477, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:40:11', '2026-05-09 17:40:11'),
(478, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:42:48', '2026-05-09 17:42:48'),
(479, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:46:15', '2026-05-09 17:46:15'),
(480, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:46:50', '2026-05-09 17:46:50'),
(481, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 18:06:06', '2026-05-09 18:06:06'),
(482, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 18:15:35', '2026-05-09 18:15:35'),
(483, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:18:39', '2026-05-09 20:18:39'),
(484, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:43:48', '2026-05-09 20:43:48'),
(485, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:44:07', '2026-05-09 20:44:07'),
(486, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:44:47', '2026-05-09 20:44:47'),
(487, 6, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:45:55', '2026-05-09 20:45:55'),
(488, 6, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:46:02', '2026-05-09 20:46:02'),
(489, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:47:06', '2026-05-09 20:47:06'),
(490, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:47:37', '2026-05-09 20:47:37'),
(491, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:48:24', '2026-05-09 20:48:24'),
(492, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:50:25', '2026-05-09 20:50:25'),
(493, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:51:10', '2026-05-09 20:51:10'),
(494, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:52:07', '2026-05-09 20:52:07'),
(495, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:53:07', '2026-05-09 20:53:07'),
(496, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:53:20', '2026-05-09 20:53:20'),
(497, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:54:16', '2026-05-09 20:54:16'),
(498, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:54:34', '2026-05-09 20:54:34'),
(499, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:55:50', '2026-05-09 20:55:50'),
(500, 2, 'create', 'users', 8, NULL, '{\"first_name\":\"john\",\"last_name\":\"baloro\",\"email\":\"john@airking.com\",\"username\":\"john baloro\",\"phone\":\"09756026160\",\"role_id\":3,\"location_id\":\"CDO Main Showroom\",\"status_id\":\"Active\",\"password_hash\":\"$2y$12$gsgz0q1ibpBK.3\\/OunuVVOOQtTCEND2aoqEa2aOiak6Z.WUCPxQRm\",\"updated_at\":\"2026-05-10\",\"created_at\":\"2026-05-10\",\"user_id\":\"john baloro\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:57:41', '2026-05-09 20:57:41'),
(501, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:27:20', '2026-05-10 02:27:20'),
(502, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:30:14', '2026-05-10 02:30:14'),
(503, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:30:28', '2026-05-10 02:30:28'),
(504, 2, 'update', 'users', 8, '{\"email\":\"john@airking.com\",\"updated_at\":\"2026-05-10T04:57:41.000000Z\"}', '{\"email\":\"zsyntax7@gmail.com\",\"updated_at\":\"2026-05-10\"}', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:46:49', '2026-05-10 02:46:49'),
(505, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:46:53', '2026-05-10 02:46:53'),
(506, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:05:48', '2026-05-10 03:05:48'),
(507, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:00', '2026-05-10 03:06:00'),
(508, 8, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:21', '2026-05-10 03:06:21'),
(509, 8, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:26', '2026-05-10 03:06:26'),
(510, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:50', '2026-05-10 03:06:50'),
(511, 2, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:07:12', '2026-05-10 03:07:12'),
(512, 8, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:13:30', '2026-05-10 03:13:30'),
(513, 8, 'logout', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:31:36', '2026-05-10 03:31:36'),
(514, 2, 'login', NULL, NULL, NULL, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:32:34', '2026-05-10 03:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `barcode_scans`
--

CREATE TABLE `barcode_scans` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `barcode` varchar(191) NOT NULL,
  `scan_mode` varchar(64) NOT NULL COMMENT 'receive-po | issue-out | transfer | adjustment',
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
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `brand_lookup`
--

INSERT INTO `brand_lookup` (`brand_id`, `brand_name`, `description`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Airking', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(2, 'Samsung', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(3, 'LG', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(4, 'Panasonic', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(5, 'Sony', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(6, 'Whirlpool', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(7, 'Condura', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(8, 'Hanabishi', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(9, 'Imarflex', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(10, 'TCL', NULL, '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL);

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
('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba', 'i:5;', 1778412757),
('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba:timer', 'i:1778412757;', 1778412757),
('laravel-cache-97fb9d442a54e03e3bec9d57e2061744ef0b7383', 'i:3;', 1777780665),
('laravel-cache-97fb9d442a54e03e3bec9d57e2061744ef0b7383:timer', 'i:1777780665;', 1777780665),
('laravel-cache-da4b9237bacccdf19c0760cab7aec4a8359010b0', 'i:1;', 1778412856),
('laravel-cache-da4b9237bacccdf19c0760cab7aec4a8359010b0:timer', 'i:1778412856;', 1778412856),
('laravel-cache-e9326848414a53f4899e944998f940f28b8ad016', 'i:3;', 1777787422),
('laravel-cache-e9326848414a53f4899e944998f940f28b8ad016:timer', 'i:1777787421;', 1777787422),
('laravel-cache-login-credentials:b311291ec34bdc27594ed0573aeca67b2e690e4c', 'i:1;', 1778378216),
('laravel-cache-login-credentials:b311291ec34bdc27594ed0573aeca67b2e690e4c:timer', 'i:1778378216;', 1778378216),
('laravel-cache-login-credentials:c7bd09da6008e3880a0c28309ae25e15f61d45fb', 'i:3;', 1778378234),
('laravel-cache-login-credentials:c7bd09da6008e3880a0c28309ae25e15f61d45fb:timer', 'i:1778378234;', 1778378234),
('laravel-cache-login-credentials:d7fb9292c33d6d05f992dfc4ff8362f470a50eb4', 'i:3;', 1778412157),
('laravel-cache-login-credentials:d7fb9292c33d6d05f992dfc4ff8362f470a50eb4:timer', 'i:1778412157;', 1778412157);

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
  `category_type` varchar(50) NOT NULL COMMENT 'product | accessory | consumable',
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `category_lookup`
--

INSERT INTO `category_lookup` (`category_id`, `category_name`, `category_type`, `description`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Air Conditioning', 'product', 'Air conditioning units', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(2, 'Television', 'product', 'TVs and displays', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(3, 'Refrigerator', 'product', 'Refrigerators and freezers', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(4, 'Washing Machine', 'product', 'Washing machines', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(5, 'Small Appliances', 'product', 'Small home appliances', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(6, 'AC Parts', 'consumable', 'Air conditioning spare parts', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(7, 'Copper Tubing', 'consumable', 'Copper tubing and fittings', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL),
(8, 'Accessories', 'accessory', 'General accessories', '2026-04-22 21:50:17', '2026-04-22 21:50:17', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` bigint(20) UNSIGNED NOT NULL,
  `customer_code` varchar(50) DEFAULT NULL,
  `customer_type` varchar(100) DEFAULT NULL COMMENT 'Ordinary | Business',
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

-- --------------------------------------------------------

--
-- Table structure for table `delivery_receipts`
--

CREATE TABLE `delivery_receipts` (
  `dr_id` bigint(20) UNSIGNED NOT NULL,
  `dr_number` varchar(50) NOT NULL,
  `sales_id` bigint(20) UNSIGNED DEFAULT NULL,
  `issued_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `condition` varchar(50) DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
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
  `issuance_date` date DEFAULT NULL,
  `issuance_type` varchar(50) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `issued_to_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `expected_return_date` date DEFAULT NULL,
  `actual_return_date` date DEFAULT NULL,
  `issued_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issuance_details`
--

CREATE TABLE `issuance_details` (
  `issuance_detail_id` bigint(20) UNSIGNED NOT NULL,
  `issuance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_issued` int(11) NOT NULL DEFAULT 0,
  `quantity_returned` int(11) NOT NULL DEFAULT 0,
  `condition_issued` varchar(50) DEFAULT NULL,
  `condition_returned` varchar(50) DEFAULT NULL,
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
  `parent_location_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Self-ref for sub-locations',
  `location_code` varchar(20) NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
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
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `manager` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`location_id`, `parent_location_id`, `location_code`, `location_name`, `code`, `location_type`, `address`, `city`, `province`, `region`, `contact_number`, `email`, `manager_name`, `capacity`, `opening_date`, `status_id`, `created_at`, `updated_at`, `manager`, `is_active`) VALUES
(1, NULL, 'CDO105', 'CDO Warehouse A', NULL, 'warehouse', 'Zone 5, Bulua CDO Mis.Or', 'Cagayan de Oro', 'Misamis Oriental', 'Region X', '0934324564', 'cdoairking@gmail.com', 'Maam Abegail', 5000, '2026-04-24', 5, '2026-04-24 03:18:22', '2026-04-24 03:18:36', NULL, 1),
(2, NULL, 'CDO102', 'CDO Main Showroom', NULL, 'showroom', 'JR Borja', 'Cagayan de Oro', 'Misamis Oriental', 'Region X', '0934324564', 'cdoairking@gmail.com', 'Maam Abegail', 5000, '2026-04-24', NULL, '2026-04-24 05:03:39', '2026-04-24 05:03:39', NULL, 1);

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

--
-- Dumping data for table `login_activities`
--

INSERT INTO `login_activities` (`id`, `user_id`, `username_attempt`, `success`, `failure_reason`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, NULL, 'admin@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:02:07'),
(2, NULL, 'admin@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:02:18'),
(3, NULL, 'admin@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:04:31'),
(4, NULL, 'admin@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:05:10'),
(5, NULL, 'admin@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:05:29'),
(6, NULL, 'adminchu@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:54:48'),
(7, NULL, 'adminchu@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 14:59:36'),
(8, NULL, 'adminchu@airking.com', 0, 'Invalid credentials.', '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 15:03:27'),
(9, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 15:18:10'),
(10, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-22 23:50:19'),
(11, 2, 'adminchu@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 03:15:34'),
(12, NULL, 'adminchu@airking.com', 0, 'Invalid CAPTCHA.', '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 04:06:23'),
(13, 2, 'adminchu@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 04:08:31'),
(14, 2, 'adminchu@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 04:42:16'),
(15, 4, 'branch@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 04:58:35'),
(16, 3, 'IA@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 04:59:24'),
(17, 2, 'adminchu@airking.com', 1, NULL, '10.0.2.189', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 05:00:04'),
(18, 2, 'adminchu@airking.com', 1, NULL, '172.20.10.2', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36 Edg/146.0.0.0', '2026-04-24 11:14:10'),
(19, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:12:09'),
(20, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:18:41'),
(21, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:21:39'),
(22, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:24:34'),
(23, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:36:58'),
(24, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:40:02'),
(25, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:42:44'),
(26, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-24 22:59:38'),
(27, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 00:27:07'),
(28, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 02:34:24'),
(29, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 02:38:14'),
(30, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 04:34:30'),
(31, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 06:50:18'),
(32, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 11:44:28'),
(33, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 12:39:13'),
(34, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 15:24:36'),
(35, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 17:08:13'),
(36, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 18:04:21'),
(37, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 21:02:37'),
(38, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-25 22:23:40'),
(39, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 00:00:29'),
(40, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 01:12:18'),
(41, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.52', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-04-26 11:47:35'),
(42, NULL, 'adminchu@airking.com', 0, 'Invalid CAPTCHA.', '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 11:57:05'),
(43, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 11:57:19'),
(44, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 12:44:23'),
(45, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 12:51:41'),
(46, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 16:07:27'),
(47, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 18:23:21'),
(48, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 19:44:45'),
(49, 2, 'adminchu@airking.com', 1, NULL, '10.191.89.236', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 21:08:15'),
(50, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-26 21:38:03'),
(51, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-28 06:40:42'),
(52, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-28 15:40:14'),
(53, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-28 16:27:28'),
(54, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 12:47:38'),
(55, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 13:19:10'),
(56, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 13:20:10'),
(57, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 15:13:34'),
(58, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 15:45:24'),
(59, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 20:19:51'),
(60, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-29 23:04:23'),
(61, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-30 03:04:58'),
(62, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-30 16:55:25'),
(63, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-04-30 21:54:36'),
(64, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-01 02:33:37'),
(65, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-01 16:41:41'),
(66, NULL, 'adminchu@gmail.com', 0, 'Invalid credentials.', '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 17:53:37'),
(67, NULL, 'adminchu@gmail.com', 0, 'Invalid credentials.', '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 17:53:55'),
(68, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 17:54:26'),
(69, 2, 'adminchu@airking.com', 0, 'Invalid credentials.', '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 18:07:58'),
(70, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 18:08:13'),
(71, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 18:09:45'),
(72, 2, 'adminchu@airking.com', 0, 'Invalid credentials.', '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 18:40:48'),
(73, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 18:41:06'),
(74, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-01 22:45:26'),
(75, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.42', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-01 22:54:59'),
(76, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-01 23:01:02'),
(77, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-02 02:46:38'),
(78, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.38', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-02 05:58:07'),
(79, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-02 05:58:53'),
(80, NULL, 'adminchu@airking.com', 0, 'Invalid CAPTCHA.', '10.0.0.38', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-02 16:39:39'),
(81, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.38', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-02 16:39:50'),
(82, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.83', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', '2026-05-02 17:27:11'),
(83, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.38', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-02 19:57:18'),
(84, 2, 'adminchu@airking.com', 1, NULL, '10.0.0.39', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36 Edg/147.0.0.0', '2026-05-02 21:49:28'),
(85, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 16:47:31'),
(86, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:18:51'),
(87, NULL, 'adminchu@airking.com', 0, 'Invalid CAPTCHA.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:34:03'),
(88, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:34:11'),
(89, NULL, 'ad@gmia.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:41:56'),
(90, NULL, 'adminchu@gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:42:14'),
(91, NULL, 'adminchu@gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:42:24'),
(92, NULL, 'adminchu@gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:42:34'),
(93, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:42:48'),
(94, 2, 'adminchu@airking.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:46:34'),
(95, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 17:46:50'),
(96, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 18:15:35'),
(97, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:18:39'),
(98, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:44:07'),
(99, 6, 'warehouse@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:45:55'),
(100, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:47:06'),
(101, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:48:24'),
(102, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:51:10'),
(103, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:53:07'),
(104, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:54:16'),
(105, 2, 'adminchu@airking.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:55:40'),
(106, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-09 20:55:50'),
(107, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:27:20'),
(108, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 02:30:28'),
(109, NULL, 'adminchu@airking.com', 0, 'Invalid CAPTCHA.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:05:34'),
(110, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:05:48'),
(111, 8, 'zsyntax7@gmail.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:21'),
(112, 2, 'adminchu@airking.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:06:50'),
(113, NULL, 'zsyntax@7gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:07:37'),
(114, NULL, 'zsyntax@7gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:07:51'),
(115, NULL, 'zsyntax@7gmail.com', 0, 'Invalid credentials.', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:08:07'),
(116, 8, 'zsyntax7@gmail.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:13:30'),
(117, 2, 'johnphilipbaloro56@gmail.com', 1, NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-10 03:32:34');

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
(1, '2025_01_20_000000_add_approved_by_to_purchase_order_details', 1),
(2, '2026_04_27_000000_add_scan_fields_to_item_serial', 2),
(3, '2025_01_15_000000_update_locations_unified', 3),
(4, '2026_05_02_000000_add_scanned_barcode_to_receiving_details', 4),
(5, '2026_05_02_000001_add_po_receiving_statuses', 5),
(6, '2026_05_02_000003_add_packages_on_hand_to_inventory', 6),
(7, '2026_05_10_000001_create_system_settings_table', 7),
(8, '2026_05_10_100000_add_max_login_attempts_system_setting', 8),
(9, '2026_05_10_120000_create_password_reset_codes_table', 9),
(10, '2026_05_10_130000_add_code_plain_to_password_reset_codes_table', 10),
(11, '2026_05_10_090058_add_otp_fields_to_users_table', 11),
(12, '2026_05_10_140000_add_two_factor_auth_system_setting', 12);

-- --------------------------------------------------------

--
-- Table structure for table `model_lookup`
--

CREATE TABLE `model_lookup` (
  `model_id` bigint(20) UNSIGNED NOT NULL,
  `brand_id` bigint(20) UNSIGNED DEFAULT NULL,
  `subcategory_id` bigint(20) UNSIGNED DEFAULT NULL,
  `model_code` varchar(100) NOT NULL,
  `variant` varchar(100) DEFAULT NULL,
  `capacity` varchar(50) DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

--
-- Dumping data for table `password_reset_codes`
--

INSERT INTO `password_reset_codes` (`id`, `user_id`, `code_hash`, `code_plain`, `expires_at`, `used_at`, `created_at`, `updated_at`) VALUES
(1, 2, '$2y$12$8Z5RwuvUwpBu5T0bJr2TZ.BP.viZnLsohAX2dV71dQw3rJNBIaVS2', NULL, '2026-05-10 02:24:38', '2026-05-09 18:24:38', '2026-05-09 18:24:38', '2026-05-09 18:24:38'),
(2, 2, '$2y$12$5B/3yBoTzOVy/fLxPQgyX.TUby5.w/zTjvPgrMqvXJv7oOGWIUWve', NULL, '2026-05-10 04:40:49', '2026-05-09 20:40:49', '2026-05-09 18:24:38', '2026-05-09 20:40:49'),
(3, 2, '$2y$12$1Bn6gTRtCqGCCx/c0dfKqOvyWfkhT6tO.CoAHEIucJ1b4Ev7VfGYe', 'RST-TEST', '2026-05-10 04:43:04', '2026-05-09 20:43:04', '2026-05-09 20:42:05', '2026-05-09 20:43:04'),
(4, 2, '$2y$12$hAbocxZ9N5Z3mVIE.FVIH.5WRBy24tik3MGLi94IDsL8Y4WJPdCs.', 'RST-RIJLUGH6', '2026-05-10 04:43:37', '2026-05-09 20:43:37', '2026-05-09 20:43:04', '2026-05-09 20:43:37'),
(5, 6, '$2y$12$8VKt8eL8ia2ESCvRGwj7EOsVgIHslOzFjQrSM9fZWQ488L9AkJc7.', 'RST-RT0ZZXJC', '2026-05-10 04:45:30', '2026-05-09 20:45:30', '2026-05-09 20:43:42', '2026-05-09 20:45:30'),
(6, 4, '$2y$12$EV.6PXMhjX/CgYp4f1L3uuhTWVXc1SRmhZKZdQjSZByEGiwsOkvwG', 'RST-SG7MHXCA', '2026-05-10 04:48:08', '2026-05-09 20:48:08', '2026-05-09 20:47:27', '2026-05-09 20:48:08'),
(7, 7, '$2y$12$xAivHJjw6rj34MYxXPEUCOnsAJ8Vmu0gkXp1K5ddle1RjI4TxPnzi', 'RST-1ITQU6PK', '2026-05-10 04:50:51', '2026-05-09 20:50:51', '2026-05-09 20:50:18', '2026-05-09 20:50:51'),
(8, 3, '$2y$12$E0L4UB6tMPty7Nq/129BQOxkF66jvKLYD77Kz1ZpcZ1kOiHYOh9ZK', 'RST-T6IFYK7C', '2026-05-10 04:53:42', '2026-05-09 20:53:42', '2026-05-09 20:51:33', '2026-05-09 20:53:42'),
(9, 5, '$2y$12$C7SRdOBX8YW2iixiB85BfedHDLJ9hLQ3Oe5kJFUe.Y6Hn4izGvPE.', 'RST-MNZU7M0J', '2026-05-10 04:54:24', '2026-05-09 20:54:24', '2026-05-09 20:51:57', '2026-05-09 20:54:24'),
(10, 2, '$2y$12$hgh.1YV7O.E6W5VQI0x0huPfLay14iJjXKDnVfq532nRQHkmL8KYy', 'RST-RFRPZLVF', '2026-05-10 04:55:05', '2026-05-09 20:55:05', '2026-05-09 20:54:28', '2026-05-09 20:55:05');

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
  `registration_kind` varchar(24) NOT NULL DEFAULT 'consumable' COMMENT 'consumable | appliance',
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
  `status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending | approved | rejected',
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
(61, 'App\\Models\\User', 2, 'auth-token', '96637d7ec6c2633c09b3212373b55b623526f3c85a42dce620c893eb187fd17c', '[\"*\"]', '2026-05-02 04:32:25', NULL, '2026-05-01 22:54:59', '2026-05-02 04:32:25'),
(62, 'App\\Models\\User', 2, 'auth-token', 'd3830cbdd93c7731b431febd5b69e78cef3937ca4c441cbfc1e99e14b3f27ee2', '[\"*\"]', '2026-05-02 01:04:17', NULL, '2026-05-01 23:01:02', '2026-05-02 01:04:17'),
(63, 'App\\Models\\User', 2, 'auth-token', 'ba749b5c7a2be10bd635e88007358daf1fa4768b91558ec39ed2848ba5b903e5', '[\"*\"]', '2026-05-02 03:37:17', NULL, '2026-05-02 02:46:38', '2026-05-02 03:37:17'),
(64, 'App\\Models\\User', 2, 'auth-token', '9070dc96e407561272505205dea086d471c473ea2063f917c6bd7a01e3bf15a1', '[\"*\"]', '2026-05-02 09:22:09', NULL, '2026-05-02 05:58:07', '2026-05-02 09:22:09'),
(65, 'App\\Models\\User', 2, 'auth-token', 'a1d3ea70b65f3193609bf9deed563aa610e0ae22a22771ae0e7ff470b2a0835d', '[\"*\"]', '2026-05-02 09:22:11', NULL, '2026-05-02 05:58:53', '2026-05-02 09:22:11'),
(66, 'App\\Models\\User', 2, 'auth-token', '4619c4c8cc41ce6452259f2b54a1ef44f488ccc22a2c66f0884e9a68339ac1cc', '[\"*\"]', '2026-05-02 18:37:58', NULL, '2026-05-02 16:39:50', '2026-05-02 18:37:58'),
(67, 'App\\Models\\User', 2, 'auth-token', 'f4956a97d7c596dcbf3b9c45c8915d3005ba96a53396dadb772aa3cafa9d9b70', '[\"*\"]', '2026-05-02 18:38:43', NULL, '2026-05-02 17:27:10', '2026-05-02 18:38:43'),
(68, 'App\\Models\\User', 2, 'auth-token', 'e5985b246d392a17063d2250d9abd9a59052c661e6a355f2a801798cad02b341', '[\"*\"]', '2026-05-02 20:32:06', NULL, '2026-05-02 19:57:18', '2026-05-02 20:32:06'),
(69, 'App\\Models\\User', 2, 'auth-token', '1b6b2deb036f98f431bd7e079803cc24a34c11f80d2c6aa8775603cf24dd1e6c', '[\"*\"]', '2026-05-02 22:19:51', NULL, '2026-05-02 21:49:28', '2026-05-02 22:19:51'),
(70, 'App\\Models\\User', 2, 'auth-token', 'b5d3dd468ff589f6cee60b29e06cf06d153074a15fd691e78d58898bce69ad2b', '[\"*\"]', '2026-05-09 17:13:17', '2026-05-09 17:14:17', '2026-05-09 16:47:31', '2026-05-09 17:13:17'),
(71, 'App\\Models\\User', 2, 'auth-token', 'ee85efd019601ce326533819192f64dc8defbfb24ed42648e43fcd036d1e269d', '[\"*\"]', '2026-05-09 17:18:56', '2026-05-09 17:19:56', '2026-05-09 17:18:51', '2026-05-09 17:18:56'),
(72, 'App\\Models\\User', 2, 'auth-token', 'fa255084f06d4917c1ab06afd61a5ef531062a1ee5a082b32f6d0c246d15cac4', '[\"*\"]', '2026-05-09 17:40:11', '2026-05-09 18:11:11', '2026-05-09 17:40:11', '2026-05-09 17:40:11'),
(73, 'App\\Models\\User', 2, 'auth-token', 'a3aef1d6ce6a71a53c681a2979ba3cfdf6619ee765dbb37f9627c283f79cb957', '[\"*\"]', '2026-05-09 17:46:15', '2026-05-09 18:17:15', '2026-05-09 17:46:15', '2026-05-09 17:46:15'),
(74, 'App\\Models\\User', 2, 'auth-token', 'f6d898163db28114e45514138faf608eff4ec9f56f4fc28a376e0b1fe16e4ada', '[\"*\"]', '2026-05-09 18:06:06', '2026-05-09 18:37:06', '2026-05-09 18:06:06', '2026-05-09 18:06:06'),
(75, 'App\\Models\\User', 2, 'auth-token', '6362c44db33d179d3d4783a6c69689bda2d9deb668dbbeb32691bd82979ef36f', '[\"*\"]', '2026-05-09 19:03:39', '2026-05-09 19:34:39', '2026-05-09 18:15:35', '2026-05-09 19:03:39'),
(76, 'App\\Models\\User', 2, 'auth-token', '732b22fdc8e212c1438dea2ac215f323cc048379847488a27ab4326d43f6c4c8', '[\"*\"]', '2026-05-09 20:43:48', '2026-05-09 21:14:48', '2026-05-09 20:43:48', '2026-05-09 20:43:48'),
(77, 'App\\Models\\User', 2, 'auth-token', '714a85e1d651f9943f7a8144bf50511f466f0a9373556b936c0dd4540f5e77e0', '[\"*\"]', '2026-05-09 20:44:47', '2026-05-09 21:15:47', '2026-05-09 20:44:47', '2026-05-09 20:44:47'),
(78, 'App\\Models\\User', 6, 'auth-token', '0bed20b624016eea82ebb08d80cc44e333846a003a0b0fb93e13c998cfcde092', '[\"*\"]', '2026-05-09 20:46:02', '2026-05-09 21:17:02', '2026-05-09 20:46:02', '2026-05-09 20:46:02'),
(79, 'App\\Models\\User', 2, 'auth-token', '8963674c5f871870a9cdbbfe787a7e91439ac4e79a0f9a2f8971948a3eb4d015', '[\"*\"]', '2026-05-09 20:47:37', '2026-05-09 21:18:37', '2026-05-09 20:47:37', '2026-05-09 20:47:37'),
(80, 'App\\Models\\User', 2, 'auth-token', 'b878e49da0f83254c28649a86af1687b64d22881e7471dc5166d4be0aa426e2c', '[\"*\"]', '2026-05-09 20:50:25', '2026-05-09 21:21:25', '2026-05-09 20:50:25', '2026-05-09 20:50:25'),
(81, 'App\\Models\\User', 2, 'auth-token', '879bfd36419c72cdeef9e973e6399ce7b26608a8059b6220dd749b5e8583212c', '[\"*\"]', '2026-05-09 20:52:07', '2026-05-09 21:23:07', '2026-05-09 20:52:07', '2026-05-09 20:52:07'),
(82, 'App\\Models\\User', 2, 'auth-token', 'ead57a267249afbedf17da5461f84a3afd7af15d73c2c36a7cf02b5f7dc98023', '[\"*\"]', '2026-05-09 20:53:20', '2026-05-09 21:24:20', '2026-05-09 20:53:20', '2026-05-09 20:53:20'),
(83, 'App\\Models\\User', 2, 'auth-token', '9f13aa713285c04e45c0a9766730712abe9ac0b69b79c6abd10d202e56c5939b', '[\"*\"]', '2026-05-09 20:54:34', '2026-05-09 21:25:34', '2026-05-09 20:54:34', '2026-05-09 20:54:34'),
(84, 'App\\Models\\User', 2, 'auth-token', '9e88c50f08b868f0928e4a9fb146ae2d3ea902e20f2bd20f576291912996ecc3', '[\"*\"]', '2026-05-09 21:35:38', '2026-05-09 22:06:38', '2026-05-09 20:55:50', '2026-05-09 21:35:38'),
(85, 'App\\Models\\User', 2, 'auth-token', '82b3bcf9bef2c68d6e2ec29b299f7b7f2373dcf1f3aa47f96badc6a929a5e6eb', '[\"*\"]', '2026-05-10 02:30:14', '2026-05-10 03:01:14', '2026-05-10 02:30:14', '2026-05-10 02:30:14'),
(86, 'App\\Models\\User', 2, 'auth-token', 'fdc9eb967ada9983fd3b385c055ff87f66e550778734fdc7edc11af03072d0d0', '[\"*\"]', '2026-05-10 02:46:53', '2026-05-10 03:17:53', '2026-05-10 02:46:53', '2026-05-10 02:46:53'),
(87, 'App\\Models\\User', 2, 'auth-token', 'c33051e1db85811979491d84a364f776f5999a6362cb0492e4111334ac798354', '[\"*\"]', '2026-05-10 03:06:00', '2026-05-10 03:37:00', '2026-05-10 03:06:00', '2026-05-10 03:06:00'),
(88, 'App\\Models\\User', 8, 'auth-token', 'd0828cf591af1388018fc61004a10637f2516aed324dd41215529bd6431aa703', '[\"*\"]', '2026-05-10 03:06:26', '2026-05-10 03:37:26', '2026-05-10 03:06:26', '2026-05-10 03:06:26'),
(89, 'App\\Models\\User', 2, 'auth-token', 'bd975d36741bc23ee5b29d069a4b6bf9f0b589238f403479552216a85960e5e4', '[\"*\"]', '2026-05-10 03:07:12', '2026-05-10 03:38:12', '2026-05-10 03:07:12', '2026-05-10 03:07:12'),
(90, 'App\\Models\\User', 8, 'auth-token', '209dfd00cf17f178354b5c0a6596aa82af95b957bc41351ec17efb6ed9206b04', '[\"*\"]', '2026-05-10 03:31:36', '2026-05-10 04:02:36', '2026-05-10 03:31:36', '2026-05-10 03:31:36'),
(91, 'App\\Models\\User', 2, 'auth-token', 'c4fda3dc1e7a82308ce408bac76b5055dc1b0753155e1560c0734a905bda0192', '[\"*\"]', '2026-05-10 03:43:13', '2026-05-10 04:14:13', '2026-05-10 03:32:34', '2026-05-10 03:43:13');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `unit_id` bigint(20) UNSIGNED DEFAULT NULL,
  `category_id` bigint(20) UNSIGNED DEFAULT NULL,
  `brand_id` bigint(20) UNSIGNED DEFAULT NULL,
  `model_id` bigint(20) UNSIGNED DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `product_code` varchar(50) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_type` varchar(20) NOT NULL DEFAULT 'appliance' COMMENT 'appliance | consumable',
  `capacity_rating` varchar(20) DEFAULT NULL COMMENT '1HP | 1.5HP | 2HP | 2.5HP | 3HP | 5HP',
  `variant` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `pieces_per_package` int(10) UNSIGNED DEFAULT NULL COMMENT 'For consumables — units per package',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `warranty_period_months` int(11) NOT NULL DEFAULT 0,
  `recommended_stocks` int(11) NOT NULL DEFAULT 0,
  `safety_stock` int(10) UNSIGNED DEFAULT NULL,
  `max_stock_level` int(10) UNSIGNED DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `unit_id`, `category_id`, `brand_id`, `model_id`, `barcode`, `product_code`, `product_name`, `product_type`, `capacity_rating`, `variant`, `description`, `pieces_per_package`, `unit_price`, `cost_price`, `warranty_period_months`, `recommended_stocks`, `safety_stock`, `max_stock_level`, `quantity`, `status_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 3, NULL, NULL, 'ABC123', 'LG 1HP Inverter Aircon', 'appliance', '1HP', NULL, NULL, NULL, 12000.00, 10000.00, 0, 0, NULL, NULL, 5, NULL, '2026-04-25 00:42:30', '2026-05-02 22:15:14'),
(2, 2, 6, NULL, NULL, NULL, 'PROD-F1C9B4F8', 'Solvent', 'consumable', NULL, NULL, 'solvent??', 50, 40.00, 37.00, 0, 55, 5, 80, 550, 3, '2026-04-25 04:49:39', '2026-05-02 22:17:35'),
(3, 1, 1, 7, NULL, NULL, 'HAHA123', 'adswadw', 'appliance', '3HP', 'Inverter', NULL, NULL, 23333.00, 23000.00, 24, 20, 15, 50, 4, 3, '2026-04-25 04:54:41', '2026-05-02 22:17:28');

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
  `supplier_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `po_number` varchar(50) NOT NULL,
  `order_date` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ordered_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approval_notes` text DEFAULT NULL,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_details`
--

CREATE TABLE `purchase_order_details` (
  `po_detail_id` bigint(20) UNSIGNED NOT NULL,
  `po_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_ordered` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
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
  `pr_number` varchar(50) NOT NULL,
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
  `pr_detail_id` bigint(20) UNSIGNED NOT NULL,
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
(2, 'inventory_analyst', 'Inventory Analyst — view and report', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(3, 'branch_manager', 'Branch / Location Manager', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(4, 'warehouse_personnel', 'Warehouse Personnel — scan and move stock', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(5, 'auditor', 'Auditor — read-only audit access', '2026-04-22 21:50:17', '2026-04-22 21:50:17');

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

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('jJJKgzIxNAIQqIUErRFTcoG54vaZ8uoSaklajVNY', NULL, '10.191.89.52', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Mobile Safari/537.36', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiYWxLMndmN2pCRHdKdW5rVHBGeFZIZmVST3U2NUtTelBaMmVpODhHVCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MjU6Imh0dHA6Ly8xMC4xOTEuODkuMjM2OjgwMDAiO3M6NToicm91dGUiO047fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=', 1777232679),
('ns0uR3tdjdXhQGb7sgsDJbYIOZkjmJaJgvNAn9vN', NULL, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', 'YTozOntzOjY6Il90b2tlbiI7czo0MDoiNDNkMGNGWlU0ckcxaUNjSXpMNjJRU1dUc0U5UVF4MVZkdDJvSWFwWSI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6MzE6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMC90ZXN0LW1haWwiO3M6NToicm91dGUiO047fXM6NjoiX2ZsYXNoIjthOjI6e3M6Mzoib2xkIjthOjA6e31zOjM6Im5ldyI7YTowOnt9fX0=', 1778403562);

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
(10, 'Pending Approval', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(11, 'Authorized', 'purchase_order', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(12, 'In Transit', 'transfer', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(13, 'Received', 'transfer', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(14, 'Pending', 'adjustment', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(15, 'Approved', 'adjustment', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(16, 'Paid', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(17, 'Unpaid', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(18, 'Partial', 'sales', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(19, 'Active', 'supplier', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(20, 'Inactive', 'supplier', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(21, 'In Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(22, 'Low Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(23, 'Out of Stock', 'inventory', 1, '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(24, 'Completed', 'purchase_order', 1, '2026-05-01 18:51:20', '2026-05-01 18:51:20'),
(25, 'Partially Received', 'purchase_order', 1, '2026-05-01 18:51:20', '2026-05-01 18:51:20');

-- --------------------------------------------------------

--
-- Table structure for table `subcategory_lookup`
--

CREATE TABLE `subcategory_lookup` (
  `subcategory_id` bigint(20) UNSIGNED NOT NULL,
  `category_id` bigint(20) UNSIGNED NOT NULL,
  `subcategory_name` varchar(100) NOT NULL,
  `is_serialized` tinyint(1) NOT NULL DEFAULT 0,
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
  `origin` varchar(20) NOT NULL DEFAULT 'Local' COMMENT 'Local | International',
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
(1, 'Supplier China', 'Xijin Ping', '093343566453', 'supplier@gmail.com', 'China', 'Local', NULL, '2222', 5, 'Supplier', '2026-04-25 12:47:51', '2026-04-25 12:47:51', NULL);

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
(1, 'session_timeout_minutes', '31', '2026-05-09 17:12:40', '2026-05-10 03:07:07'),
(2, 'max_login_attempts', '5', '2026-05-09 17:38:40', '2026-05-09 17:38:40'),
(3, 'two_factor_auth_enabled', '0', '2026-05-10 02:29:30', '2026-05-10 03:33:21');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_transfer_tracking`
--

CREATE TABLE `tbl_transfer_tracking` (
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
  `from_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `to_location_id` bigint(20) UNSIGNED DEFAULT NULL,
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date DEFAULT NULL,
  `requested_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `received_by` bigint(20) UNSIGNED DEFAULT NULL,
  `status_id` bigint(20) UNSIGNED DEFAULT NULL,
  `total_quantity_transferred` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_quantity_received` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transfer_details`
--

CREATE TABLE `transfer_details` (
  `transfer_detail_id` bigint(20) UNSIGNED NOT NULL,
  `transfer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `quantity_transferred` int(11) NOT NULL DEFAULT 0,
  `quantity_received` int(10) UNSIGNED NOT NULL DEFAULT 0,
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
  `unit_abbreviation` varchar(10) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `unit_lookup`
--

INSERT INTO `unit_lookup` (`unit_id`, `unit_name`, `unit_abbreviation`, `created_at`, `updated_at`) VALUES
(1, 'Unit', 'pc', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(2, 'Piece', 'pc', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(3, 'Roll', 'roll', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(4, 'Meter', 'm', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(5, 'Set', 'set', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(6, 'Box', 'box', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(7, 'Kilogram', 'kg', '2026-04-22 21:50:17', '2026-04-22 21:50:17'),
(8, 'Liter', 'L', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(9, 'Quarter Roll', '1/4', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(10, '3/8 Roll', '3/8', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(11, 'Half Roll', '1/2', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(12, 'Liter', 'L', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(13, 'Quarter Roll', '1/4', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(14, '3/8 Roll', '3/8', '2026-04-26 09:31:36', '2026-04-26 09:31:36'),
(15, 'Half Roll', '1/2', '2026-04-26 09:31:36', '2026-04-26 09:31:36');

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
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `location_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'Primary assigned location',
  `status_id` bigint(20) UNSIGNED NOT NULL DEFAULT 1,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `assigned_location_ids` longtext DEFAULT NULL COMMENT 'JSON array of extra locations',
  `allowed_panels` longtext DEFAULT NULL COMMENT 'JSON array of panel keys',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `otp_code` varchar(255) DEFAULT NULL,
  `otp_expires_at` timestamp NULL DEFAULT NULL,
  `alerts_last_seen_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `username`, `email`, `phone`, `role_id`, `location_id`, `status_id`, `email_verified_at`, `password_hash`, `remember_token`, `assigned_location_ids`, `allowed_panels`, `created_at`, `updated_at`, `otp_code`, `otp_expires_at`) VALUES
(2, 'Marichu', 'Contado', 'Admin Marichu', 'johnphilipbaloro56@gmail.com', '09123456789', 1, NULL, 1, NULL, '$2y$12$gn5KrHEUQYwuZZ9z3AGVyudXznCruJGq085uZEkpv7.F0et9o17L2', NULL, NULL, NULL, '2026-04-22 15:17:34', '2026-05-10 03:32:34', NULL, NULL),
(3, 'Inventory', 'Analyst', 'Inventory Analyst', 'IA@airking.com', '0975543453466', 2, 1, 1, NULL, '$2y$12$qRwVxgeEZER13qn8L7VC8OBgw9RIz41/9msGijW8B0JnJpcGbGI1G', NULL, NULL, NULL, '2026-04-24 04:11:24', '2026-05-09 20:53:42', NULL, NULL),
(4, 'Branch', 'Manager', 'Branch Manager', 'branch@airking.com', '093343566454', 3, 1, 1, NULL, '$2y$12$0P8Yy/Hq0Z3kud5ig3uhJ.19ygP1/MIjveiDUfMioXTrf5PsptoTu', NULL, NULL, NULL, '2026-04-24 04:43:44', '2026-05-09 20:48:08', NULL, NULL),
(5, 'Admin', 'Ibong', 'Admin Ibong', 'adminibong@gmail.com', '09752267355', 1, 2, 1, NULL, '$2y$12$tlHaN./20TxxEl0zykV1o.sn2vadx12vJQEw2w78N2g9h0mNLITgu', NULL, NULL, NULL, '2026-04-24 05:02:02', '2026-04-24 05:03:59', NULL, NULL),
(6, 'Warehouse', 'Personnel', 'WarehouseMan', 'warehouse@airking.com', '0912345654', 4, 1, 1, NULL, '$2y$12$7/zibdGWciGkdhbZL/bbS.SLKTuXMGb5CXQuMQVv.Qqwzt0VOhUva', NULL, NULL, NULL, '2026-04-26 13:20:13', '2026-05-09 20:45:30', NULL, NULL),
(7, 'Auditor', 'Yan', 'auditor@airking.com', 'auditor@airking.com', '092345463423', 5, 2, 1, NULL, '$2y$12$EFzoqcrO6aEaLRqaTMRBquvvPJeNL66Tp7A6ixVZloa8VOe4WtT7u', NULL, NULL, NULL, '2026-04-26 13:21:50', '2026-05-09 20:50:51', NULL, NULL),
(8, 'john', 'baloro', 'john baloro', 'zsyntax7@gmail.com', '09756026160', 3, 2, 1, NULL, '$2y$12$gsgz0q1ibpBK.3/OunuVVOOQtTCEND2aoqEa2aOiak6Z.WUCPxQRm', NULL, NULL, NULL, '2026-05-09 20:57:41', '2026-05-10 03:13:30', NULL, NULL);

-- --------------------------------------------------------

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
  ADD KEY `adjustments_status_id_foreign` (`status_id`);

--
-- Indexes for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD PRIMARY KEY (`adjustment_detail_id`),
  ADD KEY `adjustment_details_adjustment_id_foreign` (`adjustment_id`),
  ADD KEY `adjustment_details_product_id_foreign` (`product_id`);

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
  ADD KEY `barcode_scans_product_id_foreign` (`product_id`);

--
-- Indexes for table `brand_lookup`
--
ALTER TABLE `brand_lookup`
  ADD PRIMARY KEY (`brand_id`),
  ADD UNIQUE KEY `brand_lookup_brand_name_unique` (`brand_name`);

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
  ADD KEY `delivery_receipts_status_id_foreign` (`status_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`),
  ADD UNIQUE KEY `inventory_location_product_unique` (`location_id`,`product_id`),
  ADD KEY `inventory_product_id_foreign` (`product_id`),
  ADD KEY `inventory_status_id_foreign` (`status_id`);

--
-- Indexes for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_scan_events_product_id_foreign` (`product_id`),
  ADD KEY `inventory_scan_events_location_id_foreign` (`location_id`),
  ADD KEY `inventory_scan_events_to_location_id_foreign` (`to_location_id`),
  ADD KEY `inventory_scan_events_user_id_created_at_index` (`user_id`,`created_at`),
  ADD KEY `inventory_scan_events_transaction_type_created_at` (`transaction_type`,`created_at`),
  ADD KEY `inventory_scan_events_po_id_location_id_product_idx` (`po_id`,`location_id`,`product_id`);

--
-- Indexes for table `issuances`
--
ALTER TABLE `issuances`
  ADD PRIMARY KEY (`issuance_id`),
  ADD KEY `issuances_location_id_foreign` (`location_id`),
  ADD KEY `issuances_issued_to_user_id_foreign` (`issued_to_user_id`),
  ADD KEY `issuances_issued_by_foreign` (`issued_by`),
  ADD KEY `issuances_approved_by_foreign` (`approved_by`),
  ADD KEY `issuances_status_id_foreign` (`status_id`);

--
-- Indexes for table `issuance_details`
--
ALTER TABLE `issuance_details`
  ADD PRIMARY KEY (`issuance_detail_id`),
  ADD KEY `issuance_details_issuance_id_foreign` (`issuance_id`),
  ADD KEY `issuance_details_product_id_foreign` (`product_id`);

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
  ADD KEY `item_serial_scanned_by_foreign` (`scanned_by`);

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
-- Indexes for table `model_lookup`
--
ALTER TABLE `model_lookup`
  ADD PRIMARY KEY (`model_id`),
  ADD UNIQUE KEY `model_lookup_brand_model_unique` (`brand_id`,`model_code`),
  ADD KEY `model_lookup_subcategory_id_foreign` (`subcategory_id`),
  ADD KEY `model_lookup_status_id_foreign` (`status_id`);

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
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `products_product_code_unique` (`product_code`),
  ADD KEY `products_unit_id_foreign` (`unit_id`),
  ADD KEY `products_category_id_foreign` (`category_id`),
  ADD KEY `products_brand_id_foreign` (`brand_id`),
  ADD KEY `products_model_id_foreign` (`model_id`),
  ADD KEY `products_status_id_foreign` (`status_id`);

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
  ADD KEY `purchase_orders_supplier_id_foreign` (`supplier_id`),
  ADD KEY `purchase_orders_location_id_foreign` (`location_id`),
  ADD KEY `purchase_orders_ordered_by_foreign` (`ordered_by`),
  ADD KEY `purchase_orders_created_by_foreign` (`created_by`),
  ADD KEY `purchase_orders_approved_by_foreign` (`approved_by`),
  ADD KEY `purchase_orders_received_by_foreign` (`received_by`),
  ADD KEY `purchase_orders_status_id_foreign` (`status_id`);

--
-- Indexes for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  ADD PRIMARY KEY (`po_detail_id`),
  ADD KEY `purchase_order_details_po_id_foreign` (`po_id`),
  ADD KEY `purchase_order_details_product_id_foreign` (`product_id`),
  ADD KEY `purchase_order_details_approved_by_foreign` (`approved_by`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`purchase_return_id`),
  ADD UNIQUE KEY `purchase_returns_pr_number_unique` (`pr_number`),
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
  ADD PRIMARY KEY (`pr_detail_id`),
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
-- Indexes for table `subcategory_lookup`
--
ALTER TABLE `subcategory_lookup`
  ADD PRIMARY KEY (`subcategory_id`),
  ADD UNIQUE KEY `subcategory_lookup_cat_name_unique` (`category_id`,`subcategory_name`);

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
-- Indexes for table `tbl_transfer_tracking`
--
ALTER TABLE `tbl_transfer_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `tbl_transfer_tracking_transfer_id_foreign` (`transfer_id`),
  ADD KEY `tbl_transfer_tracking_location_id_foreign` (`location_id`),
  ADD KEY `tbl_transfer_tracking_recorded_by_foreign` (`recorded_by`);

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
  ADD KEY `transfers_status_id_foreign` (`status_id`);

--
-- Indexes for table `transfer_details`
--
ALTER TABLE `transfer_details`
  ADD PRIMARY KEY (`transfer_detail_id`),
  ADD KEY `transfer_details_transfer_id_foreign` (`transfer_id`),
  ADD KEY `transfer_details_product_id_foreign` (`product_id`);

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `adjustments`
--
ALTER TABLE `adjustments`
  MODIFY `adjustment_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  MODIFY `adjustment_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=515;

--
-- AUTO_INCREMENT for table `barcode_scans`
--
ALTER TABLE `barcode_scans`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `brand_lookup`
--
ALTER TABLE `brand_lookup`
  MODIFY `brand_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `category_lookup`
--
ALTER TABLE `category_lookup`
  MODIFY `category_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_receipts`
--
ALTER TABLE `delivery_receipts`
  MODIFY `dr_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issuances`
--
ALTER TABLE `issuances`
  MODIFY `issuance_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issuance_details`
--
ALTER TABLE `issuance_details`
  MODIFY `issuance_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_serial`
--
ALTER TABLE `item_serial`
  MODIFY `serial_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `location_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_activities`
--
ALTER TABLE `login_activities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `model_lookup`
--
ALTER TABLE `model_lookup`
  MODIFY `model_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_codes`
--
ALTER TABLE `password_reset_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `pending_products`
--
ALTER TABLE `pending_products`
  MODIFY `pending_product_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `profit_loss`
--
ALTER TABLE `profit_loss`
  MODIFY `profit_loss_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  MODIFY `po_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `purchase_return_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_details`
--
ALTER TABLE `purchase_return_details`
  MODIFY `pr_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receivings`
--
ALTER TABLE `receivings`
  MODIFY `receiving_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `receiving_details`
--
ALTER TABLE `receiving_details`
  MODIFY `receiving_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=67;

--
-- AUTO_INCREMENT for table `restock_recommendations`
--
ALTER TABLE `restock_recommendations`
  MODIFY `recommendation_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `role_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

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
-- AUTO_INCREMENT for table `status_lookup`
--
ALTER TABLE `status_lookup`
  MODIFY `status_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `subcategory_lookup`
--
ALTER TABLE `subcategory_lookup`
  MODIFY `subcategory_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `supplier_product`
--
ALTER TABLE `supplier_product`
  MODIFY `supplier_prod_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tbl_transfer_tracking`
--
ALTER TABLE `tbl_transfer_tracking`
  MODIFY `tracking_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transaction_type_lookup`
--
ALTER TABLE `transaction_type_lookup`
  MODIFY `transaction_type_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `transfers`
--
ALTER TABLE `transfers`
  MODIFY `transfer_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `transfer_details`
--
ALTER TABLE `transfer_details`
  MODIFY `transfer_detail_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unit_lookup`
--
ALTER TABLE `unit_lookup`
  MODIFY `unit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  ADD CONSTRAINT `adjustments_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `adjustment_details`
--
ALTER TABLE `adjustment_details`
  ADD CONSTRAINT `adjustment_details_adjustment_id_foreign` FOREIGN KEY (`adjustment_id`) REFERENCES `adjustments` (`adjustment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `adjustment_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `barcode_scans_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `customers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `delivery_receipts`
--
ALTER TABLE `delivery_receipts`
  ADD CONSTRAINT `delivery_receipts_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_receipts_sales_id_foreign` FOREIGN KEY (`sales_id`) REFERENCES `sales` (`sales_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `delivery_receipts_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `inventory_scan_events`
--
ALTER TABLE `inventory_scan_events`
  ADD CONSTRAINT `inventory_scan_events_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_scan_events_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_scan_events_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_scan_events_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_scan_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `issuances`
--
ALTER TABLE `issuances`
  ADD CONSTRAINT `issuances_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_issued_to_user_id_foreign` FOREIGN KEY (`issued_to_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `issuances_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL;

--
-- Constraints for table `issuance_details`
--
ALTER TABLE `issuance_details`
  ADD CONSTRAINT `issuance_details_issuance_id_foreign` FOREIGN KEY (`issuance_id`) REFERENCES `issuances` (`issuance_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `issuance_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

--
-- Constraints for table `item_serial`
--
ALTER TABLE `item_serial`
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
-- Constraints for table `model_lookup`
--
ALTER TABLE `model_lookup`
  ADD CONSTRAINT `model_lookup_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `model_lookup_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `model_lookup_subcategory_id_foreign` FOREIGN KEY (`subcategory_id`) REFERENCES `subcategory_lookup` (`subcategory_id`) ON DELETE SET NULL;

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
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brand_lookup` (`brand_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_model_id_foreign` FOREIGN KEY (`model_id`) REFERENCES `model_lookup` (`model_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `products_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `unit_lookup` (`unit_id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `purchase_orders_ordered_by_foreign` FOREIGN KEY (`ordered_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_details`
--
ALTER TABLE `purchase_order_details`
  ADD CONSTRAINT `purchase_order_details_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `purchase_order_details_po_id_foreign` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `purchase_return_details_purchase_return_id_foreign` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`purchase_return_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_return_details_serial_id_foreign` FOREIGN KEY (`serial_id`) REFERENCES `item_serial` (`serial_id`) ON DELETE SET NULL;

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
-- Constraints for table `subcategory_lookup`
--
ALTER TABLE `subcategory_lookup`
  ADD CONSTRAINT `subcategory_lookup_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `category_lookup` (`category_id`) ON DELETE CASCADE;

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
-- Constraints for table `tbl_transfer_tracking`
--
ALTER TABLE `tbl_transfer_tracking`
  ADD CONSTRAINT `tbl_transfer_tracking_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tbl_transfer_tracking_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tbl_transfer_tracking_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE;

--
-- Constraints for table `transfers`
--
ALTER TABLE `transfers`
  ADD CONSTRAINT `transfers_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_from_location_id_foreign` FOREIGN KEY (`from_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfers_to_location_id_foreign` FOREIGN KEY (`to_location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL;

--
-- Constraints for table `transfer_details`
--
ALTER TABLE `transfer_details`
  ADD CONSTRAINT `transfer_details_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transfer_details_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`transfer_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`),
  ADD CONSTRAINT `users_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `status_lookup` (`status_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
