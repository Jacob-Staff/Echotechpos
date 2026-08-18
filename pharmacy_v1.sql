-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 14, 2025 at 09:18 PM
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
-- Database: `pharmacy_v1`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date` date NOT NULL COMMENT 'The date the expense occurred',
  `expense_date` date NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `info`
--

CREATE TABLE `info` (
  `id` int(11) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Pharmacy Name',
  `address` varchar(255) DEFAULT NULL,
  `phone_no` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `setup_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `info`
--

INSERT INTO `info` (`id`, `name`, `address`, `phone_no`, `email`, `setup_date`) VALUES
(1, 'Jacobs Pharmacy', 'Kanyama 735', '0974140989', 'jcbstff@gmail.com', '2025-10-11 09:44:51');

-- --------------------------------------------------------

--
-- Table structure for table `layby`
--

CREATE TABLE `layby` (
  `id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `deposit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('Pending','Paid','Cancelled') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deposit_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `layby`
--

INSERT INTO `layby` (`id`, `customer_name`, `customer_phone`, `total_amount`, `deposit`, `balance_due`, `due_date`, `status`, `created_at`, `deposit_amount`) VALUES
(1, 'Jacob Daka', '0974140989', 20.00, 10.00, 10.00, '2025-10-14', 'Pending', '2025-10-13 18:49:19', 0.00),
(2, 'Jacob Daka', '0974140989', 10.00, 10.00, 0.00, '2025-10-14', 'Pending', '2025-10-13 18:50:13', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `layby_items`
--

CREATE TABLE `layby_items` (
  `id` int(11) NOT NULL,
  `layby_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `qty` int(11) NOT NULL,
  `total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `layby_items`
--

INSERT INTO `layby_items` (`id`, `layby_id`, `product_name`, `price`, `qty`, `total`) VALUES
(1, 1, 'Amoxl 250MG', 10.00, 2, 20.00),
(2, 2, 'Amoxl 250MG', 10.00, 1, 10.00);

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `registration_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('0','1') NOT NULL DEFAULT '0' COMMENT '0=Waiting, 1=Served',
  `patient_condation` varchar(3) NOT NULL DEFAULT 'No'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pos_settings`
--

CREATE TABLE `pos_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order`
--

CREATE TABLE `purchase_order` (
  `order_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_cost` decimal(10,2) NOT NULL,
  `price` decimal(10,2) NOT NULL COMMENT 'Used for total payable calculation on dashboard',
  `status` enum('0','1','2') NOT NULL DEFAULT '0' COMMENT '0=Pending/Unpaid, 1=Completed/Paid, 2=Cancelled',
  `created_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) UNSIGNED NOT NULL,
  `invoice` varchar(50) NOT NULL,
  `total` decimal(10,2) NOT NULL COMMENT 'Final total price including tax',
  `payment` varchar(50) NOT NULL COMMENT 'Payment method (Cash, Credit, etc.)',
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `invoice`, `total`, `payment`, `sale_date`) VALUES
(1, 'INV-1760361337940', 10.80, 'Cash', '2025-10-13 13:15:37'),
(2, 'INV-1760361360307', 10.80, 'Card - Zanaco', '2025-10-13 13:16:00'),
(3, 'INV-1760392985787', 10.80, 'Cash', '2025-10-13 22:03:05'),
(4, 'INV-1760393251520', 162.00, 'Cash', '2025-10-13 22:07:31'),
(5, 'INV-1760450444518', 162.00, 'Mobile - Airtel', '2025-10-14 14:00:44');

-- --------------------------------------------------------

--
-- Table structure for table `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(11) UNSIGNED NOT NULL,
  `sale_id` int(11) UNSIGNED NOT NULL COMMENT 'Links to sales(id)',
  `product_id` int(11) UNSIGNED NOT NULL COMMENT 'Links to store_items(id)',
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL COMMENT 'Price per unit at time of sale'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_items`
--

INSERT INTO `sales_items` (`id`, `sale_id`, `product_id`, `quantity`, `price`) VALUES
(1, 1, 2, 1, 10.00),
(2, 2, 2, 1, 10.00),
(3, 3, 2, 1, 10.00),
(4, 4, 4, 1, 150.00),
(5, 5, 4, 1, 150.00);

-- --------------------------------------------------------

--
-- Table structure for table `store_items`
--

CREATE TABLE `store_items` (
  `id` int(11) UNSIGNED NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL COMMENT 'Unit selling price',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 16.00,
  `barcode` varchar(255) DEFAULT NULL,
  `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `product_group` varchar(100) DEFAULT NULL COMMENT 'Category, e.g., Analgesics, Antibiotics',
  `is_service` tinyint(1) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Current stock quantity',
  `capacity` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `expiry_date` date NOT NULL,
  `manufacturer` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `store_items`
--

INSERT INTO `store_items` (`id`, `item_name`, `price`, `tax_rate`, `barcode`, `cost`, `product_group`, `is_service`, `description`, `image_path`, `quantity`, `capacity`, `is_active`, `expiry_date`, `manufacturer`, `created_at`) VALUES
(2, 'Amoxl 250MG', 10.00, 16.00, '', 5.00, 'Medicine', 0, '', NULL, 97, '250', 1, '2026-12-01', NULL, '2025-10-11 14:15:11'),
(4, 'magnavit', 150.00, 16.00, '', 100.00, 'Medicine', 0, '', NULL, 1, '100ml', 1, '2026-12-03', NULL, '2025-10-13 22:07:08');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone_unique` (`phone`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `info`
--
ALTER TABLE `info`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `layby`
--
ALTER TABLE `layby`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `layby_items`
--
ALTER TABLE `layby_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_layby` (`layby_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`);

--
-- Indexes for table `pos_settings`
--
ALTER TABLE `pos_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `purchase_order`
--
ALTER TABLE `purchase_order`
  ADD PRIMARY KEY (`order_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice` (`invoice`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `store_items`
--
ALTER TABLE `store_items`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `info`
--
ALTER TABLE `info`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `layby`
--
ALTER TABLE `layby`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `layby_items`
--
ALTER TABLE `layby_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pos_settings`
--
ALTER TABLE `pos_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_order`
--
ALTER TABLE `purchase_order`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `store_items`
--
ALTER TABLE `store_items`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `layby_items`
--
ALTER TABLE `layby_items`
  ADD CONSTRAINT `fk_layby` FOREIGN KEY (`layby_id`) REFERENCES `layby` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD CONSTRAINT `sales_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `sales_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `store_items` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
