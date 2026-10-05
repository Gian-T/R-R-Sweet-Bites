-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Oct 05, 2026 at 06:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rr_sweet_bites`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `full_name`, `email`, `password_hash`, `created_at`, `updated_at`) VALUES
(1, 'R&R Admin', 'admin@email.com', '$2y$10$4KxMFJZi.jUXGySSjl9PM.bWpFW1B/G8jP9v5oBZawxKxNI7jQhYu', '2026-10-04 01:43:12', '2026-10-04 01:43:12');

-- --------------------------------------------------------

--
-- Table structure for table `cancellation`
--

CREATE TABLE `cancellation` (
  `cancellation_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `status` enum('requested','approved','rejected') NOT NULL DEFAULT 'requested',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer`
--

CREATE TABLE `customer` (
  `customer_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `contact_number` varchar(30) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_tracking`
--

CREATE TABLE `delivery_tracking` (
  `tracking_id` int(11) NOT NULL,
  `fulfillment_id` int(11) NOT NULL,
  `status` enum('not_started','preparing','out_for_delivery','delivered','delayed','failed') NOT NULL,
  `location_lat` decimal(9,6) DEFAULT NULL,
  `location_lng` decimal(9,6) DEFAULT NULL,
  `courier_reference` varchar(150) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rating` smallint(6) NOT NULL,
  `design_rating` smallint(6) DEFAULT NULL,
  `flavor_rating` smallint(6) DEFAULT NULL,
  `delivery_rating` smallint(6) DEFAULT NULL,
  `coordination_rating` smallint(6) DEFAULT NULL,
  `feedback_text` text DEFAULT NULL,
  `gallery_consent` tinyint(1) NOT NULL DEFAULT 0,
  `display_preference` enum('named','anonymous') NOT NULL DEFAULT 'anonymous',
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_highlight`
--

CREATE TABLE `feedback_highlight` (
  `feedback_highlight_id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `highlight_tag` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `feedback_media`
--

