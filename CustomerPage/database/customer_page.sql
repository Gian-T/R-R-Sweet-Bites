CREATE DATABASE IF NOT EXISTS `R&R Sweet Bites` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `R&R Sweet Bites`;

CREATE TABLE IF NOT EXISTS customer_portal_customers (
    customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    UNIQUE KEY uq_customer_portal_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_cake_requests (
    request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    event_date DATE NULL,
    cake_type VARCHAR(80) NULL,
    details TEXT NOT NULL,
    status ENUM('new', 'contacted', 'quoted', 'closed') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_id),
    KEY idx_customer_requests_status_created (status, created_at),
    KEY idx_customer_requests_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_portal_orders (
    order_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    order_number VARCHAR(30) NOT NULL,
    cake_name VARCHAR(180) NOT NULL,
    event_date DATE NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    order_status ENUM('pending', 'quoted', 'confirmed', 'in_production', 'quality_check', 'ready', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    fulfillment_method ENUM('pickup', 'delivery') NULL,
    delivery_address VARCHAR(500) NULL,
    delivery_city VARCHAR(100) NULL,
    delivery_postal_code VARCHAR(20) NULL,
    customer_note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (order_id),
    UNIQUE KEY uq_customer_order_number (order_number),
    KEY idx_customer_orders_owner_status (customer_id, order_status),
    CONSTRAINT fk_customer_orders_owner FOREIGN KEY (customer_id) REFERENCES customer_portal_customers (customer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'gcash', 'other') NOT NULL,
    reference_number VARCHAR(120) NULL,
    payment_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    KEY idx_customer_payments_order_time (order_id, paid_at),
    CONSTRAINT fk_customer_payments_order FOREIGN KEY (order_id) REFERENCES customer_portal_orders (order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_feedback (
    feedback_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NOT NULL,
    would_recommend TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (feedback_id),
    UNIQUE KEY uq_customer_feedback_order (order_id),
    CONSTRAINT chk_customer_feedback_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_customer_feedback_order FOREIGN KEY (order_id) REFERENCES customer_portal_orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_feedback_owner FOREIGN KEY (customer_id) REFERENCES customer_portal_customers (customer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_cancellation_requests (
    cancellation_request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    request_status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cancellation_request_id),
    KEY idx_customer_cancellations_owner_status (customer_id, request_status),
    CONSTRAINT fk_customer_cancellation_order FOREIGN KEY (order_id) REFERENCES customer_portal_orders (order_id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_cancellation_owner FOREIGN KEY (customer_id) REFERENCES customer_portal_customers (customer_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_weekly_availability (
    availability_date DATE NOT NULL,
    availability_status ENUM('open', 'limited', 'full') NOT NULL DEFAULT 'open',
    note VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (availability_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;