CREATE TABLE `feedback_media` (
  `media_id` int(11) NOT NULL,
  `feedback_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `media_type` enum('image','video') NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fulfillment`
--

CREATE TABLE `fulfillment` (
  `fulfillment_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `method` enum('shop_delivery','third_party_courier','store_pickup') NOT NULL,
  `delivery_address` varchar(500) DEFAULT NULL,
  `pickup_preferred_time` time DEFAULT NULL,
  `delivery_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `estimated_distance_km` decimal(6,2) DEFAULT NULL,
  `estimated_travel_time_min` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `gallery_item`
--

CREATE TABLE `gallery_item` (
  `gallery_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message`
--

CREATE TABLE `message` (
  `message_id` int(11) NOT NULL,
  `sender_type` enum('customer','admin') NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_type` enum('customer','admin') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `message_text` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `notification_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `recipient_type` enum('customer','admin') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `message` varchar(500) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order`
--

CREATE TABLE `order` (
  `order_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `week_id` int(11) DEFAULT NULL,
  `cake_type` enum('cake','cupcake','number_shaped_cake') NOT NULL,
  `design_description` text NOT NULL,
  `flavor` varchar(150) NOT NULL,
  `num_layers` int(11) NOT NULL,
  `num_tiers` int(11) NOT NULL,
  `preferred_date` date NOT NULL,
  `is_rush` tinyint(1) NOT NULL DEFAULT 0,
  `design_intricacy_rating` int(11) NOT NULL,
  `decoration_requirements` text DEFAULT NULL,
  `structural_requirements` text DEFAULT NULL,
  `complexity_score` decimal(6,2) NOT NULL,
  `difficulty_level` enum('low','medium','high') NOT NULL,
  `status` enum('pending_review','quoted','confirmed','downpayment_pending_verification','in_production','out_for_delivery','ready_for_pickup','completed','cancellation_requested','cancelled') NOT NULL DEFAULT 'pending_review',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `order_reference_image`
--

CREATE TABLE `order_reference_image` (
  `image_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment`
--

CREATE TABLE `payment` (
  `payment_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `payment_type` enum('downpayment','balance') NOT NULL,
  `payment_method` enum('gcash','bank_transfer','cash') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `proof_image_url` varchar(500) DEFAULT NULL,
  `status` enum('pending_verification','verified','rejected') NOT NULL DEFAULT 'pending_verification',
  `verified_by_admin_id` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `quotation`
--

CREATE TABLE `quotation` (
  `quotation_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `quoted_price` decimal(10,2) NOT NULL,
  `remarks` text DEFAULT NULL,
  `version_number` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','accepted','revision_requested','revised','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `refund`
--

CREATE TABLE `refund` (
  `refund_id` int(11) NOT NULL,
  `cancellation_id` int(11) NOT NULL,
  `decision` enum('approved','rejected') NOT NULL,
  `refund_amount` decimal(10,2) NOT NULL,
  `processed_by_admin_id` int(11) NOT NULL,
  `processed_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `weekly_availability`
--

CREATE TABLE `weekly_availability` (
  `week_id` int(11) NOT NULL,
  `week_start_date` date NOT NULL,
  `week_end_date` date NOT NULL,
  `status` enum('open','closed','fully_booked') NOT NULL DEFAULT 'open',
  `set_by_admin_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `cancellation`
--
ALTER TABLE `cancellation`
  ADD PRIMARY KEY (`cancellation_id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `customer`
--
ALTER TABLE `customer`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `idx_tracking_fulfillment` (`fulfillment_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD KEY `fk_feedback_customer` (`customer_id`);

--
-- Indexes for table `feedback_highlight`
--
ALTER TABLE `feedback_highlight`
  ADD PRIMARY KEY (`feedback_highlight_id`),
  ADD KEY `fk_feedback_highlight_feedback` (`feedback_id`);

--
-- Indexes for table `feedback_media`
--
ALTER TABLE `feedback_media`
  ADD PRIMARY KEY (`media_id`),
  ADD KEY `fk_feedback_media_feedback` (`feedback_id`);

--
-- Indexes for table `fulfillment`
--
ALTER TABLE `fulfillment`
  ADD PRIMARY KEY (`fulfillment_id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `gallery_item`
--
ALTER TABLE `gallery_item`
  ADD PRIMARY KEY (`gallery_id`),
  ADD KEY `fk_gallery_admin` (`admin_id`);

--
-- Indexes for table `message`
--
ALTER TABLE `message`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_message_order` (`order_id`),
  ADD KEY `idx_message_recipient` (`recipient_type`,`recipient_id`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `fk_notification_order` (`order_id`),
  ADD KEY `idx_notification_recipient` (`recipient_type`,`recipient_id`);

--
-- Indexes for table `order`
--
ALTER TABLE `order`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `idx_order_customer` (`customer_id`),
  ADD KEY `idx_order_status` (`status`),
  ADD KEY `idx_order_week` (`week_id`);

--
-- Indexes for table `order_reference_image`
--
ALTER TABLE `order_reference_image`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `idx_ref_image_order` (`order_id`);

--
-- Indexes for table `payment`
--
ALTER TABLE `payment`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_payment_admin` (`verified_by_admin_id`),
  ADD KEY `idx_payment_order` (`order_id`),
  ADD KEY `idx_payment_status` (`status`);

--
-- Indexes for table `quotation`
--
ALTER TABLE `quotation`
  ADD PRIMARY KEY (`quotation_id`),
  ADD UNIQUE KEY `uq_quotation_version` (`order_id`,`version_number`),
  ADD KEY `fk_quotation_admin` (`admin_id`);

--
-- Indexes for table `refund`
--
ALTER TABLE `refund`
  ADD PRIMARY KEY (`refund_id`),
  ADD UNIQUE KEY `cancellation_id` (`cancellation_id`),
  ADD KEY `fk_refund_admin` (`processed_by_admin_id`);

--
-- Indexes for table `weekly_availability`
--
ALTER TABLE `weekly_availability`
  ADD PRIMARY KEY (`week_id`),
  ADD UNIQUE KEY `uq_week_range` (`week_start_date`,`week_end_date`),
  ADD KEY `fk_week_admin` (`set_by_admin_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cancellation`
--
ALTER TABLE `cancellation`
  MODIFY `cancellation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer`
--
ALTER TABLE `customer`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `feedback_highlight`
--
ALTER TABLE `feedback_highlight`
  MODIFY `feedback_highlight_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `feedback_media`
--
ALTER TABLE `feedback_media`
  MODIFY `media_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `fulfillment`
--
ALTER TABLE `fulfillment`
  MODIFY `fulfillment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery_item`
--
ALTER TABLE `gallery_item`
  MODIFY `gallery_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message`
--
ALTER TABLE `message`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order`
--
ALTER TABLE `order`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_reference_image`
--
ALTER TABLE `order_reference_image`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payment`
--
ALTER TABLE `payment`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quotation`
--
ALTER TABLE `quotation`
  MODIFY `quotation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refund`
--
ALTER TABLE `refund`
  MODIFY `refund_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `weekly_availability`
--
ALTER TABLE `weekly_availability`
  MODIFY `week_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cancellation`
--
ALTER TABLE `cancellation`
  ADD CONSTRAINT `fk_cancellation_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `delivery_tracking`
--
ALTER TABLE `delivery_tracking`
  ADD CONSTRAINT `fk_tracking_fulfillment` FOREIGN KEY (`fulfillment_id`) REFERENCES `fulfillment` (`fulfillment_id`);

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `fk_feedback_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  ADD CONSTRAINT `fk_feedback_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `feedback_highlight`
--
ALTER TABLE `feedback_highlight`
  ADD CONSTRAINT `fk_feedback_highlight_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`feedback_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `feedback_media`
--
ALTER TABLE `feedback_media`
  ADD CONSTRAINT `fk_feedback_media_feedback` FOREIGN KEY (`feedback_id`) REFERENCES `feedback` (`feedback_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fulfillment`
--
ALTER TABLE `fulfillment`
  ADD CONSTRAINT `fk_fulfillment_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `gallery_item`
--
ALTER TABLE `gallery_item`
  ADD CONSTRAINT `fk_gallery_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`);

--
-- Constraints for table `message`
--
ALTER TABLE `message`
  ADD CONSTRAINT `fk_message_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `fk_notification_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `order`
--
ALTER TABLE `order`
  ADD CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `customer` (`customer_id`),
  ADD CONSTRAINT `fk_order_week` FOREIGN KEY (`week_id`) REFERENCES `weekly_availability` (`week_id`);

--
-- Constraints for table `order_reference_image`
--
ALTER TABLE `order_reference_image`
  ADD CONSTRAINT `fk_refimg_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment`
--
ALTER TABLE `payment`
  ADD CONSTRAINT `fk_payment_admin` FOREIGN KEY (`verified_by_admin_id`) REFERENCES `admin` (`admin_id`),
  ADD CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `quotation`
--
ALTER TABLE `quotation`
  ADD CONSTRAINT `fk_quotation_admin` FOREIGN KEY (`admin_id`) REFERENCES `admin` (`admin_id`),
  ADD CONSTRAINT `fk_quotation_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`);

--
-- Constraints for table `refund`
--
ALTER TABLE `refund`
  ADD CONSTRAINT `fk_refund_admin` FOREIGN KEY (`processed_by_admin_id`) REFERENCES `admin` (`admin_id`),
  ADD CONSTRAINT `fk_refund_cancellation` FOREIGN KEY (`cancellation_id`) REFERENCES `cancellation` (`cancellation_id`);

--
-- Constraints for table `weekly_availability`
--
ALTER TABLE `weekly_availability`
  ADD CONSTRAINT `fk_week_admin` FOREIGN KEY (`set_by_admin_id`) REFERENCES `admin` (`admin_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
